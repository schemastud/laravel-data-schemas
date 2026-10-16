<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DiscoverableBranch extends DiscoverableNode
{
    public function __construct(public ?DiscoverableNode $child, string $kind = 'branch')
    {
        parent::__construct($kind);
    }
}
