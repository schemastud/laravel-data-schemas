<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\Example;
use Schemastud\DataSchemas\Fixtures\HasFixtures;
use Schemastud\DataSchemas\StudData;

/** Keyed by `ClassKey` — it has no shorter declared name, which is what the fallback is for. */
class FixtureShapeData extends StudData
{
    use HasFixtures;

    public function __construct(
        #[Example('Example Name')]
        public string $name = '',
        public ?float $limit = null,
    ) {}

    /** Test seam: `fixtureKey()` is protected, as a tier-override point should be. */
    public static function exposedFixtureKey(): string
    {
        return static::fixtureKey();
    }
}
