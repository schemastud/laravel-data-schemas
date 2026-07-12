<?php

namespace Schemastud\DataSchemas\Collectors;

use ReflectionClass;
use Spatie\LaravelData\Data;

class DataObjectCollector extends Collector
{
    public function canCollect(ReflectionClass $class): bool
    {
        // Check if class extends Spatie\LaravelData\Data
        if (! $class->isSubclassOf(Data::class)) {
            return false;
        }

        // Check if class is abstract or interface
        if ($class->isAbstract() || $class->isInterface()) {
            return false;
        }

        // Check namespace filters if configured
        $namespaceFilters = $this->config['namespaces'] ?? [];
        if (! empty($namespaceFilters)) {
            $className = $class->getName();
            $matches = false;

            foreach ($namespaceFilters as $pattern) {
                if (fnmatch($pattern, $className)) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                return false;
            }
        }

        return true;
    }
}
