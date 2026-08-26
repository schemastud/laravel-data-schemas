<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\AnnotatedMapData;
use Schemastud\DataSchemas\Tests\Fixtures\ContentOutlineItemData;
use Schemastud\DataSchemas\Tests\Fixtures\EnumArrayData;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;
use Schemastud\DataSchemas\Tests\Fixtures\ScalarArrayData;
use Schemastud\DataSchemas\Tests\Fixtures\StringMapData;
use Schemastud\DataSchemas\Tests\Fixtures\TitledData;
use Schemastud\DataSchemas\Tests\Fixtures\UploadData;

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
                ],
                'nickname' => [
                    'type' => 'string',
                    'x-optional' => true,
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
                        'id' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
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

    public function test_it_projects_a_string_keyed_map_as_an_object_with_a_declared_value_type(): void
    {
        // api-surface-coherence 75. PHP types a list and a map identically (`array`), so the
        // generator had no signal and published every map as `type: array` — a lie about the
        // wire that types a generated client `unknown[]` and gets the schema rejected outright
        // by a strict structured-output provider. `additionalProperties` is the map's keyword;
        // `items` is the list's, and the generator had a slot for only one of them.
        $schema = $this->generate(StringMapData::class);

        $this->assertEquals('object', $schema['properties']['headers']['type']);
        $this->assertEquals(['type' => 'string'], $schema['properties']['headers']['additionalProperties']);
        $this->assertArrayNotHasKey('items', $schema['properties']['headers']);

        // The list next to it is untouched — the two declarations do not bleed.
        $this->assertEquals('array', $schema['properties']['tags']['type']);
        $this->assertEquals(['type' => 'string'], $schema['properties']['tags']['items']);
        $this->assertArrayNotHasKey('additionalProperties', $schema['properties']['tags']);
    }

    public function test_a_map_of_data_objects_refs_its_value_type(): void
    {
        $schema = $this->generate(StringMapData::class);

        $this->assertEquals(['object', 'null'], $schema['properties']['records']['type']);
        $this->assertEquals(
            ['$ref' => '#/$defs/SampleData'],
            $schema['properties']['records']['additionalProperties'],
        );
        $this->assertArrayHasKey('SampleData', $schema['$defs']);
    }

    public function test_a_map_of_enum_values_inlines_them_like_array_items_does(): void
    {
        $schema = $this->generate(StringMapData::class);

        $this->assertEquals(
            ['type' => 'string', 'enum' => ['draft', 'published']],
            $schema['properties']['statuses']['additionalProperties'],
        );
    }

    public function test_a_bare_map_values_declares_object_without_a_value_type(): void
    {
        // Measured over the estate, 49 of 55 map-shaped properties are `array<string, mixed>` —
        // the value type genuinely is not declarable. A required type argument would have left
        // the array/object lie standing on 89% of the population.
        $schema = $this->generate(StringMapData::class);

        $this->assertEquals('object', $schema['properties']['meta']['type']);
        $this->assertArrayNotHasKey('additionalProperties', $schema['properties']['meta']);
    }

    public function test_the_docblock_generic_alone_projects_a_map_without_any_attribute(): void
    {
        // The measurement that reframed this ticket. spatie ALREADY parses `array<string, T>` —
        // DataIterableAnnotationReader fills keyType/type — and the TypeScript transformer reads
        // that same parse to emit `Record<string, string>`. The generator kept a second, blind
        // copy of the property model and saw only PHP's bare `array`, so one declaration produced
        // a correct .d.ts and a wrong schema. Asking spatie closes it for every annotated map at
        // once; #[MapValues] is now the override, not the mechanism.
        //
        // These are PROMOTED properties, so the annotations are on the constructor, not the
        // property — reading only `ReflectionProperty::getDocComment()` would find nothing.
        $schema = $this->generate(AnnotatedMapData::class);

        $this->assertEquals('object', $schema['properties']['headers']['type']);
        $this->assertEquals(['type' => 'string'], $schema['properties']['headers']['additionalProperties']);

        // array<string, mixed>: a map, values unconstrained. The map-ness is still declarable.
        $this->assertEquals('object', $schema['properties']['meta']['type']);
        $this->assertArrayNotHasKey('additionalProperties', $schema['properties']['meta']);

        // A list is untouched — and still needs #[ArrayItems] for its `items`, which is a
        // separate redundancy this ticket deliberately does not disturb.
        $this->assertEquals('array', $schema['properties']['tags']['type']);

        $this->assertEquals(
            ['$ref' => '#/$defs/SampleData'],
            $schema['properties']['records']['additionalProperties'],
        );
    }

    public function test_the_strict_pass_and_the_map_declaration_do_not_fight_over_additional_properties(): void
    {
        // Same keyword NAME, different schemas: `additionalProperties: false` is written on the
        // enclosing object in buildObjectSchema(), the map's value type on the property's own
        // sub-schema. makeNullable() only rewrites `type`, so a nullable map keeps its value
        // declaration through the strict pass too.
        $schema = (new JsonSchemaGenerator)->forLlmStrict()->generate(new ReflectionClass(StringMapData::class));

        $this->assertFalse($schema['additionalProperties']);
        $this->assertEquals(['type' => 'string'], $schema['properties']['headers']['additionalProperties']);
        $this->assertEquals(
            ['$ref' => '#/$defs/SampleData'],
            $schema['properties']['records']['additionalProperties'],
        );
        $this->assertContains('null', (array) $schema['properties']['records']['type']);
    }

    public function test_for_llm_strict_does_not_emit_root_metadata(): void
    {
        $schema = (new JsonSchemaGenerator(['schema_metadata' => ['$schema' => true, '$id' => true]]))
            ->forLlmStrict()
            ->generate(new ReflectionClass(SampleData::class));

        $this->assertArrayNotHasKey('$schema', $schema);
        $this->assertArrayNotHasKey('$id', $schema);
    }

    public function test_it_maps_an_uploaded_file_property_to_a_binary_string(): void
    {
        $schema = $this->generate(UploadData::class);

        // The file leaf keeps `type: string` but is tagged `format: binary`,
        // upstream of the unknown-class string-degrade catch-all.
        $this->assertSame('string', $schema['properties']['file']['type']);
        $this->assertSame('binary', $schema['properties']['file']['format']);

        // A non-file leaf is untouched — no spurious binary format.
        $this->assertArrayNotHasKey('format', $schema['properties']['caption']);
    }
}
