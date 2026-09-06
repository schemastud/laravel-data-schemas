<?php

namespace Schemastud\DataSchemas\Support;

use InvalidArgumentException;

/**
 * Dependency-free reshaping of a self-contained JSON Schema document
 * (root + internal `$defs`) into OpenAPI `components/schemas` form.
 *
 * No Scribe, no framework, no external deps — pure array transformation.
 */
class OpenApi
{
    /**
     * Hoist definitions under legal component names and rewrite both local definition pointers
     * and bundled absolute schema identities. External references remain external.
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

        $references = [];
        foreach ($defs as $name => $definition) {
            $target = '#/components/schemas/'.self::componentName((string) $name, $definition);
            $references['#/$defs/'.str_replace(['~', '/'], ['~0', '~1'], $name)] = $target;
            $references[$name] = $target;
            if (isset($definition['$id'])) {
                $references[$definition['$id']] = $target;
            }
        }

        $root = self::rewriteRefs($document, $references);

        if (empty($defs)) {
            return $root;
        }

        $schemas = [];
        $identities = [];
        foreach ($defs as $name => $definition) {
            $component = self::componentName((string) $name, $definition);
            $identity = $definition['$id'] ?? $name;
            $converted = self::rewriteRefs($definition, $references);
            // An embedded component must not rebase its document-local references onto its old ID.
            unset($converted['$id'], $converted['$schema']);

            if (isset($schemas[$component]) && ($identities[$component] !== $identity || $schemas[$component] != $converted)) {
                throw new InvalidArgumentException("OpenAPI component name collision [{$component}].");
            }

            $identities[$component] = $identity;
            $schemas[$component] = $converted;
        }

        $root['components'] = ['schemas' => $schemas];

        return $root;
    }

    /** Preserve ordinary Data names; addressable definitions use their declared schema title. */
    public static function componentName(string $name, array $definition): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            return $name;
        }

        $title = $definition['title'] ?? null;
        if (is_string($title) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $title)) {
            return $title;
        }

        // A schema without a usable title still has a stable identity. Never turn a URI's final
        // version segment into a type name or collapse distinct addresses by punctuation removal.
        return 'Schema_'.hash('sha256', $name);
    }

    /**
     * Recursively rewrite `#/$defs/X` $ref strings to `#/components/schemas/X`.
     */
    protected static function rewriteRefs(mixed $node, array $references = []): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                if (isset($references[$value])) {
                    $node[$key] = $references[$value];
                } elseif (str_starts_with($value, '#/$defs/')) {
                    $node[$key] = '#/components/schemas/'.substr($value, strlen('#/$defs/'));
                }

                continue;
            }

            $node[$key] = self::rewriteRefs($value, $references);
        }

        return $node;
    }
}
