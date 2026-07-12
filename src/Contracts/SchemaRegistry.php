<?php

namespace Schemastud\DataSchemas\Contracts;

use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;

/**
 * An immutable, $id-keyed store of frozen schema artifacts.
 *
 * Code-defined schemas are committed artifacts: a given `$id` is write-once.
 * Registering a NEW shape requires a NEW `$id` (a new version). An in-place
 * overwrite of an existing `$id` whose structural fingerprint DIFFERS is
 * rejected (throws) — the drift guard for slice 13's migration rungs.
 *
 * Re-registering the SAME `$id` with the SAME fingerprint is a tolerated no-op
 * (idempotent re-publish).
 */
interface SchemaRegistry
{
    /**
     * Register a schema under its `$id`. The schema MUST carry an `$id`.
     *
     * @param  array<string, mixed>  $schema
     *
     * @throws SchemaRegistryConflict
     *                                if an entry with this `$id` already exists with a different fingerprint.
     * @throws \InvalidArgumentException if the schema carries no `$id`.
     */
    public function register(array $schema): void;

    /**
     * Resolve a schema by its `$id`. Resolves any `$id` at any depth — a
     * top-level document or a nested addressable node previously registered.
     *
     * @return array<string, mixed>|null the stored schema, or null if absent
     */
    public function get(string $id): ?array;

    /**
     * Whether an entry exists for the given `$id`.
     */
    public function has(string $id): bool;

    /**
     * Every registered `$id`.
     *
     * @return array<int, string>
     */
    public function ids(): array;
}
