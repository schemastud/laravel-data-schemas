<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Registries\ClassKey;
use Schemastud\DataSchemas\Fixtures\FixtureFactory;
use Schemastud\DataSchemas\Fixtures\FixtureIndex;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\FixtureShapeData;
use Schemastud\DataSchemas\Tests\Fixtures\ResourceKeyedShapeData;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\Creation\CreationContextFactory;

/**
 * A TRAIT, not a method on a base class. `StudData`'s own docblock says the `extends` slot is
 * contested — six abstract Data bases already exist in this family (`SyncData` twice,
 * `StreamingData`, `LineageSnapshotData`, `OtioData`, `SecretData`) — and `DerivesJsonSchema` is the
 * established precedent for reaching those classes anyway.
 *
 * Two overridable seams, so each tier of the Data chain overrides ONE method:
 *
 *     factoryRegistry()  which registry answers
 *     fixtureKey()       what this class is keyed by there
 */
class HasFixturesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    public function test_factory_returns_a_fixture_factory_and_stays_a_spatie_one(): void
    {
        $this->assertInstanceOf(FixtureFactory::class, FixtureShapeData::factory());
        $this->assertInstanceOf(CreationContextFactory::class, FixtureShapeData::factory());
    }

    /** `::factory()` is spatie's entry point and keeps working — nothing is shadowed. */
    public function test_spaties_own_from_still_works_through_the_override(): void
    {
        $this->assertSame('Direct', FixtureShapeData::factory()->from(['name' => 'Direct'])->name);
    }

    /**
     * The default key is `ClassKey` — the FALLBACK, carrying the namespace so two same-basename
     * classes cannot silently supersede one another.
     */
    public function test_the_default_key_is_the_class_key_with_its_namespace(): void
    {
        $this->assertSame(
            (string) ClassKey::of(FixtureShapeData::class),
            FixtureShapeData::exposedFixtureKey(),
        );
    }

    /** A tier below overrides ONE method to key by a shorter declared name. */
    public function test_a_subclass_can_override_the_key_without_touching_anything_else(): void
    {
        $this->assertSame('plans', ResourceKeyedShapeData::exposedFixtureKey());
    }

    public function test_it_builds_through_the_registry_bound_in_the_container(): void
    {
        app(FixtureIndex::class)
            ->defineShape('plans', fn () => ['name' => 'Starter'])
            ->defineState('plans', 'enterprise', fn (array $b) => ['name' => 'Enterprise']);

        $this->assertSame('Enterprise', ResourceKeyedShapeData::factory()->enterprise()->make()->name);
    }

    /**
     * The thing only this tier can do: a shape gets a working fixture with ZERO registration, because
     * `DerivesJsonSchema` already gives it `::jsonSchema()` and `#[Example]` already lives here.
     */
    public function test_defaults_derive_from_the_classes_own_schema_without_any_registration(): void
    {
        $defaults = FixtureShapeData::fixtureDefaults();

        $this->assertArrayHasKey('name', $defaults);
        $this->assertSame('Example Name', $defaults['name']);
    }
}
