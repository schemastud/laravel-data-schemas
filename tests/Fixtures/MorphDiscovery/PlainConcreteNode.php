<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PlainConcreteNode extends PlainAbstractNode
{
    public function __construct(public string $label, public int $size = 0)
    {
        parent::__construct($label);
    }
}
