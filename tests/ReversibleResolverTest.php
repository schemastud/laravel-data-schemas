<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Overlay\Lens\ComplementingLens;
use Schemastud\DataSchemas\Overlay\Lens\DirectedLens;
use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Schemastud\DataSchemas\Overlay\Lens\IdentityLens;
use Schemastud\DataSchemas\Overlay\Lens\LensAssociation;
use Schemastud\DataSchemas\Overlay\Lens\LensLaws;
use Schemastud\DataSchemas\Overlay\Lens\ReversibleResolver;

// Issue 02 — the reversible resolver + lens-law enforcement. Exercises all three
// satisfiable cases (bijective / asymmetric / symmetric-with-complement), the
// free identity case, and the honest downgrade of a law-breaking lens.
class ReversibleResolverTest extends TestCase
{
    private ReversibleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ReversibleResolver;
    }

    public function test_null_association_resolves_as_identity_the_free_case(): void
    {
        $canonical = ['type' => 'string', 'body' => ['a' => 1, 'b' => [2, 3]]];

        // No lens declared → get/put are identity → byte-identical round-trip.
        $rendering = $this->resolver->get(null, $canonical);
        $this->assertSame($canonical, $rendering);
        $this->assertSame($canonical, $this->resolver->put(null, $rendering, $canonical));
    }

    public function test_bijective_lens_round_trips(): void
    {
        $assoc = LensAssociation::bijective('urn:x', '$', new IdentityLens);
        $canonical = ['text' => 'hello', 'n' => 3];

        $rendering = $this->resolver->get($assoc, $canonical);
        $edited = ['text' => 'HELLO', 'n' => 3];
        $folded = $this->resolver->put($assoc, $edited, $canonical);

        $this->assertSame($edited, $folded);
        $this->assertTrue(LensLaws::getPut($assoc->lens, $canonical));
        $this->assertTrue(LensLaws::putGet($assoc->lens, $edited, $canonical));
    }

    public function test_asymmetric_lens_reconstructs_dropped_state_from_prior_canonical(): void
    {
        // get drops the canonical-private `meta`; put restores it from the prior
        // canonical. Lossless despite a non-total projection (GetPut holds).
        $lens = new class implements DirectedLens
        {
            public function get(mixed $canonical): mixed
            {
                return ['text' => $canonical['text']]; // meta dropped
            }

            public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
            {
                return ['text' => $rendering['text'], 'meta' => $priorCanonical['meta']];
            }
        };

        $assoc = LensAssociation::bijective('urn:x', '$.text', $lens);
        $canonical = ['text' => 'hi', 'meta' => ['rev' => 7]];

        $rendering = $this->resolver->get($assoc, $canonical);
        $this->assertSame(['text' => 'hi'], $rendering);

        // Edit the rendering; put folds it back, meta reconstructed.
        $folded = $this->resolver->put($assoc, ['text' => 'bye'], $canonical);
        $this->assertSame(['text' => 'bye', 'meta' => ['rev' => 7]], $folded);

        $this->assertTrue(LensLaws::getPut($lens, $canonical));
        $this->assertTrue(LensLaws::putGet($lens, ['text' => 'bye'], $canonical));
    }

    public function test_symmetric_with_complement_reconstructs_rendering_private_state(): void
    {
        // The rendering carries private `layout` state absent from the canonical.
        // complementOf() lifts it out on the way out; put restores it. This is
        // the case that justifies the reserved complement slot (issue 04's shape).
        $lens = new class implements ComplementingLens
        {
            public function get(mixed $canonical): mixed
            {
                return ['text' => $canonical['text'], 'layout' => 'default'];
            }

            public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
            {
                // The canonical never learns about layout; it stays private,
                // preserved only in the complement.
                return ['text' => $rendering['text']];
            }

            public function complementOf(mixed $rendering, mixed $canonical): mixed
            {
                return ['layout' => $rendering['layout'] ?? 'default'];
            }
        };

        $assoc = LensAssociation::bijective('urn:x', '$', $lens);
        $canonical = ['text' => 'hi'];
        $rendering = $this->resolver->get($assoc, $canonical);
        $this->assertSame(['text' => 'hi', 'layout' => 'default'], $rendering);

        // GetPut holds for the canonical; the complement carries the private bit.
        $this->assertTrue(LensLaws::getPut($lens, $canonical));

        // With the complement materialised, put restores canonical faithfully.
        $withComplement = $assoc->withComplement($lens->complementOf($rendering, $canonical));
        $this->assertTrue($withComplement->hasComplement());
        $this->assertSame(['text' => 'hi'], $this->resolver->put($withComplement, $rendering, $canonical));
    }

    public function test_law_breaking_lens_is_downgraded_to_lossy_not_corrupting(): void
    {
        // A lens that claims lossless but whose put cannot reconstruct the
        // dropped `meta` (no prior-canonical fallback). GetPut fails.
        $lens = new class implements DirectedLens
        {
            public function get(mixed $canonical): mixed
            {
                return ['text' => $canonical['text']]; // meta dropped, never restored
            }

            public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
            {
                return ['text' => $rendering['text']]; // meta lost forever
            }
        };

        $assoc = LensAssociation::bijective('urn:x', '$.text', $lens); // declared lossless-eligible
        $this->assertSame(Fidelity::LosslessEligible, $assoc->fidelity);

        $certified = $this->resolver->certify($assoc, [['text' => 'hi', 'meta' => ['rev' => 1]]]);

        // The claim is downgraded honestly — content is NOT corrupted, the label is.
        $this->assertSame(Fidelity::Lossy, $certified->fidelity);
        $this->assertFalse(LensLaws::getPut($lens, ['text' => 'hi', 'meta' => ['rev' => 1]]));
    }

    public function test_certify_leaves_a_well_behaved_lens_eligible(): void
    {
        $assoc = LensAssociation::bijective('urn:x', '$', new IdentityLens);

        $certified = $this->resolver->certify(
            $assoc,
            canonicalSamples: [['a' => 1], ['b' => [1, 2, 3]]],
            renderingSamples: [[['a' => 9], ['a' => 1]]],
        );

        $this->assertSame(Fidelity::LosslessEligible, $certified->fidelity);
    }

    public function test_an_already_lossy_lens_is_left_honest(): void
    {
        $assoc = LensAssociation::lossy('urn:x', '$', new IdentityLens);
        $certified = $this->resolver->certify($assoc, [['a' => 1]]);

        $this->assertSame(Fidelity::Lossy, $certified->fidelity);
    }
}
