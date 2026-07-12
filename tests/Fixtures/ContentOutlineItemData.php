<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class ContentOutlineItemData extends Data
{
    public function __construct(
        public string $title,
        /** @var ContentOutlineItemData[] */
        #[DataCollectionOf(ContentOutlineItemData::class)]
        public array $children,
    ) {}
}
