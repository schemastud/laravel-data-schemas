<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\DataSchemas\Support\OpisSchema;

/**
 * beam-facade ticket 51 — the boolean gate and the formatted intake door prepare a schema document
 * identically, so a RELATIVE `$id` (the shape a bare form ref stems to) can no longer pass one door
 * and be reported non-conforming at the other.
 *
 * The old asymmetry was not a wrong verdict, it was a MISREAD exception: opis threw while parsing the
 * `$id`, and {@see AcceptanceGate::accepts()}'s deliberate fail-closed `catch` turned that into
 * *does not conform*. Downstream (beam's `ParticleWriter`) that is a `PayloadRejected` — a 500 on a
 * correct payload. The `catch` is untouched here and stays fail-closed; what changed is that a
 * relative `$id` is no longer something opis cannot parse.
 */
class AcceptanceGateRelativeIdTest extends TestCase
{
    private function relativeIdSchema(): array
    {
        return [
            '$id' => 'waitlist/1',
            'type' => 'object',
            'required' => ['email'],
            'properties' => ['email' => ['type' => 'string']],
        ];
    }

    public function test_accepts_a_conforming_candidate_against_a_relative_id_schema(): void
    {
        $this->assertTrue((new AcceptanceGate)->accepts(['email' => 'a@b.test'], $this->relativeIdSchema()));
    }

    public function test_still_refuses_a_non_conforming_candidate_against_a_relative_id_schema(): void
    {
        // The strip must not turn this into a gate that waves everything through: the shape is still
        // enforced in place.
        $this->assertFalse((new AcceptanceGate)->accepts(['email' => 42], $this->relativeIdSchema()));
    }

    public function test_an_absolute_id_survives_the_preparation_untouched(): void
    {
        // A registry-addressed artifact keeps its identity through validation — only the unresolvable
        // relative form is dropped.
        $absolute = ['$id' => 'https://schemas.example.test/waitlist/1', 'type' => 'object'];

        $this->assertSame($absolute, OpisSchema::withoutRelativeId($absolute));
        $this->assertTrue((new AcceptanceGate)->accepts(['email' => 'a@b.test'], $absolute));
    }

    public function test_a_schema_with_no_id_is_unchanged(): void
    {
        $schema = ['type' => 'object'];

        $this->assertSame($schema, OpisSchema::withoutRelativeId($schema));
    }
}
