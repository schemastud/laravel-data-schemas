<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;

/**
 * A map, a list and a nullable map side by side — PHP types all three `array`, which is
 * exactly why the declaration has to carry the difference.
 */
class StringMapData extends Data
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $tags
     * @param  array<string, SampleData>|null  $records
     * @param  array<string, StatusEnum>  $statuses
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        #[MapValues('string')]
        public array $headers,

        #[ArrayItems('string')]
        public array $tags,

        #[MapValues(SampleData::class)]
        public ?array $records,

        #[MapValues(StatusEnum::class)]
        public array $statuses,

        // The 89% case: an unconstrained bag. The map-ness is declarable, the value type is not.
        #[MapValues]
        public array $meta,
    ) {}
}
