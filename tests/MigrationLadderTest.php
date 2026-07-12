<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Migration\Contracts\LlmMigrator;
use Schemastud\DataSchemas\Migration\MigrationLadder;
use Schemastud\DataSchemas\Migration\Rungs\LlmTryRung;
use Schemastud\DataSchemas\Migration\TransformRegistry;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Invocables\LocalInvocable;

class MigrationLadderTest extends TestCase
{
    public function test_default_ladder_is_structural_then_declared_then_custom(): void
    {
        $this->assertSame(
            ['structural', 'declared-mapping', 'custom-transform'],
            MigrationLadder::default()->rungs(),
        );

        // LLM-try is NOT in the default ladder.
        $this->assertNotContains('llm-try', MigrationLadder::default()->rungs());
    }

    public function test_structural_rung_fills_an_added_field_from_its_default(): void
    {
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'active' => ['type' => 'boolean', 'default' => true],
        ], 'required' => ['name', 'active']];

        $result = MigrationLadder::default()->migrate(['name' => 'Ada'], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('structural', $result->rung);
        $this->assertSame(['name' => 'Ada', 'active' => true], $result->migrated);
    }

    public function test_structural_rung_fills_an_added_field_with_a_typed_empty_when_no_default(): void
    {
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'tags' => ['type' => 'array'],
        ], 'required' => ['name', 'tags']];

        $result = MigrationLadder::default()->migrate(['name' => 'Ada'], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame([], $result->migrated['tags']);
    }

    public function test_structural_rung_drops_a_removed_field(): void
    {
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'legacy' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
        ], 'required' => ['name'], 'additionalProperties' => false];

        $result = MigrationLadder::default()->migrate(['name' => 'Ada', 'legacy' => 'gone'], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame(['name' => 'Ada'], $result->migrated);
    }

    public function test_structural_rung_keeps_a_widened_field_value(): void
    {
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'score' => ['type' => 'integer'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'score' => ['type' => 'number'],
        ], 'required' => ['score']];

        $result = MigrationLadder::default()->migrate(['score' => 7], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame(7, $result->migrated['score']);
    }

    public function test_declared_mapping_rung_applies_an_x_migrate_from_rename(): void
    {
        // The structural rung sees full_name dropped + displayName added (no
        // default), so its candidate has an empty displayName and fails the
        // required+minLength gate; the ladder demotes to the declared rung, which
        // moves the value across.
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'full_name' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'displayName' => ['type' => 'string', 'minLength' => 1, 'x-migrate-from' => 'full_name'],
        ], 'required' => ['displayName']];

        $result = MigrationLadder::default()->migrate(['full_name' => 'Ada Lovelace'], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('declared-mapping', $result->rung);
        $this->assertSame(['displayName' => 'Ada Lovelace'], $result->migrated);
    }

    public function test_custom_transform_rung_runs_a_registered_invocable(): void
    {
        // No structural/declared path can produce `slug` from `title`, so the two
        // stronger rungs abstain and the custom transform (rung 3) runs.
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'title' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'slug' => ['type' => 'string', 'minLength' => 1, 'x-migrate' => 'slugger'],
        ], 'required' => ['slug']];

        $transforms = (new TransformRegistry)->registerName('slugger', new LocalInvocable(
            'slugger',
            fn (array $in) => ['slug' => str_replace(' ', '-', strtolower($in['title']))],
        ));

        $result = MigrationLadder::default($transforms)->migrate(['title' => 'Hello World'], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('custom-transform', $result->rung);
        $this->assertSame(['slug' => 'hello-world'], $result->migrated);
    }

    public function test_custom_transform_rung_resolves_by_from_to_id_pair(): void
    {
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'title' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'slug' => ['type' => 'string', 'minLength' => 1],
        ], 'required' => ['slug']];

        $transforms = (new TransformRegistry)->registerPair('x://p/1', 'x://p/2', new LocalInvocable(
            'pairwise',
            fn (array $in) => ['slug' => 'fixed-slug'],
        ));

        $result = MigrationLadder::default($transforms)->migrate(['title' => 'whatever'], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('custom-transform', $result->rung);
        $this->assertSame(['slug' => 'fixed-slug'], $result->migrated);
    }

    public function test_acceptance_gate_makes_a_non_conforming_rung_abstain(): void
    {
        // The custom transform emits a candidate that violates the target (slug
        // too short), so the gate rejects it; with no further rung, the ladder
        // hits the quarantine floor.
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'title' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'slug' => ['type' => 'string', 'minLength' => 5],
        ], 'required' => ['slug']];

        $transforms = (new TransformRegistry)->registerName('bad', new LocalInvocable(
            'bad',
            fn (array $in) => ['slug' => 'no'], // too short -> fails the gate
        ));
        $to['properties']['slug']['x-migrate'] = 'bad';

        $result = MigrationLadder::default($transforms)->migrate(['title' => 'x'], $from, $to);

        $this->assertFalse($result->wasMigrated());
        $this->assertTrue($result->quarantined);
    }

    public function test_quarantine_floor_preserves_the_original_payload_immutably(): void
    {
        // A change no rung can satisfy: an added required string with no default
        // gets a typed empty, which fails minLength; nothing else applies.
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string', 'minLength' => 3],
        ], 'required' => ['name', 'email']];

        $original = ['name' => 'Ada'];
        $result = MigrationLadder::default()->migrate($original, $from, $to);

        $this->assertTrue($result->quarantined);
        $this->assertNull($result->migrated);
        // Original preserved verbatim, never mutated or destroyed.
        $this->assertSame($original, $result->original);
    }

    public function test_llm_try_is_a_seam_with_no_prism_dependency(): void
    {
        // The LLM rung is host-bound: it dispatches a popcorn Invocable. Here a
        // plain stub stands in for the app-side (slice 17) model migrator. No
        // Prism / LLM SDK is referenced anywhere in the package.
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'raw' => ['type' => 'string'],
        ]];
        $to = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'summary' => ['type' => 'string', 'minLength' => 1, 'x-migrate' => 'llm'],
        ], 'required' => ['summary']];

        $migrator = new class implements LlmMigrator
        {
            public function name(): string
            {
                return 'stub-llm';
            }

            public function binding(): Binding
            {
                return Binding::Local;
            }

            public function invoke(array $input): array
            {
                return ['summary' => 'a model-produced summary'];
            }
        };

        $llmRung = (new LlmTryRung)->withMigrator($migrator);
        $ladder = MigrationLadder::default()->withRungs($llmRung);

        $this->assertContains('llm-try', $ladder->rungs());

        $result = $ladder->migrate(['raw' => 'long text'], $from, $to);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('llm-try', $result->rung);
        $this->assertSame(['summary' => 'a model-produced summary'], $result->migrated);
    }

    public function test_package_has_no_prism_or_llm_sdk_dependency(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $deps = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? []),
        );

        foreach ($deps as $dep) {
            $this->assertStringNotContainsString('prism', strtolower($dep));
            $this->assertStringNotContainsString('openai', strtolower($dep));
            $this->assertStringNotContainsString('anthropic', strtolower($dep));
        }
    }
}
