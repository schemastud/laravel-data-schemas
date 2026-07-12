<?php

namespace Schemastud\DataSchemas\Migration\Rungs;

use Schemastud\DataSchemas\Keywords;
use Schemastud\DataSchemas\Migration\MigrationRequest;
use Schemastud\DataSchemas\Migration\MigrationRung;

/**
 * Rung 2 — applies the author-declared renames/moves a structural diff cannot
 * infer. The target schema carries `x-migrate-from: oldKey` on a property (from
 * #[WasNamed]); this rung copies the OLD key's value into the new key, then drops
 * the now-consumed old key.
 *
 * It first applies the same structural reconciliation (drop/add/widen) as rung 1
 * so a schema version that BOTH renames a field AND adds/drops others migrates in
 * one pass — the rename is the increment this rung adds over pure structure.
 */
class DeclaredMappingRung extends MigrationRung
{
    public function name(): string
    {
        return 'declared-mapping';
    }

    protected function propose(MigrationRequest $request): ?array
    {
        $renames = $this->renames($request);

        // Nothing declared — abstain so the ladder need not consider this rung.
        if (empty($renames)) {
            return null;
        }

        $candidate = $request->payload;

        // Apply renames: new key takes the old key's value; drop the old key.
        foreach ($renames as $newKey => $oldKey) {
            if (array_key_exists($oldKey, $candidate)) {
                $candidate[$newKey] = $candidate[$oldKey];
                unset($candidate[$oldKey]);
            }
        }

        // Reconcile remaining structure (added defaults, dropped removals).
        $targetProps = $request->to['properties'] ?? [];
        foreach ($request->diff['dropped'] as $field) {
            // A dropped field that was the source of a rename is already consumed.
            if (in_array($field, $renames, true)) {
                continue;
            }
            unset($candidate[$field]);
        }
        foreach ($request->diff['added'] as $field) {
            // An added field satisfied by a rename keeps its mapped value.
            if (array_key_exists($field, $renames)) {
                continue;
            }
            $prop = $targetProps[$field] ?? [];
            $candidate[$field] = array_key_exists('default', $prop)
                ? $prop['default']
                : $this->emptyForType($prop);
        }

        // Keep the candidate within the target surface.
        $allowed = $this->targetFields($request);
        foreach (array_keys($candidate) as $key) {
            if (! in_array($key, $allowed, true)) {
                unset($candidate[$key]);
            }
        }

        return $candidate;
    }

    /**
     * The declared renames off the target schema, as newKey => oldKey.
     *
     * @return array<string, string>
     */
    protected function renames(MigrationRequest $request): array
    {
        $renames = [];
        foreach ($request->to['properties'] ?? [] as $field => $prop) {
            $from = $prop[Keywords::MigrateFrom] ?? null;
            if (is_string($from) && $from !== '') {
                $renames[$field] = $from;
            }
        }

        return $renames;
    }

    /**
     * @param  array<string, mixed>  $prop
     */
    protected function emptyForType(array $prop): mixed
    {
        $type = $prop['type'] ?? null;
        if (is_array($type)) {
            $type = $type[0] === 'null' ? ($type[1] ?? 'null') : $type[0];
        }

        return match ($type) {
            'string' => '',
            'integer', 'number' => 0,
            'boolean' => false,
            'array' => [],
            'object' => (object) [],
            default => null,
        };
    }
}
