<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

/** Stands in for a tier below (beam) overriding ONE method to key by a declared resource name. */
class ResourceKeyedShapeData extends FixtureShapeData
{
    protected static function fixtureKey(): string
    {
        return 'plans';
    }
}
