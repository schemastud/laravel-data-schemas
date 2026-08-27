<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Schemastud\DataSchemas\Support\InstalledPackageDataPaths;

/**
 * The scan-path discoverer behind beam-facade ticket 107's ruling.
 *
 * ## What 107 ruled, and why this class exists
 *
 * `$id` divergence across hosts is CORRECT, not drift: an `$id` names the origin that serves THIS
 * copy (ticket 64), so one `Data` class legitimately has N `$id`s across N hosts and identical
 * system schemas are identical bytes under different names. The consequence is the whole reason
 * this class exists — if a host stamps its own authority onto every versioned class it installs,
 * then that host must actually ANSWER for every one of them, or its own `$id`s 404 at its own door.
 *
 * `data-schemas.scan_paths` (ticket 152) is what decides that population, and it was host-declared
 * with no package default — so exactly one root in the estate had ever set it, and every other root
 * fell back to `app/` and froze nothing from a package. This supplies the default: every installed
 * package's `src/Data` tree, so the guard's population matches the population whose `$id`s the host
 * is already minting.
 *
 * ## Why this needs no family-vendor list, which is the non-obvious part
 *
 * Scanning EVERY installed package reads as over-broad and is not, because the consumer
 * ({@see \Splicewire\Tower\Schema\SchemaDriftGuard::discover()}) filters to classes implementing
 * {@see \Schemastud\DataSchemas\Contracts\SchemaIdentity} — an interface this package declares. A
 * third-party dependency will never implement it, so the filter is inherent in the contract rather
 * than maintained in a list that would drift the moment a vendor was added. That also keeps ticket
 * 64's rule intact: this package stays ignorant of WHICH authority anyone claims, and now also of
 * WHOSE packages count.
 *
 * The core is pure over a pre-built `installed.json` payload — sibling discipline with the estate's
 * audits, and the reason these cases need no filesystem beyond one fixture tree.
 */
class InstalledPackageDataPathsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // pid-keyed: a fixed scratch name collides with a concurrent session's suite, which is this
        // estate's measured 29-of-35-failure trap.
        $root = sys_get_temp_dir().'/ds-scan-paths-'.getmypid().'-'.spl_object_id($this);

        // `vendor/composer/` is not decoration: `install-path` is written relative to it, so the
        // resolver traverses THROUGH this directory and `realpath()` returns false without it.
        @mkdir($root.'/vendor/composer', 0777, true);
        @mkdir($root.'/vendor/acme/widgets/src/Data', 0777, true);
        @mkdir($root.'/vendor/acme/no-data/src', 0777, true);

        // macOS hands out `/var/folders/...`, which is a symlink to `/private/var/folders/...`.
        // The resolver returns resolved paths by design (that is what makes the de-dupe work), so
        // the fixture has to compare against the resolved root or every case fails on the prefix.
        $this->root = realpath($root) ?: $root;
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    public function test_it_returns_the_src_data_tree_of_an_installed_package(): void
    {
        $paths = InstalledPackageDataPaths::fromInstalledPackages(
            [['name' => 'acme/widgets', 'install-path' => '../acme/widgets']],
            $this->root.'/vendor',
        );

        $this->assertSame([$this->root.'/vendor/acme/widgets/src/Data'], $paths);
    }

    public function test_a_package_without_a_data_tree_contributes_nothing(): void
    {
        $paths = InstalledPackageDataPaths::fromInstalledPackages(
            [['name' => 'acme/no-data', 'install-path' => '../acme/no-data']],
            $this->root.'/vendor',
        );

        $this->assertSame([], $paths);
    }

    /**
     * The guard already skips non-existent declared paths, but a package that composer lists and
     * that is not on disk must not reach it at all — an uninstalled package is not a scan path.
     */
    public function test_a_package_that_is_not_on_disk_contributes_nothing(): void
    {
        $paths = InstalledPackageDataPaths::fromInstalledPackages(
            [['name' => 'acme/ghost', 'install-path' => '../acme/ghost']],
            $this->root.'/vendor',
        );

        $this->assertSame([], $paths);
    }

    /**
     * A malformed or partial entry is skipped rather than fatal. `installed.json` is read at config
     * time, so a throw here would take the whole host's boot with it — the estate's rule that a
     * check whose answer depends on the host must not throw, applied to discovery.
     */
    public function test_a_malformed_entry_is_skipped_rather_than_fatal(): void
    {
        $paths = InstalledPackageDataPaths::fromInstalledPackages(
            [
                ['name' => 'acme/widgets'],            // no install-path
                ['install-path' => '../acme/widgets'], // no name
                'not-an-array',
                ['name' => 'acme/widgets', 'install-path' => '../acme/widgets'],
            ],
            $this->root.'/vendor',
        );

        $this->assertSame([$this->root.'/vendor/acme/widgets/src/Data'], $paths);
    }

    /**
     * Composer writes one entry per package; a family package symlinked into `vendor/` by the co-dev
     * overlay resolves to the SAME real tree as its workspace checkout, so the same directory can be
     * reached twice. Freezing a class twice is not merely wasteful — the second pass would compare an
     * artifact against itself under a second path, so the de-dupe is correctness, not tidiness.
     */
    public function test_the_same_resolved_tree_is_returned_once(): void
    {
        $paths = InstalledPackageDataPaths::fromInstalledPackages(
            [
                ['name' => 'acme/widgets', 'install-path' => '../acme/widgets'],
                ['name' => 'acme/widgets-alias', 'install-path' => '../acme/../acme/widgets'],
            ],
            $this->root.'/vendor',
        );

        $this->assertSame([$this->root.'/vendor/acme/widgets/src/Data'], $paths);
    }

    /**
     * Byte-stable output: the guard's population, the doctor's findings and any diff over them are
     * only comparable run-to-run if the order is not composer's.
     */
    public function test_the_order_is_stable_and_not_composers(): void
    {
        @mkdir($this->root.'/vendor/acme/alpha/src/Data', 0777, true);

        $paths = InstalledPackageDataPaths::fromInstalledPackages(
            [
                ['name' => 'acme/widgets', 'install-path' => '../acme/widgets'],
                ['name' => 'acme/alpha', 'install-path' => '../acme/alpha'],
            ],
            $this->root.'/vendor',
        );

        $this->assertSame([
            $this->root.'/vendor/acme/alpha/src/Data',
            $this->root.'/vendor/acme/widgets/src/Data',
        ], $paths);
    }

    /**
     * A root with no `vendor/composer/installed.json` — a package's own testbench harness is the
     * common case — discovers nothing and says so by returning empty, never by throwing.
     */
    public function test_a_root_with_no_installed_json_discovers_nothing(): void
    {
        $this->assertSame([], InstalledPackageDataPaths::discover($this->root.'/nowhere'));
    }
}
