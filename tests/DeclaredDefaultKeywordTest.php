<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Migration\MigrationLadder;
use Schemastud\DataSchemas\Tests\Fixtures\DeclaredDefaultsData;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * api-surface-coherence 118 — the `default` keyword, emitted off the declared `Data` default.
 *
 * Booted through Testbench with spatie's provider deliberately: the value is asked of
 * `DataProperty::$defaultValue`, which goes through `DataContainer`, so a plain PHPUnit
 * harness cannot answer the question at all (the same trap {@see PromotedDefaultRequiredTest}
 * records).
 *
 * The shape under test is ONE value-level rule applied identically on every mode, minus one
 * `llm_strict` strip-list entry. There is deliberately no mode branch — see
 * `JsonSchemaGenerator::declaredDefault()`.
 */
class DeclaredDefaultKeywordTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function properties(string $mode): array
    {
        return (new JsonSchemaGenerator)
            ->schemaMode($mode)
            ->generate(new ReflectionClass(DeclaredDefaultsData::class))['properties'];
    }

    public function test_a_declared_default_reaches_the_document_on_every_mode(): void
    {
        // The 58 the measurement counted, in miniature: today each of these migrates to a
        // meaningless `emptyForType()` empty (`0`, `""`, `false`), because the four rungs that
        // branch on `array_key_exists('default', $prop)` never found one.
        foreach (['collapsed', 'request', 'response'] as $mode) {
            $props = $this->properties($mode);

            $this->assertSame(100, $props['limit']['default'], $mode);
            $this->assertSame('RED', $props['colour']['default'], $mode);
            $this->assertSame(true, $props['enabled']['default'], $mode);
            $this->assertSame(24.0, $props['rate']['default'], $mode);
            $this->assertSame(['alpha'], $props['tags']['default'], $mode);
        }
    }

    public function test_a_backed_enum_default_emits_its_backing_value(): void
    {
        // The same rule `ensureEnumDef()` already applies to the `enum` list: a case is
        // published as `$case->value`, never as the case object or its name. Nine properties
        // across the estate, several of which migrate to `null` today — a value their own
        // non-nullable enum field rejects.
        $props = $this->properties('collapsed');

        $this->assertSame('published', $props['status']['default']);
    }

    public function test_default_null_is_never_emitted_on_any_mode(): void
    {
        // 82% of the estate's 1304 declared defaults are literal `null`, and `null` is the
        // destructive value in both consumers: the ladder would migrate the majority of newly
        // added fields to null instead of a typed empty, and RJSF copies `schema.default` into
        // `formData`, so every untouched optional field would submit an explicit `null` rather
        // than being absent. Suppressing it keeps the whole benefit and removes the whole risk.
        foreach (['collapsed', 'request', 'response', 'llm_strict'] as $mode) {
            $props = $this->properties($mode);

            $this->assertArrayNotHasKey('default', $props['note'], $mode);
        }
    }

    public function test_a_property_with_no_default_carries_no_keyword(): void
    {
        $props = $this->properties('collapsed');

        $this->assertArrayNotHasKey('default', $props['name']);
        // `Optional` is erased by spatie's own DataPropertyFactory before it ever reaches here.
        $this->assertArrayNotHasKey('default', $props['maybe']);
    }

    public function test_default_never_appears_in_an_llm_strict_schema(): void
    {
        // OpenAI's strict subset REJECTS the keyword at the provider, so a leak here is not a
        // cosmetic difference — the completion never runs. Asserted over the `$defs` too,
        // because a nested definition is built by the same method.
        $schema = (new JsonSchemaGenerator)
            ->forLlmStrict()
            ->generate(new ReflectionClass(DeclaredDefaultsData::class));

        $this->assertStringNotContainsString('"default"', json_encode($schema));
    }

    public function test_the_keyword_is_keyed_on_the_wire_name_not_the_php_name(): void
    {
        // 54's projection: on the request axis the document key is the mapped name, and
        // `required` is keyed the same way. A `default` published under the PHP name would be a
        // statement about a property the document does not contain.
        $request = $this->properties('request');
        $response = $this->properties('response');

        $this->assertSame(25, $request['page_size']['default']);
        $this->assertArrayNotHasKey('pageSize', $request);

        // No output mapper, so the response axis keeps the PHP name — and still carries it.
        $this->assertSame(25, $response['pageSize']['default']);
    }

    public function test_a_type_inconsistent_default_is_never_emitted(): void
    {
        // ⚠️ Correctness, not tidiness — see the ladder test below for why. `$bag` is
        // `object`-typed with a PHP `[]` default (33 of the estate's 39 carve-outs); `$envelope`
        // is array-typed with a string-keyed default (the other 6). Both would encode as the
        // WRONG JSON kind. 72 ruled skip, do not coerce: coercing would make the generator a
        // second, worse reader of a fact the type system already holds.
        foreach (['collapsed', 'request', 'response'] as $mode) {
            $props = $this->properties($mode);

            $this->assertSame('object', $props['bag']['type'], $mode);
            $this->assertArrayNotHasKey('default', $props['bag'], $mode);

            $this->assertSame('array', $props['envelope']['type'], $mode);
            $this->assertArrayNotHasKey('default', $props['envelope'], $mode);
        }
    }

    public function test_the_type_guard_exists_because_the_gate_would_demote_the_rung(): void
    {
        // This is the test the guard must not be relaxed past. `MigrationRung::attempt()` runs
        // every candidate through `AcceptanceGate::accepts($candidate, $request->to)` — real
        // opis/json-schema validation against the target — and a REJECTED candidate makes the
        // rung ABSTAIN, demoting the ladder to the next, weaker rung.
        //
        // So the damage from emitting `default: []` on an `object`-typed property is to the
        // ladder's ROUTING, not to the document's looks: a field that migrates correctly today
        // would silently fall through to a weaker rung, surfacing only as "migrations got worse".
        // Demonstrated here by handing the ladder the target schema the unguarded emit WOULD
        // have produced.
        $from = ['$id' => 'x://p/1', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
        ]];
        $unguarded = ['$id' => 'x://p/2', 'type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'bag' => ['type' => 'object', 'default' => []],
        ], 'required' => ['name', 'bag']];

        $demoted = MigrationLadder::default()->migrate(['name' => 'Ada'], $from, $unguarded);

        $this->assertNotSame(
            'structural',
            $demoted->rung,
            'the structural rung must NOT accept `[]` for an object-typed field — if it does, '
            .'the gate has stopped validating and this whole guard is unmotivated',
        );

        // And the guarded form — the keyword simply absent, which is what the generator emits —
        // migrates on the structural rung to the typed empty, exactly as it does today.
        $guarded = $unguarded;
        unset($guarded['properties']['bag']['default']);

        $result = MigrationLadder::default()->migrate(['name' => 'Ada'], $from, $guarded);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('structural', $result->rung);
    }

    public function test_a_consistent_default_migrates_to_the_declared_value(): void
    {
        // The payoff, end to end: the generated target schema drives the structural rung, and a
        // newly added field lands on its declared value instead of `emptyForType()`'s `0`.
        $generated = (new JsonSchemaGenerator)
            ->generate(new ReflectionClass(DeclaredDefaultsData::class));

        // The added fields only — the fixture also carries a nullable `$ref` and an `Optional`,
        // neither of which the structural rung claims to fill, and neither of which this test
        // is about.
        $keep = ['name', 'limit', 'colour', 'status', 'tags'];

        $to = [
            '$id' => 'x://declared-defaults/2',
            'type' => 'object',
            'properties' => array_intersect_key($generated['properties'], array_flip($keep)),
            'required' => $keep,
            '$defs' => $generated['$defs'],
        ];

        $from = ['$id' => 'x://declared-defaults/1', 'type' => 'object', 'properties' => [
            'name' => $generated['properties']['name'],
        ], 'required' => ['name']];

        $result = MigrationLadder::default()->migrate(['name' => 'Ada'], $from, $to);

        $this->assertTrue($result->wasMigrated(), 'the generated schema must still be migratable');
        $this->assertSame(100, $result->migrated['limit']);
        $this->assertSame('RED', $result->migrated['colour']);
        $this->assertSame('published', $result->migrated['status']);
        $this->assertSame(['alpha'], $result->migrated['tags']);
    }
}
