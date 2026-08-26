<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;

/** A host's own generator, proving the container binding is swappable. */
class StubGenerator implements Generator
{
    public function canGenerate(ReflectionClass $class): bool
    {
        return true;
    }

    public function generate(ReflectionClass $class): array
    {
        return ['stub' => true];
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
