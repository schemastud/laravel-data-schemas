<?php

use Opis\JsonSchema\Validator;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;

it('validates nullable Data references without admitting malformed nonnull values', function (string $mode) {
    $schema = (new JsonSchemaGenerator)->schemaMode($mode)->generate(new ReflectionClass(NullableReferencePageData::class));
    $document = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    $validator = new Validator;

    expect($validator->validate((object) ['entry' => null], $document)->isValid())->toBeTrue();
    expect($validator->validate((object) ['entry' => (object) ['title' => 'Profile']], $document)->isValid())->toBeTrue();
    expect($validator->validate((object) ['entry' => (object) ['title' => 42]], $document)->isValid())->toBeFalse();
    expect($validator->validate((object) ['entry' => (object) []], $document)->isValid())->toBeFalse();
    expect($validator->validate((object) ['entry' => 'Profile'], $document)->isValid())->toBeFalse();
    expect($validator->validate((object) [], $document)->isValid())->toBeFalse();
})->with(['collapsed', 'request', 'response', 'llm_strict']);

it('keeps nonnullable Data references required and rejects null', function (string $mode) {
    $schema = (new JsonSchemaGenerator)->schemaMode($mode)->generate(new ReflectionClass(RequiredReferencePageData::class));
    $document = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    $validator = new Validator;

    expect($validator->validate((object) ['entry' => (object) ['title' => 'Profile']], $document)->isValid())->toBeTrue();
    expect($validator->validate((object) ['entry' => null], $document)->isValid())->toBeFalse();
    expect($validator->validate((object) ['entry' => (object) ['title' => 42]], $document)->isValid())->toBeFalse();
    expect($validator->validate((object) [], $document)->isValid())->toBeFalse();
})->with(['collapsed', 'request', 'response', 'llm_strict']);

class NullableReferenceEntryData extends Data
{
    public function __construct(public string $title) {}
}

class NullableReferencePageData extends Data
{
    public function __construct(public ?NullableReferenceEntryData $entry) {}
}

class RequiredReferencePageData extends Data
{
    public function __construct(public NullableReferenceEntryData $entry) {}
}
