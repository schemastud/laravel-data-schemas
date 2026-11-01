<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use RuntimeException;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\ClassSnakeMappedData;
use Schemastud\DataSchemas\Tests\Fixtures\GlobalOutputStrategyData;
use Schemastud\DataSchemas\Tests\Fixtures\GlobalStrategyData;
use Schemastud\DataSchemas\Tests\Fixtures\MapNameBothData;
use Schemastud\DataSchemas\Tests\Fixtures\MappedNameData;
use Schemastud\DataSchemas\Tests\Fixtures\MappedNameHostData;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Support\Caching\DataStructureCache;
use Spatie\LaravelData\Support\DataConfig;

/**
 * api-surface-coherence 54 — the schema must name the key the wire actually carries, on EVERY
 * mode (owner ruling, root 805623d0, 2026-11-01).
 *
 * **The ruling.** spatie already resolves the name each property answers to — `DataProperty`
 * carries `inputMappedName` and `outputMappedName`, folded from `#[MapInputName]`,
 * `#[MapOutputName]`, `#[MapName]` (property- AND class-level, including a mapper class) and the
 * host's global `data.name_mapping_strategy`. The generator must key the document on that fact in
 * every mode, never by parsing attributes itself and never by keeping the raw PHP property name:
 *
 * - `request` keys on the INPUT name — what `from()` accepts.
 * - `response`, `collapsed` and `llm_strict` key on the OUTPUT name — what `toArray()` emits,
 *   which is exactly what storage writes, so the stored/registry/migration-ladder shape and the
 *   provider-facing strict shape all agree with it.
 *
 * ONE code path, in `wireName()`: request uses `inputMappedName`, every other mode uses
 * `outputMappedName`, each falling back to the PHP name when spatie has no mapping (null) or
 * cannot be asked (a non-Data class, or an unbooted container).
 *
 * This REPLACES the earlier contract in which `collapsed` and `llm_strict` deliberately kept the
 * PHP property name. That is the ruled design change — see `test_collapsed_and_llm_strict_*`.
 * Collapsed is the stored shape, so where an output mapper is configured its fingerprint now
 * follows the name storage actually used; the consumer census reports every artifact that moves.
 *
 * Booted through Testbench with spatie's provider deliberately: `DataConfig::getDataClass()` goes
 * through `DataContainer`, so a plain PHPUnit harness cannot answer the question at all.
 */
class MappedNameProjectionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function schema(string $mode, string $class = MappedNameData::class): array
    {
        return (new JsonSchemaGenerator)
            ->schemaMode($mode)
            ->generate(new ReflectionClass($class));
    }

    public function test_spatie_already_knows_the_mapped_names(): void
    {
        // The premise, pinned rather than asserted in prose: nothing here needs a new
        // annotation, a new attribute or a second parse of the class. The answer is already
        // on spatie's own property model — which is the whole reason this is a projection
        // fix and not a wire-vocabulary change.
        $properties = $this->app->make(DataConfig::class)
            ->getDataClass(MappedNameData::class)
            ->properties;

        $this->assertSame('source_type', $properties['sourceType']->inputMappedName);
        $this->assertNull($properties['sourceType']->outputMappedName);
        $this->assertSame('rendered_at', $properties['renderedAt']->outputMappedName);
        $this->assertNull($properties['renderedAt']->inputMappedName);
        $this->assertSame('both_in', $properties['bothWays']->inputMappedName);
        $this->assertSame('both_out', $properties['bothWays']->outputMappedName);
        $this->assertNull($properties['plain']->inputMappedName);
    }

    public function test_the_request_schema_publishes_the_input_mapped_name(): void
    {
        $schema = $this->schema('request');

        $this->assertArrayHasKey('source_type', $schema['properties']);
        $this->assertArrayNotHasKey('sourceType', $schema['properties']);

        $this->assertArrayHasKey('both_in', $schema['properties']);
        $this->assertArrayNotHasKey('both_out', $schema['properties']);
    }

    public function test_required_is_keyed_by_the_wire_name_too(): void
    {
        // A fix that re-keyed `properties` and left `required` alone would publish a
        // mandatory field that appears nowhere in the document.
        $this->assertContains('source_type', $this->schema('request')['required'] ?? []);
        $this->assertNotContains('sourceType', $this->schema('request')['required'] ?? []);
    }

    public function test_an_output_only_mapping_does_not_leak_onto_the_request_axis(): void
    {
        $schema = $this->schema('request');

        // `renderedAt` has no INPUT mapping, so hydration keys on the property name and
        // the request schema must say so — the output mapping is none of its business.
        $this->assertArrayHasKey('renderedAt', $schema['properties']);
        $this->assertArrayNotHasKey('rendered_at', $schema['properties']);
    }

    public function test_the_response_schema_publishes_the_output_mapped_name(): void
    {
        $schema = $this->schema('response');

        $this->assertArrayHasKey('rendered_at', $schema['properties']);
        $this->assertArrayNotHasKey('renderedAt', $schema['properties']);

        $this->assertArrayHasKey('both_out', $schema['properties']);
        $this->assertArrayNotHasKey('both_in', $schema['properties']);

        // No output mapping means spatie serializes on the property name.
        $this->assertArrayHasKey('sourceType', $schema['properties']);
        $this->assertArrayNotHasKey('source_type', $schema['properties']);
    }

    public function test_an_unmapped_property_is_untouched_on_every_axis(): void
    {
        foreach (['request', 'response', 'collapsed', 'llm_strict'] as $mode) {
            $this->assertArrayHasKey('plain', $this->schema($mode)['properties'], $mode);
        }
    }

    public function test_collapsed_and_llm_strict_use_the_output_mapped_name(): void
    {
        // The ruled change (owner, root 805623d0): collapsed is the STORED shape and storage
        // writes the output name, so the collapsed key must be the output name too. llm_strict
        // describes what a provider emits — also the output name. Where a property has no output
        // mapping (`sourceType` is input-only) both modes fall back to the PHP name, exactly as
        // spatie serializes it.
        foreach (['collapsed', 'llm_strict'] as $mode) {
            $properties = $this->schema($mode)['properties'];

            // Output-mapped properties take the output name.
            $this->assertArrayHasKey('rendered_at', $properties, $mode);
            $this->assertArrayNotHasKey('renderedAt', $properties, $mode);
            $this->assertArrayHasKey('both_out', $properties, $mode);
            $this->assertArrayNotHasKey('both_in', $properties, $mode);

            // Input-only mapping does not reach the output axis: no output name, so the PHP name.
            $this->assertArrayHasKey('sourceType', $properties, $mode);
            $this->assertArrayNotHasKey('source_type', $properties, $mode);
        }
    }

    public function test_map_name_keys_every_mode_the_same(): void
    {
        // `#[MapName('max_depth')]` sets both the input and the output name, so there is no axis
        // on which the PHP name `maxDepth` survives.
        foreach (['request', 'response', 'collapsed', 'llm_strict'] as $mode) {
            $properties = $this->schema($mode, MapNameBothData::class)['properties'];

            $this->assertArrayHasKey('max_depth', $properties, $mode);
            $this->assertArrayNotHasKey('maxDepth', $properties, $mode);
        }
    }

    public function test_a_class_level_mapper_snakes_every_mode(): void
    {
        // A class-level `#[MapName(SnakeCaseMapper::class)]` resolves both axes through the mapper,
        // so every camel property answers to its snake name in and out, on every mode.
        foreach (['request', 'response', 'collapsed', 'llm_strict'] as $mode) {
            $properties = $this->schema($mode, ClassSnakeMappedData::class)['properties'];

            $this->assertArrayHasKey('max_depth', $properties, $mode);
            $this->assertArrayHasKey('source_url', $properties, $mode);
            $this->assertArrayNotHasKey('maxDepth', $properties, $mode);
            $this->assertArrayNotHasKey('sourceUrl', $properties, $mode);
        }
    }

    public function test_the_global_input_strategy_reaches_unmapped_properties(): void
    {
        // No attribute anywhere — only the host's global strategy. With the flagship's
        // input => CamelCaseMapper, output => null, a snake property keys camel on the request
        // (input) axis and keeps snake everywhere else (no output mapper).
        config(['data.name_mapping_strategy' => ['input' => CamelCaseMapper::class, 'output' => null]]);
        $this->app->forgetInstance(DataStructureCache::class);
        $this->app->forgetInstance(DataConfig::class);

        $request = $this->schema('request', GlobalStrategyData::class)['properties'];
        $this->assertArrayHasKey('maxDepth', $request);
        $this->assertArrayHasKey('sourceUrl', $request);
        $this->assertArrayNotHasKey('max_depth', $request);

        foreach (['response', 'collapsed', 'llm_strict'] as $mode) {
            $properties = $this->schema($mode, GlobalStrategyData::class)['properties'];
            $this->assertArrayHasKey('max_depth', $properties, $mode);
            $this->assertArrayNotHasKey('maxDepth', $properties, $mode);
        }
    }

    public function test_the_input_projection_reaches_a_nested_def(): void
    {
        $schema = $this->schema('request', MappedNameHostData::class);

        $nested = $schema['$defs']['MappedNameData']['properties'] ?? [];

        $this->assertArrayHasKey('source_type', $nested);
        $this->assertArrayNotHasKey('sourceType', $nested);
        $this->assertContains('source_type', $schema['$defs']['MappedNameData']['required'] ?? []);
    }

    public function test_the_output_projection_reaches_a_nested_def(): void
    {
        // `ensureDef()` recurses with the generator's own mode, so a nested `$defs` entry must
        // carry the same OUTPUT wire names as the root on the stored/collapsed axis — otherwise
        // every nested stored shape keeps the defect at depth 1 while looking fixed at depth 0.
        $schema = $this->schema('collapsed', MappedNameHostData::class);

        $nested = $schema['$defs']['MappedNameData']['properties'] ?? [];

        $this->assertArrayHasKey('rendered_at', $nested);
        $this->assertArrayNotHasKey('renderedAt', $nested);
        $this->assertArrayHasKey('both_out', $nested);
    }

    public function test_a_structure_cache_failure_cannot_silently_publish_raw_property_names(): void
    {
        $this->app['env'] = 'local';
        config([
            'cache.stores.throwing' => ['driver' => 'throwing'],
            'data.structure_caching.enabled' => true,
            'data.structure_caching.cache.store' => 'throwing',
        ]);
        Cache::extend('throwing', function () {
            return new Repository(new class extends ArrayStore
            {
                public function get($key): never
                {
                    throw new RuntimeException('Structure cache store [throwing] is unavailable.');
                }
            });
        });

        try {
            $schema = $this->schema('request');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(MappedNameData::class.'::$sourceType', $exception->getMessage());
            $this->assertStringContainsString(RuntimeException::class, $exception->getMessage());
            $this->assertStringContainsString('Structure cache store [throwing] is unavailable.', $exception->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());

            return;
        }

        $this->fail(sprintf(
            'The cache failure was swallowed; the schema silently published [%s].',
            implode(', ', array_keys($schema['properties'])),
        ));
    }

    public function test_a_database_structure_cache_can_be_cold_before_its_table_exists(): void
    {
        $this->app['env'] = 'local';
        config([
            'cache.stores.fresh_database' => [
                'driver' => 'database',
                'connection' => config('database.default'),
                'table' => 'cache',
            ],
            'data.structure_caching.enabled' => true,
            'data.structure_caching.cache.store' => 'fresh_database',
        ]);
        $this->app->forgetInstance(DataStructureCache::class);
        $this->app->forgetInstance(DataConfig::class);

        $schema = $this->schema('request');

        $this->assertArrayHasKey('source_type', $schema['properties']);
        $this->assertArrayNotHasKey('sourceType', $schema['properties']);
    }

    // --- Round-trip: the ruling is "each schema key must equal the toArray()/from() key". These
    // build a real instance and bind the SCHEMA keys to spatie's actual toArray()/from() keys,
    // rather than asserting hard-coded strings. ---

    /** @param array<int,string> $expected @param array<int,string> $actual */
    private function assertSameKeySet(array $expected, array $actual, string $msg = ''): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, $msg);
    }

    public function test_round_trip_input_output_mapping(): void
    {
        $dto = new MappedNameData(sourceType: 'a', renderedAt: 'b', bothWays: 'c', plain: 'd');

        // collapsed and response keys == what toArray() actually emits.
        foreach (['collapsed', 'response'] as $mode) {
            $this->assertSameKeySet(array_keys($dto->toArray()), array_keys($this->schema($mode)['properties']), $mode);
        }

        // a payload keyed by the REQUEST schema hydrates through from() with no lost values.
        $payload = ['source_type' => 'a', 'renderedAt' => 'b', 'both_in' => 'c', 'plain' => 'd'];
        $this->assertSameKeySet(array_keys($payload), array_keys($this->schema('request')['properties']));
        $back = MappedNameData::from($payload);
        $this->assertSame('a', $back->sourceType);
        $this->assertSame('b', $back->renderedAt);
        $this->assertSame('c', $back->bothWays);
        $this->assertSame('d', $back->plain);
    }

    public function test_round_trip_map_name(): void
    {
        $dto = new MapNameBothData(maxDepth: 7);

        foreach (['collapsed', 'response'] as $mode) {
            $this->assertSameKeySet(array_keys($dto->toArray()), array_keys($this->schema($mode, MapNameBothData::class)['properties']), $mode);
        }

        $payload = ['max_depth' => 7];
        $this->assertSameKeySet(array_keys($payload), array_keys($this->schema('request', MapNameBothData::class)['properties']));
        $this->assertSame(7, MapNameBothData::from($payload)->maxDepth);
    }

    public function test_round_trip_class_level_mapper(): void
    {
        $dto = new ClassSnakeMappedData(maxDepth: 3, sourceUrl: 'x');

        foreach (['collapsed', 'response'] as $mode) {
            $this->assertSameKeySet(array_keys($dto->toArray()), array_keys($this->schema($mode, ClassSnakeMappedData::class)['properties']), $mode);
        }

        $payload = ['max_depth' => 3, 'source_url' => 'x'];
        $this->assertSameKeySet(array_keys($payload), array_keys($this->schema('request', ClassSnakeMappedData::class)['properties']));
        $back = ClassSnakeMappedData::from($payload);
        $this->assertSame(3, $back->maxDepth);
        $this->assertSame('x', $back->sourceUrl);
    }

    public function test_round_trip_reaches_a_nested_def(): void
    {
        $dto = new MappedNameHostData(payload: new MappedNameData(sourceType: 'a', renderedAt: 'b', bothWays: 'c', plain: 'd'));

        // nested collapsed $def keys == what the nested toArray() emits.
        $nested = $this->schema('collapsed', MappedNameHostData::class)['$defs']['MappedNameData']['properties'] ?? [];
        $this->assertSameKeySet(array_keys($dto->toArray()['payload']), array_keys($nested));

        // a request-keyed nested payload hydrates the nested Data with no lost values.
        $back = MappedNameHostData::from(['payload' => ['source_type' => 'a', 'renderedAt' => 'b', 'both_in' => 'c', 'plain' => 'd']]);
        $this->assertSame('a', $back->payload->sourceType);
        $this->assertSame('b', $back->payload->renderedAt);
        $this->assertSame('c', $back->payload->bothWays);
        $this->assertSame('d', $back->payload->plain);
    }

    public function test_a_global_output_strategy_reaches_unmapped_properties(): void
    {
        // Item 2 of the review: output-strategy case (not output => null). RED on the parent, which
        // kept the PHP name on collapsed regardless of the output strategy.
        config(['data.name_mapping_strategy' => ['input' => null, 'output' => SnakeCaseMapper::class]]);
        $this->app->forgetInstance(DataStructureCache::class);
        $this->app->forgetInstance(DataConfig::class);

        $dto = new GlobalOutputStrategyData(maxDepth: 3, sourceUrl: 'x');

        foreach (['collapsed', 'response'] as $mode) {
            $props = $this->schema($mode, GlobalOutputStrategyData::class)['properties'];
            $this->assertArrayHasKey('max_depth', $props, $mode);
            $this->assertArrayHasKey('source_url', $props, $mode);
            $this->assertArrayNotHasKey('maxDepth', $props, $mode);
            $this->assertSameKeySet(array_keys($dto->toArray()), array_keys($props), $mode);
        }

        // input => null, so the request axis keeps the PHP name and from() hydrates on it.
        $req = $this->schema('request', GlobalOutputStrategyData::class)['properties'];
        $this->assertArrayHasKey('maxDepth', $req);
        $this->assertArrayNotHasKey('max_depth', $req);
        $this->assertSame(3, GlobalOutputStrategyData::from(['maxDepth' => 3, 'sourceUrl' => 'x'])->maxDepth);
    }

    public function test_without_a_booted_container_every_mode_falls_back_to_php_names(): void
    {
        // Item 3: spatieProperty() returns null with no booted application, so every mode — collapsed
        // included — falls back to the PHP name. Collapsed now feeds fingerprints/frozen artifacts, so
        // this fallback is standalone-use only, NOT a resolved contract. Pinned so nobody mistakes it.
        $saved = Container::getInstance();
        Container::setInstance(new Container); // bare container: not an Application, no `config` binding
        try {
            foreach (['request', 'response', 'collapsed', 'llm_strict'] as $mode) {
                $props = (new JsonSchemaGenerator)->schemaMode($mode)->generate(new ReflectionClass(MappedNameData::class))['properties'];

                $this->assertArrayHasKey('renderedAt', $props, $mode);
                $this->assertArrayNotHasKey('rendered_at', $props, $mode);
                $this->assertArrayHasKey('bothWays', $props, $mode);
                $this->assertArrayNotHasKey('both_out', $props, $mode);
                $this->assertArrayNotHasKey('both_in', $props, $mode);
            }
        } finally {
            Container::setInstance($saved);
        }
    }
}
