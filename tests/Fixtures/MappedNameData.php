<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;

/**
 * api-surface-coherence 54 — the wire name a mapped property actually answers to.
 *
 * Four shapes, one per arm of the projection:
 *
 * - `$sourceType` is `#[MapInputName]`-mapped only, so the REQUEST axis must publish
 *   `source_type` while the RESPONSE axis keeps `sourceType` (spatie serializes on the
 *   property name when no output mapper is configured).
 * - `$renderedAt` is `#[MapOutputName]`-mapped only — the mirror image.
 * - `$bothWays` carries both, and each axis must pick its own.
 * - `$plain` is unmapped and must be untouched on every axis, which is what keeps this
 *   from being a blanket rename.
 *
 * `$sourceType` is deliberately NON-nullable and undefaulted so it lands in `required`:
 * the `required` list is keyed by wire name too, and a projection that fixed `properties`
 * and forgot `required` would publish a mandatory field nothing declares.
 */
class MappedNameData extends Data
{
    public function __construct(
        #[MapInputName('source_type')]
        public string $sourceType,
        #[MapOutputName('rendered_at')]
        public ?string $renderedAt = null,
        #[MapInputName('both_in')]
        #[MapOutputName('both_out')]
        public ?string $bothWays = null,
        public ?string $plain = null,
    ) {}
}
