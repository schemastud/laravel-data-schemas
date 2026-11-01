<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * No per-property or per-class mapping attribute — camelCase properties whose ONLY mover is a
 * global OUTPUT strategy (`data.name_mapping_strategy.output`). With output => SnakeCaseMapper the
 * stored/collapsed and response keys must become snake (what toArray() emits), while the request
 * axis (input => null here) keeps the PHP name. This is the case the parent generator got wrong:
 * it kept the PHP name on collapsed regardless of the output strategy.
 */
class GlobalOutputStrategyData extends Data
{
    public function __construct(
        public int $maxDepth,
        public string $sourceUrl,
    ) {}
}
