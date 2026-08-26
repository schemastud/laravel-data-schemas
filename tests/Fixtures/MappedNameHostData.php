<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * A nested Data property, so the projection is checked one level down as well.
 *
 * `ensureDef()` recurses with the generator's own mode, so a nested `$defs` entry must
 * carry the same wire names as the root — otherwise every non-trivial request body keeps
 * the defect at depth 1 while looking fixed at depth 0.
 */
class MappedNameHostData extends Data
{
    public function __construct(
        public MappedNameData $payload,
    ) {}
}
