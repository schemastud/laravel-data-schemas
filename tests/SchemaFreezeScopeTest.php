<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase;
use Schemastud\DataSchemas\Commands\SchemaFreezeCommand;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;

/**
 * `schema:freeze --scope=` — the repo-local default, and the loud skip that keeps it honest
 * (beam-facade ticket 176).
 *
 * ## What is actually under test, and why the loud skip is the important half
 *
 * The write is **irreversible** (ticket 111: an `$id` is write-once; ticket 82: it is a fetch key a
 * host must answer at a URL forever), and after this lift the command reaches **15 of the estate's 21
 * real Herd roots** rather than the flagship alone. So the default narrowed to what the repo itself
 * ships.
 *
 * The trap that creates is the whole reason for this file. In this estate `app/` holds **zero**
 * `SchemaIdentity` classes — all 54 live in packages — so `--scope=app` freezes nothing at every root
 * and exits 0. That is exactly the estate's recurring defect, *an instrument that reports success by
 * not running*: it is how `scan_paths` went unnoticed for a day, and how the flagship's guard read
 * green while 31 `$id`s were 404ing at its own door. A `Froze 0` that does not name what it skipped is
 * indistinguishable from a root with nothing to do, so the skip report is asserted here as behaviour,
 * not treated as console decoration.
 *
 * ## Why the fixture is a hand-built root rather than the testbench skeleton
 *
 * Attribution reads `vendor/composer/installed.json` under `base_path()` — deliberately, because this
 * estate symlinks family packages into `vendor/` where `grep -r` cannot follow and `-R` cannot finish.
 * Testbench cannot stand that root up, so the commands expose an `ownerMap()` seam and these cases
 * override it with a map built over a pid-keyed scratch tree. Pid-keyed because a fixed scratch name
 * collides with a concurrent session's suite — this estate's measured 29-of-35-failure trap.
 */
class SchemaFreezeScopeTest extends TestCase
{
    private string $root;

    private string $frozenDir;

