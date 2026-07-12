<?php

namespace Schemastud\DataSchemas\Support;

/**
 * Dependency-free reshaping of a self-contained JSON Schema document
 * (root + internal `$defs`) into OpenAPI `components/schemas` form.
 *
 * No Scribe, no framework, no external deps — pure array transformation.
 */
class OpenApi
{
    /**
     * Hoist a document's `$defs` under `components.schemas` and rewrite every
     * `#/$defs/X` reference to `#/components/schemas/X`.
     *
     * Idempotent on a document with no `$defs`: the root is returned with refs
     * rewritten (a no-op when there are none) and no `components` key added.
     *
     * @param  array  $document  Self-contained schema: root keys plus optional `$defs`.
     */
    public static function toOpenApiComponents(array $document): array
    {
        $defs = $document['$defs'] ?? [];
        unset($document['$defs']);

        $root = self::rewriteRefs($document);

        if (empty($defs)) {
            return $root;
        }

        $schemas = [];
        foreach ($defs as $name => $definition) {
            $schemas[$name] = self::rewriteRefs($definition);
        }

        $root['components'] = ['schemas' => $schemas];

        return $root;
    }

    /**
     * Recursively rewrite `#/$defs/X` $ref strings to `#/components/schemas/X`.
     */
    protected static function rewriteRefs(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/$defs/')) {
                $node[$key] = '#/components/schemas/'.substr($value, strlen('#/$defs/'));

                continue;
            }

            $node[$key] = self::rewriteRefs($value);
        }

        return $node;
    }
}
