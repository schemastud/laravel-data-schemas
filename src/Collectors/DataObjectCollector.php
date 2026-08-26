<?php

namespace Schemastud\DataSchemas\Collectors;

use Illuminate\Support\Str;
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

        // Check namespace filters if configured.
        //
        // `Str::is()`, never `fnmatch()`: fnmatch treats `\` as an ESCAPE character, so the one
        // pattern shape anybody would actually write here — `App\Data\*` — matched nothing at all,
        // silently. The filter has been inert for every separator-carrying pattern since it was
        // written; only a separator-free pattern like `App*` ever did anything. Str::is quotes the
        // subject and expands only `*`, so a namespace pattern means what it looks like, and it
        // takes the array directly.
        $namespaceFilters = $this->config['namespaces'] ?? [];

        if (! empty($namespaceFilters)) {
            return Str::is($namespaceFilters, $class->getName());
        }

        return true;
    }
}
