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

    /**
     * Is the only difference between a frozen artifact and the current projection the ADDITION of
     * `default` keywords? (api-surface-coherence 122.)
     *
     * The narrow predicate that licenses re-freezing a write-once artifact in place. It is deliberately
     * conservative: ANY removed key, ANY changed value, and ANY added key that is not `default` returns
     * false, so the write-once refusal stands for everything except the one delta proven inert.
     *
     * Inert is proven, not assumed. All three migration rungs read `default` off the TARGET schema and
     * only for ADDED fields — `$request->to['properties']`, never `$request->from` (see
     * {@see \Schemastud\DataSchemas\Migration\Rungs\StructuralRung::propose()}). So a frozen
     * artifact's `default` is consumed only when that artifact is a migration target, and the CALLER
     * must additionally establish that it cannot be one. {@see SchemaDriftGuard::check()} does that by
     * requiring version 1: being a target means something migrates INTO the version, and nothing
     * precedes the first.
     *
     * `default` is NOT volatile and must never be added to {@see self::VOLATILE_KEYS} — on any version
     * above 1 the keyword is structural, because that version CAN be a target and the value it carries
     * is the value a migrated document receives.
     */
    public static function inertDefaultAdditionOnly(array $frozen, array $current): bool
    {
        // Compare what the FINGERPRINT compares. Volatile keys (`examples`, `description`, `title`, …)
        // are already excluded from identity, so a difference in one is not a difference at all — and a
        // raw comparison here would refuse a genuinely inert delta because a `title` moved. Canonicalize
        // both sides first, exactly as {@see self::of()} does. `default` is NOT volatile, so it survives
        // canonicalization and remains visible to the walk below, which is the whole point.
        $frozen = self::canonicalize($frozen);
        $current = self::canonicalize($current);

        if (! is_array($frozen) || ! is_array($current)) {
            return false;
        }

        return self::walkInertDefaults($frozen, $current);
    }

    /**
     * The recursive half of {@see self::inertDefaultAdditionOnly()}, over already-canonical nodes.
     */
    protected static function walkInertDefaults(array $frozen, array $current): bool
    {
        foreach ($current as $key => $value) {
            if (! array_key_exists($key, $frozen)) {
                if ($key !== 'default') {
                    return false;
                }

                continue;
            }

            if (is_array($value) && is_array($frozen[$key])) {
                if (! self::walkInertDefaults($frozen[$key], $value)) {
                    return false;
                }

                continue;
            }

            if ($frozen[$key] !== $value) {
                return false;
            }
        }

        foreach ($frozen as $key => $value) {
            if (! array_key_exists($key, $current)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The trailing version segment of a schema `$id`, or null when the id does not end in one.
     */
    public static function versionOf(string $id): ?int
    {
        $tail = substr($id, strrpos($id, '/') === false ? 0 : strrpos($id, '/') + 1);

        return ctype_digit($tail) ? (int) $tail : null;
    }
}
