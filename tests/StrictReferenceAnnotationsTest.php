<?php

namespace Schemastud\DataSchemas\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;

class StrictReferenceAnnotationsTest extends TestCase
{
    public function test_strict_refuses_a_sibling_anyof_instead_of_dropping_its_constraint(): void
    {
        $strategy = new class implements \Schemastud\DataSchemas\Strategies\SchemaStrategy
        {
            public function apply(\ReflectionProperty $property, array $schema, \Schemastud\DataSchemas\Strategies\SchemaStrategyContext $context): array
            {
                if ($property->name === 'entry') {
                    $schema['anyOf'] = [['type' => 'object', 'minProperties' => 2]];
                }

                return $schema;
            }
        };
        $generator = new JsonSchemaGenerator(['strategies' => [$strategy]]);
        $normal = $generator->generate(new ReflectionClass(AnnotatedReferencePage::class));
        self::assertArrayHasKey('$ref', $normal['properties']['entry']);
        self::assertSame([['type' => 'object', 'minProperties' => 2]], $normal['properties']['entry']['anyOf']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('existing anyOf: entry');
        $generator->forLlmStrict()->generate(new ReflectionClass(AnnotatedReferencePage::class));
    }

    public function test_strict_annotations_preserve_reference_validation_and_normal_projection(): void
    {
        $generator = new JsonSchemaGenerator;
        $schema = $generator->forLlmStrict()->generate(new ReflectionClass(AnnotatedReferencePage::class));
        $property = $schema['properties']['entry'];
        self::assertArrayNotHasKey('$ref', $property);
        self::assertSame([['$ref' => '#/$defs/AnnotatedReferenceEntry']], $property['anyOf']);
        self::assertSame('Source entry', $property['title']);
        $validator = new Validator;
        $document = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        self::assertTrue($validator->validate((object) ['entry' => (object) ['title' => 'Profile']], $document)->isValid());
        self::assertFalse($validator->validate((object) ['entry' => null], $document)->isValid());
        self::assertFalse($validator->validate((object) ['entry' => (object) ['title' => 42]], $document)->isValid());
        self::assertArrayHasKey('$ref', $generator->generate(new ReflectionClass(AnnotatedReferencePage::class))['properties']['entry']);
    }
}
class AnnotatedReferenceEntry extends Data
{
    public function __construct(public string $title) {}
}
class AnnotatedReferencePage extends Data
{
    public function __construct(#[\Schemastud\DataSchemas\Attributes\Title('Source entry')] public AnnotatedReferenceEntry $entry) {}
}
