<?php

namespace Schemastud\DataSchemas\Migration\Rungs;

use Schemastud\DataSchemas\Keywords;
use Schemastud\DataSchemas\Migration\MigrationRequest;
use Schemastud\DataSchemas\Migration\MigrationRung;
use Schemastud\DataSchemas\Migration\Source\ForeignSource;
use Schemastud\DataSchemas\Migration\Source\SourceCast;
use Schemastud\DataSchemas\Migration\Source\SourcePath;

/**
 * The FOREIGN-source bottom rung — the declared-mapping rung's projection sibling.
 *
 * When the ladder is entered from a foreign shape (the `$from` descriptor is the
 * {@see ForeignSource} sentinel), this rung reads the `x-source` annotation off
 * each target property and extracts + coerces a value from a nested path into the
 * foreign payload:
 *
 *   x-source: { path: "author.name", cast?: "trim", default?: "anon" }
 *
 * It is DATA-not-code (pure array→array extraction + a fixed cast vocabulary) — no
 * expression language, no sandbox. Anything path+cast cannot express carries NO
 * `x-source` and falls through to the custom-transform escape hatch.
 *
 * After projecting the annotated fields, it REUSES the structural floor: any target
 * field WITHOUT an `x-source` (an `added` field, since diffing against the empty
 * foreign descriptor marks every target field added) is filled from its schema
 * `default`, else a typed empty — identical to {@see StructuralRung}. The base
 * rung's uniform acceptance gate then validates: a missing-required field left
 * empty by projection fails the gate, this rung ABSTAINS, and (with no annotated
 * value the custom rung can salvage) the ladder QUARANTINES. Never a silent pass.
 */
class SourceProjectionRung extends MigrationRung
{
    public function name(): string
    {
        return 'source-projection';
    }

    protected function propose(MigrationRequest $request): ?array
    {
        // Only fires on a foreign-source entry; a version-to-version migration is
        // none of this rung's business (it abstains, ladder uses the others).
        if (! ForeignSource::isForeign($request->from)) {
            return null;
        }

        $targetProps = $request->to['properties'] ?? [];
        $sources = $this->sources($request);

        // A foreign entry with no `x-source` anywhere has nothing declarative to
        // project — abstain so the custom-transform rung owns the whole shape.
        if (empty($sources)) {
            return null;
        }

        $candidate = [];

        foreach ($targetProps as $field => $prop) {
            if (array_key_exists($field, $sources)) {
                $candidate[$field] = $this->project($sources[$field], $request->payload);

                continue;
            }

            // Structural floor for every non-projected field (all are "added"
            // relative to the empty foreign descriptor): declared default else a
            // typed empty — reusing the exact StructuralRung semantics.
            $candidate[$field] = array_key_exists('default', $prop)
                ? $prop['default']
                : $this->emptyForType($prop);
        }

        return $candidate;
    }

    /**
     * The `x-source` annotations off the target schema, as field => spec. An
     * annotation naming a cast outside the fixed vocabulary is DROPPED here (the
     * field is treated as unprojectable and left to the structural floor / custom
     * rung), so an out-of-grammar cast never silently mis-coerces.
     *
     * @return array<string, array{path: string, cast?: string, default?: mixed}>
     */
    protected function sources(MigrationRequest $request): array
    {
        $sources = [];
        foreach ($request->to['properties'] ?? [] as $field => $prop) {
            $spec = $prop[Keywords::Source] ?? null;
            if (! is_array($spec) || ! array_key_exists('path', $spec) || ! is_string($spec['path'])) {
                continue;
            }
            if (array_key_exists('cast', $spec)) {
                if (! is_string($spec['cast']) || ! SourceCast::knows($spec['cast'])) {
                    continue; // out-of-grammar cast -> not a valid x-source declaration
                }
            }
            $sources[$field] = $spec;
        }

        return $sources;
    }

    /**
     * Extract + coerce ONE annotated field from the foreign payload.
     *
     * @param  array{path: string, cast?: string, default?: mixed}  $spec
     */
    protected function project(array $spec, mixed $foreign): mixed
    {
        $value = SourcePath::extract($foreign, $spec['path']);

        // Absent path (or a present-but-null value) falls back to the declared
        // default when one is given; otherwise the extracted value stands.
        if ($value === SourcePath::MISSING || $value === null) {
            if (array_key_exists('default', $spec)) {
                return $spec['default'];
            }

            return $value === SourcePath::MISSING ? null : null;
        }

        if (isset($spec['cast'])) {
            return SourceCast::apply($spec['cast'], $value);
        }

        return $value;
    }

    /**
     * A type-appropriate empty value for a target field lacking a default — the
     * same mapping the structural rung uses, kept in sync deliberately.
     *
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
            'null' => null,
            default => null,
        };
    }
}
