<?php

namespace Schemastud\DataSchemas\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\Keyword;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;

class KeywordAttributeFixtureData
{
    public function __construct(
        #[Keyword('x-widget', 'rich-content')]
        #[Keyword('x-widget-options', ['manifestRef' => 'block-manifests/content'])]
        public ?array $bodyDoc = null,
        public string $title = '',
    ) {}
}

class KeywordAttributeBadFixtureData
{
    public function __construct(
        #[Keyword('format', 'uri')]
        public string $link = '',
    ) {}
}

/**
 * The generic #[Keyword('x-…', value)] channel: host-owned extension keywords
 * ride the emitted schema, are stripped for strict LLM output, and can never
 * name a structural (non x-) keyword.
 */
class KeywordAttributeTest extends TestCase
{
    public function test_repeatable_x_keywords_are_stamped_onto_the_property_schema(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(KeywordAttributeFixtureData::class));

        $this->assertSame('rich-content', $schema['properties']['bodyDoc']['x-widget']);
        $this->assertSame(
            ['manifestRef' => 'block-manifests/content'],
            $schema['properties']['bodyDoc']['x-widget-options']
        );
        $this->assertArrayNotHasKey('x-widget', $schema['properties']['title']);
    }

    public function test_keyword_annotations_are_stripped_from_strict_llm_schemas(): void
    {
        $schema = (new JsonSchemaGenerator)->forLlmStrict()
            ->generate(new ReflectionClass(KeywordAttributeFixtureData::class));

        $this->assertArrayNotHasKey('x-widget', $schema['properties']['bodyDoc']);
        $this->assertArrayNotHasKey('x-widget-options', $schema['properties']['bodyDoc']);
    }

    public function test_non_x_keyword_names_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonSchemaGenerator)->generate(new ReflectionClass(KeywordAttributeBadFixtureData::class));
    }
}
