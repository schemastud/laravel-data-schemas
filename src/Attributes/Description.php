<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class Description
{
    public function __construct(public string $value) {}
}
