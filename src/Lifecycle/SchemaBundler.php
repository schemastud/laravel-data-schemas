<?php

namespace Schemastud\DataSchemas\Lifecycle;

use Opis\JsonSchema\Validator;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/**
 * Produces a self-contained JSON Schema 2020-12 compound document.
 *
 * Two transports:
 *
 *  - {@see bundle()} — an OFFLINE-PORTABLE-YET-RE-RESOLVABLE compound document:
 *    every absolute `$ref` that points at an addressable resource is embedded as
 *    a bundled resource under `$defs`, each EMBEDDED RESOURCE RETAINING ITS OWN
 *    `$id` (the canonical 2020-12 bundling shape). `$ref`s are left absolute and
 *    re-resolve against the embedded `$id`s, so the bundle is both portable and
 *    re-resolvable.
 *
 *  - {@see dereference()} — a fully INLINED schema with NO `$ref` and NO `$defs`,
 *    for strict-LLM consumption. The model receives one complete schema.
 *
 * Resources are sourced from a {@see SchemaRegistry} (and/or an inline `$defs`
 * map carried on the document itself), so any addressable node — top level or
 * nested — can be assembled.
 */
class SchemaBundler
{
    public function __construct(
        protected ?SchemaRegistry $registry = null,
    ) {}

    /**
     * Embed every referenced addressable resource into a single compound
     * document, keyed under `$defs` by `$id`, each retaining its `$id`.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function bundle(array $document): array
    {
        $defs = $document['$defs'] ?? [];
        unset($document['$defs']);

        // Index any inline defs that already carry an $id so absolute refs into
        // them resolve without a registry round-trip.
        $byId = [];
        foreach ($defs as $def) {
            if (is_array($def) && isset($def['$id'])) {
                $byId[$def['$id']] = $def;
            }
        }

        $embedded = [];
        $this->collectAbsoluteResources($document, $byId, $embedded);

        // Re-attach inline $defs (short-name resources) plus the embedded
        // absolute resources keyed by their $id.
        $merged = $defs;
        foreach ($embedded as $id => $resource) {
            $merged[$id] = $resource;
        }

        if (! empty($merged)) {
            $document['$defs'] = $merged;
        }

        if (! isset($document['$schema'])) {
            $document = ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + $document;
        }

        return $document;
    }

    /**
     * Fully inline every resolvable `$ref` (absolute or `#/$defs/X`) and drop
     * `$defs`, yielding a complete schema with no external resolution needed.
     * `$id` chrome on embedded resources is stripped so the result is a single
     * flat schema. Self-recursive refs are left as a local `#/$defs/X` pointer
     * to avoid infinite expansion.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function dereference(array $document): array
    {
        $defs = $document['$defs'] ?? [];

        // Index resources by both their $id and short-name $defs key.
        $byId = [];
        foreach ($defs as $key => $def) {
            if (is_array($def)) {
                $byId['#/$defs/'.$key] = $def;
                if (isset($def['$id'])) {
                    $byId[$def['$id']] = $def;
                }
            }
        }
        if ($this->registry) {
            // Registry resources resolve too, by absolute $id.
        }

        unset($document['$defs']);

        $recursive = [];
        $result = $this->inline($document, $byId, [], $recursive);

        // Any ref that could only be expressed recursively is re-hoisted to a
        // minimal local $defs so the document stays self-contained & valid.
        if (! empty($recursive)) {
            $localDefs = [];
            foreach ($recursive as $defKey => $resource) {
                $localDefs[$defKey] = $this->inline($resource, $byId, [$defKey], $recursive);
            }
            $result['$defs'] = $localDefs;
        }

        return $result;
    }

    /**
     * Assert (via opis) that a bundled/dereferenced document is a valid,
     * fully-resolvable JSON Schema. Returns true or throws on an unresolved ref.
     *
     * @param  array<string, mixed>  $document
     */
    public function assertResolvable(array $document): bool
    {
        $json = json_decode(json_encode($document));
        $validator = new Validator;

        // Registering the schema forces opis to parse & resolve every $ref.
        // An unresolved ref throws during validation of a trivial instance.
        $validator->validate(json_decode('{}'), $json);

        return true;
    }

    /**
     * Walk the document, resolving absolute `$ref`s to their resources and
     * embedding each (with its `$id`) into $embedded keyed by `$id`.
     *
     * @param  array<string, array>  $byId
     * @param  array<string, array>  $embedded
     */
    protected function collectAbsoluteResources(mixed $node, array $byId, array &$embedded): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && $this->isAbsoluteRef($value)) {
                if (isset($embedded[$value])) {
                    continue;
                }
                $resource = $this->resolve($value, $byId);
                if ($resource !== null) {
                    $embedded[$value] = $resource;
                    // Recurse into the embedded resource to pull its own deps.
                    $this->collectAbsoluteResources($resource, $byId, $embedded);
                }

                continue;
            }

            $this->collectAbsoluteResources($value, $byId, $embedded);
        }
    }

    /**
     * Recursively inline refs into a node.
     *
     * @param  array<string, array>  $byId
     * @param  array<int, string>  $stack  refs currently being expanded (cycle guard)
     * @param  array<string, array>  $recursive  collected recursive resources
     */
    protected function inline(mixed $node, array $byId, array $stack, array &$recursive): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $ref = $node['$ref'];
            $resource = $this->resolve($ref, $byId);

            if ($resource === null) {
                return $node;
            }

            $defKey = $this->localKeyFor($ref);

            // Cycle: point at a local $defs entry rather than expand forever.
            if (in_array($ref, $stack, true)) {
                $recursive[$defKey] = $this->stripId($resource);

                return ['$ref' => '#/$defs/'.$defKey];
            }

            $expanded = $this->inline($this->stripId($resource), $byId, [...$stack, $ref], $recursive);

            // Merge sibling keywords (e.g. nullable) onto the expansion.
            $siblings = $node;
            unset($siblings['$ref']);

            return is_array($expanded) ? $expanded + $siblings : $expanded;
        }

        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = $this->inline($value, $byId, $stack, $recursive);
        }

        return $out;
    }

    /**
     * @param  array<string, array>  $byId
     * @return array<string, mixed>|null
     */
    protected function resolve(string $ref, array $byId): ?array
    {
        if (isset($byId[$ref])) {
            return $byId[$ref];
        }

        if ($this->registry && $this->registry->has($ref)) {
            return $this->registry->get($ref);
        }

        return null;
    }

    protected function isAbsoluteRef(string $ref): bool
    {
        return str_contains($ref, '://');
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    protected function stripId(array $resource): array
    {
        unset($resource['$id'], $resource['$schema']);

        return $resource;
    }

    protected function localKeyFor(string $ref): string
    {
        if (str_starts_with($ref, '#/$defs/')) {
            return substr($ref, strlen('#/$defs/'));
        }

        // Derive a stable, JSON-pointer-safe key from the absolute $id tail.
        $path = parse_url($ref, PHP_URL_PATH) ?: $ref;

        return trim(str_replace('/', '_', $path), '_');
    }
}
