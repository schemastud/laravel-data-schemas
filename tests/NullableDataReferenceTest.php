<?php

namespace Schemastud\DataSchemas\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;

class NullableDataReferenceTest extends TestCase
{
    #[DataProvider('schemaModes')]
    public function test_validates_nullable_references_without_admitting_malformed_values(string $mode): void
    {
        $schema = (new JsonSchemaGenerator)->schemaMode($mode)->generate(new ReflectionClass(NullableReferencePageData::class));
        $document = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $validator = new Validator;

        $this->assertTrue($validator->validate((object) ['entry' => null], $document)->isValid());
        $this->assertTrue($validator->validate((object) ['entry' => (object) ['title' => 'Profile']], $document)->isValid());
        $this->assertFalse($validator->validate((object) ['entry' => (object) ['title' => 42]], $document)->isValid());
        $this->assertFalse($validator->validate((object) ['entry' => (object) []], $document)->isValid());
        $this->assertFalse($validator->validate((object) ['entry' => 'Profile'], $document)->isValid());
        $this->assertFalse($validator->validate((object) [], $document)->isValid());
    }

    #[DataProvider('schemaModes')]
    public function test_keeps_nonnullable_references_required_and_rejects_null(string $mode): void
    {
        $schema = (new JsonSchemaGenerator)->schemaMode($mode)->generate(new ReflectionClass(RequiredReferencePageData::class));
        $document = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $validator = new Validator;

        $this->assertTrue($validator->validate((object) ['entry' => (object) ['title' => 'Profile']], $document)->isValid());
        $this->assertFalse($validator->validate((object) ['entry' => null], $document)->isValid());
        $this->assertFalse($validator->validate((object) ['entry' => (object) ['title' => 42]], $document)->isValid());
        $this->assertFalse($validator->validate((object) [], $document)->isValid());
    }

    public static function schemaModes(): array
    {
        return ['collapsed' => ['collapsed'], 'request' => ['request'], 'response' => ['response'], 'llm_strict' => ['llm_strict']];
    }
}

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
