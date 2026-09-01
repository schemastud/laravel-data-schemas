<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Schemastud\DataSchemas\Fixtures\FixtureIndex;
use Schemastud\DataSchemas\Overlay\Lens\LensRegistry;

/**
 * registry-kernel ticket 74 — this package's composing registries forward the pushed authorizer into
 * the {@see BasicRegistry} they hold.
 *
 * ## Behavioural, never reflective
 *
 * Both wrong numbers ticket 74 corrected came from reflection probes: one read `MISSING` without asking
 * why, the other read through an accessor that nulls the authorizer by design and reported 55 missing of
 * 64. So this installs a deny-all authorizer against an ability-carrying entry and reads the registry's
 * own public surface — the consequence, not the field.
 *
 * The probe is seeded through the composed store because the outer `register()` signatures narrow their
 * key and entry types per registry; the ASSERTION is entirely through the public read.
 */
class ComposedRegistryForwardsAuthorizerTest extends TestCase
{
    private const PROBE_KEY = 'ticket-74-probe';

    private const PROBE_ABILITY = 'ticket-74.probe';

    /** @return array<string, array{0: callable(): Registry, 1: string}> registry factory, composed-store property */
    public static function forwardingRegistries(): array
    {
        return [
            'schemas.lenses' => [fn () => new LensRegistry, 'store'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forwardingRegistries')]
    public function test_a_deny_all_authorizer_reaches_the_composed_store(callable $make, string $property): void
    {
        /** @var Registry&Gated $registry */
        $registry = $make();

        $this->assertInstanceOf(Gated::class, $registry);

        $store = new ReflectionProperty($registry, $property);
        $store->setAccessible(true);

        /** @var BasicRegistry $inner */
        $inner = $store->getValue($registry);
        $inner->register(self::PROBE_KEY, 'probe-entry', by: self::class, ability: self::PROBE_ABILITY);

        $this->assertTrue(
            $this->probeVisible($registry),
            'the probe entry must be visible before an authorizer is installed — otherwise the deny below proves nothing',
        );

        $registry->authorizeWith(new Ticket74DenyAllAuthorizer);

        $this->assertFalse(
            $this->probeVisible($registry),
            'a deny-all authorizer installed on the outer registry did not reach the composed store',
        );

        $registry->authorizeWith(null);

        $this->assertTrue(
            $this->probeVisible($registry),
            'removing the authorizer must reopen the surface — last-wins, per Gated',
        );
    }

    /**
     * `schemas.fixtures` is the trusted-shell population's member in this package: its non-`Gated` status
     * is a ruling with an argument in the class docblock, not an omission. If it ever gains `Gated`, the
     * refusal beside it has gone stale and must be removed in the same change.
     */
    public function test_the_fixture_index_refuses_gated_and_argues_it(): void
    {
        $this->assertFalse(
            is_subclass_of(FixtureIndex::class, Gated::class),
            'FixtureIndex gained Gated — remove the docblock refusal in the same change',
        );

        $this->assertStringContainsString(
            '`Gated` is deliberately absent',
            file_get_contents((new \ReflectionClass(FixtureIndex::class))->getFileName()),
            'FixtureIndex does not implement Gated and does not say why — ticket 20 requires the refusal be argued',
        );
    }

    /**
     * Whether the probe key is visible on the registry's own public read.
     *
     * Matched by substring because `BasicRegistry::door()` stamps the declared root onto a relative key,
     * so the stored spelling differs per registry — the key TYPE is each registry's business, not this
     * test's.
     */
    private function probeVisible(Registry $registry): bool
    {
        foreach ($registry->keys() as $key) {
            if (str_contains((string) $key, self::PROBE_KEY)) {
                return true;
            }
        }

        return false;
    }
}

/** Denies everything it is asked about — the only authorizer shape that can prove a push arrived. */
class Ticket74DenyAllAuthorizer implements Authorizer
{
    public function allows(string $ability, RegistryKey $key): bool
    {
        return false;
    }
}
