<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Concerns\DerivesJsonSchema;
use Schemastud\DataSchemas\Contracts\ProvidesJsonSchema;
use Spatie\LaravelData\Data;

/**
 * Stands in for the six abstract Data bases already in this family — a class whose `extends` slot is
 * spoken for. It reaches the same behaviour by composition.
 */
class TraitOnlyData extends Data implements ProvidesJsonSchema
{
    use DerivesJsonSchema;

    public function __construct(public string $title) {}
}
