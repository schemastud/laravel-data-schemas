<?php

namespace Schemastud\DataSchemas\Commands;

use Illuminate\Console\Command;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;

/**
 * Freeze a single schema version, read from a complete schema JSON document carrying an absolute
 * versioned `$id`, into the write-once registry. Surfaces a {@see SchemaRegistryConflict} — a changed
 * shape under an existing `$id` — as a clear inline failure, which is the immutability contract.
 *
 * ## Lifted out of the flagship — beam-facade ticket 176, 2026-08-27
 *
 * This was `App\Console\Commands\SchemaFreezeVersionCommand` at `~/Herd/splicewire-app`, one host's
 * private tooling, and it wrapped `Splicewire\Tower\Jobs\FreezeSchemaVersionJob` — a queueable whose
 * entire `handle()` is `$registry->register($schema)`. The job stays in tower (its live consumer is
 * tower's own `Api\V1\SchemaRegistryController`, which needs the queueable form); the command resolves
 * the bound {@see SchemaRegistry} directly, so it carries no tower dependency and runs at all 15 roots
 * that install this package rather than the 2 that install tower.
 *
 * ⚠️ **This writes into `data-schemas.registry_directory` (the container's `SchemaRegistry` binding),
 * NOT into `resources/schemas/lifecycle`** — which is where `schema:check` and `schema:freeze` read and
 * write via `SchemaDriftGuard::defaultFrozenDirectory()`. That divergence is inherited from the tower
 * job verbatim and is deliberately not "fixed" here: changing which store a write-once command writes
 * to is a behaviour change, not a lift. A host whose two stores differ will see a `$id` frozen by this
 * command still report as unfrozen by `schema:check`.
 */
class SchemaFreezeVersionCommand extends Command
{
    protected $signature = 'schema:freeze-version {file : Path to a JSON schema document carrying an absolute versioned $id}';

    protected $description = 'Freeze a single schema version (from a JSON file) into the write-once registry.';

    public function handle(SchemaRegistry $registry): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("Schema file not found: {$file}");

            return self::FAILURE;
        }

        $schema = json_decode((string) file_get_contents($file), true);
        if (! is_array($schema)) {
            $this->error('Schema file does not contain a valid JSON object.');

            return self::FAILURE;
        }

        $id = $schema['$id'] ?? null;

        try {
            // Write-once: an existing `$id` with a differing fingerprint throws.
            $registry->register($schema);
        } catch (SchemaRegistryConflict $e) {
            $this->error('Cannot freeze: a schema changed shape without a version bump.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Froze schema version: '.(is_string($id) ? $id : '(no $id)'));

        return self::SUCCESS;
    }
}
