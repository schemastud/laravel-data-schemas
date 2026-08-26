<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Generators\ChainedGenerator;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Generators\MissingSchemaBaseUri;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\RefusingGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\StubGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\UserData;
use Schemastud\DataSchemas\Tests\Fixtures\VersionedArticleData;

/**
 * The container front door for the generator.
 *
 * Every consumer that wanted a schema built one by hand — `new JsonSchemaGenerator` — and a bare
 * construction takes NO config. `strategies` and `id_parsers` self-heal (the generator falls back to
 * the container for those two), but `schema_metadata`, `schema_version` and `base_uri` do not: a
 * bare generator silently emits a document with no `$schema` and no `$id` even where the host
 * configured both. A census found 41 construction sites across 13 repos; ~26 are bare.
 *
 * So the step "build the generator this host configured" gets a home, exactly as
 * {@see \Schemastud\DataSchemas\Ids\SchemaIdResolver} already has one.
 */
class GeneratorBindingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    public function test_the_container_resolves_the_configured_generator_list_as_one_chain(): void
    {
        $resolved = $this->app->make(Generator::class);

        $this->assertInstanceOf(ChainedGenerator::class, $resolved);
        $this->assertInstanceOf(JsonSchemaGenerator::class, $resolved->generators()[0]);
    }

    /**
     * The `~/Herd/thingsontv` shape: two generators, the narrow one first. Binding `generators[0]`
     * would hand every ordinary Data class a Block-only generator; the chain dispatches instead.
     */
    public function test_a_multi_generator_host_dispatches_on_can_generate_not_on_position(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class, JsonSchemaGenerator::class]);

        $schema = $this->app->make(Generator::class)->generate(new ReflectionClass(UserData::class));

        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayNotHasKey('refused', $schema);
    }

    public function test_the_chain_reports_that_it_cannot_generate_when_no_member_accepts(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class]);

        $chain = $this->app->make(Generator::class);

        $this->assertFalse($chain->canGenerate(new ReflectionClass(UserData::class)));
    }

    public function test_the_chain_names_its_members_when_nothing_accepts(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(RefusingGenerator::class);

        $this->app->make(Generator::class)->generate(new ReflectionClass(UserData::class));
    }

    /** Mode reaches the member that ends up winning, not just the first one. */
    public function test_mode_propagates_through_the_chain(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class, JsonSchemaGenerator::class]);

        $chain = $this->app->make(Generator::class)->forRequest();

        $this->assertInstanceOf(ChainedGenerator::class, $chain);
        $this->assertIsArray($chain->generate(new ReflectionClass(UserData::class)));
    }

    public function test_the_resolved_generator_carries_the_hosts_config(): void
    {
        config()->set('data-schemas.schema_metadata', ['$schema' => true, '$id' => false]);
        config()->set('data-schemas.schema_version', 'https://example.test/draft/2020-12/schema');

        $schema = $this->app->make(Generator::class)->generate(new ReflectionClass(UserData::class));

        $this->assertSame('https://example.test/draft/2020-12/schema', $schema['$schema'] ?? null);
    }

    /**
     * The `base_uri` half of the same bug, asserted separately because it is the riskiest of the
     * three non-self-healing keys: it is a tri-state with NO default, so a bare generator does not
     * merely emit a stale `$id` — it emits none at all, and a `$ref`-following client gets a
     * document it cannot address.
     */
    public function test_the_resolved_generator_carries_the_hosts_base_uri(): void
    {
        config()->set('data-schemas.schema_metadata', ['$schema' => false, '$id' => true]);
        config()->set('data-schemas.base_uri', 'https://example.test/schemas');

        $schema = $this->app->make(Generator::class)->generate(new ReflectionClass(VersionedArticleData::class));

        $this->assertSame('https://example.test/schemas/content/article/3', $schema['$id'] ?? null);
    }

    /**
     * ...and the bare generator does not degrade quietly here — it CRASHES. `base_uri` is write-once
     * with no default, so a versioned class built by a config-blind generator raises
     * {@see MissingSchemaBaseUri}, telling the operator to configure a key the host already
     * configured. This half of the defect is loud, not silent, which is why it is worth pinning
     * separately from the `$schema` half above: any of the ~26 bare construction sites that ever
     * meets a SchemaIdentity class is failing today, not drifting.
     */
    public function test_a_bare_generator_crashes_on_a_versioned_class_the_host_configured_correctly(): void
    {
        config()->set('data-schemas.schema_metadata', ['$schema' => false, '$id' => true]);
        config()->set('data-schemas.base_uri', 'https://example.test/schemas');

        $this->expectException(MissingSchemaBaseUri::class);

        (new JsonSchemaGenerator)->generate(new ReflectionClass(VersionedArticleData::class));
    }

    /**
     * The bug this binding exists to remove, stated as a test: a hand-built generator does not see
     * the same configuration the container-resolved one does.
     */
    public function test_a_bare_generator_does_not_see_that_config(): void
    {
        config()->set('data-schemas.schema_metadata', ['$schema' => true, '$id' => false]);
        config()->set('data-schemas.schema_version', 'https://example.test/draft/2020-12/schema');

        $bare = (new JsonSchemaGenerator)->generate(new ReflectionClass(UserData::class));

        $this->assertArrayNotHasKey('$schema', $bare);
    }

    /**
     * NOT a singleton, for the reason recorded on the SchemaIdResolver binding: config is read at
     * construction, so a test (or a host) that sets config after boot must get the new value.
     */
    public function test_the_binding_is_not_a_singleton_so_late_config_is_honoured(): void
    {
        $this->app->make(Generator::class);

        config()->set('data-schemas.schema_metadata', ['$schema' => true, '$id' => false]);
        config()->set('data-schemas.schema_version', 'https://late.test/schema');

        $schema = $this->app->make(Generator::class)->generate(new ReflectionClass(UserData::class));

        $this->assertSame('https://late.test/schema', $schema['$schema'] ?? null);
    }

    /**
     * A class-string in `data-schemas.generators` is something the CONFIG'S AUTHOR could have got
     * right without knowing which host would load it, so a bad one throws — but it must throw
     * NAMING the key it came from. Unguarded, an unknown class surfaces as a bare
     * `Error: Class "..." not found` from deep in the container, attributable to nothing.
     */
    public function test_an_unknown_generator_class_names_the_config_key_it_came_from(): void
    {
        config()->set('data-schemas.generators', ['App\\Nope\\NoSuchGenerator']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('data-schemas.generators');

        $this->app->make(Generator::class);
    }

    /**
     * The worse half: a real class that is not a Generator CONSTRUCTS fine (the chain's `array`
     * param types no elements) and survives until something calls `canGenerate()` on it — an Error
     * raised far from the configuration that caused it.
     */
    public function test_a_configured_class_that_is_not_a_generator_is_refused_at_resolve_time(): void
    {
        config()->set('data-schemas.generators', [UserData::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('data-schemas.generators');

        $this->app->make(Generator::class);
    }

    public function test_a_host_can_swap_the_binding(): void
    {
        $this->app->bind(Generator::class, fn () => new StubGenerator);

        $this->assertInstanceOf(StubGenerator::class, $this->app->make(Generator::class));
    }
}
