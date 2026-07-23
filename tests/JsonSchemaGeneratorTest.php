<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\ContentOutlineItemData;
use Schemastud\DataSchemas\Tests\Fixtures\EnumArrayData;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;
use Schemastud\DataSchemas\Tests\Fixtures\ScalarArrayData;
use Schemastud\DataSchemas\Tests\Fixtures\TitledData;

class JsonSchemaGeneratorTest extends TestCase
{
    private function generate(string $class): array
    {
        return (new JsonSchemaGenerator)->generate(new ReflectionClass($class));
    }

    public function test_it_emits_the_full_golden_schema_for_a_representative_data_class(): void
    {
        $expected = [
            'type' => 'object',
            'title' => 'SampleData',
            'description' => 'A representative resource exercising every mapping rule.',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Human-readable title.',
                    'maxLength' => 255,
                    'examples' => ['string'],
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'examples' => ['user@example.com'],
                ],
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                    'examples' => ['11111111-1111-1111-1111-111111111111'],
                ],
                'bio' => [
                    'type' => ['string', 'null'],
                    'examples' => ['string'],
                ],
                'nickname' => [
                    'type' => 'string',
                    'x-optional' => true,
                    'examples' => ['string'],
                ],
                'user' => [
                    '$ref' => '#/$defs/UserData',
                    'nullable' => true,
                    'readOnly' => true,
                    'x-lazy' => true,
                ],
                'status' => [
                    '$ref' => '#/$defs/StatusEnum',
                ],
                'collaborators' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/$defs/UserData'],
                ],
            ],
            'required' => ['title', 'email', 'uuid', 'bio', 'status', 'collaborators'],
            '$defs' => [
                'UserData' => [
                    'type' => 'object',
                    'title' => 'UserData',
                    'properties' => [
                        'id' => ['type' => 'string', 'examples' => ['string']],
                        'name' => ['type' => 'string', 'examples' => ['string']],
                    ],
                    'required' => ['id', 'name'],
                ],
                'StatusEnum' => [
                    'type' => 'string',
                    'title' => 'StatusEnum',
                    'enum' => ['draft', 'published'],
                ],
            ],
        ];

        $this->assertEquals($expected, $this->generate(SampleData::class));
    }

    public function test_class_level_title_sets_the_root_schema_title(): void
    {
        $schema = $this->generate(TitledData::class);

        // A class-level #[Title] wins over the class short name (which would be 'TitledData').
        $this->assertSame('A Custom Title', $schema['title']);
        $this->assertSame('A resource whose root title comes from a class-level attribute.', $schema['description']);
    }

    public function test_root_title_falls_back_to_the_class_short_name_without_a_title_attribute(): void
    {
        // SampleData carries #[Description] but no class-level #[Title] — the root title stays the class name.
        $this->assertSame('SampleData', $this->generate(SampleData::class)['title']);
    }

    public function test_optional_is_not_required_and_carries_vendor_key(): void
    {
        $schema = $this->generate(SampleData::class);

        $this->assertNotContains('nickname', $schema['required']);
        $this->assertTrue($schema['properties']['nickname']['x-optional']);
        $this->assertSame('string', $schema['properties']['nickname']['type']);
    }

    public function test_lazy_union_is_a_ref_with_readonly_and_x_lazy_and_not_required(): void
    {
        $schema = $this->generate(SampleData::class);
        $user = $schema['properties']['user'];

        $this->assertSame('#/$defs/UserData', $user['$ref']);
        $this->assertTrue($user['nullable']);
        $this->assertTrue($user['readOnly']);
        $this->assertTrue($user['x-lazy']);
        $this->assertNotContains('user', $schema['required']);
        $this->assertArrayNotHasKey('x-optional', $user);
    }

    public function test_plain_nullable_adds_null_to_type_and_stays_required(): void
    {
        $schema = $this->generate(SampleData::class);

        $this->assertSame(['string', 'null'], $schema['properties']['bio']['type']);
        $this->assertContains('bio', $schema['required']);
    }

    public function test_backed_enum_becomes_a_def_with_backing_values(): void
    {
        $schema = $this->generate(SampleData::class);

        $this->assertSame('#/$defs/StatusEnum', $schema['properties']['status']['$ref']);
        $this->assertSame(['draft', 'published'], $schema['$defs']['StatusEnum']['enum']);
        $this->assertSame('string', $schema['$defs']['StatusEnum']['type']);
    }

    public function test_data_collection_of_becomes_array_with_items_ref(): void
    {
        $schema = $this->generate(SampleData::class);
        $collaborators = $schema['properties']['collaborators'];

        $this->assertSame('array', $collaborators['type']);
        $this->assertSame('#/$defs/UserData', $collaborators['items']['$ref']);
    }

    public function test_validation_attributes_map_to_constraints(): void
    {
        $schema = $this->generate(SampleData::class);

        $this->assertSame(255, $schema['properties']['title']['maxLength']);
        $this->assertSame('email', $schema['properties']['email']['format']);
        $this->assertSame('uuid', $schema['properties']['uuid']['format']);
    }

    public function test_example_attribute_overrides_inferred_example(): void
    {
        $schema = $this->generate(SampleData::class);

        $this->assertSame(['11111111-1111-1111-1111-111111111111'], $schema['properties']['uuid']['examples']);
        // No #[Example] => inferred baseline.
        $this->assertSame(['user@example.com'], $schema['properties']['email']['examples']);
    }

    public function test_self_referential_data_resolves_into_defs_and_terminates(): void
    {
        $schema = $this->generate(ContentOutlineItemData::class);

        $this->assertSame(
            '#/$defs/ContentOutlineItemData',
            $schema['properties']['children']['items']['$ref']
        );
        $this->assertArrayHasKey('ContentOutlineItemData', $schema['$defs']);
        $this->assertSame(
            '#/$defs/ContentOutlineItemData',
            $schema['$defs']['ContentOutlineItemData']['properties']['children']['items']['$ref']
        );
    }

    public function test_mode_seam_exists_and_defaults_to_collapsed_output(): void
    {
        $generator = new JsonSchemaGenerator;
        $reflection = new ReflectionClass(SampleData::class);

        $this->assertEquals(
            $generator->generate($reflection),
            $generator->forRequest()->generate($reflection)
        );
        $this->assertEquals(
            $generator->generate($reflection),
            $generator->forResponse()->generate($reflection)
        );
    }

    public function test_for_llm_strict_emits_a_strict_compatible_schema(): void
    {
        $schema = (new JsonSchemaGenerator)->forLlmStrict()->generate(new ReflectionClass(SampleData::class));

        // Every object forbids extra properties and lists every property in `required`.
        $this->assertFalse($schema['additionalProperties']);
        $this->assertEqualsCanonicalizing(
            ['title', 'email', 'uuid', 'bio', 'nickname', 'user', 'status', 'collaborators'],
            $schema['required']
        );

        // Optional properties are made nullable rather than omitted.
        $this->assertEquals(['string', 'null'], $schema['properties']['nickname']['type']);

        // An optional ref becomes anyOf [ref, null].
        $this->assertEquals(
            [['$ref' => '#/$defs/UserData'], ['type' => 'null']],
            $schema['properties']['user']['anyOf']
        );

        // Nested $defs are strict too.
        $this->assertFalse($schema['$defs']['UserData']['additionalProperties']);

        // Keywords strict providers reject are stripped everywhere.
        $json = json_encode($schema);
        foreach (['examples', 'x-optional', 'x-lazy', 'readOnly', 'nullable'] as $keyword) {
            $this->assertStringNotContainsString($keyword, $json);
        }
    }

    public function test_it_emits_items_for_a_scalar_array_via_array_items_attribute(): void
    {
        $schema = $this->generate(ScalarArrayData::class);

        $this->assertEquals('array', $schema['properties']['tags']['type']);
        $this->assertEquals(['type' => 'string'], $schema['properties']['tags']['items']);
    }

    public function test_it_inlines_enum_values_for_an_array_items_enum_class(): void
    {
        $schema = $this->generate(EnumArrayData::class);

        $this->assertEquals('array', $schema['properties']['statuses']['type']);
        $this->assertEquals(
            ['type' => 'string', 'enum' => ['draft', 'published']],
            $schema['properties']['statuses']['items'],
        );
        // Inlined, not a $ref — no enum def is hoisted for ArrayItems item types.
        $this->assertArrayNotHasKey('$defs', $schema);
    }

    public function test_for_llm_strict_does_not_emit_root_metadata(): void
    {
        $schema = (new JsonSchemaGenerator(['schema_metadata' => ['$schema' => true, '$id' => true]]))
            ->forLlmStrict()
            ->generate(new ReflectionClass(SampleData::class));

        $this->assertArrayNotHasKey('$schema', $schema);
        $this->assertArrayNotHasKey('$id', $schema);
    }
}
