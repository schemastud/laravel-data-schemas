<?php

namespace Rushing\LaravelDataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionParameter;
use Rushing\LaravelDataSchemas\Tests\Fixtures\Vocabulary\SampleDescriber;
use Rushing\LaravelDataSchemas\Tests\Fixtures\Vocabulary\SamplePolicySource;
use Rushing\LaravelDataSchemas\Vocabulary\KeywordDescriptor;
use Rushing\LaravelDataSchemas\Vocabulary\ValueSource;

/**
 * The keyword-vocabulary describer MECHANISM: reflect each ValueSource into JSON Schema + TypeScript,
 * with content (which keywords, their strings, the title) supplied by a subclass. Composition's grammar
 * vocabulary is one consumer; this proves the mechanism independent of it.
 */
class KeywordVocabularyDescriberTest extends TestCase
{
    public function test_it_keys_every_declared_keyword_by_accessor(): void
    {
        $keywords = (new SampleDescriber)->keywords();

        $this->assertSame(
            ['flavor', 'choice', 'toggle', 'depth', 'note', 'policy'],
            array_keys($keywords),
        );
    }

    public function test_it_reflects_enum_value_domains_live_from_cases(): void
    {
        $properties = (new SampleDescriber)->toJsonSchema()['properties'];

        $this->assertSame(
            ['description' => 'an enum', 'type' => 'string', 'enum' => ['sweet', 'sour']],
            $properties['x-sample-flavor'],
        );
    }

    public function test_it_projects_a_string_bool_return_type_to_a_oneof_union(): void
    {
        $choice = (new SampleDescriber)->toJsonSchema()['properties']['x-sample-choice'];

        $this->assertSame([['type' => 'string'], ['type' => 'boolean']], $choice['oneOf']);
    }

    public function test_it_reflects_an_object_shape_from_its_constructor_with_only_non_null_non_array_required(): void
    {
        $policy = (new SampleDescriber)->toJsonSchema()['properties']['x-sample-policy'];

        $this->assertSame('object', $policy['type']);
        $this->assertSame(['scope', 'ttl', 'key'], array_keys($policy['properties']));
        // scope is required; ttl (nullable) and key (array, dropped when empty) are not.
        $this->assertSame(['scope'], $policy['required']);
    }

    public function test_the_top_level_meta_schema_carries_the_subclass_identity(): void
    {
        $schema = (new SampleDescriber)->toJsonSchema();

        $this->assertSame('SampleKeywords', $schema['title']);
        $this->assertSame('sample dialect', $schema['description']);
        $this->assertSame('object', $schema['type']);
        // Properties are sorted for a stable artifact.
        $keys = array_keys($schema['properties']);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    public function test_typescript_emits_named_types_and_an_optional_member_interface(): void
    {
        $ts = (new SampleDescriber)->toTypeScript();

        $this->assertStringContainsString("export type SampleFlavor = 'sweet' | 'sour';", $ts);
        $this->assertStringContainsString('export interface SamplePolicy {', $ts);
        $this->assertStringContainsString('scope: string;', $ts);
        $this->assertStringContainsString('ttl?: number;', $ts);
        $this->assertStringContainsString('export interface SampleKeywords {', $ts);
        $this->assertStringContainsString("'x-sample-flavor'?: SampleFlavor;", $ts);
    }

    public function test_the_optionality_convention_is_an_overridable_default(): void
    {
        $describer = new class extends SampleDescriber
        {
            protected function parameterIsOptional(ReflectionParameter $parameter, string $typeName): bool
            {
                return false; // everything required
            }
        };

        $policy = $describer->toJsonSchema()['properties']['x-sample-policy'];

        $this->assertSame(['scope', 'ttl', 'key'], $policy['required']);
    }

    public function test_it_fails_loudly_on_a_non_scalar_object_parameter(): void
    {
        $describer = new class extends SampleDescriber
        {
            protected function descriptors(): iterable
            {
                return [new KeywordDescriptor('bad', ValueSource::Object_, 'bad', sourceClass: BadCtorSource::class, tsType: 'Bad')];
            }
        };

        $this->expectException(\RuntimeException::class);
        $describer->toJsonSchema();
    }
}

class BadCtorSource
{
    public function __construct(public SamplePolicySource $nested) {}
}
