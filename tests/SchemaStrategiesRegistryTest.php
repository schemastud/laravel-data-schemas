<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Strategies\KeywordAttributesStrategy;
use Schemastud\DataSchemas\Strategies\MigrationAttributesStrategy;
use Schemastud\DataSchemas\Strategies\SchemaStrategiesRegistry;
use Schemastud\DataSchemas\Strategies\ValidationAttributeStrategy;

/**
 * The strategy pipeline was a real five-registrant cross-vendor registry with no class, and so no
 * declaration the index or the surgeon gate could read (registry-kernel ticket 25). These pin that it
 * now has one, and that acquiring it changed nothing about the config key the five registrants write to.
 */
class SchemaStrategiesRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataSchemasServiceProvider::class];
    }

    public function test_it_is_bound_as_a_singleton_by_this_package(): void
    {
        $this->assertSame(
            app(SchemaStrategiesRegistry::class),
            app(SchemaStrategiesRegistry::class),
        );
    }

    public function test_it_declares_itself_so_the_gate_can_read_it(): void
    {
        $declaration = IsRegistry::of(SchemaStrategiesRegistry::class);

        $this->assertNotNull($declaration);
        $this->assertSame('schemas.strategies', $declaration->root);
        $this->assertSame(RegistryArity::RunAll, $declaration->arity);
    }

    public function test_it_reads_the_shipped_pipeline_in_order_off_the_real_config_key(): void
    {
        $registry = app(SchemaStrategiesRegistry::class);

        $this->assertSame(
            config('data-schemas.strategies'),
            $registry->matches('schemas.strategies'),
        );

        $this->assertSame(
            ValidationAttributeStrategy::class,
            $registry->resolve('validation-attribute-strategy'),
        );
    }

    public function test_each_shipped_strategy_addresses_under_the_declared_root(): void
    {
        $keys = array_map(strval(...), app(SchemaStrategiesRegistry::class)->keys());

        $this->assertSame([
            'schemas.strategies.validation-attribute-strategy',
            'schemas.strategies.migration-attributes-strategy',
            'schemas.strategies.keyword-attributes-strategy',
        ], $keys);
    }

    public function test_registering_leaves_the_config_key_a_plain_list_for_its_existing_consumers(): void
    {
        app(SchemaStrategiesRegistry::class)
            ->register('widget-attributes-strategy', 'Schemastud\Frame\Strategies\WidgetAttributesStrategy');

        $strategies = config('data-schemas.strategies');

        // Every consumer — JsonSchemaGenerator, the four appending providers' `in_array` guards — reads
        // this as an ordered list of class-strings, and the adapter must not have changed that.
        $this->assertTrue(array_is_list($strategies));
        $this->assertSame([
            ValidationAttributeStrategy::class,
            MigrationAttributesStrategy::class,
            KeywordAttributesStrategy::class,
            'Schemastud\Frame\Strategies\WidgetAttributesStrategy',
        ], $strategies);
    }
}
