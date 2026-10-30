<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\SchemaDriftGuard;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;

class InertOptionalRemovalTest extends TestCase
{
    public function test_removal_is_inert_for_first_and_later_versions_without_changing_fingerprint_rules(): void
    {
        foreach ([1, 2] as $version) {
            $old = ['$id' => "https://example.test/calendar-series/{$version}", 'properties' => [
                'name' => ['type' => 'string', 'x-optional' => true],
                'nested' => ['anyOf' => [['type' => 'string', 'x-optional' => true], ['type' => 'null']]],
            ]];
            $new = $old;
            unset($new['properties']['name']['x-optional'], $new['properties']['nested']['anyOf'][0]['x-optional']);
            $this->assertNotSame(SchemaFingerprint::of($old), SchemaFingerprint::of($new));
            $result = $this->guard($old, $new)->check();
            $this->assertFalse($result->hasDrift());
            $this->assertCount(1, $result->refreezable);
            $this->assertStringContainsString('x-optional', $result->refreezable[0]->reason);
        }
        $this->assertNotContains('x-optional', SchemaFingerprint::VOLATILE_KEYS);
    }

    public function test_other_changes_and_keyword_additions_or_value_changes_remain_drift(): void
    {
        $old = ['$id' => 'https://example.test/calendar-series/2', 'properties' => ['name' => ['type' => 'string', 'x-optional' => true]]];
        $removed = $old;
        unset($removed['properties']['name']['x-optional']);
        $changedType = $removed;
        $changedType['properties']['name']['type'] = 'integer';
        $changedValue = $old;
        $changedValue['properties']['name']['x-optional'] = false;
        $otherKeyword = $old;
        $otherKeyword['properties']['name']['x-lazy'] = true;
        $removedOther = $removed;
        $removedOther['properties']['name']['x-lazy'] = true;
        foreach ([[$old, $changedType], [$removed, $old], [$old, $changedValue], [$otherKeyword, $removed], [$old, $removedOther]] as [$frozen, $current]) {
            $this->assertTrue($this->guard($frozen, $current)->check()->hasDrift());
        }
    }

    public function test_a_property_named_x_optional_and_a_default_object_are_data_not_keyword_removals(): void
    {
        foreach ([
            ['properties' => ['x-optional' => ['type' => 'string']]],
            ['default' => ['x-optional' => true]],
        ] as $body) {
            $old = ['$id' => 'https://example.test/calendar-series/2'] + $body;
            $new = $old;
            if (isset($body['properties'])) {
                unset($new['properties']['x-optional']);
            } else {
                unset($new['default']['x-optional']);
            }
            $this->assertTrue($this->guard($old, $new)->check()->hasDrift());
        }
    }

    private function guard(array $frozen, array $current): SchemaDriftGuard
    {
        $registry = $this->createMock(SchemaRegistry::class);
        $registry->method('get')->willReturn($frozen);
        return new class($registry, new JsonSchemaGenerator, [], $current) extends SchemaDriftGuard
        {
            public function __construct(SchemaRegistry $registry, JsonSchemaGenerator $generator, array $paths, private array $current)
            {
                parent::__construct($registry, $generator, $paths);
            }
            public function discover(): array { return ['OptionalFixture']; }
            public function project(string $class): array { return $this->current; }
        };
    }
}
