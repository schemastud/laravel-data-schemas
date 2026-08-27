<?php

namespace Schemastud\DataSchemas\Support;

/**
 * One generated schema document and the class it was projected from.
 *
 * Deliberately carries NO destination. A generated schema is not a file: this
 * package projects the same generator into an in-memory registry, into
 * {@see \Schemastud\DataSchemas\Lifecycle\ServedSchemaChain}, and into the
 * OpenAPI spec, none of which have a path. The disk-bound case is the SUBSET,
 * and it is named — {@see WrittenSchema}.
 */
class GeneratedSchema
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $className,
        public array $schema,
    ) {}

    public function getPropertyCount(): int
    {
        return count($this->schema['properties'] ?? []);
    }
}
