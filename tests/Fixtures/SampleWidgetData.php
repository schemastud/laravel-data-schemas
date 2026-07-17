<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

class SampleWidgetData extends Data
{
    public function __construct(
        public string $label,
        public string $widget,
    ) {}
}
