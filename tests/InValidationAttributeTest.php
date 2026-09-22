<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Rules\In as InRule;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

class InValidationAttributeTest extends TestCase
{
    public function test_in_choices_preserve_strings_escaping_and_numeric_property_types(): void
    {
        $schema = (new JsonSchemaGenerator)->forRequest()->generate(new ReflectionClass(InChoiceData::class));
        $this->assertSame(['page', 'with,comma', 'with"quote', '1'], $schema['properties']['text']['enum']);
        $this->assertSame([1, 2], $schema['properties']['integer']['enum']);
        $this->assertSame([1.5, 2.5], $schema['properties']['number']['enum']);
        $this->assertSame(['a', 'b', null], $schema['properties']['nullable']['enum']);
        $this->assertSame([0, 1, null], $schema['properties']['nullableInteger']['enum']);
        $this->assertSame(['a', 'b'], $schema['properties']['choices']['items']['enum']);
        $validator = new Validator;
        $valid = (object) ['text' => 'page', 'integer' => 1, 'number' => 1.5, 'nullable' => null, 'nullableInteger' => null, 'choices' => ['a']];
        $this->assertTrue($validator->validate($valid, json_decode(json_encode($schema)))->isValid());
        $this->assertFalse($validator->validate((object) [...(array) $valid, 'text' => 'not-declared'], json_decode(json_encode($schema)))->isValid());
        $this->assertFalse($validator->validate((object) [...(array) $valid, 'integer' => 3], json_decode(json_encode($schema)))->isValid());
    }

    public function test_union_and_boolean_choices_agree_with_validation_for_typed_values(): void
    {
        $schema = (new JsonSchemaGenerator)->forRequest()->generate(new ReflectionClass(UnionInChoiceData::class));
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $validator = new Validator;
        foreach (['draft', '1', 1, 2, 'missing'] as $value) {
            $accepted = $factory->make(['value' => $value], ['value' => [new InRule(['draft', '1'])]])->passes();
            $this->assertSame($accepted, $validator->validate($value, json_decode(json_encode($schema['properties']['value'])))->isValid());
        }
        foreach ([true, false] as $enabled) {
            $accepted = $factory->make(['enabled' => $enabled], ['enabled' => ['boolean', new InRule(['1'])]])->passes();
            $this->assertSame($accepted, $validator->validate($enabled, json_decode(json_encode($schema['properties']['enabled'])))->isValid());
        }
        $this->assertSame(['draft', '1', 1], $schema['properties']['value']['enum']);
        $this->assertSame([true], $schema['properties']['enabled']['enum']);
    }

    public function test_wrapped_rule_and_empty_vocabulary_are_not_unrestricted(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(WrappedInChoiceData::class));
        $this->assertSame(['x', 'y'], $schema['properties']['wrapped']['enum']);
        $this->assertSame('{}', json_encode($schema['properties']['empty']['not']));
    }
}

class InChoiceData extends Data
{
    public function __construct(
        #[In(['page', 'with,comma', 'with"quote', '1'])]
        public string $text,
        #[In([1, 2])]
        public int $integer,
        #[In(['1.5', '2.5'])]
        public float $number,
        #[In('a', 'b')]
        public ?string $nullable,
        #[In([0, 1])]
        public ?int $nullableInteger,
        #[ArrayItems('string'), In('a', 'b')]
        public array $choices,
    ) {}
}

class WrappedInChoiceData extends Data
{
    public function __construct(
        #[In(new InRule(['x', 'y']))]
        public string $wrapped,
        #[In([])]
        public string $empty,
    ) {}
}

class UnionInChoiceData extends Data
{
    public function __construct(
        #[In(['draft', '1'])]
        public int|string $value,
        #[In(['1'])]
        public bool $enabled,
    ) {}
}
