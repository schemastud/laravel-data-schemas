<?php

namespace Schemastud\DataSchemas\Lifecycle;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;

/**
 * The structured outcome of a {@see SchemaDriftGuard} sweep.
 *
 * `checked` is every {@see SchemaIdentity}
 * class discovered and projected. `drifted` is the subset whose current
 * structural fingerprint diverges from the frozen artifact registered under the
 * same `$id` — i.e. a shape change that was NOT accompanied by a version bump.
 * `unfrozen` is the subset whose `$id` is not yet in the frozen store (a new or
 * version-bumped class) — accepted by the guard; `schema:freeze` will record it.
 */
class SchemaDriftResult
{
    /**
     * @param  list<class-string>  $checked
     * @param  list<SchemaDriftEntry>  $drifted
     * @param  list<class-string>  $unfrozen
     */
    public function __construct(
        public array $checked,
        public array $drifted,
        public array $unfrozen,
        /**
         * Frozen artifacts whose ONLY delta is added `default` keywords on a first version
         * (api-surface-coherence 122), or removed retired `x-optional` keywords (owner-review 39). Not drift: re-freezable in place under the same
         * `$id`. Defaulted so existing callers constructing a result with three arguments keep working.
         *
         * @var list<SchemaDriftEntry>
         */
        public array $refreezable = [],
    ) {}

    public function hasDrift(): bool
    {
        return $this->drifted !== [];
    }
}
