<?php

namespace Schemastud\DataSchemas\Commands;

use Illuminate\Console\Command;
use Schemastud\DataSchemas\Lifecycle\SchemaDriftGuard;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;
use Schemastud\DataSchemas\Support\InstalledPackageDataPaths;
use Schemastud\DataSchemas\Support\SchemaOwnerAttribution;

/**
 * Freeze the current projected schema for every versioned ({@see
 * \Schemastud\DataSchemas\Contracts\SchemaIdentity}) Data class into the write-once frozen-artifacts
 * store.
 *
 * Write-once: freezing an existing `$id` with a CHANGED shape errors — bump the version first.
 *
 * ## Lifted out of the flagship, with a report step and a scope it did not have — beam-facade 176
 *
 * This was `App\Console\Commands\SchemaFreezeCommand` at `~/Herd/splicewire-app` and it froze the whole
 * discovered population immediately on invocation. That was survivable while exactly one root could
 * run it. It is not survivable now: `schemastud/laravel-data-schemas` resolves at **15 of the estate's
 * 21 real Herd roots** (measured 2026-08-27, `~/Herd/*` walked on disk with the three starter symlinks
 * resolved), and every artifact this writes is a **permanent promise to serve that `$id` at this
 * host's origin** — ticket 82 made `$id` a fetch key, ticket 111 made it write-once. Fifteen roots ×
 * the flagship's 33 is roughly a thousand irreversible commitments behind one keystroke.
 *
 * Two guards follow from that, and both exist because **the write is irreversible and the estate is
 * 15 roots wide**, not because wide freezes are wrong:
 *
 * ### 1. Report, then confirm, then mutate
 *
 * 107's report-only pass is what turned a planned ~20-root freeze into a 1-root freeze, and it was
 * done by hand. Here it is the command. `--dry-run` reports and writes nothing; interactively the
 * freeze is confirmed after the report; non-interactively it **refuses** without `--force`, because a
 * write-once mutation must not be reachable by a CI job that merely inherited this package.
 *
 * ### 2. `--scope`, defaulting to REPO-LOCAL
 *
 * - `--scope=app` (**default**) — only classes this repo itself ships: its own `app/`, or in a package
 *   repo its own `src/`. Formally: everything discovery found that is NOT under an installed package's
 *   `src/Data` tree per `installed.json`.
 * - `--scope=all` — the full `scan_paths` population. This is what the flagship's original command did,
 *   and reproducing its 31-artifact freeze today would require passing it explicitly.
 * - `--scope=<vendor/name>` — one installed package's shapes, resolved through
 *   {@see \Schemastud\DataSchemas\Support\InstalledPackageDataPaths::mapFromInstalledPackages()} rather
 *   than a namespace-prefix guess, because in this estate a class's namespace says what someone named
 *   it and its resolved path says which package this root actually installed.
 *
 * `schema:check` is deliberately NOT narrowed. **Report wide, mutate narrow** — the read-only half is
 * the signal and the full population must be visible at every root, always.
 *
 * ### ⚠️ The trap the default creates, and why the skip is LOUD
 *
 * In this estate `app/` holds **zero** `SchemaIdentity` classes — all 54 live in packages. So
 * `--scope=app` freezes **nothing at every root** and exits 0, which is precisely the estate's
 * recurring defect: *an instrument that reports success by not running.* It is how `scan_paths` went
 * unnoticed for a day, and how the flagship's guard read green while 31 `$id`s were 404ing at its own
 * door. A bare `Froze 0 schema(s)` here would be indistinguishable from a root with nothing to do.
 *
 * So a narrowed run **names what it skipped, on stdout, with per-package counts, saying the `$id`s are
 * currently unresolvable and naming the flag that would fix it.** A run that freezes nothing must be
 * impossible to mistake for a run with nothing to freeze.
 *
 * ⚠️ **Freezing reads live neighbour SOURCE, not just this root's files.** This estate symlinks family
 * packages into `vendor/`; 19 neighbours were mid-edit at the flagship on 2026-08-27. A shape frozen
 * from a package with uncommitted edits records a transient shape permanently, and nothing downstream
 * can tell. Run `pnpm neighbours <vendor/name>` from the ecosystem root before confirming — the report
 * names the owning packages precisely so you know which neighbours to check.
 *
 * ⚠️ **`base_uri => false` is a stated skip**, not a silent zero — see {@see SchemaCheckCommand}.
 */
class SchemaFreezeCommand extends Command
{
    protected $signature = 'schema:freeze
        {--dir= : Frozen-artifacts directory (defaults to resources/schemas/lifecycle)}
        {--scope=app : app (repo-local, default) | all | a vendor/name of one installed package}
        {--dry-run : Report what WOULD be frozen, and write nothing}
        {--force : Freeze without confirming (required non-interactively)}';

    protected $description = 'Freeze current versioned Data-class schemas into the write-once frozen-artifacts store.';

