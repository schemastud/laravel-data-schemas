<?php

namespace Schemastud\DataSchemas\Collectors;

use ReflectionClass;

abstract class Collector
{
    public function __construct(protected array $config) {}

    abstract public function canCollect(ReflectionClass $class): bool;
}
