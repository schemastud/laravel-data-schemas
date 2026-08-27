<?php

namespace Schemastud\DataSchemas\Support;

use Illuminate\Support\Collection;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;

/**
 * A set of generated schema documents, and the small surface for interrogating it.
 *
 * TYPE HONESTY. The generic annotation below is a claim, and `Collection::map()` would
 * make it a lie: it builds its result with `new static`, so a mapped `SchemaCollection`
 * would advertise `GeneratedSchema` while holding strings. Eloquent settled this shape
 * years ago — downgrade to a base collection the moment the items stop being of the
 * declared type — so the method set and the `toBase()` pattern below are MIRRORED from
 * `Illuminate\Database\Eloquent\Collection` rather than invented here.
 *
 * SURFACE. Deliberately tiny: a method earns its place only if `Collection` cannot
 * already say the same thing in one readable line. `keyByClass()`, `keyByPath()`,
 * `totalProperties()` and `forClass()` were considered and rejected as costumes over
 * `keyBy()`, `sum()` and `firstWhere()`. What remains are the questions the estate
 * actually asks and cannot phrase: structural fingerprints, drift against a set of
 * documents someone else read, and registration.
 *
 * PROVENANCE. This collection knows what was GENERATED. Where those documents are
 * destined on disk is a different fact, and it lives in {@see FileSchemaCollection}.
 * No filesystem access happens in either — reading is {@see SchemaFileReader}'s job.
 *
 * @template TKey of array-key
 * @template TSchema of GeneratedSchema
 *
 * @extends Collection<TKey, TSchema>
 */
class SchemaCollection extends Collection
{
    /**
     * The item type this collection claims to hold — the guard the downgrade consults.
     * Overridden by subclasses that narrow it.
     *
     * @return class-string<GeneratedSchema>
     */
    protected static function itemType(): string
    {
        return GeneratedSchema::class;
    }

    /**
     * The structural fingerprint of every document, keyed by class name.
     *
     * Keyed by class rather than pushed as a list because the caller is always asking
     * "did THIS class's shape move", and a class projects exactly one document.
     *
     * @return Collection<string, string>
     */
    public function fingerprints(): Collection
    {
        return $this->toBase()->mapWithKeys(
            fn (GeneratedSchema $schema) => [$schema->className => SchemaFingerprint::of($schema->schema)],
        );
    }

    /**
     * What differs between this generated set and a set of documents someone else already read.
     *
     * PURE, and that is the whole point of the signature: it takes the documents rather than a
     * directory, so it never touches a filesystem, needs no disk, and can be called from an
     * audit that has its own reading strategy (beam's `schema.projection-drift` does).
     * {@see SchemaFileReader::documentsFor()} produces the argument for the on-disk case.
     *
     * `drifted` compares STRUCTURAL fingerprints, so re-worded prose is not drift —
     * see {@see SchemaFingerprint::VOLATILE_KEYS}.
     *
     * @param  array<string, array<string, mixed>>  $onDisk  class name => document
     * @return array{missing: list<string>, drifted: list<string>, orphaned: list<string>}
     */
    public function diffAgainst(array $onDisk): array
    {
        $missing = [];
        $drifted = [];

        foreach ($this as $schema) {
            $existing = $onDisk[$schema->className] ?? null;

            if ($existing === null) {
                $missing[] = $schema->className;

                continue;
            }

            if (SchemaFingerprint::of($existing) !== SchemaFingerprint::of($schema->schema)) {
                $drifted[] = $schema->className;
            }
        }

        $generated = $this->toBase()->map(fn (GeneratedSchema $schema) => $schema->className)->all();

        return [
            'missing' => $missing,
            'drifted' => $drifted,
            'orphaned' => array_values(array_diff(array_keys($onDisk), $generated)),
        ];
    }

    /**
     * Commit every document into a registry.
     *
     * The bridge between "just generated" and "frozen artifact". Registration is write-once
     * and the registry enforces that itself, so a conflicting shape throws from there rather
     * than being pre-screened here — a drift guard with two implementations is a drift guard
     * with two answers.
     *
     * @return $this
     */
    public function registerInto(SchemaRegistry $registry): static
    {
        foreach ($this as $schema) {
            $registry->register($schema->schema);
        }

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Type-honesty overrides (mirrored from Eloquent\Collection)
    |--------------------------------------------------------------------------
    |
    | The first two downgrade CONDITIONALLY — the callback may well hand back
    | schemas. The rest change the shape of the items unconditionally, so they
    | always hand back a base collection.
    */

    /** {@inheritDoc} */
    #[\Override]
    public function map(callable $callback)
    {
        $result = parent::map($callback);
        $type = static::itemType();

        return $result->contains(fn ($item) => ! $item instanceof $type) ? $result->toBase() : $result;
    }

    /** {@inheritDoc} */
    #[\Override]
    public function mapWithKeys(callable $callback)
    {
        $result = parent::mapWithKeys($callback);
        $type = static::itemType();

        return $result->contains(fn ($item) => ! $item instanceof $type) ? $result->toBase() : $result;
    }

    /** {@inheritDoc} */
    #[\Override]
    public function countBy($countBy = null)
    {
        return $this->toBase()->countBy($countBy);
    }

    /** {@inheritDoc} */
    #[\Override]
    public function collapse()
    {
        return $this->toBase()->collapse();
    }

    /** {@inheritDoc} */
    #[\Override]
    public function flatten($depth = INF)
    {
        return $this->toBase()->flatten($depth);
    }

    /** {@inheritDoc} */
    #[\Override]
    public function flip()
    {
        return $this->toBase()->flip();
    }

    /** {@inheritDoc} */
    #[\Override]
    public function keys()
    {
        return $this->toBase()->keys();
    }

    /** {@inheritDoc} */
    #[\Override]
    public function pad($size, $value)
    {
        return $this->toBase()->pad($size, $value);
    }

    /** {@inheritDoc} */
    #[\Override]
    public function partition($key, $operator = null, $value = null)
    {
        return parent::partition(...func_get_args())->toBase();
    }

    /** {@inheritDoc} */
    #[\Override]
    public function pluck($value, $key = null)
    {
        return $this->toBase()->pluck($value, $key);
    }

    /** {@inheritDoc} */
    #[\Override]
    public function zip($items)
    {
        return $this->toBase()->zip(...func_get_args());
    }
}
