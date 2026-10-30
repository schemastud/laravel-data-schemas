<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\RetiredOptional;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class OptionalData extends Data implements SchemaIdentity
{
    public function __construct(public string|Optional $nickname) {}
    public static function schemaName(): string { return 'retired-optional'; }
    public static function schemaVersion(): int { return 2; }
}
