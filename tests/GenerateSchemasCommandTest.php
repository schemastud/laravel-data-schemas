<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Sources\SchemaProjectionRegistry;
use Schemastud\DataSchemas\Tests\Fixtures\Collisions\Alpha\WidgetData;
use Schemastud\DataSchemas\Tests\Fixtures\Collisions\Beta\WidgetData as BetaWidgetData;
use Schemastud\DataSchemas\Tests\Fixtures\Discovery\NotAData;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;
use Schemastud\DataSchemas\Tests\Fixtures\StubSchemaSource;
use Schemastud\DataSchemas\Tests\Fixtures\UserData;

/**
 * The `schemas:generate` path — discover → generate → write → report — had no test at all,
 * which is a large part of why the collection under it drifted. This is the end-to-end
 * witness that the rename, the disk move and the provenance split did not break the command.
 */
class GenerateSchemasCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('data-schemas.auto_discover_types', [__DIR__.'/Fixtures/Collisions/Alpha']);
        $app['config']->set('data-schemas.output_directory', '/schemas');
        $app['config']->set('data-schemas.base_uri', false);
    }

    public function test_it_generates_and_writes_a_schema_to_the_data_schemas_disk(): void
    {
        Storage::fake('data-schemas');

        $this->artisan('schemas:generate')
            ->expectsOutputToContain('Found 1 Data class(es)')
            ->assertSuccessful();

        $relative = str_replace('\\', '/', WidgetData::class).'.schema.json';

        Storage::disk('data-schemas')->assertExists($relative);

        $document = json_decode(Storage::disk('data-schemas')->get($relative), true);

        $this->assertSame('object', $document['type']);
        $this->assertArrayHasKey('id', $document['properties']);
    }

    public function test_a_flat_layout_warns_before_it_overwrites_one_class_with_another(): void
    {
        Storage::fake('data-schemas');

        config([
            'data-schemas.auto_discover_types' => [__DIR__.'/Fixtures/Collisions'],
            'data-schemas.path_structure' => 'flat',
        ]);

        $this->artisan('schemas:generate')
            ->expectsOutputToContain('Path collision')
            ->expectsOutputToContain('WidgetData.schema.json')
            ->assertSuccessful();

        Storage::disk('data-schemas')->assertExists('WidgetData.schema.json');
    }

    /**
     * `--output` re-points the disk ROOT and forgets the resolved disk, so this one
     * deliberately does not fake: the override's whole job is to make the disk answer
     * somewhere else, and a fake that survived it would be proving the opposite.
     */
    public function test_the_output_override_moves_the_disk_root_with_it(): void
    {
        $target = sys_get_temp_dir().'/data-schemas-'.getmypid().'-'.uniqid();

        try {
            $this->artisan('schemas:generate', ['--output' => $target])->assertSuccessful();

            // The disk root followed the override, so the file lands at the SAME disk-relative
            // path — the two knobs cannot drift apart and quietly write to the wrong place.
            $this->assertSame($target, config('filesystems.disks.data-schemas.root'));
            $this->assertFileExists($target.'/'.str_replace('\\', '/', WidgetData::class).'.schema.json');
        } finally {
            exec('rm -rf '.escapeshellarg($target));
        }
    }

    public function test_it_reports_when_nothing_was_discovered(): void
    {
        Storage::fake('data-schemas');

        config(['data-schemas.auto_discover_types' => [__DIR__.'/Fixtures/Nothing']]);

        $this->artisan('schemas:generate')
            ->expectsOutputToContain('No Data classes found')
            ->assertSuccessful();
    }

    /**
     * The reason the registry exists: a contributed source is generated from, without the command
     * knowing anything about it. Before this, `schemas:generate` enumerated exactly one universe —
     * the path scan — and a host holding a second one had nowhere to say so.
     */
    public function test_it_generates_from_every_registered_source_not_only_the_path_scan(): void
    {
        Storage::fake('data-schemas');

        $this->projection()->register('stub', new StubSchemaSource([UserData::class]), by: 'test');

        $this->artisan('schemas:generate')
            ->expectsOutputToContain('Found 2 Data class(es)')
            ->assertSuccessful();

        Storage::disk('data-schemas')->assertExists(str_replace('\\', '/', WidgetData::class).'.schema.json');
        Storage::disk('data-schemas')->assertExists(str_replace('\\', '/', UserData::class).'.schema.json');
    }

    /**
     * A particle IS a Data class under a scanned path, so two sources naming one class is the
     * ordinary case and not a conflict. It is generated ONCE — the registry's union dedupes by class
     * name before the command ever sees it.
     */
    public function test_a_class_two_sources_both_know_about_is_generated_once(): void
    {
        Storage::fake('data-schemas');

        $this->projection()->register('stub', new StubSchemaSource([WidgetData::class]), by: 'test');

        $this->artisan('schemas:generate')
            ->expectsOutputToContain('Found 1 Data class(es)')
            ->assertSuccessful();
    }

    /**
     * `--class` REPLACES the registry rather than filtering it, which is what it has always meant:
     * "generate this class" is an escape hatch, and it works for a class no registered source knows
     * about. Filtering the union would have silently turned it into "generate this class IF something
     * already discovers it" — a different command, and a worse one.
     */
    public function test_a_class_override_replaces_the_registry_rather_than_filtering_it(): void
    {
        Storage::fake('data-schemas');

        $this->projection()->register('stub', new StubSchemaSource([UserData::class]), by: 'test');

        // SampleData is under no configured path and in no registered source.
        $this->artisan('schemas:generate', ['--class' => SampleData::class])
            ->expectsOutputToContain('Found 1 Data class(es)')
            ->assertSuccessful();

        Storage::disk('data-schemas')->assertExists(str_replace('\\', '/', SampleData::class).'.schema.json');
        Storage::disk('data-schemas')->assertMissing(str_replace('\\', '/', UserData::class).'.schema.json');
        Storage::disk('data-schemas')->assertMissing(str_replace('\\', '/', WidgetData::class).'.schema.json');
    }

    /** The same rule for `--path`: an ad-hoc scan of that path INSTEAD of every registered source. */
    public function test_a_path_override_replaces_the_registry_rather_than_filtering_it(): void
    {
        Storage::fake('data-schemas');

        $this->projection()->register('stub', new StubSchemaSource([UserData::class]), by: 'test');

        $this->artisan('schemas:generate', ['--path' => __DIR__.'/Fixtures/Collisions/Beta'])
            ->expectsOutputToContain('Found 1 Data class(es)')
            ->assertSuccessful();

        Storage::disk('data-schemas')->assertExists(str_replace('\\', '/', BetaWidgetData::class).'.schema.json');
        Storage::disk('data-schemas')->assertMissing(str_replace('\\', '/', UserData::class).'.schema.json');
    }

    /** A `--class` that is not a Data class is still nothing to generate, not a crash. */
    public function test_a_class_override_that_the_collectors_refuse_reports_nothing_found(): void
    {
        Storage::fake('data-schemas');

        $this->artisan('schemas:generate', ['--class' => NotAData::class])
            ->expectsOutputToContain('No Data classes found')
            ->assertSuccessful();
    }

    private function projection(): SchemaProjectionRegistry
    {
        return $this->app->make(SchemaProjectionRegistry::class);
    }
}
