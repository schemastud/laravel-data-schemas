<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Support\Collection;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Actions\GenerateSchemasAction;
use Schemastud\DataSchemas\Generators\ChainedGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\PathGenerators\DefaultPathGenerator;
use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\SchemaCollection;
use Schemastud\DataSchemas\Support\WrittenSchema;
use Schemastud\DataSchemas\Tests\Fixtures\Collisions\Alpha\WidgetData as AlphaWidgetData;
use Schemastud\DataSchemas\Tests\Fixtures\Collisions\Beta\WidgetData as BetaWidgetData;

/**
 * `path_structure: 'flat'` keys the output file on `getShortName()`, so two Data classes
 * in different namespaces with the same short name resolve to ONE path — and the writer's
 * last write silently wins. Nothing reported it, because nothing ever asked.
 *
 * The question is answerable only where the paths are, which is why provenance splits the
 * collection family: `SchemaCollection` holds what was GENERATED, `FileSchemaCollection`
 * holds what has a destination on disk.
 */
class FileSchemaCollectionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function generate(string $pathStructure): FileSchemaCollection
    {
        $config = array_merge((array) config('data-schemas'), [
            'output_directory' => '/schemas',
            'path_structure' => $pathStructure,
            'base_uri' => false,
        ]);

        $action = new GenerateSchemasAction(
            ChainedGenerator::fromConfig($config)->generators(),
            new DefaultPathGenerator($config),
        );

        return $action->execute([
            new ReflectionClass(AlphaWidgetData::class),
            new ReflectionClass(BetaWidgetData::class),
        ]);
    }

    public function test_the_generate_action_yields_written_schemas_in_a_file_collection(): void
    {
        $collection = $this->generate('namespace');

        $this->assertInstanceOf(FileSchemaCollection::class, $collection);
        $this->assertInstanceOf(SchemaCollection::class, $collection);
        $this->assertContainsOnlyInstancesOf(WrittenSchema::class, $collection->all());
    }

    public function test_a_flat_path_structure_collides_two_identically_named_classes(): void
    {
        $collisions = $this->generate('flat')->pathCollisions();

        $this->assertInstanceOf(Collection::class, $collisions);
        $this->assertCount(1, $collisions);
        $this->assertSame(
            [AlphaWidgetData::class, BetaWidgetData::class],
            $collisions->get('/schemas'.DIRECTORY_SEPARATOR.'WidgetData.schema.json'),
        );
    }

    public function test_a_namespace_path_structure_has_no_collision(): void
    {
        $this->assertTrue($this->generate('namespace')->pathCollisions()->isEmpty());
    }

    /**
     * The collision is only visible because the collection is NOT keyed by path — a
     * path-keyed collection would have dropped the second entry on construction, which
     * is the very bug `pathCollisions()` exists to expose.
     */
    public function test_the_collection_keeps_both_sides_of_a_collision(): void
    {
        $this->assertCount(2, $this->generate('flat'));
    }
}
