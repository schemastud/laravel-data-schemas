<?php

namespace Schemastud\DataSchemas\PathGenerators;

use ReflectionClass;

interface PathGenerator
{
    public function getSchemaPath(ReflectionClass $class): string;
}
