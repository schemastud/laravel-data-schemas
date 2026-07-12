<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * A trivial Data class used to prove that a registered property strategy can
 * contribute an out-of-band `x-*` keyword, and that forLlmStrict strips it.
 */
class VendorKeywordData extends Data
{
    public function __construct(
        public string $title,
    ) {}
}