    public function handle(): int
    {
        $baseUri = config('data-schemas.base_uri');

        if ($baseUri === false) {
            $this->line('SKIPPED: data-schemas.base_uri is false — this host has opted out of versioned schema identity.');
            $this->line('  Freezing here would mint artifacts under an authority this host has declared it does not keep.');

            return self::SUCCESS;
        }

        $scope = (string) ($this->option('scope') ?: 'app');

        $guard = SchemaDriftGuard::forApp($this->option('dir') ?: null);

        // Report before mutate — 107's sequencing, now not optional. Discovery is always WIDE;
        // only the write is narrowed.
        $result = $guard->check();

        $this->info(sprintf(
            'Checked %d versioned schema(s); %d unfrozen in total.',
            count($result->checked),
            count($result->unfrozen),
        ));

        if ($result->checked === []) {
            $this->warn('Discovered NOTHING. Check `data-schemas.scan_paths` — a green guard that scanned no');
            $this->warn('directories reports the same thing as a host with no versioned classes.');
        }

        $byOwner = SchemaOwnerAttribution::group($result->unfrozen, $this->ownerMap());

        [$inScope, $skipped] = $this->partition($byOwner, $scope);

        if ($inScope !== []) {
            $this->line(sprintf('WOULD FREEZE in scope `%s` (each one a permanent promise to serve that $id here):', $scope));
            foreach ($inScope as $package => $classes) {
                $this->line(sprintf('  %-45s %d', $package, count($classes)));
            }
        }

        if ($result->hasDrift()) {
            $this->error(sprintf('Schema drift in %d class(es) — freeze refused. Bump the version first:', count($result->drifted)));
            foreach ($result->drifted as $entry) {
                $this->line('  - '.$entry->reason);
            }

            return self::FAILURE;
        }

        $inScopeClasses = [];
        foreach ($inScope as $classes) {
            $inScopeClasses = array_merge($inScopeClasses, $classes);
        }

        if ($this->option('dry-run')) {
            $this->reportSkipped($skipped, $scope);
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        if ($inScopeClasses === []) {
            $this->info(sprintf('Froze 0 schema(s) in scope `%s`.', $scope));
            $this->reportSkipped($skipped, $scope);

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Refusing to freeze non-interactively without --force.');
                $this->line('  These artifacts are write-once and are not undoable per artifact.');

                return self::FAILURE;
            }

            if (! $this->confirm(sprintf('Freeze %d write-once artifact(s)? This cannot be undone.', count($inScopeClasses)))) {
                $this->line('Aborted — nothing written.');

                return self::SUCCESS;
            }
        }

        try {
            $frozen = $guard->freeze($inScopeClasses);
        } catch (SchemaRegistryConflict $e) {
            $this->error('Cannot freeze: a schema changed shape without a version bump.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Froze %d schema(s) in scope `%s`:', count($frozen), $scope));
        foreach ($frozen as $id) {
            $this->line('  - '.$id);
        }

        $this->reportSkipped($skipped, $scope);

        return self::SUCCESS;
    }

    /**
     * `src/Data` path => owning `vendor/name`, for this root.
     *
     * A seam, not indirection for its own sake: attribution reads `installed.json` under `base_path()`,
     * which a testbench harness cannot stand up, so the scope partition and the loud-skip report would
     * otherwise be untestable — and an untested loud-skip is the one thing this command must not have.
     *
     * @return array<string, string>
     */
    protected function ownerMap(): array
    {
        return InstalledPackageDataPaths::owners();
    }

    /**
     * Split the owner-grouped unfrozen population into what `$scope` writes and what it leaves behind.
     *
     * @param  array<string, list<class-string>>  $byOwner
     * @return array{0: array<string, list<class-string>>, 1: array<string, list<class-string>>}
     */
    protected function partition(array $byOwner, string $scope): array
    {
        if ($scope === 'all') {
            return [$byOwner, []];
        }

        $keep = $scope === 'app' ? SchemaOwnerAttribution::HOST : $scope;

        $inScope = array_filter($byOwner, static fn (string $owner): bool => $owner === $keep, ARRAY_FILTER_USE_KEY);
        $skipped = array_filter($byOwner, static fn (string $owner): bool => $owner !== $keep, ARRAY_FILTER_USE_KEY);

        if ($scope !== 'app' && $inScope === [] && $skipped !== []) {
            $this->warn(sprintf('No installed package named `%s` owns an unfrozen versioned schema at this root.', $scope));
        }

        return [$inScope, $skipped];
    }

    /**
     * Name the population this run did NOT write — loudly, with owners and the flag that would.
     *
     * Required, not cosmetic. `--scope=app` freezes nothing at every root in this estate (all 54
     * versioned classes live in packages), so without this a scoped run is byte-identical in output to
     * a root that had nothing to do.
     *
     * @param  array<string, list<class-string>>  $skipped
     */
    protected function reportSkipped(array $skipped, string $scope): void
    {
        if ($skipped === []) {
            return;
        }

        $total = array_sum(array_map('count', $skipped));

        $this->newLine();
        $this->warn(sprintf('WARNING: %d schema(s) are UNFROZEN and out of scope `%s`.', $total, $scope));
        $this->warn('Their $ids are minted here and currently 404 at this origin.');
        foreach ($skipped as $package => $classes) {
            $this->warn(sprintf('    %-45s %d', $package, count($classes)));
        }
        $this->warn('Re-run with --scope=all (or --scope=<vendor/name>) to freeze them.');
        $this->warn('These $ids are write-once — see beam-facade 107.');
    }
}
