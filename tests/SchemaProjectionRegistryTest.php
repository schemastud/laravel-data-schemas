<?php

namespace Schemastud\DataSchemas\Tests;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Sources\PathScanSource;
use Schemastud\DataSchemas\Sources\SchemaProjectionRegistry;
use Schemastud\DataSchemas\Tests\Fixtures\StubSchemaSource;
use Schemastud\DataSchemas\Tests\Fixtures\UserData;

/**
 * "Where do this host's schemas come from" — enumerable, rather than hard-coded in a command.
 *
 * Projection had exactly one source and it was spelled inline: `schemas:generate` newed a
 * {@see \Schemastud\DataSchemas\Actions\DiscoverDataClassesAction} over
 * `config('data-schemas.auto_discover_types')`, and any other way of knowing which classes exist
 * — a particle registry, an explicit manifest, a database of tenant shapes — had nowhere to say so.
 * A consumer holding a second universe cannot reconcile it with a path scan it cannot see.
 *
 * ## Entries are SOURCES, not paths and not class-strings
 *
 * A path is one source's private vocabulary (the particle-registry source has no paths at all), so a
 * registry of paths forecloses the contribution it exists to accept. Class-strings are worse: they
 * would have to be enumerated AT REGISTRATION TIME, in a provider's `boot()`, which is the boot-order
 * trap — a source registered before the thing it enumerates is populated silently contributes nothing,
 * and records load order as truth. A source is asked at READ time.
 */
class SchemaProjectionRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function registry(): SchemaProjectionRegistry
    {
        return $this->app->make(SchemaProjectionRegistry::class);
    }

    public function test_the_root_is_declared_domain_first_and_vendor_free(): void
    {
        $declaration = IsRegistry::of(SchemaProjectionRegistry::class);

        $this->assertNotNull($declaration);
        $this->assertSame('schemas.projection', $declaration->root);
    }

    /**
     * A two-step read, so a LIST — pick a source, then enumerate that source's classes. The same
     * shape `PipelineRegistry` (pick a pipeline, compose its stages) and `ResourceRenderingRegistry`
     * (pick a resource, run its renderings) already declare.
     */
    public function test_the_arity_is_the_two_step_read_and_not_a_bare_case(): void
    {
        $declaration = IsRegistry::of(SchemaProjectionRegistry::class);

        $this->assertSame(
            [RegistryArity::PickOne, RegistryArity::RunAll],
            $declaration->arity,
        );
    }

    public function test_the_package_ships_its_path_scan_source_registered(): void
    {
        $source = $this->registry()->source('path-scan');

        $this->assertInstanceOf(PathScanSource::class, $source);
    }

    public function test_the_root_is_routable_because_the_provider_describes_it(): void
    {
        $index = $this->app->make(RegistryIndex::class);

        $this->assertNotNull($index->tryResolve('schemas.projection'));
    }

    public function test_a_contributed_source_is_enumerable_alongside_the_shipped_one(): void
    {
        $this->registry()->register('particles', new StubSchemaSource([UserData::class]), by: 'test');

        $this->assertEqualsCanonicalizing(
            ['schemas.projection.path-scan', 'schemas.projection.particles'],
            array_map(fn ($key) => (string) $key, $this->registry()->keys()),
        );
    }

    /**
     * The whole point: one union across every registered source, so a consumer stops enumerating a
     * universe some other consumer cannot see.
     */
    public function test_classes_unions_every_registered_source(): void
    {
        config()->set('data-schemas.auto_discover_types', []);

        $this->registry()->register('a', new StubSchemaSource([UserData::class]), by: 'test');
        $this->registry()->register('b', new StubSchemaSource([Fixtures\SampleData::class]), by: 'test');

        $names = array_map(fn (ReflectionClass $c) => $c->getName(), $this->registry()->classes());

        $this->assertContains(UserData::class, $names);
        $this->assertContains(Fixtures\SampleData::class, $names);
    }

    /**
     * Two sources legitimately know about the same class — a particle IS a Data class under a scanned
     * path — so the union dedupes rather than generating it twice.
     */
    public function test_a_class_two_sources_both_know_about_appears_once(): void
    {
        config()->set('data-schemas.auto_discover_types', []);

        $this->registry()->register('a', new StubSchemaSource([UserData::class]), by: 'test');
        $this->registry()->register('b', new StubSchemaSource([UserData::class]), by: 'test');

        $names = array_map(fn (ReflectionClass $c) => $c->getName(), $this->registry()->classes());

        $this->assertSame([UserData::class], $names);
    }

    /**
     * WHICH source wins the slot, now that the command reads this union: the FIRST to name a class,
     * and registration order is the tie-break. Pinned on the ORDER of the union rather than on object
     * identity, because that is the user-visible consequence — it is the order `schemas:generate`
     * generates and reports in.
     *
     * This does NOT contradict `onDuplicate: Supersede`. The two rules are about different things:
     * Supersede governs one KEY re-registered (a host replacing the shipped `path-scan` with a
     * narrowed one — last registration wins the KEY), first-wins governs one CLASS named by two
     * DIFFERENT keys (no key is being replaced; the class simply already has a slot).
     */
    public function test_the_first_source_to_name_a_class_keeps_its_slot_in_the_union(): void
    {
        config()->set('data-schemas.auto_discover_types', []);

        $this->registry()->register('a', new StubSchemaSource([UserData::class, Fixtures\SampleData::class]), by: 'test');
        $this->registry()->register('b', new StubSchemaSource([Fixtures\SampleData::class, Fixtures\TitledData::class]), by: 'test');

        $this->assertSame(
            [UserData::class, Fixtures\SampleData::class, Fixtures\TitledData::class],
            array_map(fn (ReflectionClass $c) => $c->getName(), $this->registry()->classes()),
        );
    }

    /** Supersede's half of that pair: re-registering a KEY replaces the source at it. */
    public function test_re_registering_a_key_supersedes_the_source_at_it(): void
    {
        config()->set('data-schemas.auto_discover_types', []);

        $this->registry()->register('path-scan', new StubSchemaSource([UserData::class]), by: 'test');

        $this->assertSame(
            [UserData::class],
            array_map(fn (ReflectionClass $c) => $c->getName(), $this->registry()->classes()),
        );
    }

    /**
     * The boot-order trap this registry is shaped to avoid, stated as a test: a source registered
     * before the thing it enumerates is populated must still contribute, because it is asked at READ
     * time and not at registration time.
     */
    public function test_a_source_is_asked_at_read_time_not_at_registration_time(): void
    {
        config()->set('data-schemas.auto_discover_types', []);

        $source = new StubSchemaSource([]);
        $this->registry()->register('late', $source, by: 'test');

        $source->classNames = [UserData::class];

        $this->assertSame(
            [UserData::class],
            array_map(fn (ReflectionClass $c) => $c->getName(), $this->registry()->classes()),
        );
    }

    /** The kernel's rule: a port publishes BOTH halves of the miss pair, never only the throwing one. */
    public function test_the_nullable_half_of_the_accessor_pair_exists(): void
    {
        $this->assertNull($this->registry()->trySource('no-such-source'));
    }

    /**
     * The composite is a {@see \Schemastud\DataSchemas\Sources\SchemaSource} itself — so the command can
     * take one type whether it is handed the whole registry or an ad-hoc override — which makes exactly
     * one nonsense entry newly typeable, and `classes()` would recurse on it forever.
     */
    public function test_the_registry_refuses_to_be_registered_inside_itself(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->register('self', $this->registry(), by: 'test');
    }

    public function test_an_entry_that_is_not_a_source_is_refused_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->register('bad', 'App\\Data\\UserData', by: 'test');
    }

    /**
     * The shipped source is the existing path scan and nothing new — it reads the host's configured
     * `auto_discover_types` through the same action `schemas:generate` has always used.
     */
    public function test_the_path_scan_source_enumerates_the_hosts_configured_paths(): void
    {
        config()->set('data-schemas.auto_discover_types', [__DIR__.'/Fixtures/Discovery']);

        $names = array_map(
            fn (ReflectionClass $c) => $c->getName(),
            (new PathScanSource)->classes(),
        );

        $this->assertContains(Fixtures\Discovery\ClassFetchData::class, $names);
        $this->assertNotContains(Fixtures\Discovery\NotAData::class, $names);
    }
}
