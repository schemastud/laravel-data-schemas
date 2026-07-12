<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Strategies\ValidationAttributeStrategy;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;
use Schemastud\DataSchemas\Tests\Fixtures\StubVendorKeywordStrategy;
use Schemastud\DataSchemas\Tests\Fixtures\VendorKeywordData;
use Spatie\LaravelData\Attributes\Validation\Max;

class SchemaStrategyTest extends TestCase
{
    public function test_a_registered_strategy_contributes_a_vendor_keyword(): void
    {
        $schema = (new JsonSchemaGenerator([
            'strategies' => [new StubVendorKeywordStrategy],
        ]))->generate(new ReflectionClass(VendorKeywordData::class));

        $this->assertSame('tagged', $schema['properties']['title']['x-demo']);
    }

    public function test_for_llm_strict_strips_all_vendor_keywords(): void
    {
        $schema = (new JsonSchemaGenerator([
            'strategies' => [new StubVendorKeywordStrategy],
        ]))->forLlmStrict()->generate(new ReflectionClass(VendorKeywordData::class));

        $this->assertArrayNotHasKey('x-demo', $schema['properties']['title']);
        $this->assertStringNotContainsString('x-demo', json_encode($schema));
    }

    public function test_strategies_default_to_validation_when_none_configured(): void
    {
        // No explicit strategies and no container — the built-in default set runs,
        // so validation attributes still map (proving the extraction preserved it).
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(SampleData::class));

        $this->assertSame(255, $schema['properties']['title']['maxLength']);
        $this->assertSame('email', $schema['properties']['email']['format']);
    }

    public function test_validation_strategy_honours_a_custom_mapping_from_config(): void
    {
        $generator = new JsonSchemaGenerator([
            'strategies' => [new ValidationAttributeStrategy],
            'validation_mapping' => [
                Max::class => fn ($attr) => ['x-max-seen' => true],
            ],
        ]);

        $schema = $generator->generate(new ReflectionClass(SampleData::class));

        // The custom mapping replaces the built-in Max handling for `title`.
        $this->assertTrue($schema['properties']['title']['x-max-seen']);
        $this->assertArrayNotHasKey('maxLength', $schema['properties']['title']);
    }
}
