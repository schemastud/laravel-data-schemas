<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Lifecycle\SchemaDiff;

class SchemaDiffTest extends TestCase
{
    public function test_it_reports_added_dropped_and_widened_fields(): void
    {
        $old = [
            'type' => 'object',
            'properties' => [
                'kept' => ['type' => 'string'],
                'dropped' => ['type' => 'integer'],
                'widened' => ['type' => 'string'],
            ],
        ];

        $new = [
            'type' => 'object',
            'properties' => [
                'kept' => ['type' => 'string'],
                'added' => ['type' => 'boolean'],
                'widened' => ['type' => ['string', 'null']],
            ],
        ];

        $diff = SchemaDiff::between($old, $new);

        $this->assertSame(['added'], $diff['added']);
        $this->assertSame(['dropped'], $diff['dropped']);
        $this->assertCount(1, $diff['widened']);
        $this->assertSame('widened', $diff['widened'][0]['field']);
        $this->assertSame(['string'], $diff['widened'][0]['from']);
        $this->assertSame(['null', 'string'], $diff['widened'][0]['to']);
        $this->assertTrue($diff['breaking']); // a drop breaks consumers
    }

    public function test_integer_to_number_is_a_widening_not_a_breaking_change(): void
    {
        $old = ['properties' => ['n' => ['type' => 'integer']]];
        $new = ['properties' => ['n' => ['type' => 'number']]];

        $diff = SchemaDiff::between($old, $new);

        $this->assertCount(1, $diff['widened']);
        $this->assertEmpty($diff['changed']);
        $this->assertFalse($diff['breaking']);
    }

    public function test_a_narrowing_type_change_is_breaking(): void
    {
        $old = ['properties' => ['n' => ['type' => ['string', 'null']]]];
        $new = ['properties' => ['n' => ['type' => 'string']]];

        $diff = SchemaDiff::between($old, $new);

        $this->assertEmpty($diff['widened']);
        $this->assertCount(1, $diff['changed']);
        $this->assertTrue($diff['breaking']);
    }

    public function test_identical_schemas_report_no_changes(): void
    {
        $schema = ['properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']]];

        $diff = SchemaDiff::between($schema, $schema);

        $this->assertEmpty($diff['added']);
        $this->assertEmpty($diff['dropped']);
        $this->assertEmpty($diff['widened']);
        $this->assertEmpty($diff['changed']);
        $this->assertFalse($diff['breaking']);
    }
}
