<?php

use Opis\JsonSchema\Validator;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\ClassUnionData;

require_once __DIR__.'/Fixtures/ClassUnionData.php';

it('preserves both DTO alternatives and their definitions', function () {
    $schema = (new JsonSchemaGenerator)->forResponse()->generate(new ReflectionClass(ClassUnionData::class));
    expect($schema['properties']['choice'])->toBe(['anyOf' => [
        ['$ref' => '#/$defs/UnionLeftData'], ['$ref' => '#/$defs/UnionRightData'],
    ]]);
    expect(array_keys($schema['$defs']))->toContain('UnionLeftData', 'UnionRightData');
});

it('keeps optional lazy and nullable semantics around a DTO union', function () {
    $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(ClassUnionData::class));
    expect($schema['properties']['optional']['x-optional'])->toBeTrue();
    expect($schema['properties']['lazy'])->toHaveKeys(['anyOf', 'readOnly', 'x-lazy']);
    expect($schema['required'])->toContain('choice', 'nullable')->not->toContain('optional', 'lazy');
    expect($schema['properties']['nullable']['anyOf'])->toContain(['type' => 'null']);
    expect($schema['properties']['single'])->toBe([
        'anyOf' => [['$ref' => '#/$defs/UnionLeftData'], ['type' => 'null']], 'readOnly' => true, 'x-lazy' => true,
    ]);
});

it('retains built-in alternatives beside references and existing scalar unions', function () {
    $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(ClassUnionData::class));
    expect($schema['properties']['scalar'])->toBe(['anyOf' => [
        ['$ref' => '#/$defs/UnionLeftData'], ['type' => 'string'],
    ]]);
    expect($schema['properties']['builtin']['type'])->toEqualCanonicalizing(['integer', 'string', 'null']);
});

it('accepts either overlapping DTO shape and null while rejecting unrelated values', function () {
    $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(ClassUnionData::class));
    $property = $schema['properties']['nullable'] + ['$defs' => $schema['$defs']];
    $validator = new Validator;
    $document = json_decode(json_encode($property));
    foreach ([(object) ['id' => 'a'], (object) ['name' => 'b'], (object) ['id' => 'a', 'name' => 'b'], null] as $value) {
        expect($validator->validate($value, $document)->isValid())->toBeTrue();
    }
    expect($validator->validate(42, $document)->isValid())->toBeFalse();
});

it('makes optional unions nullable in strict mode without excluding their object alternatives', function () {
    $schema = (new JsonSchemaGenerator)->forLlmStrict()->generate(new ReflectionClass(ClassUnionData::class));
    $property = $schema['properties']['optional'];
    expect($property)->not->toHaveKey('type');
    expect($property['anyOf'])->toContain(['type' => 'null']);
    expect($schema['required'])->toContain('optional');
    $validator = new Validator;
    $document = json_decode(json_encode($property + ['$defs' => $schema['$defs']]));
    expect($validator->validate(null, $document)->isValid())->toBeTrue();
    expect($validator->validate((object) ['id' => 'a'], $document)->isValid())->toBeTrue();
});
