<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;

/**
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Example
{
    public function __construct(public mixed $value) {}
}
