<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Data;

class UploadData extends Data
{
    public function __construct(
        public UploadedFile $file,
        public ?string $caption,
    ) {}
}
