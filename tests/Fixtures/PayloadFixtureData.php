<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Fixtures\HasFixtures;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class PayloadFixtureData extends Data
{
    use HasFixtures;

    public function __construct(#[Required] public string $name, public string $slug = '') {}

    protected static function fixtureKey(): string
    {
        return 'payloads';
    }
}
