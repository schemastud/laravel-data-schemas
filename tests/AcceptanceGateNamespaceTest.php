<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\JsonNs\Vocab\VocabularyRegistry;
use Schemastud\JsonNs\Vocab\VocabularyValidator;

/**
 * beam-namespace-wiring ticket 03 — the pipeline write path's boolean gate enforces the SAME
 * per-namespace `$vocabulary` scoping the formatted intake door does (ticket 02), so a
 * namespaced document can never pass one door and not the other. The fixture MIRRORS ticket
 * 02's (laravel-beam `tests/Validation/SchemaFormValidatorTest` +
 * `tests/Intake/PublicIntakeRouteTest`): same vocabulary shape (`sources` required), same
 * schema declarations, same payloads.
 */
class AcceptanceGateNamespaceTest extends TestCase
{
    private const VOCAB_URI = 'https://schemas.splicewire.app/splice/grounding-test';

    private function namespacedGate(): AcceptanceGate
    {
        $registry = VocabularyRegistry::make()->registerJson(self::VOCAB_URI, (string) json_encode([
            'type' => 'object',
            'required' => ['sources'],
            'properties' => ['sources' => ['type' => 'array', 'minItems' => 1]],
        ]));

        return new AcceptanceGate(vocabularies: new VocabularyValidator($registry));
    }

    private function namespacedTargetSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string'],
                'splice:grounding' => ['type' => 'object'],
            ],
            '@namespace' => ['splice' => self::VOCAB_URI],
        ];
    }

    public function test_accepts_a_conforming_namespaced_candidate_in_parity_with_the_intake_door(): void
    {
        $this->assertTrue($this->namespacedGate()->accepts(
            ['title' => 'ok', 'splice:grounding' => ['sources' => ['ctx://a']]],
            $this->namespacedTargetSchema(),
        ));
    }

    public function test_rejects_the_candidate_ticket_02_proved_fails_the_intake_door(): void
    {
        // Structurally valid, but the namespaced subtree violates its $vocabulary (`sources`
        // missing) — the same payload SchemaFormValidator returns a formatted error for.
        $this->assertFalse($this->namespacedGate()->accepts(
            ['title' => 'ok', 'splice:grounding' => ['nope' => true]],
            $this->namespacedTargetSchema(),
        ));
    }

    public function test_keeps_the_plain_structural_gate_for_a_non_namespaced_target(): void
    {
        $schema = ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]];

        $this->assertTrue($this->namespacedGate()->accepts(['title' => 'ok'], $schema));
        $this->assertFalse($this->namespacedGate()->accepts([], $schema));
    }

    public function test_stays_a_plain_structural_gate_when_no_enforcement_engine_is_available(): void
    {
        // A host with no json-ns wiring: the bare gate accepts a namespaced target's structurally
        // valid candidate — enforcement is additive, never a boot requirement.
        $bare = new AcceptanceGate;

        $this->assertTrue($bare->accepts(
            ['title' => 'ok', 'splice:grounding' => ['nope' => true]],
            $this->namespacedTargetSchema(),
        ));
    }
}
