<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Data;

/**
 * Exercises the request-mode #[Computed] drop: `slug` is derived (output-only),
 * so it must vanish from a `forRequest()` form schema while surviving in
 * `forResponse()`/collapsed output.
 */
class ComputedFieldData extends Data
{
    public function __construct(
        public string $title,
        #[Computed]
        public string $slug = '',
    ) {}
}
