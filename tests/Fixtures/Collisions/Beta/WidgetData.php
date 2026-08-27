<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\Collisions\Beta;

use Spatie\LaravelData\Data;

/**
 * The other half of the deliberate short-name collision — see the Alpha sibling.
 * A DIFFERENT shape, so a last-write-wins overwrite is a real loss of content.
 */
class WidgetData extends Data
{
    public function __construct(
        public string $label,
        public int $weight,
    ) {}
}
