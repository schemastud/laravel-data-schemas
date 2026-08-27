<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\Collisions\Alpha\WidgetData;

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
}
