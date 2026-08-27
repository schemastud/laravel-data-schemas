<?php

namespace Schemastud\DataSchemas\Fixtures;

use Rushing\Popcorn\Registries\ClassKey;
use Spatie\LaravelData\Support\Creation\CreationContext;

/**
 * Fixture-factory sugar for a declared Data class: `SomeData::factory()->enterprise()->make()`.
 *
 * ## A trait, and deliberately not a method on a base class
 *
 * {@see \Schemastud\DataSchemas\StudData}'s own docblock says the `extends` slot is contested — six
 * abstract Data bases already exist in this family (`SyncData` twice, `StreamingData`,
 * `LineageSnapshotData`, `OtioData`, `SecretData`) — and that a class under one of those reaches the
 * same behaviour with `use DerivesJsonSchema`. A base-class method would reach none of them, and
 * `SyncData` is precisely a shape someone would want fixtures for. So this follows the established
 * pattern: a trait beside the abstract class, usable without it.
 *
 * ## `::factory()` stays spatie's
 *
 * The override is covariant — {@see FixtureFactory} extends `CreationContextFactory` — so spatie's
 * contract holds, `::factory()` remains the single entry point, and every creation control it ships
 * keeps working and composes with the fixture behaviour. Nothing is shadowed and no global helper is
 * introduced.
 *
 * ## Two seams, so a tier overrides one method
 *
 *     factoryRegistry()  WHICH registry answers
 *     fixtureKey()       WHAT this class is keyed by there
 *
 * That is also where "must a fixture name a declared surface?" is decided — and the answer is not a
 * property of this tier but of whichever registry answers. This package knows about schemas, not
 * resources, so it keys by {@see ClassKey} and accepts anything; a tier that owns a resource registry
 * overrides `fixtureKey()` and can refuse an undeclared class.
 */
trait HasFixtures
{
    public static function factory(?CreationContext $creationContext = null): FixtureFactory
    {
        return FixtureFactory::promote(
            parent::factory($creationContext),
            static::fixtureKey(),
            static::factoryRegistry(),
        );
    }

    /** SEAM 1 — override to point a tier at its own registry. */
    protected static function factoryRegistry(): FixtureIndex
    {
        return app(FixtureIndex::class);
    }

    /**
     * SEAM 2 — the FALLBACK key.
     *
     * {@see ClassKey} rather than `Key::fromClass()` because the latter reduces to the basename, and
     * that loss is not theoretical: measured across the splicewire estate, 17 `*Data` basenames name
     * more than one class. Under `Supersede` those would collide silently. A tier with a shorter
     * declared name — a resource key — should override this and leave the class key as the fallback
     * for shapes that have none.
     */
    protected static function fixtureKey(): string
    {
        return (string) ClassKey::of(static::class);
    }

    /**
     * Defaults derived from the class's OWN schema — `#[Example]` values, which the generator emits as
     * `examples`. This is what makes this package the right home: a shape gets a working fixture with
     * ZERO registration, and states become the only thing anyone registers. A tier that does not own
     * schema derivation cannot offer that.
     *
     * @return array<string, mixed>
     */
    public static function fixtureDefaults(): array
    {
        if (! method_exists(static::class, 'jsonSchema')) {
            return [];
        }

        $defaults = [];

        foreach (static::jsonSchema()['properties'] ?? [] as $name => $property) {
            if (is_array($property) && array_key_exists('examples', $property) && $property['examples'] !== []) {
                $defaults[$name] = $property['examples'][0];
            }
        }

        return $defaults;
    }
}
