<?php

namespace Schemastud\DataSchemas\Actions;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\PathGenerators\PathGenerator;
use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\WrittenSchema;

class GenerateSchemasAction
{
    public function __construct(
        protected array $generators,
        protected PathGenerator $pathGenerator
    ) {}

    /**
     * Generate a schema per class, each with the destination this host's `PathGenerator` chose.
     *
     * Returns a {@see FileSchemaCollection} — this action holds a `PathGenerator`, so everything
     * it produces has a destination. `push()` rather than the old `addSchema()`: that method was
     * `push()` with a type hint the collection could not enforce anywhere else, and it made the
     * collection look like a builder.
     *
     * @param  ReflectionClass[]  $classes
     * @return FileSchemaCollection<int, WrittenSchema>
     */
    public function execute(array $classes): FileSchemaCollection
    {
        $collection = new FileSchemaCollection;

        foreach ($classes as $class) {
            foreach ($this->generators as $generator) {
                if ($generator instanceof Generator && $generator->canGenerate($class)) {
                    $collection->push(new WrittenSchema(
                        className: $class->getName(),
                        schema: $generator->generate($class),
                        outputPath: $this->pathGenerator->getSchemaPath($class),
                    ));

                    break; // Use first matching generator
                }
            }
        }

        return $collection;
    }
}
