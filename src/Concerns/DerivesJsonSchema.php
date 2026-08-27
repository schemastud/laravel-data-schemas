<?php

namespace Schemastud\DataSchemas\Concerns;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;

/**
 * Derive this class's JSON Schema through the host's CONFIGURED generator.
 *
 * The default implementation of {@see \Schemastud\DataSchemas\Contracts\ProvidesJsonSchema}. Compose
 * it into anything — a class with its own parent, a third-party Data class, a package that would
 * rather not inherit from us. {@see \Schemastud\DataSchemas\StudData} is only the short form.
 *
 * ## Why there is no `?Generator` parameter
 *
 * Because that parameter is how the estate got into trouble. A census found 41 `new
 * JsonSchemaGenerator` sites across 13 repos; ~26 pass no config at all, which silently costs them
 * `schema_metadata`/`schema_version` and hard-crashes them on `base_uri` the moment they meet a
 * versioned class. Every one of those is a caller who found it easy to supply their own generator.
 *
 * So the sugar has exactly one path: resolve the container binding, which is built from
 * `config('data-schemas')` and is swappable and fakeable by a host or a test. A caller who genuinely
 * needs an explicit generator asks the container for one — where they are already thinking about
 * configuration — rather than reaching past it here.
 */
trait DerivesJsonSchema
{
    /** @return array<string, mixed> */
    public static function jsonSchema(): array
    {
        return app(Generator::class)->generate(new ReflectionClass(static::class));
    }
}
