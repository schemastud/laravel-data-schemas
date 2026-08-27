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
    ) {}

    public function hasDrift(): bool
    {
        return $this->drifted !== [];
    }
}
