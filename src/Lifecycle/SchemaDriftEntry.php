<?php

namespace Schemastud\DataSchemas\Lifecycle;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;

/**
 * A single drifted {@see SchemaIdentity}
 * class: its current `$id`, the frozen vs. current structural fingerprints, and
 * a human-readable reason naming the remedy (bump version + freeze).
 */
class SchemaDriftEntry
{
    /**
     * @param  class-string  $class
     */
    public function __construct(
        public string $class,
        public string $id,
        public string $frozenFingerprint,
        public string $currentFingerprint,
        public string $reason,
    ) {}
}
