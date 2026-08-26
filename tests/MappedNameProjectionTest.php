<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\MappedNameData;
use Schemastud\DataSchemas\Tests\Fixtures\MappedNameHostData;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;

/**
 * api-surface-coherence 54 — the schema must name the key the wire actually carries.
 *
 * The same shape as 70/31 one axis over, and the same argument: **spatie already computes
 * this fact and the generator kept a second, worse copy of it.** `DataProperty` carries
 * `inputMappedName` and `outputMappedName` — resolved from `#[MapInputName]`,
 * `#[MapOutputName]`, `#[MapName]` AND the host's global `name_mapping_strategy` — and the
 * generator ignored all of it, keying `properties` and `required` on the raw PHP property
 * name. So the published contract named a key the server does not accept, the SDK generated
 * from that contract sent it, and the part was silently dropped at both ends.
 *
 * Booted through Testbench with spatie's provider deliberately: `DataConfig::getDataClass()`
 * goes through `DataContainer`, so a plain PHPUnit harness cannot answer the question at all.
 *
 * Scope is the two axes that HAVE an axis. `collapsed` and `llm_strict` keep the PHP property
 * name: a mapped name is an input-or-output fact, and those two modes are neither — collapsed
 * schemas are the stored/registry/migration-ladder shape (`Migration\Rungs\*` read
 * `$request->to['properties']` by key), and re-keying them would rewrite fingerprints and
 * migration mappings for a question they never asked.
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

    public function test_collapsed_and_llm_strict_keep_the_php_property_name(): void
    {
        // Deliberate, not an oversight — see the class docblock. Pinned so a later sweep
        // that "finishes the job" has to argue with this test first.
        foreach (['collapsed', 'llm_strict'] as $mode) {
            $properties = $this->schema($mode)['properties'];

            $this->assertArrayHasKey('sourceType', $properties, $mode);
            $this->assertArrayNotHasKey('source_type', $properties, $mode);
            $this->assertArrayHasKey('renderedAt', $properties, $mode);
        }
    }

    public function test_the_projection_reaches_a_nested_def(): void
    {
        $schema = $this->schema('request', MappedNameHostData::class);

        $nested = $schema['$defs']['MappedNameData']['properties'] ?? [];

        $this->assertArrayHasKey('source_type', $nested);
        $this->assertArrayNotHasKey('sourceType', $nested);
        $this->assertContains('source_type', $schema['$defs']['MappedNameData']['required'] ?? []);
    }
}
