<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\PipelineRegistry\PipelineContext;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Pipelines\GenerateJsonSchemasStage;
use Schemastud\DataSchemas\Tests\Fixtures\RefusingGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\StubGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\UserData;

// See TransformTypesStageTest for why the engine's passable is loaded by path.
if (! class_exists(PipelineContext::class)) {
    $enginePath = __DIR__.'/../../../rushing/laravel-pipeline-registry/src/PipelineContext.php';
    if (is_file($enginePath)) {
        require_once $enginePath;
    }
}

/**
 * The stage was the last production site in this package building a generator by hand.
 *
 * Ticket 105 gave it the host's `data-schemas` config, which fixed the missing `$id`/`$schema`
 * half of the defect. What it could not fix is that `new JsonSchemaGenerator($config)` reads
 * every key in that array EXCEPT `generators` — so at a host that configures a list (the
 * `~/Herd/thingsontv` shape), the stage was the one consumer still generating with the package
 * default while every other consumer dispatched over the host's chain. Two universes, one config.
 *
 * The throw is the reason this needs its own test rather than a one-line swap.
 * {@see \Schemastud\DataSchemas\Generators\ChainedGenerator::generate()} THROWS when no member
 * accepts the class, where a bare `JsonSchemaGenerator` generated unconditionally. The stage is
 * safe because it has always asked `canGenerate()` first and NOTED a skip — that guard was
 * incidental before and is load-bearing now, so it is pinned here.
 */
class GenerateJsonSchemasStageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function emit(array $options): PipelineContext
    {
        return (new GenerateJsonSchemasStage($options))->handle(new PipelineContext, fn ($c) => $c);
    }

    public function test_the_stage_generates_with_the_generator_the_host_configured_not_the_package_default(): void
    {
        config()->set('data-schemas.generators', [StubGenerator::class]);

        $context = $this->emit(['data' => [UserData::class]]);

        $this->assertSame(
            ['stub' => true],
            json_decode($context->files['schemas/UserData.json'], true),
        );
    }

    public function test_a_host_that_swaps_the_container_binding_outright_is_honoured(): void
    {
        $this->app->bind(Generator::class, fn () => new StubGenerator);

        $context = $this->emit(['data' => [UserData::class]]);

        $this->assertSame(
            ['stub' => true],
            json_decode($context->files['schemas/UserData.json'], true),
        );
    }

    /**
     * The chain throws where the bare generator did not; the stage's pre-existing `canGenerate()`
     * guard is what keeps that throw off the pipeline. A refused class is a NOTE and no file.
     */
    public function test_a_class_no_configured_generator_accepts_is_skipped_rather_than_thrown(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class]);

        $context = $this->emit(['data' => [UserData::class]]);

        $this->assertSame([], $context->files);
        $this->assertStringContainsString('SKIPPED', $context->log[0] ?? '');
    }

    /**
     * The `config` option is a deliberate override of the host's config, so it must not be
     * silently replaced by the container's host-configured chain.
     */
    public function test_an_explicit_config_option_still_overrides_the_hosts_config(): void
    {
        config()->set('data-schemas.generators', [StubGenerator::class]);

        $context = $this->emit([
            'data' => [UserData::class],
            'config' => ['generators' => [JsonSchemaGenerator::class]],
        ]);

        $this->assertArrayHasKey(
            'properties',
            json_decode($context->files['schemas/UserData.json'], true),
        );
    }

    public function test_the_mode_reaches_every_member_of_the_configured_chain(): void
    {
        config()->set('data-schemas.schema_metadata', ['$schema' => false, '$id' => false]);

        $context = $this->emit(['data' => [UserData::class], 'mode' => 'llm_strict']);

        $schema = json_decode($context->files['schemas/UserData.json'], true);

        $this->assertArrayHasKey('additionalProperties', $schema);
        $this->assertFalse($schema['additionalProperties']);
    }
}
