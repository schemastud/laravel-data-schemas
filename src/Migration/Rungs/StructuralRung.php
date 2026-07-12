<?php

namespace Schemastud\DataSchemas\Migration\Rungs;

use Schemastud\DataSchemas\Lifecycle\SchemaDiff;
use Schemastud\DataSchemas\Migration\MigrationRequest;
use Schemastud\DataSchemas\Migration\MigrationRung;

/**
 * Rung 1 (strongest) — the purely structural migration a {@see SchemaDiff}
 * fully describes:
 *
 *  - ADDED field   -> set its value from the target schema's `default`, else a
 *                     type-appropriate empty value.
 *  - DROPPED field -> remove it from the payload.
 *  - WIDENED field -> keep the value as-is (it already satisfies the broader type).
 *
 * It never guesses a rename (that is the declared-mappings rung) and never invents
 * a value for an added field with no default beyond a typed empty. If the result
 * does not validate (e.g. an added required field with no default cannot be filled
 * sensibly), the acceptance gate makes the rung abstain.
 */
class StructuralRung extends MigrationRung
{
    public function name(): string
    {
        return 'structural';
    }

    protected function propose(MigrationRequest $request): ?array
    {
        $diff = $request->diff;
        $candidate = $request->payload;

        // Drop removed fields.
        foreach ($diff['dropped'] as $field) {
            unset($candidate[$field]);
        }

        // Fill added fields with their declared default, else a typed empty.
        $targetProps = $request->to['properties'] ?? [];
        foreach ($diff['added'] as $field) {
            $prop = $targetProps[$field] ?? [];
            $candidate[$field] = array_key_exists('default', $prop)
                ? $prop['default']
                : $this->emptyForType($prop);
        }

        // Widened (and unchanged) fields are kept verbatim — the broader type
        // already accepts the existing value. Drop any stray key the target no
        // longer declares so the candidate stays inside the target surface.
        $allowed = $this->targetFields($request);
        foreach (array_keys($candidate) as $key) {
            if (! in_array($key, $allowed, true)) {
                unset($candidate[$key]);
            }
        }

        return $candidate;
    }

    /**
     * A type-appropriate empty value for an added field lacking a default.
     *
     * @param  array<string, mixed>  $prop
     */
    protected function emptyForType(array $prop): mixed
    {
        $type = $prop['type'] ?? null;
        if (is_array($type)) {
            // Prefer a non-null member so a nullable-or-X field gets a concrete empty.
            $type = $type[0] === 'null' ? ($type[1] ?? 'null') : $type[0];
        }

        return match ($type) {
            'string' => '',
            'integer', 'number' => 0,
            'boolean' => false,
            'array' => [],
            'object' => (object) [],
            'null' => null,
            default => null,
        };
    }
}
