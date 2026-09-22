<?php

namespace Schemastud\DataSchemas\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\ClassUnionData;

require_once __DIR__.'/Fixtures/ClassUnionData.php';

class ClassUnionSchemaTest extends TestCase
{
    public function test_preserves_both_dto_alternatives_and_their_definitions(): void
    {
        $schema = (new JsonSchemaGenerator)->forResponse()->generate(new ReflectionClass(ClassUnionData::class));
        $this->assertSame(['anyOf' => [
            ['$ref' => '#/$defs/UnionLeftData'], ['$ref' => '#/$defs/UnionRightData'],
        ]], $schema['properties']['choice']);
        $this->assertArrayHasKey('UnionLeftData', $schema['$defs']);
        $this->assertArrayHasKey('UnionRightData', $schema['$defs']);
    }

    public function test_keeps_optional_lazy_and_nullable_semantics_around_a_dto_union(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(ClassUnionData::class));
        $this->assertTrue($schema['properties']['optional']['x-optional']);
        foreach (['anyOf', 'readOnly', 'x-lazy'] as $key) {
            $this->assertArrayHasKey($key, $schema['properties']['lazy']);
        }
        foreach (['choice', 'nullable'] as $key) {
            $this->assertContains($key, $schema['required']);
        }
        foreach (['optional', 'lazy'] as $key) {
            $this->assertNotContains($key, $schema['required']);
        }
        $this->assertContains(['type' => 'null'], $schema['properties']['nullable']['anyOf']);
        $this->assertSame([
            'anyOf' => [['$ref' => '#/$defs/UnionLeftData'], ['type' => 'null']], 'readOnly' => true, 'x-lazy' => true,
        ], $schema['properties']['single']);
    }

    public function test_retains_built_in_alternatives_beside_references_and_existing_scalar_unions(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(ClassUnionData::class));
        $this->assertSame(['anyOf' => [
            ['$ref' => '#/$defs/UnionLeftData'], ['type' => 'string'],
        ]], $schema['properties']['scalar']);
        $this->assertEqualsCanonicalizing(['integer', 'string', 'null'], $schema['properties']['builtin']['type']);
    }

    public function test_accepts_either_overlapping_dto_shape_and_null_while_rejecting_unrelated_values(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(ClassUnionData::class));
        $property = $schema['properties']['nullable'] + ['$defs' => $schema['$defs']];
        $validator = new Validator;
        $document = json_decode(json_encode($property));
        foreach ([(object) ['id' => 'a'], (object) ['name' => 'b'], (object) ['id' => 'a', 'name' => 'b'], null] as $value) {
            $this->assertTrue($validator->validate($value, $document)->isValid());
        }
        $this->assertFalse($validator->validate(42, $document)->isValid());
    }

    public function test_makes_optional_unions_nullable_in_strict_mode_without_excluding_their_object_alternatives(): void
    {
        $schema = (new JsonSchemaGenerator)->forLlmStrict()->generate(new ReflectionClass(ClassUnionData::class));
        $property = $schema['properties']['optional'];
        $this->assertArrayNotHasKey('type', $property);
        $this->assertContains(['type' => 'null'], $property['anyOf']);
        $this->assertContains('optional', $schema['required']);
        $validator = new Validator;
        $document = json_decode(json_encode($property + ['$defs' => $schema['$defs']]));
        $this->assertTrue($validator->validate(null, $document)->isValid());
        $this->assertTrue($validator->validate((object) ['id' => 'a'], $document)->isValid());
    }
}
