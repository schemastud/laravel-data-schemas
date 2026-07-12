<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A versioned ROOT that mixes an addressable nested node (VersionedAuthorData,
 * which opts in → absolute `$ref`) with an inlined nested node (UserData, which
 * does not opt in → `#/$defs/UserData`). Exercises a mixed addressable/inlined
 * tree.
 */
class VersionedArticleData extends Data implements SchemaIdentity
{
    public function __construct(
        public string $title,
        public VersionedAuthorData $author,
        public UserData $editor,
    ) {}

    public static function schemaName(): string
    {
        return 'content/article';
    }

    public static function schemaVersion(): int
    {
        return 3;
    }
}
