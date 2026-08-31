<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;

/**
 * api-surface-coherence 122 — the one delta that is not drift.
 *
 * 118 taught the generator to emit `default`, which changed the shape of ten already-frozen
 * write-once artifacts and turned the drift gate red. The ruling: an added `default` on a VERSION-1
 * artifact is provably inert, because all three migration rungs read `default` off the TARGET schema
 * (`$request->to['properties']`) and nothing migrates INTO a first version.
 *
 * Both clauses are load-bearing and both are asserted here. The version clause is what expires the
 * argument the moment a class gains a v2 — on any later version the artifact CAN be a target, the
 * keyword IS read, and the write-once refusal must stand.
 */
class InertDefaultDeltaTest extends TestCase
{
    public function test_an_added_default_is_inert(): void
    {
        $this->assertTrue(SchemaFingerprint::inertDefaultAdditionOnly(
            ['properties' => ['status' => ['type' => 'string']]],
            ['properties' => ['status' => ['type' => 'string', 'default' => 'open']]],
        ));
    }

    public function test_a_volatile_key_moving_does_not_defeat_inertness(): void
    {
        // `description`/`title`/`examples` are excluded from identity by VOLATILE_KEYS, so a difference
        // in one is not a difference at all. Comparing raw schemas here would refuse a genuinely inert
        // delta because a title moved — the defect this test exists to pin.
        $this->assertTrue(SchemaFingerprint::inertDefaultAdditionOnly(
            ['properties' => ['status' => ['type' => 'string', 'examples' => ['x']]]],
            ['properties' => ['status' => ['type' => 'string', 'title' => 'Status', 'default' => 'open']]],
        ));
    }

    public function test_a_changed_value_is_not_inert(): void
    {
        $this->assertFalse(SchemaFingerprint::inertDefaultAdditionOnly(
            ['properties' => ['spawn' => ['$ref' => '#/$defs/SeriesSpawnData']]],
            ['properties' => ['spawn' => ['$ref' => '#/$defs/SpawnData']]],
        ));
    }

    public function test_an_added_non_default_key_is_not_inert(): void
    {
        $this->assertFalse(SchemaFingerprint::inertDefaultAdditionOnly(
            ['properties' => ['status' => ['type' => 'string']]],
            ['properties' => ['status' => ['type' => 'string'], 'extra' => ['type' => 'integer']]],
        ));
    }

    public function test_a_removed_key_is_not_inert(): void
    {
        $this->assertFalse(SchemaFingerprint::inertDefaultAdditionOnly(
            ['properties' => ['status' => ['type' => 'string'], 'gone' => ['type' => 'string']]],
            ['properties' => ['status' => ['type' => 'string']]],
        ));
    }

    #[DataProvider('versions')]
    public function test_the_version_is_read_off_the_id(string $id, ?int $expected): void
    {
        $this->assertSame($expected, SchemaFingerprint::versionOf($id));
    }

    public static function versions(): array
    {
        return [
            'first version' => ['https://app.splicewire.com/schemas/commerce/invoice/1', 1],
            'later version' => ['https://app.splicewire.com/schemas/commerce/invoice/2', 2],
            'unversioned' => ['https://app.splicewire.com/schemas/commerce/invoice', null],
        ];
    }

    public function test_default_is_not_volatile_and_must_never_become_so(): void
    {
        // The rejected option from 122. On any version above 1 the keyword is structural: that version
        // can be a migration target, and the value it carries is the value a migrated document receives.
        // Adding it to VOLATILE_KEYS would blind the guard to a change that alters migrated data.
        $this->assertNotContains('default', SchemaFingerprint::VOLATILE_KEYS);
    }
}
