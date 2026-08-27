<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Schemastud\DataSchemas\Support\GeneratedSchema;
use Schemastud\DataSchemas\Support\SchemaCollection;
use Schemastud\DataSchemas\Tests\Fixtures\InMemorySchemaRegistry;

/**
 * The interrogation surface of a generated schema set — and the honesty of the
 * collection type that carries it.
 *
 * `SchemaCollection` declares `@extends Collection<int, GeneratedSchema>`, and
 * `Illuminate\Support\Collection::map()` builds its result with `new static`.
 * So an un-overridden `map()` hands back something that CLAIMS to hold
 * `GeneratedSchema` while holding arrays. Eloquent solved this years ago by
 * downgrading to a base collection the moment the items stop being models; the
 * same downgrade is mirrored here rather than reinvented.
 */
class SchemaCollectionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function schema(string $className, array $properties): GeneratedSchema
    {
        return new GeneratedSchema(
            className: $className,
            schema: [
                '$id' => 'https://schemas.test/'.str_replace('\\', '.', $className),
                'type' => 'object',
                'properties' => $properties,
            ],
        );
    }

    private function collection(): SchemaCollection
    {
        return new SchemaCollection([
            $this->schema('App\Data\UserData', ['id' => ['type' => 'string']]),
            $this->schema('App\Data\PostData', ['title' => ['type' => 'string'], 'body' => ['type' => 'string']]),
        ]);
    }

    public function test_map_downgrades_to_a_base_collection_once_the_items_stop_being_generated_schemas(): void
    {
        $mapped = $this->collection()->map(fn (GeneratedSchema $s) => $s->className);

        $this->assertInstanceOf(Collection::class, $mapped);
        $this->assertNotInstanceOf(SchemaCollection::class, $mapped);
        $this->assertSame(['App\Data\UserData', 'App\Data\PostData'], $mapped->all());
    }

    public function test_map_stays_a_schema_collection_when_the_items_are_still_generated_schemas(): void
    {
        $mapped = $this->collection()->map(fn (GeneratedSchema $s) => $s);

        $this->assertInstanceOf(SchemaCollection::class, $mapped);
    }

    public function test_map_with_keys_downgrades_the_same_way(): void
    {
        $keyed = $this->collection()->mapWithKeys(fn (GeneratedSchema $s) => [$s->className => $s]);
        $this->assertInstanceOf(SchemaCollection::class, $keyed);

        $counts = $this->collection()->mapWithKeys(fn (GeneratedSchema $s) => [$s->className => $s->getPropertyCount()]);
        $this->assertNotInstanceOf(SchemaCollection::class, $counts);
        $this->assertSame(['App\Data\UserData' => 1, 'App\Data\PostData' => 2], $counts->all());
    }

    public function test_the_shape_changing_methods_always_return_a_base_collection(): void
    {
        $collection = $this->collection();

        foreach ([$collection->keys(), $collection->pluck('className'), $collection->flatten(), $collection->collapse()] as $result) {
            $this->assertNotInstanceOf(SchemaCollection::class, $result);
            $this->assertInstanceOf(Collection::class, $result);
        }
    }

    public function test_fingerprints_are_the_lifecycle_fingerprint_of_each_document_keyed_by_class(): void
    {
        $collection = $this->collection();

        $fingerprints = $collection->fingerprints();

        $this->assertNotInstanceOf(SchemaCollection::class, $fingerprints);
        $this->assertSame(
            SchemaFingerprint::of($collection->first()->schema),
            $fingerprints->get('App\Data\UserData'),
        );
        $this->assertNotSame(
            $fingerprints->get('App\Data\UserData'),
            $fingerprints->get('App\Data\PostData'),
        );
    }

    public function test_diff_against_reports_missing_drifted_and_orphaned(): void
    {
        $collection = $this->collection();

        $diff = $collection->diffAgainst([
            // UserData drifted — the on-disk copy has an extra property.
            'App\Data\UserData' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string'], 'legacy' => ['type' => 'string']],
            ],
            // PostData is absent entirely → missing.
            // And a document nothing generates any more → orphaned.
            'App\Data\GhostData' => ['type' => 'object', 'properties' => []],
        ]);

        $this->assertSame(['App\Data\UserData'], $diff['drifted']);
        $this->assertSame(['App\Data\PostData'], $diff['missing']);
        $this->assertSame(['App\Data\GhostData'], $diff['orphaned']);
    }

    public function test_diff_against_is_empty_when_the_on_disk_set_matches(): void
    {
        $collection = $this->collection();

        $onDisk = [];
        foreach ($collection as $generated) {
            $onDisk[$generated->className] = $generated->schema;
        }

        $this->assertSame(
            ['missing' => [], 'drifted' => [], 'orphaned' => []],
            $collection->diffAgainst($onDisk),
        );
    }

    /**
     * Purity, proved by contradiction rather than by reading the implementation: the
     * disk is given a document that DISAGREES with the argument, and the answer follows
     * the argument. A `diffAgainst()` that reached for the filesystem would report the
     * opposite.
     */
    public function test_diff_against_reads_its_argument_and_never_the_disk(): void
    {
        Storage::fake('data-schemas');
        Storage::disk('data-schemas')->put(
            'App/Data/UserData.schema.json',
            json_encode(['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'drifted' => ['type' => 'string']]]),
        );

        $collection = new SchemaCollection([
            $this->schema('App\Data\UserData', ['id' => ['type' => 'string']]),
        ]);

        $diff = $collection->diffAgainst([
            'App\Data\UserData' => $collection->first()->schema,
        ]);

        $this->assertSame(['missing' => [], 'drifted' => [], 'orphaned' => []], $diff);
    }

    public function test_register_into_puts_every_document_into_the_registry(): void
    {
        $registry = new InMemorySchemaRegistry;

        $returned = $this->collection()->registerInto($registry);

        $this->assertSame(
            ['https://schemas.test/App.Data.UserData', 'https://schemas.test/App.Data.PostData'],
            $registry->ids(),
        );
        $this->assertInstanceOf(SchemaCollection::class, $returned);
    }
}
