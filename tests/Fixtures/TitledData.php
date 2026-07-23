<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

/**
 * Exercises a class-level #[Title] (peer of the class-level #[Description]) setting the
 * root schema title — otherwise the root title falls back to the class short name.
 */
#[Title('A Custom Title')]
#[Description('A resource whose root title comes from a class-level attribute.')]
class TitledData extends Data
{
    public function __construct(
        public ?string $name = null,
    ) {}
}
