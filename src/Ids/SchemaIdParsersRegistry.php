<?php

namespace Schemastud\DataSchemas\Ids;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The registered ref grammars — `config('data-schemas.id_parsers')`, declared.
 *
 * Storage is a LIST of class-strings appended to by whichever packages spell their refs their own
 * way, from their own providers, exactly as `config('data-schemas.strategies')` is (see
 * {@see \Schemastud\DataSchemas\Strategies\SchemaStrategiesRegistry}, whose shape this mirrors
 * deliberately — a second spelling of "a registry whose storage is a config array" is the thing the
 * adapter exists to prevent). It ships EMPTY: this package's own grammar is
 * {@see RelativeSchemaIdParser}, which is {@see SchemaIdResolver}'s floor rather than an entry here,
 * so an appended parser is always consulted before the default and never has to out-order it.
 *
 * `PickOne`, because a ref has exactly one grammar. The read asks each registrant `handles()` in
 * registration order and stops at the first claimer — it is not a pipeline, and two parsers
 * rewriting the same string in turn would be a grammar nobody declared.
 *
 * Keys are derived per entry via {@see Key::fromClass()}, for the reason spelled out at length on the
 * strategies registry: the config value is a list, so `ConfigRegistry::keyFor()` refuses to invent
 * keys for it, and an ordinal would renumber every key the first time somebody appends.
 * `OnDuplicate::Supersede` makes the estate's habitual `in_array($parser, $parsers)` append guard
 * the kernel's idempotence rather than a hand-rolled one per registrant.
 *
 * ⚠️ One consequence of shipping empty, which the strategies registry never exhibits because it ships
 * three entries: {@see ConfigRegistry::register()} treats an EMPTY array as a MAP, so the first
 * registrant lands keyed (`['prefixing-schema-id-parser' => …]`) rather than appended. That is the
 * adapter behaving as documented, not a defect — {@see SchemaIdResolver} takes `array_values()` and
 * reads either shape, so a package may equally append to the config list directly, which is what the
 * estate's five strategy registrants already do.
 */
#[IsRegistry(
    root: 'schemas.id-parsers',
    of: 'SchemaIdParser implementations — how a package\'s schema REFS parse into a resolved namespace URI, given the host\'s declared authority',
    arity: RegistryArity::PickOne,
    entryType: 'class-string<'.SchemaIdParser::class.'>',
    onDuplicate: OnDuplicate::Supersede,
    note: 'Storage is `config(\'data-schemas.id_parsers\')`, a LIST of class-strings. Ships empty; the package\'s own absolute/relative grammar is SchemaIdResolver\'s unshadowable floor, not an entry. First claimer by `handles()` wins, in registration order.',
)]
class SchemaIdParsersRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'data-schemas.id_parsers';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return is_string($entry)
            ? Key::fromClass($entry)
            : Key::fromClass($entry::class);
    }
}
