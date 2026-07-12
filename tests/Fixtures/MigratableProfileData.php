<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\MigrateWith;
use Schemastud\DataSchemas\Attributes\WasNamed;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A versioned Data class carrying the migration vocabulary:
 *  - #[WasNamed] declares a rename -> x-migrate-from
 *  - #[MigrateWith(class)] pins a custom transform -> x-migrate
 *  - #[MigrateWith('llm')] opts a field into the host LLM rung -> x-migrate: llm
 */
class MigratableProfileData extends Data implements SchemaIdentity
{
    public function __construct(
        #[WasNamed('full_name')]
        public string $displayName,
        #[MigrateWith(StubAddressTransform::class)]
        public string $address,
        #[MigrateWith(MigrateWith::LLM)]
        public string $bio,
    ) {}

    public static function schemaName(): string
    {
        return 'people/profile';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }
}
