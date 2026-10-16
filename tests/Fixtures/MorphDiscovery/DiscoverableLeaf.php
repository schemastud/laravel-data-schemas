<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DiscoverableLeaf extends DiscoverableNode
{
    public function __construct(public string $value, string $kind = 'leaf')
    {
        parent::__construct($kind);
    }
}
