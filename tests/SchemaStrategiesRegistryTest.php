<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryIndex;
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
        return [
            // laravel-popcorn binds RegistryIndex as a SINGLETON. Without it the index is
            // auto-resolvable but UNSHARED, so this package's describe() lands on a throwaway and
            // every membership assertion below would pass against an index nobody else can see.
            // 27 D3 found this in laravel-beam's harness and 43 found it again in tower's; this is
            // the third instance, and the pattern is that requiring laravel-popcorn does not fix it
            // — testbench does not auto-discover.
            PopcornServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
        ];
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
    }

    /**
     * DECLARING and INDEXING are two acts (21 D1). Ticket 25 landed the declaration; ticket 37 lands
     * this — the act that actually makes `schemas.strategies` reachable through the index.
     */
    public function test_it_is_described_into_the_shared_index(): void
    {
        $this->assertSame(app(RegistryIndex::class), app(RegistryIndex::class));

        $keys = array_map(strval(...), app(RegistryIndex::class)->keys());

        $this->assertContains('schemas.strategies', $keys);
    }

    public function test_the_index_routes_a_strategy_key_back_to_the_registry(): void
    {
        $key = (string) app(SchemaStrategiesRegistry::class)->keys()[0];

        $this->assertSame(
            app(SchemaStrategiesRegistry::class),
            app(RegistryIndex::class)->routeTo($key),
        );
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
