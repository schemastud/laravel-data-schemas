<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A class-level `#[MapName(SnakeCaseMapper::class)]` resolves BOTH axes through the mapper, so
 * every camel property answers to its snake_case name in and out. Under the owner ruling the
 * generator keys on spatie's resolved name in every mode, so `maxDepth`/`sourceUrl` publish as
 * `max_depth`/`source_url` for request, response, collapsed and llm_strict alike.
 */
#[MapName(SnakeCaseMapper::class)]
class ClassSnakeMappedData extends Data
{
    public function __construct(
        public int $maxDepth,
        public string $sourceUrl,
    ) {}
}
