<?php

namespace Schemastud\DataSchemas\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\MigrationKeywordPlacement;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\DataSchemas\Support\OpenApi;
use Spatie\LaravelData\Data;

class MapValueUnionTest extends TestCase
{
    public function test_explicit_union_overrides_a_union_phpdoc_in_every_generation_mode(): void
    {
        foreach (['forRequest', 'forResponse', 'forLlmStrict'] as $mode) {
            $schema = (new JsonSchemaGenerator)->{$mode}()->generate(new ReflectionClass(UnionMapData::class));
            $this->assertSame(['number', 'string'], $schema['properties']['values']['additionalProperties']['type']);
            $gate = new AcceptanceGate;
            $this->assertFalse($gate->accepts(['values' => []], $schema));
            $this->assertTrue($gate->accepts(['values' => new \stdClass], $schema));
            $this->assertTrue($gate->accepts(['values' => ['symbol' => 'ETH', 'quantity' => 2, 'fee' => 0.25]], $schema));
            foreach ([true, null, ['nested'], (object) ['nested' => 1]] as $invalid) {
                $this->assertFalse($gate->accepts(['values' => ['bad' => $invalid]], $schema));
            }
        }
    }

    public function test_a_declared_nullable_integer_value_union_accepts_only_its_types(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(NullableMapData::class));
        $gate = new AcceptanceGate;
        $this->assertTrue($gate->accepts(['values' => ['missing' => null, 'count' => 2]], $schema));
        $this->assertFalse($gate->accepts(['values' => ['count' => 2.5]], $schema));
        $this->assertFalse($gate->accepts(['values' => ['count' => '2']], $schema));
    }

    public function test_union_maps_reach_openapi_components_and_remain_structurally_significant(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(UnionMapData::class));
        MigrationKeywordPlacement::assertTopLevelOnly($schema);
        $components = OpenApi::toOpenApiComponents(['$defs' => ['UnionMapData' => $schema]]);
        $this->assertSame(['number', 'string'], $components['components']['schemas']['UnionMapData']['properties']['values']['additionalProperties']['type']);
        $old = $schema;
        $old['properties']['values']['additionalProperties']['type'] = 'string';
        $this->assertNotSame(SchemaFingerprint::of($old), SchemaFingerprint::of($schema));
    }

    public function test_type_list_order_does_not_change_the_emitted_fingerprint(): void
    {
        $generator = new JsonSchemaGenerator;
        $schema = $generator->generate(new ReflectionClass(UnionMapData::class));
        $reordered = $generator->generate(new ReflectionClass(ReorderedUnionMapData::class));
        $this->assertSame($schema['properties']['values'], $reordered['properties']['values']);
        $this->assertSame(SchemaFingerprint::of($schema), SchemaFingerprint::of($reordered));
    }

    public function test_migration_rename_keywords_remain_forbidden_inside_a_union_map_value(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(UnionMapData::class));
        $schema['properties']['values']['additionalProperties']['x-migrate-from'] = 'old';
        $this->expectException(InvalidArgumentException::class);
        MigrationKeywordPlacement::assertTopLevelOnly($schema);
    }

    public function test_malformed_type_lists_are_rejected_as_declaration_errors(): void
    {
        foreach ([[], ['type' => 'string'], ['string', 'string'], ['string', 'unknown'], ['string', []], ['object']] as $types) {
            try {
                new MapValues($types);
                $this->fail('Malformed map type list was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('MapValues', $exception->getMessage());
            }
        }
    }
}

class UnionMapData extends Data
{
    /** @param array<string, int|float|string> $values */
    public function __construct(
        #[MapValues(['number', 'string'])]
        public array $values,
    ) {}
}

class NullableMapData extends Data
{
    /** @param array<string, int|null> $values */
    public function __construct(
        #[MapValues(['integer', 'null'])]
        public array $values,
    ) {}
}

class ReorderedUnionMapData extends Data
{
    /** @param array<string, int|float|string> $values */
    public function __construct(
        #[MapValues(['string', 'number'])]
        public array $values,
    ) {}
}
