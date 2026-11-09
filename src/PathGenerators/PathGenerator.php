<?php

namespace Schemastud\DataSchemas\PathGenerators;

use ReflectionClass;

/**
 * @api
 */
interface PathGenerator
{
    public function getSchemaPath(ReflectionClass $class): string;
}
