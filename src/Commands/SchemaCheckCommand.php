<?php

namespace Schemastud\DataSchemas\Commands;

use Illuminate\Console\Command;
use Schemastud\DataSchemas\Lifecycle\SchemaDriftGuard;
use Schemastud\DataSchemas\Support\InstalledPackageDataPaths;
use Schemastud\DataSchemas\Support\SchemaOwnerAttribution;

/**
 * Build-time drift gate: fails (non-zero exit) when a versioned Data class has diverged from its
 * frozen schema artifact without a version bump. Intended for CI / pre-commit; deliberately a CLI
 * command, not a runtime-boot scan.
 *
 * ## Lifted out of the flagship — beam-facade ticket 176, 2026-08-27
 *
 * This was `App\Console\Commands\SchemaCheckCommand` at `~/Herd/splicewire-app`. Measured that day by
 * enumerating `~/Herd/*` on disk with the three starter symlinks resolved: **21 real roots carry an
 * `artisan`, and `schema:check` was defined at exactly ONE of them** — while 15 install this package
 * and mint versioned `$id`s from it. Ticket 107 ruled that a host must ANSWER for every versioned
 * class it installs; this is the instrument that discharges that, and 19 of the 21 roots that incur
 * the obligation had no way to even ask.
 *
 * Report-only, always. It never writes. `schema:freeze` is the mutating half and its own report step
 * is built on the same numbers this prints.
 *
 * Two things it now states rather than leaving to be inferred, both because this estate's recurring
 * defect is an instrument that reports success by not running:
 *
 * - **`base_uri => false` is a STATED SKIP, not a zero.** A host that opted out of versioned identity
 *   has nothing to answer for, and a bare `Checked 0` there is indistinguishable from a guard that
 *   discovered nothing (which is exactly what 152 found had been happening estate-wide).
 * - **Unfrozen classes are grouped by OWNING PACKAGE.** 107 derived that grouping by hand at the
 *   flagship; a count with no owners does not tell you whose shapes you are about to answer for.
 */
class SchemaCheckCommand extends Command
{
    protected $signature = 'schema:check {--dir= : Frozen-artifacts directory (defaults to resources/schemas/lifecycle)}';

    protected $description = 'Fail when a versioned Data class drifts from its frozen schema without a version bump.';

    public function handle(): int
    {
        $baseUri = config('data-schemas.base_uri');

        if ($baseUri === false) {
            $this->line('SKIPPED: data-schemas.base_uri is false — this host has opted out of versioned schema identity.');
            $this->line('  Nothing is minted here, so there is nothing to freeze or check. This is a stated skip, not a zero.');

            return self::SUCCESS;
        }

        $guard = SchemaDriftGuard::forApp($this->option('dir') ?: null);

        $result = $guard->check();

        $this->info(sprintf(
            'Checked %d versioned schema(s); %d new/unfrozen.',
            count($result->checked),
            count($result->unfrozen),
        ));

        if ($result->checked === []) {
            $this->warn('Discovered NOTHING. Check `data-schemas.scan_paths` — a green guard that scanned no');
            $this->warn('directories reports the same thing as a host with no versioned classes.');
        }

        if ($result->unfrozen !== []) {
            $this->line('Unfrozen, by owning package:');
            foreach (SchemaOwnerAttribution::group($result->unfrozen, $this->ownerMap()) as $package => $classes) {
                $this->line(sprintf('  %-45s %d', $package, count($classes)));
            }
        }

        if (! $result->hasDrift()) {
            $this->info('No schema drift detected.');

            return self::SUCCESS;
        }

        // api-surface-coherence 122: reported, never silent. An inert delta is not drift, but a reader
        // who is told nothing cannot tell "no delta" from "a delta we decided not to count" - which is
        // the false-green shape this whole gate exists to refuse.
        if ($result->refreezable !== []) {
            $this->line(sprintf('Inert (`default`-only, version 1) in %d class(es) - NOT drift:', count($result->refreezable)));
            foreach ($result->refreezable as $entry) {
                $this->line('  - '.$entry->reason);
            }
        }

        $this->error(sprintf('Schema drift detected in %d class(es):', count($result->drifted)));
        foreach ($result->drifted as $entry) {
            $this->line('  - '.$entry->reason);
        }

        return self::FAILURE;
    }

    /**
     * `src/Data` path => owning `vendor/name`, for this root. Overridable for the same reason as
     * {@see SchemaFreezeCommand::ownerMap()}.
     *
     * @return array<string, string>
     */
    protected function ownerMap(): array
    {
        return InstalledPackageDataPaths::owners();
    }
}
