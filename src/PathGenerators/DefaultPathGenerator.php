<?php

namespace Schemastud\DataSchemas\PathGenerators;

use ReflectionClass;

class DefaultPathGenerator implements PathGenerator
{
    public function __construct(protected array $config) {}

    public function getSchemaPath(ReflectionClass $class): string
    {
        $baseDir = $this->config['output_directory'];
        $structure = $this->config['path_structure'] ?? 'namespace';

        return match ($structure) {
            'flat' => $this->flatPath($class, $baseDir),
            'namespace' => $this->namespacePath($class, $baseDir),
            'custom' => $this->customPath($class, $baseDir),
            default => $this->namespacePath($class, $baseDir),
        };
    }

    protected function namespacePath(ReflectionClass $class, string $baseDir): string
    {
        // App\Data\Schemas\StoryEntityAttributesData
        // → resources/schemas/App/Data/Schemas/StoryEntityAttributesData.schema.json
        $namespace = str_replace('\\', DIRECTORY_SEPARATOR, $class->getName());

        return $baseDir.DIRECTORY_SEPARATOR.$namespace.'.schema.json';
    }

    protected function flatPath(ReflectionClass $class, string $baseDir): string
    {
        // App\Data\Schemas\StoryEntityAttributesData
        // → resources/schemas/StoryEntityAttributesData.schema.json
        return $baseDir.DIRECTORY_SEPARATOR.$class->getShortName().'.schema.json';
    }

    protected function customPath(ReflectionClass $class, string $baseDir): string
    {
        // Allow user to provide custom callable via config
        $callable = $this->config['custom_path_generator'] ?? null;

        if (is_callable($callable)) {
            return $callable($class, $baseDir);
        }

        return $this->namespacePath($class, $baseDir);
    }
}
