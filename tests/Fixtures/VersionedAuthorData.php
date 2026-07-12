<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A versionable NESTED node: opts into versioning, so when nested it projects
 * its own absolute `$id` and is referenced by it.
 */
class VersionedAuthorData extends Data implements SchemaIdentity
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    public static function schemaName(): string
    {
        return 'content/author';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }
}
