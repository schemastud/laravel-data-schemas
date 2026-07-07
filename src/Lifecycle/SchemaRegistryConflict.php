<?php

namespace Rushing\LaravelDataSchemas\Lifecycle;

use RuntimeException;

/**
 * Thrown when an in-place overwrite of an already-frozen `$id` is attempted
 * with a schema whose structural fingerprint differs from the stored one.
 *
 * Registry versions are write-once: a new shape requires a new `$id`/version.
 */
class SchemaRegistryConflict extends RuntimeException
{
    public function __construct(
        public string $id,
        public string $existingFingerprint,
        public string $incomingFingerprint,
    ) {
        parent::__construct(sprintf(
            'Refusing to overwrite frozen schema "%s": stored fingerprint %s differs from incoming %s. '
            .'A changed shape requires a new $id/version.',
            $id,
            substr($existingFingerprint, 0, 12),
            substr($incomingFingerprint, 0, 12),
        ));
    }
}
