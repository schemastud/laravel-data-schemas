<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\PipelineRegistry\PipelineRegistry;
use Rushing\PipelineRegistry\PipelineRegistryServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;

/**
 * The provider's LAST boot contribution, and the only one nothing was watching.
 *
 * {@see LaravelDataSchemasServiceProvider::boot()} ends with
 * `app(PipelineRegistry::class)->mergePipelinesFrom(config/pipelines)`, which is how
 * `resources:schemastud` reaches a host. `rushing/laravel-pipeline-registry` is a hard `require`
 * of this package, but testbench does not auto-discover, so until this file no suite here
 * registered `PipelineRegistryServiceProvider` — and `PipelineRegistry` is AUTO-RESOLVABLE
 * (its only constructor argument is the container). The merge therefore succeeded, silently,
 * onto a throwaway instance that was discarded the moment boot returned.
 *
 * Measured before this file existed, under `[PopcornServiceProvider, LaravelDataSchemasServiceProvider]`:
 * `make(PipelineRegistry::class) === make(PipelineRegistry::class)` was FALSE and `names()` was `[]`.
 * With the provider registered they are TRUE and `['resources:schemastud']`. That is outcome three of
 * the testbench trap catalogued in `splicewire/tower`'s `tests/TestCase.php` — not a failure, not a
 * null, but a correct-looking answer from an object nobody else holds.
 *
 * The two assertions are deliberately paired: the name alone would still pass against an unshared
 * registry (the same boot that merged is the one being read in-process), so the sharing is what makes
 * the membership mean anything at a host.
 */
class PipelineContributionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            // laravel-popcorn binds RegistryIndex as a SINGLETON — PipelineRegistryServiceProvider's
            // own boot describes into it, so this has to come first.
            PopcornServiceProvider::class,

            // Binds PipelineRegistry as a singleton. Without it this package's `mergePipelinesFrom()`
            // writes to a fresh, unshared instance and the pipeline it contributes is unreachable.
            PipelineRegistryServiceProvider::class,

            LaravelDataSchemasServiceProvider::class,
        ];
    }

    public function test_the_registry_is_shared_so_a_contribution_outlives_the_provider_that_made_it(): void
    {
        $this->assertSame(
            $this->app->make(PipelineRegistry::class),
            $this->app->make(PipelineRegistry::class),
        );
    }

    public function test_the_package_contributes_its_resources_pipeline_into_the_shared_registry(): void
    {
        $registry = $this->app->make(PipelineRegistry::class);

        $this->assertContains('resources:schemastud', $registry->names());
        $this->assertTrue($registry->has('resources:schemastud'));
    }
}
