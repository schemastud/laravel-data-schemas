<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\SchemaFileReader;
use Schemastud\DataSchemas\Support\WrittenSchema;

/**
 * The one class allowed to touch the filesystem on the read side. It exists so the
 * collections do not: `SchemaCollection::diffAgainst()` stays pure because this holds
 * the impure half, and the two compose without either knowing the other's business.
 */
class SchemaFileReaderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function reader(): SchemaFileReader
    {
        return new SchemaFileReader(array_merge((array) config('data-schemas'), ['output_directory' => '/schemas']));
    }

    private function written(string $class, array $schema): WrittenSchema
    {
        return new WrittenSchema($class, $schema, '/schemas/'.str_replace('\\', '/', $class).'.schema.json');
    }

    public function test_it_reads_a_document_back_off_the_disk(): void
    {
        Storage::fake('data-schemas');
        Storage::disk('data-schemas')->put('App/Data/UserData.schema.json', json_encode(['type' => 'object']));

        $this->assertSame(['type' => 'object'], $this->reader()->document('/schemas/App/Data/UserData.schema.json'));
    }

    public function test_an_absent_file_and_an_undecodable_one_are_both_null(): void
    {
        Storage::fake('data-schemas');
        Storage::disk('data-schemas')->put('App/Data/BrokenData.schema.json', '{ not json');

        $this->assertNull($this->reader()->document('/schemas/App/Data/MissingData.schema.json'));
        $this->assertNull($this->reader()->document('/schemas/App/Data/BrokenData.schema.json'));
    }

    /**
     * The join between the two halves: the reader keys by CLASS, which is the key
     * `diffAgainst()` takes — a path could not be that key, because a flat layout
     * gives two classes one path.
     */
    public function test_documents_for_keys_by_class_and_omits_what_is_not_there(): void
    {
        Storage::fake('data-schemas');
        Storage::disk('data-schemas')->put('App/Data/UserData.schema.json', json_encode(['type' => 'object']));

        $collection = new FileSchemaCollection([
            $this->written('App\Data\UserData', ['type' => 'object']),
            $this->written('App\Data\PostData', ['type' => 'object']),
        ]);

        $documents = $this->reader()->documentsFor($collection);

        $this->assertSame(['App\Data\UserData' => ['type' => 'object']], $documents);
        $this->assertSame(['App\Data\PostData'], $collection->diffAgainst($documents)['missing']);
    }
}
