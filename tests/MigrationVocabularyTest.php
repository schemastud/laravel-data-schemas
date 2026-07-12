<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\MigrateWith;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Strategies\MigrationAttributesStrategy;
use Schemastud\DataSchemas\Strategies\ValidationAttributeStrategy;
use Schemastud\DataSchemas\Tests\Fixtures\MigratableProfileData;
use Schemastud\DataSchemas\Tests\Fixtures\StubAddressTransform;
use Schemastud\DataSchemas\Tests\Fixtures\VendorKeywordData;

class MigrationVocabularyTest extends TestCase
{
    private function generator(): JsonSchemaGenerator
    {
        return new JsonSchemaGenerator([
            'strategies' => [
                new ValidationAttributeStrategy,
                new MigrationAttributesStrategy,
            ],
        ]);
    }

    public function test_was_named_projects_to_x_migrate_from(): void
    {
        $schema = $this->generator()->generate(new ReflectionClass(MigratableProfileData::class));

        $this->assertSame('full_name', $schema['properties']['displayName']['x-migrate-from']);
    }

    public function test_migrate_with_class_projects_to_x_migrate(): void
    {
        $schema = $this->generator()->generate(new ReflectionClass(MigratableProfileData::class));

        $this->assertSame(StubAddressTransform::class, $schema['properties']['address']['x-migrate']);
    }

    public function test_migrate_with_llm_projects_the_llm_opt_in_string(): void
    {
        $schema = $this->generator()->generate(new ReflectionClass(MigratableProfileData::class));

        $this->assertSame(MigrateWith::LLM, $schema['properties']['bio']['x-migrate']);
        $this->assertSame('llm', $schema['properties']['bio']['x-migrate']);
    }

    public function test_migration_strategy_is_a_no_op_for_unversioned_classes(): void
    {
        // VendorKeywordData does NOT implement SchemaIdentity, so even with the
        // migration strategy registered, no x-migrate keywords appear: registering
        // the strategy never changes non-migration schema output.
        $schema = $this->generator()->generate(new ReflectionClass(VendorKeywordData::class));

        $json = json_encode($schema);
        $this->assertStringNotContainsString('x-migrate', $json);
    }

    public function test_for_llm_strict_strips_migration_keywords(): void
    {
        $schema = $this->generator()
            ->forLlmStrict()
            ->generate(new ReflectionClass(MigratableProfileData::class));

        $this->assertStringNotContainsString('x-migrate', json_encode($schema));
    }
}
