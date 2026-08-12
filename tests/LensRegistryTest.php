<?php

namespace Schemastud\DataSchemas\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Overlay\Lens\DirectedLens;
use Schemastud\DataSchemas\Overlay\Lens\Direction;
use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Schemastud\DataSchemas\Overlay\Lens\IdentityLens;
use Schemastud\DataSchemas\Overlay\Lens\LensAssociation;
use Schemastud\DataSchemas\Overlay\Lens\LensEvidence;
use Schemastud\DataSchemas\Overlay\Lens\LensRegistration;
use Schemastud\DataSchemas\Overlay\Lens\LensRegistry;
use Schemastud\DataSchemas\Overlay\Lens\LensTier;

// Ticket 11 — the lens registry and its host-applied / engine-authoritative tier
// axis. The motivating failure was a search for lens usage that found nothing
// because there was nothing to search; these assert the enumeration surface, the
// tier axis, and that fidelity is CERTIFIED here rather than self-reported.
class LensRegistryTest extends TestCase
{
    public function test_it_enumerates_registered_lenses_in_registration_order(): void
    {
        $registry = (new LensRegistry)
            ->register($this->registration('host/one'))
            ->register($this->registration('host/two'));

        $this->assertSame(['host/one', 'host/two'], $registry->keys());
        $this->assertCount(2, $registry->all());
        $this->assertSame('host/two', $registry->get('host/two')?->key);
        $this->assertNull($registry->get('host/nope'));
    }

    public function test_each_registration_carries_its_tier_and_the_tiers_are_separable(): void
    {
        $registry = (new LensRegistry)
            ->register($this->registration('host/applied', LensTier::HostApplied))
            ->register($this->registration('engine/binding', LensTier::EngineAuthoritative));

        $this->assertSame(
            ['host/applied'],
            array_map(fn ($r) => $r->key, $registry->ofTier(LensTier::HostApplied)),
        );
        $this->assertSame(
            ['engine/binding'],
            array_map(fn ($r) => $r->key, $registry->ofTier(LensTier::EngineAuthoritative)),
        );
    }

    public function test_two_lenses_may_share_one_canonical_id_and_both_stay_visible(): void
    {
        // The motivating satellite declares two vendor downscales of ONE OTIO canonical.
        // Keying on the association's `@id` would have silently evicted one of them.
        $registry = (new LensRegistry)
            ->register($this->registration('vendor/minimax', id: 'app/timeline-otio'))
            ->register($this->registration('vendor/elevenlabs', id: 'app/timeline-otio'));

        $this->assertSame(
            ['vendor/minimax', 'vendor/elevenlabs'],
            array_map(fn ($r) => $r->key, $registry->forId('app/timeline-otio')),
        );
        $this->assertSame([], $registry->forId('app/unknown'));
    }

