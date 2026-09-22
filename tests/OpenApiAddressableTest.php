<?php

namespace Schemastud\DataSchemas\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Support\OpenApi;

class OpenApiAddressableTest extends TestCase
{
    public function test_hoists_bundled_schema_identities_and_rewrites_self_references(): void
    {
        $id = 'https://app.splicewire.com/schemas/taxonomy/silo/1';
        $document = [
            'properties' => ['data' => ['$ref' => $id]],
            '$defs' => [$id => [
                '$id' => $id, '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'title' => 'SiloData', 'type' => 'object',
                'properties' => ['parent' => ['$ref' => $id], '$id' => ['type' => 'string']],
            ]],
        ];
        $converted = OpenApi::toOpenApiComponents($document);
        $name = array_key_first($converted['components']['schemas']);
        $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
        $this->assertSame('#/components/schemas/'.$name, $converted['properties']['data']['$ref']);
        $this->assertSame('#/components/schemas/'.$name, $converted['components']['schemas'][$name]['properties']['parent']['$ref']);
        $this->assertArrayHasKey('$id', $converted['components']['schemas'][$name]['properties']);
        $this->assertArrayNotHasKey('$id', $converted['components']['schemas'][$name]);
        $this->assertArrayNotHasKey('$schema', $converted['components']['schemas'][$name]);
    }

    public function test_reuses_a_named_component_for_the_same_bundled_identity(): void
    {
        $id = 'https://example.test/silo/1';
        $definition = ['$id' => $id, 'title' => 'SiloData', 'type' => 'object'];
        $converted = OpenApi::toOpenApiComponents([
            'properties' => ['data' => ['$ref' => $id], 'other' => ['$ref' => '#/$defs/SiloData']],
            '$defs' => ['SiloData' => ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + $definition, $id => $definition],
        ]);
        $this->assertCount(1, $converted['components']['schemas']);
        $this->assertArrayHasKey('SiloData', $converted['components']['schemas']);
        $this->assertSame('#/components/schemas/SiloData', $converted['properties']['data']['$ref']);
        $this->assertSame('#/components/schemas/SiloData', $converted['properties']['other']['$ref']);
    }

    public function test_rejects_distinct_identities_claiming_the_same_component_title(): void
    {
        $first = 'https://example.test/first/1';
        $second = 'https://example.test/second/1';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('component name collision');
        OpenApi::toOpenApiComponents(['$defs' => [
            $first => ['$id' => $first, 'title' => 'SharedData', 'type' => 'object'],
            $second => ['$id' => $second, 'title' => 'SharedData', 'type' => 'object'],
        ]]);
    }

    public function test_maps_local_pointers_and_declared_ids_without_rewriting_external_references(): void
    {
        $id = 'https://example.test/address/1';
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $id);
        $converted = OpenApi::toOpenApiComponents([
            'properties' => [
                'local' => ['$ref' => '#/$defs/'.$escaped],
                'external' => ['$ref' => 'https://elsewhere.test/other/1'],
            ],
            '$defs' => [$id => ['$id' => $id, 'title' => 'AddressData', 'type' => 'object']],
        ]);
        $name = array_key_first($converted['components']['schemas']);
        $this->assertSame('#/components/schemas/'.$name, $converted['properties']['local']['$ref']);
        $this->assertSame('https://elsewhere.test/other/1', $converted['properties']['external']['$ref']);
    }

    public function test_refuses_a_generated_component_name_that_collides_with_an_existing_definition(): void
    {
        $id = 'https://example.test/address/1';
        $definition = ['$id' => $id, 'title' => 'AddressData', 'type' => 'object'];
        $converted = OpenApi::toOpenApiComponents(['$defs' => [$id => $definition]]);
        $name = array_key_first($converted['components']['schemas']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('component name collision');
        OpenApi::toOpenApiComponents(['$defs' => [
            $name => ['type' => 'string'], $id => $definition,
        ]]);
    }
}
