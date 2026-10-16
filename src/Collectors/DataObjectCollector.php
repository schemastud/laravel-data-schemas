<?php

namespace Schemastud\DataSchemas\Collectors;

use Illuminate\Support\Str;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\MorphUnion;
use Spatie\LaravelData\Data;

class DataObjectCollector extends Collector
{
    public function canCollect(ReflectionClass $class): bool
    {
        // Check if class extends Spatie\LaravelData\Data
        if (! $class->isSubclassOf(Data::class)) {
            return false;
        }

        // Interfaces never carry a schema.
        if ($class->isInterface()) {
            return false;
        }

        // An abstract class is collected ONLY when it declares #[MorphUnion]. EP-02's
        // MorphUnionTransformer and morph-aware JsonSchemaGenerator project such a base to a
        // discriminated union, but only if discovery hands it over — a directory scan (the real
        // pipeline, and how the app's typescript:transform / schemas:generate find classes) must reach
        // the base itself, not just its arms. Every OTHER abstract still falls out here: an abstract
        // Data with no morph union has no concrete schema and is not a union either.
        if ($class->isAbstract() && $class->getAttributes(MorphUnion::class) === []) {
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
