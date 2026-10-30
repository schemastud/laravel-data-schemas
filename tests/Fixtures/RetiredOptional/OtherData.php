<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\RetiredOptional;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

class OtherData extends Data implements SchemaIdentity
{
    public function __construct(public string $name) {}
    public static function schemaName(): string { return 'other-retired-control'; }
    public static function schemaVersion(): int { return 2; }
}
