<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use ReflectionClass;
use Schemastud\DataSchemas\Sources\SchemaSource;

/**
 * A contributed source standing in for the one beam will ship — a universe this package has no
 * paths for. `$classNames` is public and mutable on purpose: it is what lets a test prove the
 * source is asked at READ time rather than at registration time.
 */
class StubSchemaSource implements SchemaSource
{
    /** @param list<class-string> $classNames */
    public function __construct(public array $classNames = []) {}

    public function classes(): array
    {
        return array_map(fn (string $class) => new ReflectionClass($class), $this->classNames);
    }
}
