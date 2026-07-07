<?php

namespace Rushing\LaravelDataSchemas\Strategies;

use ReflectionProperty;
use Rushing\LaravelDataSchemas\Attributes\MigrateWith;
use Rushing\LaravelDataSchemas\Attributes\WasNamed;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Rushing\LaravelDataSchemas\Keywords;
use Rushing\LaravelDataSchemas\Migration\MigrationLadder;

/**
 * Projects the data-schemas migration vocabulary onto a property schema, mirroring
 * how generation attributes project to `x-*` keywords:
 *
 *  - #[WasNamed('oldKey')]            -> `x-migrate-from: oldKey`
 *  - #[MigrateWith(Transform::class)] -> `x-migrate: Transform::class`
 *  - #[MigrateWith('llm')]            -> `x-migrate: llm`
 *
 * These keywords carry the rename/move and custom-transform pins that the
 * deterministic migration {@see MigrationLadder}
 * rungs consume off the TARGET schema.
 *
 * VERSIONED-ONLY: migration metadata is only emitted for classes that opt into
 * versioning ({@see SchemaIdentity}). For every other class this strategy is a
 * strict no-op, so registering it in the default strategy set never changes
 * non-migration schema output. Like every `x-*` keyword it is also stripped by
 * `forLlmStrict`.
 */
class MigrationAttributesStrategy implements SchemaStrategy
{
    public function apply(ReflectionProperty $property, array $schema, SchemaStrategyContext $context): array
    {
        if (! $this->isVersioned($property)) {
            return $schema;
        }

        $wasNamed = $property->getAttributes(WasNamed::class);
        if (! empty($wasNamed)) {
            $schema[Keywords::MigrateFrom] = $wasNamed[0]->newInstance()->from;
        }

        $migrateWith = $property->getAttributes(MigrateWith::class);
        if (! empty($migrateWith)) {
            $schema[Keywords::Migrate] = $migrateWith[0]->newInstance()->with;
        }

        return $schema;
    }

    /**
     * Whether the property's declaring class opts into versioning. Migration
     * metadata is meaningless without a stable, versioned `$id` to diff against.
     */
    protected function isVersioned(ReflectionProperty $property): bool
    {
        return $property->getDeclaringClass()->implementsInterface(SchemaIdentity::class);
    }
}
