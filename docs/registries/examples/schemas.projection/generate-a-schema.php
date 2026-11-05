<?php

use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Sources\ExplicitClassSource;
use Schemastud\DataSchemas\Sources\SchemaProjectionRegistry;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;

// A package adds a source beside the shipped path scan, naming one Data class.
$projection = app(SchemaProjectionRegistry::class)
    ->register('acme.manifest', new ExplicitClassSource(SampleData::class), by: 'acme/shapes');

// What `schemas:generate` would read: every source, the union of their classes, and the document for each.
$classes = $projection->classes();

return [
    'keys' => array_map('strval', $projection->keys()),
    'classes' => array_map(fn (ReflectionClass $class) => $class->getName(), array_values($classes)),
    'schema' => app(Generator::class)->generate(new ReflectionClass(SampleData::class)),
];
