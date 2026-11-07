<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Migration\MigrationLadder;

/**
 * Launch ticket 30: a declared rename (`x-migrate-from`) keeps every Record's value. The structural rung runs first; with a
 * renamed field it dropped the old key and filled the new one with a typed empty, a candidate that passes the gate whenever
 * the new field accepts an empty value (a plain string), so the declared-mapping rung never ran and the value was lost
 * (tots, App 3003be3e: summary="VALUE-KEEPS-ME-12345" -> blurb=""). The existing rename test only passed because its new
 * field was required with minLength 1.
 *
 * The last three tests RECORD today's behaviour, which this ticket does not change: renames apply at the top level
 * only, and the ladder migrates a stored record to its target in one step, so a rename must be declared on the target.
 */
class DeclaredRenameKeepsValuesTest extends TestCase
{
    /** @param  array<string, mixed>  $props */
    private function schema(int $version, array $props, array $extra = []): array
    {
        return ['$id' => "x://t30/{$version}", 'type' => 'object', 'properties' => $props] + $extra;
    }

    public function test_a_declared_rename_of_a_plain_string_keeps_the_value(): void
    {
        $result = MigrationLadder::default()->migrate(
            ['summary' => 'VALUE-KEEPS-ME-12345'],
            $this->schema(1, ['summary' => ['type' => 'string']]),
            $this->schema(2, ['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary']]),
        );

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('declared-mapping', $result->rung);
        $this->assertSame(['blurb' => 'VALUE-KEEPS-ME-12345'], $result->migrated);
    }

    public function test_a_rename_beside_other_structural_changes_migrates_in_one_pass_and_keeps_the_value(): void
    {
        $result = MigrationLadder::default()->migrate(
            ['title' => 'T', 'summary' => 'S', 'gone' => 'G'],
            $this->schema(1, ['title' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'gone' => ['type' => 'string']]),
            $this->schema(2, [
                'title' => ['type' => 'string'],
                'blurb' => ['type' => 'string', 'x-migrate-from' => 'summary'],
                'active' => ['type' => 'boolean', 'default' => true],
            ]),
        );

        $this->assertSame('declared-mapping', $result->rung);
        $this->assertSame(['title' => 'T', 'blurb' => 'S', 'active' => true], $result->migrated);
    }

    public function test_v1_to_v3_keeps_the_value_when_v3_still_declares_the_rename(): void
    {
        $result = MigrationLadder::default()->migrate(
            ['summary' => 'V1-VALUE'],
            $this->schema(1, ['summary' => ['type' => 'string']]),
            $this->schema(3, ['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary'], 'extra' => ['type' => 'string']]),
        );

        $this->assertSame('declared-mapping', $result->rung);
        $this->assertSame(['blurb' => 'V1-VALUE', 'extra' => ''], $result->migrated);
    }

    // orch-peer REVISE (17:15Z): renames are SIMULTANEOUS. Each new field takes its old field's value from the stored
    // payload; applied one after another, a chain read an already-overwritten field and lost a value.
    public function test_an_overlapping_rename_chain_keeps_both_values(): void
    {
        $result = MigrationLadder::default()->migrate(
            ['a' => 'VALUE-A', 'b' => 'VALUE-B'],
            $this->schema(1, ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]),
            $this->schema(2, [
                'b' => ['type' => 'string', 'x-migrate-from' => 'a'],
                'c' => ['type' => 'string', 'x-migrate-from' => 'b'],
            ]),
        );

        $this->assertSame('declared-mapping', $result->rung);
        $this->assertSame(['b' => 'VALUE-A', 'c' => 'VALUE-B'], $result->migrated);
    }

    public function test_a_rename_swap_exchanges_the_values(): void
    {
        $result = MigrationLadder::default()->migrate(
            ['a' => 'VALUE-A', 'b' => 'VALUE-B'],
            $this->schema(1, ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]),
            $this->schema(2, [
                'a' => ['type' => 'string', 'x-migrate-from' => 'b'],
                'b' => ['type' => 'string', 'x-migrate-from' => 'a'],
            ]),
        );

        $this->assertSame('declared-mapping', $result->rung);
        $this->assertSame(['a' => 'VALUE-B', 'b' => 'VALUE-A'], $result->migrated);
    }

    public function test_pins_a_null_renamed_into_a_non_nullable_field_is_refused_and_the_record_kept_whole(): void
    {
        // The declared rung carries the null across; a non-nullable target refuses it at the gate, so the ladder
        // quarantines with the original preserved, rather than the structural rung's old silent empty string.
        $payload = ['summary' => null];
        $result = MigrationLadder::default()->migrate(
            $payload,
            $this->schema(1, ['summary' => ['type' => ['string', 'null']]]),
            $this->schema(2, ['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary']]),
        );

        $this->assertFalse($result->wasMigrated());
        $this->assertNull($result->migrated);
        $this->assertSame($payload, $result->original);
    }

    public function test_a_record_already_past_the_rename_still_migrates_structurally(): void
    {
        // v2 -> v3: the target still carries the v2 rename, but the old key is long gone. Nothing to carry, so the
        // structural rung is not displaced.
        $result = MigrationLadder::default()->migrate(
            ['blurb' => 'KEPT'],
            $this->schema(2, ['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary']]),
            $this->schema(3, ['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary'], 'extra' => ['type' => 'string', 'default' => 'x']]),
        );

        $this->assertSame('structural', $result->rung);
        $this->assertSame(['blurb' => 'KEPT', 'extra' => 'x'], $result->migrated);
    }

    public function test_records_a_nested_object_rename_is_not_applied_and_the_value_stays_under_the_old_key(): void
    {
        $result = MigrationLadder::default()->migrate(
            ['meta' => ['summary' => 'NESTED-VALUE']],
            $this->schema(1, ['meta' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']]]]),
            $this->schema(2, ['meta' => ['type' => 'object', 'properties' => ['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary']]]]),
        );

        $this->assertSame(['meta' => ['summary' => 'NESTED-VALUE']], $result->migrated);
    }

    public function test_records_an_array_item_rename_is_not_applied_and_the_values_stay_under_the_old_key(): void
    {
        $item = fn (array $props) => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $props]];
        $result = MigrationLadder::default()->migrate(
            ['items' => [['summary' => 'ITEM-1'], ['summary' => 'ITEM-2']]],
            $this->schema(1, ['items' => $item(['summary' => ['type' => 'string']])]),
            $this->schema(2, ['items' => $item(['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary']])]),
        );

        $this->assertSame(['items' => [['summary' => 'ITEM-1'], ['summary' => 'ITEM-2']]], $result->migrated);
    }

    public function test_records_v1_to_v3_loses_the_value_when_only_v2_declared_the_rename(): void
    {
        // One step from the stored version to the target: a rename the target no longer declares is not seen.
        $result = MigrationLadder::default()->migrate(
            ['summary' => 'V1-VALUE'],
            $this->schema(1, ['summary' => ['type' => 'string']]),
            $this->schema(3, ['blurb' => ['type' => 'string'], 'extra' => ['type' => 'string']]),
        );

        $this->assertSame('structural', $result->rung);
        $this->assertSame(['blurb' => '', 'extra' => ''], $result->migrated);
    }
}
