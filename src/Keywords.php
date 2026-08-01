<?php

namespace Schemastud\DataSchemas;

/**
 * The JSON-Schema extension keywords THIS package owns.
 *
 * Ownership doctrine (the JSON-LD `@context` model): the base leaf
 * (`rushing/laravel-json-reference`) owns the small cross-engine set (`@id`,
 * `x-dereference`); every other package owns and guards its OWN keywords locally.
 * There is no central keyword list to curate — a keyword is legitimate because some
 * package declares it here, and drift is caught by each package asserting what it
 * emits stays within `base ∪ own` (see the KeywordOwnership test).
 *
 * These are unprefixed because they are substrate-level projections of the schema
 * generator itself, not engine-private (engine-private keywords take `x-{prefix}-*`):
 *  - `x-lazy` / `x-optional`  — Spatie Lazy/Optional property projections
 *  - `x-hidden` — a property dropped from the emitted schema entirely (never reaches
 *    the client) while staying on the Data class for server-side binding: a
 *    mode-independent peer of the `#[Computed]` skip. Recognized by the generator
 *    regardless of which owner's constant supplied the string.
 *  - `x-migrate-from` / `x-migrate` — the migration-ladder rename/transform pins
 *  - `x-source` — the projection dialect ({@see Migration\Source\SourceProjectionRung}):
 *    `{ path, cast?, default? }` extracts + coerces a nested foreign-source value into
 *    this property when the ladder is entered from a FOREIGN shape. Declarative,
 *    execution-free (data-not-code) — the bounded sibling of `x-migrate-from`.
 *
 * Both the emit sites (generator, migration strategy) and the read sites (migration
 * rungs) reference these constants, so a keyword name lives in exactly one place.
 */
class Keywords
{
    public const Lazy = 'x-lazy';

    public const Optional = 'x-optional';

    public const Hidden = 'x-hidden';

    public const MigrateFrom = 'x-migrate-from';

    public const Migrate = 'x-migrate';

    public const Source = 'x-source';

    /**
     * Every `x-` keyword this package owns / emits.
     *
     * @return list<string>
     */
    public static function owned(): array
    {
        return [self::Lazy, self::Optional, self::Hidden, self::MigrateFrom, self::Migrate, self::Source];
    }
}
