<?php

namespace Schemastud\DataSchemas\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Not one schema attribute — only the docblock generics that already drive the TypeScript
 * transformer. Promoted properties, so the annotations live on the constructor, which is
 * where spatie's reader has to be asked for them.
 */
class AnnotatedMapData extends Data
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $tags
     * @param  array<string, SampleData>  $records
     */
    public function __construct(
        public array $headers,
        public array $meta,
        public array $tags,
        public array $records,
    ) {}
}
