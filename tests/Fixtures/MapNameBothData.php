<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * A single `#[MapName('max_depth')]` sets BOTH the input and the output mapped name to the
 * same string. Under the owner ruling every mode keys by spatie's resolved name, so request
 * (input), response/collapsed/llm_strict (output) must all publish `max_depth` — there is no
 * axis on which the PHP name `maxDepth` survives.
 */
class MapNameBothData extends Data
{
    public function __construct(
        #[MapName('max_depth')]
        public int $maxDepth,
    ) {}
}
