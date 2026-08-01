<?php

namespace Schemastud\DataSchemas\Migration\Source;

/**
 * A schemastud-LOCAL sentinel describing "the bottom rung is a FOREIGN source
 * shape", not a prior schema version. It lets the {@see MigrationLadder} be
 * entered from an arbitrary foreign payload against a target schema `$id` without
 * a versioned `$from` schema — projection is "migration whose source is a foreign
 * shape".
 *
 * This is expressed entirely in schemastud's own terms: the from-descriptor is a
 * minimal anonymous schema carrying a sentinel `$id` and no declared properties,
 * so `SchemaDiff::between()` reports every
 * target field as ADDED — which is exactly what makes the structural rung fill the
 * required-fields floor for free after projection.
 *
 * IMPORTANT: no `Splicewire\*` type is referenced here. The open foundation must
 * not depend up on the paid engine (topology rule R1); a beam-side
 * `ParticleSource`→entry adapter lives on the beam side and maps its own source
 * kind onto this sentinel.
 */
class ForeignSource
{
    /**
     * The sentinel `$id` marking an anonymous foreign-source origin. It is a URN
     * in schemastud's own namespace so it can never collide with a real versioned
     * schema `$id` (`<base>/<name>/<version>`).
     */
    public const Id = 'urn:schemastud:x-source:foreign';

    /**
     * The from-descriptor a foreign-source ladder entry uses: an empty-properties
     * schema whose `$id` is the {@see Id} sentinel. Diffing the target against it
     * marks every target field as added (the structural floor).
     *
     * @return array{'$id': string, type: string, properties: array<string, mixed>}
     */
    public static function descriptor(): array
    {
        return [
            '$id' => self::Id,
            'type' => 'object',
            'properties' => [],
        ];
    }

    /**
     * Whether a from-descriptor is the foreign-source sentinel — the signal a rung
     * uses to decide it is projecting from a foreign shape rather than migrating a
     * prior version.
     *
     * @param  array<string, mixed>  $from
     */
    public static function isForeign(array $from): bool
    {
        return ($from['$id'] ?? null) === self::Id;
    }
}
