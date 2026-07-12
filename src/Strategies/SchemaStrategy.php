<?php

namespace Schemastud\DataSchemas\Strategies;

use ReflectionProperty;

/**
 * A property-schema strategy: given a reflected property and the schema built so
 * far, it returns the schema (optionally augmented with extra keywords). The
 * generator runs the configured strategies in order, each handed the previous
 * one's result — the Scribe pattern. This generalizes the former per-attribute
 * `validation_mapping` callable seam: built-in mappers (e.g. validation) and
 * downstream packages (e.g. content-engine's generation attributes -> `x-*`)
 * are registered uniformly in `config('data-schemas.strategies')`.
 *
 * Strategies must not require a booted Laravel container: the generator is
 * constructed bare in unit tests. Consult config through the passed context.
 */
interface SchemaStrategy
{
    /**
     * @param  array<string, mixed>  $schema  the property schema built so far
     * @return array<string, mixed> the (possibly augmented) property schema
     */
    public function apply(ReflectionProperty $property, array $schema, SchemaStrategyContext $context): array;
}
