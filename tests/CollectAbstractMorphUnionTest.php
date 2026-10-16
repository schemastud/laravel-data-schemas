<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Actions\DiscoverDataClassesAction;
use Schemastud\DataSchemas\Collectors\DataObjectCollector;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery\DiscoverableBranch;
use Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery\DiscoverableLeaf;
use Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery\DiscoverableNode;
use Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery\PlainAbstractNode;
use Schemastud\DataSchemas\Tests\Fixtures\MorphDiscovery\PlainConcreteNode;

/**
 * A #[MorphUnion] base must be reachable through the NORMAL discovery path.
 *
 * EP-02 built {@see \Schemastud\DataSchemas\TypeScript\MorphUnionTransformer} and the morph-aware
 * {@see JsonSchemaGenerator}, and {@see MorphUnionTest} proved them — but only by handing the abstract
 * base straight to the transformer/generator. {@see DataObjectCollector} rejected EVERY abstract
 * class, so a directory scan (how the app's `typescript:transform` and `schemas:generate` actually
 * find classes) never saw the base, and the union was unreachable in the real pipeline. This pins the
 * discovery path and that the gate widened for morph bases ONLY, not for every abstract.
 */
class CollectAbstractMorphUnionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // laravel-popcorn is not auto-discovered under testbench, and the action resolves
        // PopcornManager out of the container.
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    /** @return list<string> */
    private function discover(): array
    {
        $config = [
            'auto_discover_types' => [__DIR__.'/Fixtures/MorphDiscovery'],
            'namespaces' => [],
        ];

        return array_map(
            fn (ReflectionClass $class): string => $class->getName(),
            (new DiscoverDataClassesAction($config, [new DataObjectCollector($config)]))->execute(),
        );
    }

    public function test_discovery_reaches_an_abstract_morph_union_base_and_the_generator_emits_its_union(): void
    {
        $discovered = $this->discover();

        // The gap this closes: a directory scan reaches the morph base itself, not only its arms.
        $this->assertContains(DiscoverableNode::class, $discovered);
        $this->assertContains(DiscoverableLeaf::class, $discovered);
        $this->assertContains(DiscoverableBranch::class, $discovered);

        // And the base a scan reached projects as the 2-arm discriminated union through the ordinary
        // generator — reached by discovery, not by a direct transformer call.
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(DiscoverableNode::class));
        $this->assertCount(2, $schema['oneOf'] ?? []);
    }

    public function test_discovery_still_skips_a_plain_abstract_without_a_morph_union(): void
    {
        $discovered = $this->discover();

        $this->assertNotContains(PlainAbstractNode::class, $discovered);
        $this->assertContains(PlainConcreteNode::class, $discovered);
    }
}
