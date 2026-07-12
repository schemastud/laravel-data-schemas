<?php

namespace Schemastud\DataSchemas\Lifecycle;

/**
 * Stable structural hash of a projected JSON Schema — the drift-guard input.
 *
 * Same structure → same fingerprint; a structural change → a different one.
 * Canonicalization:
 *  - object keys are sorted (so key order never affects the hash);
 *  - volatile / non-structural metadata is excluded by default (`examples`,
 *    `description`, `title`, `$comment`, and `$id`/`$schema` document chrome) so
 *    that re-ordering or re-wording prose does not register as drift.
 *
 * Lists (JSON arrays) keep their order — `required`, `enum`, `type` unions and
 * `prefixItems` are structurally significant.
 */
class SchemaFingerprint
{
    /**
     * Keys excluded from the fingerprint because they are descriptive, not
     * structural.
     *
     * @var array<int, string>
     */
    public const VOLATILE_KEYS = [
        'examples',
        'example',
        'description',
        'title',
        '$comment',
        '$id',
        '$schema',
    ];

    /**
     * Produce the canonical structural hash of a schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $excludeKeys  override the volatile key set
     */
    public static function of(array $schema, ?array $excludeKeys = null): string
    {
        $canonical = self::canonicalize($schema, $excludeKeys ?? self::VOLATILE_KEYS);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The canonical (sorted-key, volatile-stripped) form behind the hash.
     * Exposed for debugging / golden comparison.
     *
     * @param  array<int, string>  $excludeKeys
     */
    public static function canonicalize(mixed $node, array $excludeKeys = self::VOLATILE_KEYS): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        // A list keeps its order; only its elements are canonicalized.
        if (array_is_list($node)) {
            return array_map(fn ($item) => self::canonicalize($item, $excludeKeys), $node);
        }

        $out = [];
        foreach ($node as $key => $value) {
            if (in_array($key, $excludeKeys, true)) {
                continue;
            }
            $out[$key] = self::canonicalize($value, $excludeKeys);
        }

        ksort($out);

        return $out;
    }
}
