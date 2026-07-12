<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Spatie\LaravelData\Data;

class EnumArrayData extends Data
{
    /**
     * @param  list<string>  $statuses  A list of enum-valued scalars.
     */
    public function __construct(
        #[ArrayItems(StatusEnum::class)]
        public array $statuses,
    ) {}
}
