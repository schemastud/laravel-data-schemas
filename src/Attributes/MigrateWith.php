<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

/**
 * Pins a CUSTOM migration transform for a property, for shape changes neither a
 * structural diff nor a declared rename can express (a split/merge, a unit
 * conversion, a reformat).
 *
 * Two forms, both projected by {@see MigrationAttributesStrategy} onto the
 * property schema as the `x-migrate` keyword:
 *
 *  - a transform class-string — the author-registered popcorn `Invocable` keyed
 *    `from->to` ($id pair) that the custom-transform migration rung resolves and
 *    runs;
 *  - the literal string `'llm'` — an opt-in marking that this field is a candidate
 *    for the host-bound LLM-try rung (a seam only; data-schemas never depends on
 *    an LLM SDK).
 *
 * Migration metadata only rides VERSIONED schemas; the strategy is a no-op
 * otherwise, so non-migration schema output is unchanged.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MigrateWith
{
    /**
     * The opt-in string requesting the host-bound LLM migration rung.
     */
    public const LLM = 'llm';

    /**
     * @param  class-string|string  $with  a Transform/Invocable class-string, or the literal `'llm'`
     */
    public function __construct(public string $with) {}
}
