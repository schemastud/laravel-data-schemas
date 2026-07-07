<?php

namespace Rushing\LaravelDataSchemas;

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
 *  - `x-migrate-from` / `x-migrate` — the migration-ladder rename/transform pins
 *
 * Both the emit sites (generator, migration strategy) and the read sites (migration
 * rungs) reference these constants, so a keyword name lives in exactly one place.
 */
class Keywords
{
    public const Lazy = 'x-lazy';

    public const Optional = 'x-optional';

    public const MigrateFrom = 'x-migrate-from';

    public const Migrate = 'x-migrate';

    /**
     * Every `x-` keyword this package owns / emits.
     *
     * @return list<string>
     */
    public static function owned(): array
    {
        return [self::Lazy, self::Optional, self::MigrateFrom, self::Migrate];
    }
}
