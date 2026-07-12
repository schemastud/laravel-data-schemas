<?php

namespace Schemastud\DataSchemas\Actions;

use ReflectionClass;
use Schemastud\DataSchemas\Collectors\Collector;
use Symfony\Component\Finder\Finder;

class DiscoverDataClassesAction
{
    public function __construct(
        protected array $config,
        protected array $collectors
    ) {}

    public function execute(?string $path = null, ?string $className = null): array
    {
        // If specific class provided, return just that class
        if ($className) {
            if (! class_exists($className)) {
                throw new \InvalidArgumentException("Class {$className} does not exist");
            }

            $reflection = new ReflectionClass($className);

            return $this->canCollect($reflection) ? [$reflection] : [];
        }

        // Otherwise scan paths
        $paths = $path ? [$path] : $this->config['auto_discover_types'];
        $classes = [];

        foreach ($paths as $searchPath) {
            if (! file_exists($searchPath)) {
                continue;
            }

            $finder = (new Finder)
                ->files()
                ->name('*.php')
                ->in($searchPath);

            foreach ($finder as $file) {
                $className = $this->getClassNameFromFile($file->getRealPath());

                if ($className && class_exists($className)) {
                    $reflection = new ReflectionClass($className);

                    if ($this->canCollect($reflection)) {
                        $classes[] = $reflection;
                    }
                }
            }
        }

        return $classes;
    }

    protected function canCollect(ReflectionClass $class): bool
    {
        foreach ($this->collectors as $collector) {
            if ($collector instanceof Collector && $collector->canCollect($class)) {
                return true;
            }
        }

        return false;
    }

    protected function getClassNameFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatches)) {
            $namespace = $namespaceMatches[1];
        } else {
            $namespace = '';
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $contents, $classMatches)) {
            $className = $classMatches[1];

            return $namespace ? $namespace.'\\'.$className : $className;
        }

        return null;
    }
}
