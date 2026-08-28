<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * A stand-in for the estate's four object defaults (all `Splicewire\Knowledge\Compliance\SourceRef`).
 * Exists only so {@see DeclaredDefaultsData::$anchor} can declare `= new DefaultAnchorData(...)`.
 */
class DefaultAnchorData extends Data
{
    public function __construct(
        public string $uri = 'about:blank',
        public int $line = 1,
    ) {}
}
