<?php

namespace Schemastud\DataSchemas\Actions;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\PathGenerators\PathGenerator;
use Schemastud\DataSchemas\Support\GeneratedSchema;
use Schemastud\DataSchemas\Support\SchemaCollection;

class GenerateSchemasAction
{
    public function __construct(
        protected array $generators,
        protected PathGenerator $pathGenerator
    ) {}

    /**
     * @param  ReflectionClass[]  $classes
     */
    public function execute(array $classes): SchemaCollection
    {
        $collection = new SchemaCollection;

        foreach ($classes as $class) {
            foreach ($this->generators as $generator) {
                if ($generator instanceof Generator && $generator->canGenerate($class)) {
                    $schema = $generator->generate($class);
                    $outputPath = $this->pathGenerator->getSchemaPath($class);

                    $collection->addSchema(new GeneratedSchema(
                        className: $class->getName(),
                        outputPath: $outputPath,
                        schema: $schema
                    ));

                    break; // Use first matching generator
                }
            }
        }

        return $collection;
    }
}
