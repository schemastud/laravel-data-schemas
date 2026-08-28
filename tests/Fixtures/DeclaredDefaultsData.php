<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * api-surface-coherence 118 — one class per population 72 measured.
 *
 * Every property is PROMOTED, because that is the shape the estate actually writes and the
 * one raw reflection cannot answer for (see {@see RequiredShapesData}).
 *
 * The populations:
 *
 * - **ships**: `$limit`, `$colour`, `$enabled`, `$rate`, `$tags`, `$status` — a declared value
 *   that is type-consistent with the property's own schema.
 * - **suppressed**: `$note` (`default: null` — 82% of the estate, and the destructive one).
 * - **absent**: `$name` (no default at all), `$maybe` (`Optional`, erased by spatie).
 * - **carved out by the type guard**: `$bag` (`object`-typed, PHP `[]` default — 33 of 72's
 *   39) and `$envelope` (array-typed, string-keyed default — the other 6).
 * - **not serializable**: `$anchor`, an object default (the estate's four `SourceRef`s).
 * - **projected**: `$pageSize` is `#[MapInputName]`-mapped, so on the request axis its
 *   `default` must appear under `page_size` — the same key `required` uses.
 */
class DeclaredDefaultsData extends Data
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $bag
     * @param  array<string, string>  $envelope
     */
    public function __construct(
        public string $name,

        public string|Optional $maybe,

        public int $limit = 100,

        public string $colour = 'RED',

        public bool $enabled = true,

        public float $rate = 24.0,

        public ?string $note = null,

        #[ArrayItems('string')]
        public array $tags = ['alpha'],

        public StatusEnum $status = StatusEnum::Published,

        #[MapInputName('page_size')]
        public int $pageSize = 25,

        // `object`-typed, PHP `[]`. Encodes as `[]` — a JSON array, never the `{}` the schema
        // demands — so emitting it would make the acceptance gate reject the candidate and
        // demote the rung. 72 ruled skip, do not coerce.
        #[MapValues]
        public array $bag = [],

        // The mirror: array-typed, but the declared default is a string-keyed structure, which
        // encodes as an object.
        #[ArrayItems('string')]
        public array $envelope = ['method' => 'GET'],

        // An object default. Not scalar, not an array, not a BackedEnum — nothing to publish.
        public ?DefaultAnchorData $anchor = new DefaultAnchorData,
    ) {}
}
