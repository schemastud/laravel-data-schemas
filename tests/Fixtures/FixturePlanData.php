<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/** A minimal Data class for the fixture-factory tests. `name` is required so `alwaysValidate()` bites. */
class FixturePlanData extends Data
{
    public function __construct(
        #[Required]
        public string $name = '',
        public string $slug = '',
        public ?float $limit = null,
    ) {}
}
