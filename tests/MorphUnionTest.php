<?php

namespace Schemastud\DataSchemas\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\MorphUnion;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\TypeScript\MorphUnionTransformer;
use Spatie\LaravelData\Contracts\PropertyMorphableData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\Data\WritingContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;

require_once __DIR__.'/Fixtures/MorphUnion/Nodes.php';

class MorphUnionTest extends TestCase
{
    public function test_json_discriminates_root_and_recursive_nodes(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(MorphNode::class));
        $this->assertCount(2, $schema['oneOf'] ?? []);
        $validator = new Validator;
        foreach ([
            [['kind' => 'text', 'text' => 'hello'], true],
            [['kind' => 'block', 'child' => ['kind' => 'text', 'text' => 'nested']], true],
            [['kind' => 'unknown', 'text' => 'hello'], false],
            [['kind' => 'text', 'child' => null], false],
            [['text' => 'hello'], false],
        ] as [$value, $valid]) {
            $this->assertSame($valid, $validator->validate(json_decode(json_encode($value)), json_decode(json_encode($schema)))->isValid());
        }
    }

    public function test_unannotated_data_keeps_its_object_schema(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(MorphText::class));
        $this->assertSame('object', $schema['type']);
        $this->assertArrayNotHasKey('oneOf', $schema);
        $this->assertSame('string', $schema['properties']['kind']['type']);
    }

    public function test_invalid_arm_is_refused_by_both_generators(): void
    {
        foreach (['json', 'ts'] as $generator) {
            try {
                if ($generator === 'json') {
                    (new JsonSchemaGenerator)->generate(new ReflectionClass(InvalidMorph::class));
                } else {
                    $node = PhpClassNode::fromClassString(InvalidMorph::class);
                    (new MorphUnionTransformer)->transform($node, TransformationContext::createFromPhpClass($node));
                }
                $this->fail('An unrelated arm must not be projected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('subclasses of the base', $exception->getMessage());
            }
        }
    }

    public function test_typescript_emits_real_tagged_references_and_leaves_unannotated_alone(): void
    {
        $transformer = new MorphUnionTransformer;
        $node = PhpClassNode::fromClassString(MorphNode::class);
        $result = $transformer->transform($node, TransformationContext::createFromPhpClass($node));
        $collection = new \Spatie\TypeScriptTransformer\Collections\TransformedCollection([$result]);
        $refs = [$result->getReference()->getKey() => 'MorphNode'];
        foreach ([MorphText::class, MorphBlock::class] as $class) {
            $armNode = PhpClassNode::fromClassString($class);
            $arm = (new AttributedClassTransformer)->transform($armNode, TransformationContext::createFromPhpClass($armNode));
            $collection->add($arm);
            $refs[$arm->getReference()->getKey()] = $armNode->getShortName();
        }
        (new \Spatie\TypeScriptTransformer\Actions\ConnectReferencesAction(new \Spatie\TypeScriptTransformer\Support\Loggers\NullLogger))->execute($collection);
        foreach ($collection as $transformed) {
            $this->assertSame([], $transformed->getMissingReferences());
        }
        if ($path = getenv('MORPH_TS_OUTPUT')) {
            file_put_contents($path, implode("\n", array_map(fn ($item) => $item->getNode()->write(new WritingContext($refs)), iterator_to_array($collection))));
        }
        $output = $result->getNode()->write(new WritingContext($refs));
        $this->assertStringContainsString('MorphText &', $output);
        $this->assertStringContainsString("kind: 'text'", $output);
        $this->assertStringContainsString('MorphBlock &', $output);
        $this->assertStringContainsString("kind: 'block'", $output);
        $plain = PhpClassNode::fromClassString(MorphText::class);
        $this->assertInstanceOf(Untransformable::class, $transformer->transform($plain, TransformationContext::createFromPhpClass($plain)));
    }
}

#[MorphUnion(discriminator: 'kind', arms: ['wrong' => MorphText::class])]
abstract class InvalidMorph extends Data implements PropertyMorphableData
{
    public static function morph(array $properties): ?string
    {
        return null;
    }
}
