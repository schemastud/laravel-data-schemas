<?php

use Schemastud\DataSchemas\Support\OpenApi;

it('hoists bundled schema identities under legal component names and rewrites self references', function () {
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
    expect($name)->toMatch('/^[A-Za-z_][A-Za-z0-9_]*$/')
        ->and($converted['properties']['data']['$ref'])->toBe('#/components/schemas/'.$name)
        ->and($converted['components']['schemas'][$name]['properties']['parent']['$ref'])->toBe('#/components/schemas/'.$name)
        ->and($converted['components']['schemas'][$name]['properties'])->toHaveKey('$id')
        ->and($converted['components']['schemas'][$name])->not->toHaveKeys(['$id', '$schema']);
});

it('reuses a named component for the same bundled identity', function () {
    $id = 'https://example.test/silo/1';
    $definition = ['$id' => $id, 'title' => 'SiloData', 'type' => 'object'];
    $converted = OpenApi::toOpenApiComponents([
        'properties' => ['data' => ['$ref' => $id], 'other' => ['$ref' => '#/$defs/SiloData']],
        '$defs' => ['SiloData' => ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + $definition, $id => $definition],
    ]);
    expect($converted['components']['schemas'])->toHaveCount(1)->toHaveKey('SiloData')
        ->and($converted['properties']['data']['$ref'])->toBe('#/components/schemas/SiloData')
        ->and($converted['properties']['other']['$ref'])->toBe('#/components/schemas/SiloData');
});

it('rejects distinct identities claiming the same component title', function () {
    $first = 'https://example.test/first/1';
    $second = 'https://example.test/second/1';
    expect(fn () => OpenApi::toOpenApiComponents(['$defs' => [
        $first => ['$id' => $first, 'title' => 'SharedData', 'type' => 'object'],
        $second => ['$id' => $second, 'title' => 'SharedData', 'type' => 'object'],
    ]]))->toThrow(InvalidArgumentException::class, 'component name collision');
});

it('maps local definition pointers and declared ids without rewriting external references', function () {
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
    expect($converted['properties']['local']['$ref'])->toBe('#/components/schemas/'.$name)
        ->and($converted['properties']['external']['$ref'])->toBe('https://elsewhere.test/other/1');
});

it('refuses a generated component name that collides with an existing definition', function () {
    $id = 'https://example.test/address/1';
    $definition = ['$id' => $id, 'title' => 'AddressData', 'type' => 'object'];
    $converted = OpenApi::toOpenApiComponents(['$defs' => [$id => $definition]]);
    $name = array_key_first($converted['components']['schemas']);

    expect(fn () => OpenApi::toOpenApiComponents(['$defs' => [
        $name => ['type' => 'string'], $id => $definition,
    ]]))->toThrow(InvalidArgumentException::class, 'component name collision');
});
