<?php

namespace Schemastud\DataSchemas\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\SelfParentNodeData;

require_once __DIR__.'/Fixtures/SelfParentData.php';

/**
 * A `self` or `parent` property type is a class, resolved against the class that declares the property. It used to fall
 * through to "unknown class, degrade to string", so a `?self` tree node projected as `string|null` and every object a
 * consumer sent there was refused (laravel-beam config-sections F2: such a Config Section could not be saved at all).
 */
class SelfParentSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return (new JsonSchemaGenerator)->generate(new ReflectionClass(SelfParentNodeData::class));
    }

    public function test_self_and_parent_project_as_references_to_the_declaring_class_and_its_parent(): void
    {
        $schema = $this->schema();

        $this->assertSame(['anyOf' => [['$ref' => '#/$defs/SelfParentNodeData'], ['type' => 'null']]], $schema['properties']['child']);
        $this->assertSame(['anyOf' => [['$ref' => '#/$defs/SelfParentBaseData'], ['type' => 'null']]], $schema['properties']['base']);
        $this->assertArrayHasKey('SelfParentBaseData', $schema['$defs']);
        $this->assertArrayHasKey('SelfParentNodeData', $schema['$defs'], 'the self-reference has its own definition');
    }

    public function test_a_self_member_of_a_union_is_a_reference_beside_the_builtin(): void
    {
        $this->assertSame(['anyOf' => [['$ref' => '#/$defs/SelfParentNodeData'], ['type' => 'string'], ['type' => 'null']]], $this->schema()['properties']['either']);
    }

    public function test_a_nested_node_validates_and_a_string_where_a_node_belongs_does_not(): void
    {
        $document = json_decode((string) json_encode($this->schema()));
        $validator = new Validator;

        // The generator lists every property as required (defaulted and inherited ones too), so each node is complete:
        // the only thing these two documents differ in is what sits at the `self` property.
        $node = fn (mixed $child): object => (object) ['name' => 'n', 'child' => $child, 'base' => null, 'either' => null, 'label' => ''];
        $tree = $node($node(null));
        $tree->base = (object) ['label' => 'b'];
        $this->assertTrue($validator->validate($tree, $document)->isValid(), 'an object at a self/parent property');
        $this->assertFalse($validator->validate($node('leaf'), $document)->isValid(), 'not a string');
    }
}
