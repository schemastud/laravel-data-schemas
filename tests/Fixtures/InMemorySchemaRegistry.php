<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/**
 * An array-backed {@see SchemaRegistry} for tests that care about what was handed
 * to a registry, not about how a registry stores it.
 */
class InMemorySchemaRegistry implements SchemaRegistry
{
    /** @var array<string, array<string, mixed>> */
    public array $entries = [];

    public function register(array $schema): void
    {
        $id = $schema['$id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('Cannot register a schema without an $id.');
        }

        $this->entries[$id] = $schema;
    }

    public function get(string $id): ?array
    {
        return $this->entries[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }

    public function ids(): array
    {
        return array_keys($this->entries);
    }
}
