<?php

namespace Schemastud\DataSchemas\Strategies;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The class `config('data-schemas.strategies')` never had.
 *
 * The strategy pipeline is a real, working, five-registrant cross-vendor registry — this package seeds
 * three, `schemastud/laravel-frame` appends four, `rushing/laravel-data-filters`,
 * `splicewire/laravel-composition-spine` and `splicewire/laravel-composition-engine` one apiece, each by
 * reading the array in its provider, appending under an `in_array` guard and writing it back. What it
 * has never had is a class: no container binding, no attribute, and so no way for the index or the
 * surgeon gate to see it at all. This is that class, and nothing else — the config array stays the
 * storage (see {@see ConfigRegistry}).
 *
 * ## Keys are derived from the class, deliberately and here
 *
 * The config value is a LIST, so `ConfigRegistry::keyFor()` refuses to invent keys for it. The entries
 * are class-strings, so the derivation is {@see Key::fromClass()} — the kernel's explicit, opt-in
 * class→key conversion — and stating it here is what makes it a declaration by the owner rather than a
 * guess by the kernel. `ValidationAttributeStrategy` addresses as
 * `schemas.strategies.validation-attribute-strategy`.
 *
 * Two live properties fall out of it and are worth naming. Ordinals were the alternative and would
 * renumber every key the first time a package appended, which is the one thing this array does
 * constantly. And a class-derived key makes the estate's hand-rolled `in_array($strategy, $strategies)`
 * dedupe guard exactly {@see OnDuplicate::Supersede} — the same idempotence, spelled once in the kernel
 * instead of five times across three vendors.
 *
 * The generator walks the whole pipeline in order and each
 * strategy contributes keywords to the schema built so far. Registration order is the config array's
 * order, which is the pipeline's order — the ordering guarantee is load-bearing here, not incidental.
 */
#[IsRegistry(
    root: 'schemas.strategies',
    entryType: 'class-string<'.SchemaStrategy::class.'>',
    onDuplicate: OnDuplicate::Supersede,
    description: 'SchemaStrategy implementations — the ordered property pipeline each reflected property is walked through, every one free to contribute keywords to the schema so far. Storage is `config(\'data-schemas.strategies\')`, a LIST of class-strings appended to by five packages across three vendors from their own providers. Keys are derived per entry via Key::fromClass(); the config path is unchanged and every existing consumer still reads the plain list.',
)]
class SchemaStrategiesRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'data-schemas.strategies';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return is_string($entry)
            ? Key::fromClass($entry)
            : Key::fromClass($entry::class);
    }
}
