<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * No per-property or per-class mapping attribute at all — the only thing that can move these
 * names is the host's global `data.name_mapping_strategy`. The flagship sets that strategy to
 * input => CamelCaseMapper, output => null, so a snake_case property like `max_depth` is keyed
 * `maxDepth` on the REQUEST (input) axis while response/collapsed keep `max_depth` (no output
 * mapper). This is the estate case the census calls out: snake properties without MapName whose
 * request schema moves purely because of the global input mapper.
 */
class GlobalStrategyData extends Data
{
    public function __construct(
        public int $max_depth,
        public ?string $source_url = null,
    ) {}
}
