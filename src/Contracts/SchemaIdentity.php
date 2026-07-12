<?php

namespace Schemastud\DataSchemas\Contracts;

use Spatie\LaravelData\Data;

/**
 * Opt-in marker for schema lifecycle (slice 12).
 *
 * A {@see Data} class that implements this interface opts
 * into versioned, absolutely-addressable schemas:
 *
 *  - the generator emits a real absolute versioned `$id`
 *    (`<base>/<schemaName>/<schemaVersion>`), carrying the version natively in
 *    `$id` rather than a bespoke attribute or the `$schema` dialect field;
 *  - when such a class is nested inside another schema it projects its OWN
 *    absolute versioned `$id` and is referenced via `$ref: <absolute-$id>`
 *    (a JSON Schema 2020-12 bundled resource) instead of `#/$defs/Short`.
 *
 * A class that does NOT implement this interface is completely unchanged:
 * short-name `$id`, `#/$defs/Short` inlining for nested Data classes. This is
 * the backward-compatibility seam — versioning is strictly opt-in.
 */
interface SchemaIdentity
{
    /**
     * The stable, path-style schema name (e.g. `content/article`). Combined
     * with the configured base URI and version to form the absolute `$id`.
     */
    public static function schemaName(): string;

    /**
     * The author-declared, orderable schema version. Monotonically increasing
     * integers keep `$id`s sortable and comparable for drift/diff tooling.
     */
    public static function schemaVersion(): int;
}
