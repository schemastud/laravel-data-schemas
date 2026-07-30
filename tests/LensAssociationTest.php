<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Overlay\Lens\ComplementingLens;
use Schemastud\DataSchemas\Overlay\Lens\DirectedLens;
use Schemastud\DataSchemas\Overlay\Lens\Direction;
use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Schemastud\DataSchemas\Overlay\Lens\IdentityLens;
use Schemastud\DataSchemas\Overlay\Lens\LensAssociation;

// Issue 01 — the reversible directed-lens VO. These assert the *declaration*
// shape (core-5 + reserved complement) and the identity free-case. The laws
// (GetPut/PutGet) and the reversible resolver are issue 02.
class LensAssociationTest extends TestCase
{
    public function test_core_five_fields_land_on_an_association(): void
    {
        $lens = new IdentityLens;

        $assoc = new LensAssociation(
            id: 'urn:splice:content:42',
            locator: '$.body',
            direction: Direction::CanonicalToRendering,
            fidelity: Fidelity::LosslessEligible,
            lens: $lens,
        );

        $this->assertSame('urn:splice:content:42', $assoc->id);
        $this->assertSame('$.body', $assoc->locator);
        $this->assertSame(Direction::CanonicalToRendering, $assoc->direction);
        $this->assertSame(Fidelity::LosslessEligible, $assoc->fidelity);
        $this->assertSame($lens, $assoc->lens);
    }

    public function test_complement_is_reserved_and_null_by_default(): void
    {
        $assoc = LensAssociation::bijective('urn:x', '$.body', new IdentityLens);

        $this->assertNull($assoc->complement);
        $this->assertFalse($assoc->hasComplement());
    }

    public function test_direction_names_which_side_is_canonical(): void
    {
        // The natural orientation: id names the canonical, get runs id → locator.
        $this->assertSame('canonical-to-rendering', Direction::CanonicalToRendering->value);
        $this->assertSame('rendering-to-canonical', Direction::RenderingToCanonical->value);
    }

    public function test_fidelity_tags_the_honest_round_trip_claim(): void
    {
        $this->assertSame('lossless-eligible', Fidelity::LosslessEligible->value);
        $this->assertSame('lossy', Fidelity::Lossy->value);

        $eligible = LensAssociation::bijective('urn:x', '$.body', new IdentityLens);
        $lossy = LensAssociation::lossy('urn:x', '$.body', new IdentityLens);

        $this->assertSame(Fidelity::LosslessEligible, $eligible->fidelity);
        $this->assertSame(Fidelity::Lossy, $lossy->fidelity);
    }

    public function test_identity_lens_round_trips_byte_identical_the_free_case(): void
    {
        $lens = new IdentityLens;
        $canonical = ['type' => 'string', 'body' => ['a' => 1, 'b' => [2, 3]]];

        // get then put with no edit reconstructs the canonical exactly.
        $rendering = $lens->get($canonical);
        $this->assertSame($canonical, $rendering);
        $this->assertSame($canonical, $lens->put($rendering, $canonical));
    }

    public function test_complement_slot_materialises_immutably(): void
    {
        $assoc = LensAssociation::bijective('urn:x', '$.body', new IdentityLens);
        $withComplement = $assoc->withComplement(['otio-private' => true]);

        // Immutable: the original stays reserved-null.
        $this->assertNull($assoc->complement);
        $this->assertFalse($assoc->hasComplement());

        $this->assertSame(['otio-private' => true], $withComplement->complement);
        $this->assertTrue($withComplement->hasComplement());
    }

    public function test_a_complementing_lens_extracts_rendering_private_state(): void
    {
        // A minimal earned-lossless stand-in: the rendering carries an extra
        // 'ui' key absent from the canonical; complementOf() lifts it out so put
        // can restore it. (The real music↔OTIO lens is issue 04.)
        $lens = new class implements ComplementingLens
        {
            public function get(mixed $canonical): mixed
            {
                return ['text' => $canonical['text'] ?? '', 'ui' => 'default'];
            }

            public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
            {
                return ['text' => $rendering['text']];
            }

            public function complementOf(mixed $rendering, mixed $canonical): mixed
            {
                return ['ui' => $rendering['ui'] ?? null];
            }
        };

        $this->assertSame(['ui' => 'collapsed'], $lens->complementOf(['text' => 'hi', 'ui' => 'collapsed'], ['text' => 'hi']));
        $this->assertInstanceOf(DirectedLens::class, $lens);
    }
}
