<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Actions\DiscoverDataClassesAction;
use Schemastud\DataSchemas\Collectors\DataObjectCollector;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\Discovery\ClassFetchData;
use Schemastud\DataSchemas\Tests\Fixtures\Discovery\NotAData;
use Schemastud\DataSchemas\Tests\Fixtures\Discovery\ProseDocblockData;

/**
 * Discovery had no coverage at all, which is why a `preg_match('/class\s+(\w+)/')` FQCN derivation
 * survived: it matched the first "class" in the file — prose in a docblock, or a `::class` fetch —
 * derived a name that does not exist, and `class_exists()` false-d the file into silence. Twelve real
 * `Data` subclasses across the estate never generated a schema and nothing reported it.
 *
 * Derivation is popcorn's now (`PopcornManager::classesIn()` over the tokenizing
 * `AttributedClassScanner`), so these pin the two prose/`::class` shapes and that handing enumeration
 * away did not move the collector's own filtering.
 */
class DiscoverDataClassesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            // laravel-popcorn is not auto-discovered under testbench, and the action resolves
            // PopcornManager out of the container.
            PopcornServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
        ];
    }

    /** @return list<string> */
    private function discover(array $namespaces = []): array
    {
        $config = [
            'auto_discover_types' => [__DIR__.'/Fixtures/Discovery'],
            'namespaces' => $namespaces,
        ];

        $action = new DiscoverDataClassesAction($config, [new DataObjectCollector($config)]);

        return array_map(
            fn (ReflectionClass $class): string => $class->getName(),
            $action->execute()
        );
    }

    public function test_it_discovers_a_data_class_whose_docblock_prose_contains_the_word_class(): void
    {
        $this->assertContains(ProseDocblockData::class, $this->discover());
    }

    public function test_it_discovers_a_data_class_preceded_by_a_class_constant_fetch(): void
    {
        $this->assertContains(ClassFetchData::class, $this->discover());
    }

    public function test_it_does_not_collect_a_non_data_class_under_a_scanned_path(): void
    {
        $this->assertNotContains(NotAData::class, $this->discover());
    }

    public function test_it_honours_a_separator_free_namespace_filter(): void
    {
        $matching = $this->discover(['Schemastud*']);

        $this->assertContains(ProseDocblockData::class, $matching);
        $this->assertContains(ClassFetchData::class, $matching);

        $this->assertSame([], $this->discover(['App*']));
    }

    /**
     * The regression this class exists to hold down: the filter used `fnmatch()`, which treats `\`
     * as an escape character, so a pattern written the way anyone would actually write one —
     * with namespace separators — matched NOTHING and the filter silently excluded everything.
     */
    public function test_a_namespace_pattern_carrying_separators_actually_matches(): void
    {
        $matching = $this->discover(['Schemastud\\DataSchemas\\Tests\\Fixtures\\Discovery\\*']);

        $this->assertContains(ProseDocblockData::class, $matching);
        $this->assertContains(ClassFetchData::class, $matching);
    }

    public function test_a_namespace_pattern_carrying_separators_still_excludes_non_matches(): void
    {
        $this->assertSame([], $this->discover(['App\\Data\\*']));
    }

    public function test_any_one_pattern_in_the_list_admits_the_class(): void
    {
        $matching = $this->discover(['App\\Data\\*', 'Schemastud\\DataSchemas\\Tests\\*']);

        $this->assertContains(ProseDocblockData::class, $matching);
    }

    public function test_a_missing_path_is_skipped_rather_than_raised(): void
    {
        $config = ['auto_discover_types' => [__DIR__.'/Fixtures/NoSuchDirectory']];

        $action = new DiscoverDataClassesAction($config, [new DataObjectCollector($config)]);

        $this->assertSame([], $action->execute());
    }
}
