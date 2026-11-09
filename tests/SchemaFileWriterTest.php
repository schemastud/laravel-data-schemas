<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\WrittenSchema;
use Schemastud\DataSchemas\Writers\SchemaFileWriter;

/**
 * The writer went through the `File` facade at absolute paths, which is not fakeable and
 * not swappable for any other storage. It writes to a DISK now — `data-schemas`, defined by
 * this package and rooted at `output_directory` — so `Storage::fake('data-schemas')` is the
 * whole testing story.
 *
 * The contract keeps its idea-name (`Writer`); the implementation takes its strategy-name
 * (`SchemaFileWriter`), matching `SchemaRegistry` ← `FilesystemSchemaRegistry` and
 * `PathGenerator` ← `DefaultPathGenerator`.
 */
class SchemaFileWriterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function collection(): FileSchemaCollection
    {
        return new FileSchemaCollection([
            new WrittenSchema(
                className: 'App\Data\UserData',
                schema: ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                outputPath: '/schemas/App/Data/UserData.schema.json',
            ),
        ]);
    }

    private function config(array $overrides = []): array
    {
        return array_merge((array) config('data-schemas'), array_merge([
            'output_directory' => '/schemas',
        ], $overrides));
    }

    public function test_the_package_defines_a_data_schemas_disk_rooted_at_the_output_directory(): void
    {
        $this->assertSame(
            config('data-schemas.output_directory'),
            config('filesystems.disks.data-schemas.root'),
        );
        $this->assertSame('data-schemas', config('data-schemas.disk'));
    }

    public function test_it_writes_each_schema_to_the_faked_disk(): void
    {
        Storage::fake('data-schemas');

        (new SchemaFileWriter($this->config()))->write($this->collection());

        Storage::disk('data-schemas')->assertExists('App/Data/UserData.schema.json');

        $this->assertSame(
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            json_decode(Storage::disk('data-schemas')->get('App/Data/UserData.schema.json'), true),
        );
    }

    public function test_format_output_controls_pretty_printing(): void
    {
        Storage::fake('data-schemas');
        (new SchemaFileWriter($this->config(['format_output' => true])))->write($this->collection());
        $this->assertStringContainsString("\n", Storage::disk('data-schemas')->get('App/Data/UserData.schema.json'));

        Storage::fake('data-schemas');
        (new SchemaFileWriter($this->config(['format_output' => false])))->write($this->collection());
        $this->assertStringNotContainsString("\n", Storage::disk('data-schemas')->get('App/Data/UserData.schema.json'));
    }

    /**
     * calcucrypt MAP-24: a host that commits the generated schemas runs `prettier --check` on them, and every one failed:
     * `JSON_PRETTY_PRINT` breaks short arrays one element a line and leaves no trailing newline. The formatted output is
     * prettier's own layout now; `Fixtures/Prettier/schema.json` is prettier 3.6.2's output (tabWidth 4, printWidth 80)
     * for the value in `schema.php`, so a clean file stays clean.
     */
    public function test_formatted_output_is_what_prettier_writes_for_it(): void
    {
        Storage::fake('data-schemas');
        $fixtures = __DIR__.'/Fixtures/Prettier';

        (new SchemaFileWriter($this->config(['format_output' => true])))->write(new FileSchemaCollection([
            new WrittenSchema(
                className: 'App\Data\UserData',
                schema: require $fixtures.'/schema.php',
                outputPath: '/schemas/App/Data/UserData.schema.json',
            ),
        ]));

        $this->assertSame(
            file_get_contents($fixtures.'/schema.json'),
            Storage::disk('data-schemas')->get('App/Data/UserData.schema.json'),
        );
    }

    /**
     * orch-peer REVISE on a2403e1: a schema json_encode cannot encode (invalid UTF-8 in a title) was cast to '', decoded
     * to null and written as a valid but wrong `null` document. Both layouts refuse it, name the class and write nothing.
     */
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function test_an_unencodable_schema_throws_and_writes_nothing(bool $formatted): void
    {
        Storage::fake('data-schemas');
        $writer = new SchemaFileWriter($this->config(['format_output' => $formatted]));

        try {
            $writer->write(new FileSchemaCollection([
                new WrittenSchema(
                    className: 'App\Data\UserData',
                    schema: ['type' => 'object', 'title' => "bad \xB1 utf-8"],
                    outputPath: '/schemas/App/Data/UserData.schema.json',
                ),
            ]));
            $this->fail('An unencodable schema was written.');
        } catch (\JsonException $e) {
            $this->assertStringContainsString('App\Data\UserData', $e->getMessage());
            $this->assertStringContainsString('Malformed UTF-8', $e->getMessage());
        }

        Storage::disk('data-schemas')->assertMissing('App/Data/UserData.schema.json');
    }

    public function test_the_shipped_config_names_the_renamed_implementation(): void
    {
        $this->assertSame(SchemaFileWriter::class, config('data-schemas.writer'));
    }
}
