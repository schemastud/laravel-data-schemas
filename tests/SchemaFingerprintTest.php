<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;

class SchemaFingerprintTest extends TestCase
{
    public function test_same_structure_yields_the_same_fingerprint_regardless_of_key_order(): void
    {
        $a = ['type' => 'object', 'properties' => ['x' => ['type' => 'string'], 'y' => ['type' => 'integer']]];
        $b = ['properties' => ['y' => ['type' => 'integer'], 'x' => ['type' => 'string']], 'type' => 'object'];

        $this->assertSame(SchemaFingerprint::of($a), SchemaFingerprint::of($b));
    }

    public function test_volatile_metadata_does_not_change_the_fingerprint(): void
    {
        $bare = ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
        $decorated = $bare + [
            'title' => 'Thing',
            'description' => 'A thing',
            '$id' => 'https://example.test/thing/1',
        ];
        $decorated['properties']['x']['examples'] = ['hello'];

        $this->assertSame(SchemaFingerprint::of($bare), SchemaFingerprint::of($decorated));
    }

    public function test_a_structural_change_changes_the_fingerprint(): void
    {
        $a = ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
        $b = ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]];

        $this->assertNotSame(SchemaFingerprint::of($a), SchemaFingerprint::of($b));
    }

    public function test_required_order_is_structural(): void
    {
        $a = ['type' => 'object', 'required' => ['a', 'b']];
        $b = ['type' => 'object', 'required' => ['b', 'a']];

        // Lists keep order; required reordering is a structural difference.
        $this->assertNotSame(SchemaFingerprint::of($a), SchemaFingerprint::of($b));
    }
}