    protected function getPackageProviders($app): array
    {
        return [LaravelDataSchemasServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // PID-keyed, not per-test-object: the fixture declares real classes, and PHP cannot redeclare
        // them, so a fresh tree per test method fatals on the second one. One tree per process, with
        // the frozen store emptied between cases instead.
        $root = sys_get_temp_dir().'/ds-freeze-scope-'.getmypid();
        @mkdir($root.'/app', 0777, true);
        @mkdir($root.'/packages/acme/widgets/src/Data', 0777, true);
        @mkdir($root.'/frozen', 0777, true);

        // macOS resolves sys_get_temp_dir() through a symlink; attribution compares RESOLVED paths,
        // so the fixture must too or every prefix match fails.
        $this->root = realpath($root) ?: $root;
        $this->frozenDir = $this->root.'/frozen';

        file_put_contents($this->root.'/app/HostOwnedData.php', <<<'PHP'
            <?php

            namespace DsFreezeScopeFixture\App;

            use Schemastud\DataSchemas\Contracts\SchemaIdentity;
            use Spatie\LaravelData\Data;

            class HostOwnedData extends Data implements SchemaIdentity
            {
                public function __construct(public string $label) {}

                public static function schemaName(): string
                {
                    return 'fixture/host-owned';
                }

                public static function schemaVersion(): int
                {
                    return 1;
                }
            }
            PHP);

        file_put_contents($this->root.'/packages/acme/widgets/src/Data/PackageOwnedData.php', <<<'PHP'
            <?php

            namespace DsFreezeScopeFixture\Pkg;

            use Schemastud\DataSchemas\Contracts\SchemaIdentity;
            use Spatie\LaravelData\Data;

            class PackageOwnedData extends Data implements SchemaIdentity
            {
                public function __construct(public string $sku) {}

                public static function schemaName(): string
                {
                    return 'fixture/package-owned';
                }

                public static function schemaVersion(): int
                {
                    return 1;
                }
            }
            PHP);

        // The guard's discover() requires class_exists(), and nothing autoloads a scratch tree.
        require_once $this->root.'/app/HostOwnedData.php';
        require_once $this->root.'/packages/acme/widgets/src/Data/PackageOwnedData.php';

        config()->set('data-schemas.base_uri', 'https://fixture.test/schemas');
        config()->set('data-schemas.scan_paths', [
            $this->root.'/app',
            $this->root.'/packages/acme/widgets/src/Data',
        ]);

        $this->app[Kernel::class]->registerCommand(new FixtureScopedFreezeCommand([
            $this->root.'/packages/acme/widgets/src/Data' => 'acme/widgets',
        ]));
    }

    protected function tearDown(): void
    {
        // Only the write-once store is reset between cases; the fixture class files must survive,
        // because their classes are already declared in this process.
        foreach (glob($this->frozenDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        $root = sys_get_temp_dir().'/ds-freeze-scope-'.getmypid();

        if (is_dir($root)) {
            self::deleteTreeAt(realpath($root) ?: $root);
        }

        parent::tearDownAfterClass();
    }

    public function test_scope_app_freezes_only_repo_local_classes(): void
    {
        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'app', '--force' => true])
            ->assertExitCode(0);

        $ids = $this->frozenIds();

        $this->assertContains('https://fixture.test/schemas/fixture/host-owned/1', $ids);
        $this->assertNotContains('https://fixture.test/schemas/fixture/package-owned/1', $ids);
    }

    public function test_scope_app_names_the_package_population_it_skipped(): void
    {
        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'app', '--force' => true])
            ->expectsOutputToContain('acme/widgets')
            ->expectsOutputToContain('UNFROZEN')
            ->expectsOutputToContain('404')
            ->expectsOutputToContain('--scope=all')
            ->assertExitCode(0);
    }

    /**
     * The case the whole file exists for: a scope that writes NOTHING must still say what it left
     * behind. A bare `Froze 0` here would read identically to a root with nothing to do.
     */
    public function test_a_scope_that_freezes_nothing_still_names_the_skipped_owners(): void
    {
        // Freeze the host's one class first, so the second run has nothing in scope left to write.
        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'app', '--force' => true])
            ->assertExitCode(0);

        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'app', '--force' => true])
            ->expectsOutputToContain('Froze 0 schema(s) in scope `app`.')
            ->expectsOutputToContain('acme/widgets')
            ->expectsOutputToContain('--scope=all')
            ->assertExitCode(0);
    }

    public function test_scope_all_freezes_the_package_population_too(): void
    {
        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'all', '--force' => true])
            ->assertExitCode(0);

        $ids = $this->frozenIds();

        $this->assertContains('https://fixture.test/schemas/fixture/host-owned/1', $ids);
        $this->assertContains('https://fixture.test/schemas/fixture/package-owned/1', $ids);
    }

    public function test_scope_can_name_one_installed_package(): void
    {
        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'acme/widgets', '--force' => true])
            ->assertExitCode(0);

        $ids = $this->frozenIds();

        $this->assertContains('https://fixture.test/schemas/fixture/package-owned/1', $ids);
        $this->assertNotContains('https://fixture.test/schemas/fixture/host-owned/1', $ids);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'all', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame([], $this->frozenIds());
    }

    /**
     * `base_uri => false` is a host opting out of versioned identity entirely. It must be a STATED
     * skip, never a silent zero — the estate's `scan_paths` blind spot is exactly what a silent zero
     * looks like.
     */
    public function test_base_uri_false_is_a_stated_skip(): void
    {
        config()->set('data-schemas.base_uri', false);

        $this->artisan('schema:freeze', ['--dir' => $this->frozenDir, '--scope' => 'all', '--force' => true])
            ->expectsOutputToContain('SKIPPED')
            ->assertExitCode(0);

        $this->assertSame([], $this->frozenIds());
    }

    /**
     * `schema:check` stays WIDE — report wide, mutate narrow. It is read-only and it is the signal, so
     * the full population must be visible at every root even though `schema:freeze` defaults to the
     * repo-local slice. A check narrowed to match the freeze default would hide exactly the classes
     * whose `$id`s are 404ing.
     */
    public function test_schema_check_reports_the_full_population_not_the_freeze_scope(): void
    {
        $this->artisan('schema:check', ['--dir' => $this->frozenDir])
            ->expectsOutputToContain('Checked 2 versioned schema(s); 2 new/unfrozen.')
            ->assertExitCode(0);
    }

    public function test_schema_check_states_the_base_uri_false_skip(): void
    {
        config()->set('data-schemas.base_uri', false);

        $this->artisan('schema:check', ['--dir' => $this->frozenDir])
            ->expectsOutputToContain('SKIPPED')
            ->assertExitCode(0);
    }

    /** @return list<string> the `$id` of every artifact in the frozen store */
    private function frozenIds(): array
    {
        $ids = [];

        foreach (glob($this->frozenDir.'/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['$id']) && is_string($decoded['$id'])) {
                $ids[] = $decoded['$id'];
            }
        }

        sort($ids);

        return $ids;
    }

    private static function deleteTreeAt(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            is_dir($child) ? self::deleteTreeAt($child) : @unlink($child);
        }

        @rmdir($path);
    }
}

/**
 * The freeze command with its attribution seam pinned to a fixture map — see the file docblock for
 * why `installed.json` cannot be read from a testbench root.
 */
class FixtureScopedFreezeCommand extends SchemaFreezeCommand
{
    /** @param array<string, string> $owners */
    public function __construct(private array $owners)
    {
        parent::__construct();
    }

    protected function ownerMap(): array
    {
        return $this->owners;
    }
}
