<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Contracts\ProvidesJsonSchema;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\StudData;
use Schemastud\DataSchemas\Tests\Fixtures\StudBackedData;
use Schemastud\DataSchemas\Tests\Fixtures\TraitOnlyData;

/**
 * The short way to ask a Data class for its schema.
 *
 * Three layers on purpose, so nothing is closed (the estate's standing steer: interface/trait over
 * inheritance). The INTERFACE is what a consumer type-hints. The TRAIT is the default implementation,
 * for a class that already has a parent — six abstract Data bases in this family occupy that slot
 * (SyncData ×2, StreamingData, LineageSnapshotData, OtioData, SecretData). The BASE CLASS is only a
 * short form for the common case.
 *
 * The trait takes NO generator argument. That is the whole point: 26 call sites in this estate build
 * a generator by hand and drop the host's config, and an easy parameter is how they got there. A
 * caller who needs an explicit generator goes through the container binding, not through here.
 */
class StudDataTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    public function test_a_stud_data_subclass_provides_its_own_schema(): void
    {
        $schema = StudBackedData::jsonSchema();

        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertArrayHasKey('title', $schema['properties'] ?? []);
    }

    public function test_the_base_class_satisfies_the_contract(): void
    {
        $this->assertInstanceOf(ProvidesJsonSchema::class, new StudBackedData('x'));
        $this->assertTrue(is_subclass_of(StudData::class, \Spatie\LaravelData\Data::class));
    }

    /**
     * The case the base class cannot serve: a class that already extends something else. This is why
     * the trait is the mechanism and the base class is only sugar.
     */
    public function test_a_class_with_its_own_parent_gets_the_same_behaviour_from_the_trait(): void
    {
        $this->assertInstanceOf(ProvidesJsonSchema::class, new TraitOnlyData('x'));
        $this->assertFalse(is_subclass_of(TraitOnlyData::class, StudData::class));

        $this->assertSame(
            StudBackedData::jsonSchema()['type'] ?? null,
            TraitOnlyData::jsonSchema()['type'] ?? null,
        );
    }

    /**
     * The config the 26 bare call sites drop. If the trait ever stops going through the container
     * binding, this is the test that notices.
     */
    public function test_it_honours_the_hosts_config(): void
    {
        config()->set('data-schemas.schema_metadata', ['$schema' => true, '$id' => false]);
        config()->set('data-schemas.schema_version', 'https://example.test/draft/2020-12/schema');

        $this->assertSame(
            'https://example.test/draft/2020-12/schema',
            StudBackedData::jsonSchema()['$schema'] ?? null,
        );
    }

    public function test_a_host_that_swaps_the_generator_binding_changes_what_the_trait_returns(): void
    {
        $this->app->bind(Generator::class, fn () => new Fixtures\StubGenerator);

        $this->assertSame(['stub' => true], StudBackedData::jsonSchema());
    }
}
