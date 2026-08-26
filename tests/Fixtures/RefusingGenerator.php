<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;

/** Accepts nothing — stands in for a narrow generator like blockdoc's, which only takes Blocks. */
class RefusingGenerator implements Generator
{
    public function canGenerate(ReflectionClass $class): bool
    {
        return false;
    }

    public function generate(ReflectionClass $class): array
    {
        return ['refused' => true];
    }

    public function forRequest(): static
    {
        return $this;
    }

    public function forResponse(): static
    {
        return $this;
    }

    public function forLlmStrict(): static
    {
        return $this;
    }

    public function schemaMode(string $mode): static
    {
        return $this;
    }
}