    public function test_a_duplicate_key_throws_rather_than_overwriting(): void
    {
        $registry = (new LensRegistry)->register($this->registration('host/one'));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->registration('host/one'));
    }

    public function test_an_unexercised_lossless_claim_certifies_lossy(): void
    {
        // No evidence: the laws over zero samples are vacuously true, so "submitted
        // nothing" must not read as "survived everything".
        $registry = (new LensRegistry)->register(new LensRegistration(
            key: 'host/unexercised',
            tier: LensTier::HostApplied,
            association: LensAssociation::bijective('app/thing', '$', new IdentityLens),
        ));

        $this->assertSame(Fidelity::LosslessEligible, $registry->get('host/unexercised')?->association()->fidelity);
        $this->assertSame(Fidelity::Lossy, $registry->certifiedFidelity('host/unexercised'));
    }

    public function test_a_lossless_claim_with_surviving_evidence_certifies_lossless(): void
    {
        $registry = (new LensRegistry)->register(new LensRegistration(
            key: 'host/exercised',
            tier: LensTier::HostApplied,
            association: LensAssociation::bijective('app/thing', '$', new IdentityLens),
            evidence: new LensEvidence(['a', 'b'], [['c', 'a']]),
        ));

        $this->assertSame(Fidelity::LosslessEligible, $registry->certifiedFidelity('host/exercised'));
    }

    public function test_a_law_breaking_lens_is_downgraded_despite_its_claim(): void
    {
        $registry = (new LensRegistry)->register(new LensRegistration(
            key: 'host/liar',
            tier: LensTier::HostApplied,
            association: LensAssociation::bijective('app/thing', '$', new TruncatingLens),
            evidence: new LensEvidence(['keep-all-of-this']),
        ));

        $this->assertSame(Fidelity::LosslessEligible, $registry->get('host/liar')?->association()->fidelity);
        $this->assertSame(Fidelity::Lossy, $registry->certifiedFidelity('host/liar'));
    }

    public function test_an_unknown_key_certifies_lossy_rather_than_lossless(): void
    {
        $this->assertSame(Fidelity::Lossy, (new LensRegistry)->certifiedFidelity('host/never-registered'));
    }

    public function test_a_registration_exposes_no_bare_fidelity_accessor(): void
    {
        // The claim lives on the association, documented as a claim. If a `fidelity()`
        // reader ever appears here, a consumer will decide a write verb from a
        // self-report — the exact thing certification exists to refuse.
        $this->assertFalse(method_exists(LensRegistration::class, 'fidelity'));
    }

    public function test_a_lazy_association_is_built_once_on_first_read(): void
    {
        $built = 0;

        $registration = new LensRegistration(
            key: 'host/lazy',
            tier: LensTier::HostApplied,
            association: function () use (&$built) {
                $built++;

                return LensAssociation::lossy('app/thing', '$', new IdentityLens);
            },
        );

        $this->assertSame(0, $built, 'registration must not build the association');

        $registration->association();
        $registration->association();

        $this->assertSame(1, $built);
    }

    public function test_lazy_evidence_is_only_built_when_a_verdict_is_asked_for(): void
    {
        $built = 0;

        $registry = (new LensRegistry)->register(new LensRegistration(
            key: 'host/lazy-evidence',
            tier: LensTier::HostApplied,
            association: LensAssociation::bijective('app/thing', '$', new IdentityLens),
            evidence: new LensEvidence(function () use (&$built) {
                $built++;

                return ['sample'];
            }),
        ));

        $registry->all();

        $this->assertSame(0, $built);
        $this->assertSame(Fidelity::LosslessEligible, $registry->certifiedFidelity('host/lazy-evidence'));
        $this->assertSame(1, $built);
    }

    public function test_describe_renders_a_row_per_registration_with_tier_and_certified_fidelity(): void
    {
        $registry = (new LensRegistry)
            ->register(new LensRegistration(
                key: 'host/applied',
                tier: LensTier::HostApplied,
                association: new LensAssociation(
                    id: 'app/song',
                    locator: '$',
                    direction: Direction::CanonicalToRendering,
                    fidelity: Fidelity::Lossy,
                    lens: new IdentityLens,
                ),
                owner: 'vendor/app',
                of: 'song → timeline',
            ))
            ->register(new LensRegistration(
                key: 'engine/binding',
                tier: LensTier::EngineAuthoritative,
                association: LensAssociation::bijective('app/spine', '$', new IdentityLens),
                evidence: new LensEvidence(['a']),
                owner: 'vendor/engine',
            ));

        $rows = $registry->describe();

        $this->assertSame([
            'key' => 'host/applied',
            'tier' => 'host-applied',
            'owner' => 'vendor/app',
            'of' => 'song → timeline',
            'id' => 'app/song',
            'locator' => '$',
            'direction' => 'canonical-to-rendering',
            'lens' => IdentityLens::class,
            'fidelity' => 'lossy',
            'certified' => false,
            'complement' => false,
        ], $rows[0]);

        $this->assertSame('engine-authoritative', $rows[1]['tier']);
        $this->assertSame('lossless-eligible', $rows[1]['fidelity']);
        $this->assertTrue($rows[1]['certified']);
    }

    public function test_the_tiers_state_what_registration_does_and_does_not_claim(): void
    {
        $this->assertStringContainsString('authoritative nowhere', LensTier::HostApplied->blurb());
        $this->assertStringContainsString('every host', LensTier::EngineAuthoritative->blurb());
    }

    private function registration(
        string $key,
        LensTier $tier = LensTier::HostApplied,
        string $id = 'app/thing',
    ): LensRegistration {
        return new LensRegistration(
            key: $key,
            tier: $tier,
            association: LensAssociation::lossy($id, '$', new IdentityLens),
        );
    }
}

// A lens that claims bijection and drops state — the case certification exists for.
class TruncatingLens implements DirectedLens
{
    public function get(mixed $canonical): mixed
    {
        return substr((string) $canonical, 0, 4);
    }

    public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
    {
        return $rendering;
    }
}
