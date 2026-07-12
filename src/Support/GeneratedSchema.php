<?php

namespace Schemastud\DataSchemas\Support;

class GeneratedSchema
{
    public function __construct(
        public string $className,
        public string $outputPath,
        public array $schema,
    ) {}

    public function getPropertyCount(): int
    {
        return count($this->schema['properties'] ?? []);
    }
}
