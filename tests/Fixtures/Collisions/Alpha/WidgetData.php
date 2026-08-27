<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\Collisions\Alpha;

use Spatie\LaravelData\Data;

/**
 * Half of a deliberate short-name collision — see the Beta sibling. Under
 * `path_structure: 'flat'` both resolve to `WidgetData.schema.json`.
 */
class WidgetData extends Data
{
    public function __construct(
        public string $id,
    ) {}
}
