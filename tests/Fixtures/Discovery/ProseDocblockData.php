<?php

namespace Schemastud\DataSchemas\Tests\Fixtures\Discovery;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Modelled on the real `App\Data\ContextScopeEmbeddingsInputData`, whose docblock is what made the
 * old regex derive `App\Data\publishes` and skip the file entirely.
 *
 * The op is mounted `GET context-scopes/{id}/op/embeddings`, so this class publishes as QUERY
 * PARAMETERS rather than a body — a first-class Capability of the route, not of the declaration.
 */
class ProseDocblockData extends Data
{
    public function __construct(
        /** @var array<string, mixed>|null */
        public array|Optional|null $filter = null,
        public bool|Optional|null $unchunk = null,
    ) {}
}
