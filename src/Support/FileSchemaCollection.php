<?php

namespace Schemastud\DataSchemas\Support;

use Illuminate\Support\Collection;

/**
 * The disk-bound subset: generated schemas that also carry a destination.
 *
 * The family splits on PROVENANCE, not on behaviour. `SchemaCollection` is what the
 * generator produced; this is what a writer can act on. Everything the base collection
 * can answer, it still answers here — this adds exactly the one question that needs a
 * path to be asked at all.
 *
 * @template TKey of array-key
 * @template TSchema of WrittenSchema
 *
 * @extends SchemaCollection<TKey, TSchema>
 */
class FileSchemaCollection extends SchemaCollection
{
    /** @return class-string<GeneratedSchema> */
    protected static function itemType(): string
    {
        return WrittenSchema::class;
    }

    /**
     * Every output path claimed by more than one class, and who claims it.
     *
     * Reachable today with the SHIPPED configuration: `path_structure: 'flat'` keys the
     * file on `getShortName()`, so `App\Data\UserData` and `App\Admin\UserData` resolve to
     * one path and the writer's last write wins, silently and with no error anywhere.
     *
     * Grouped rather than keyed. A path-keyed COLLECTION would have discarded the second
     * entry when it was built, so the collision would be gone before anyone could ask about
     * it; grouping is a read over a collection that still holds both.
     *
     * @return Collection<string, list<string>> output path => class names, collisions only
     */
    public function pathCollisions(): Collection
    {
        return $this->toBase()
            ->groupBy(fn (WrittenSchema $schema) => $schema->outputPath)
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group) => $group->map(fn (WrittenSchema $schema) => $schema->className)->values()->all());
    }
}
