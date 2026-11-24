<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;

/**
 * Fix B (calcucrypt, root ruling 0bc3e9ce): `register()` wrote the artifact in place with `file_put_contents`,
 * which creates (or truncates) the file BEFORE writing its bytes. A process that reads the artifact in that
 * window — every request and worker builds and reads this registry — finds it empty or half-written: `get()`
 * answers null for a schema that is being registered, so a second writer with a different shape passes the
 * frozen-schema guard instead of being refused, and a reader that `file_get_contents` the path sees no JSON.
 * A failed write left the empty file behind for good. The artifact is now written to a temporary file in the
 * SAME directory and renamed over its path, so a reader sees no file or the whole file, never a part of one.
 *
 * What the first two tests check is a SIMULATED READER VIEW, not a concurrent replay: no second process reads
 * anything. A stream wrapper holds the directory in memory and snapshots it at every write; the test asserts what
 * a reader of the directory WOULD have seen at each of those instants. The other tests run on a real directory.
 *
 * Replacing an artifact (one whose file is there but does not decode, so `get()` is null and `register()` writes)
 * keeps the artifact's mode: the temporary file would otherwise carry the umask's mode over a file a host had
 * set itself. A new artifact gets what the in-place write gave it, `0666 & ~umask()`.
 *
 * Two writers of DIFFERENT shapes used to both pass the `get()` check before either renamed, so both registrations
 * succeeded and the last rename won. The check and the write now run under an exclusive lock on the registry's
 * `.registry.lock`, so the second writer waits and is then refused. The lock window is checked twice: by the
 * simulated view (the wrapper models a held lock: a second holder would wait, which it records), and by two real
 * processes on a real directory (pcntl_fork; skipped where pcntl or posix is absent).
 */
class SchemaRegistryAtomicWriteTest extends TestCase
{
    private const DIR = 'observedreg://fleet';

    /** @var array<string, mixed> */
    private array $schema = [
        '$id' => 'https://example.test/schemas/thing/1',
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string'], 'size' => ['type' => 'integer']],
    ];

    protected function setUp(): void
    {
        ObservedRegistryDirectory::reset();
        stream_wrapper_register('observedreg', ObservedRegistryDirectory::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('observedreg');
    }

    /** @return array<string, string> the artifacts (`*.schema.json`) in a snapshot of the directory */
    private static function artifacts(array $files): array
    {
        return array_filter($files, fn (string $path) => str_ends_with($path, '.schema.json'), ARRAY_FILTER_USE_KEY);
    }

    public function test_a_simulated_reader_view_never_shows_the_artifact_part_written(): void
    {
        (new FilesystemSchemaRegistry(self::DIR))->register($this->schema);

        $final = self::artifacts(ObservedRegistryDirectory::$files);
        $this->assertCount(1, $final, 'one artifact, no temporary file left over');
        $this->assertSame($this->schema, json_decode((string) reset($final), true));

        $this->assertNotEmpty(ObservedRegistryDirectory::$seenDuringWrites);
        foreach (ObservedRegistryDirectory::$seenDuringWrites as $i => $seen) {
            foreach (self::artifacts($seen) as $path => $contents) {
                $this->assertSame(
                    $this->schema,
                    json_decode($contents, true),
                    "write #{$i}: the simulated reader view of [{$path}] shows ".strlen($contents).' bytes of a part-written artifact',
                );
            }
        }

        // The swap is a rename within the directory (atomic on one filesystem), not a copy across.
        foreach (ObservedRegistryDirectory::$renames as [$from, $to]) {
            $this->assertSame(dirname($to), dirname($from));
        }
    }

    public function test_a_failed_write_leaves_no_artifact_behind(): void
    {
        ObservedRegistryDirectory::$diskFull = true;

        $refused = null;
        try {
            (new FilesystemSchemaRegistry(self::DIR))->register($this->schema);
        } catch (RuntimeException $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'a write that did not complete must not pass silently');
        $this->assertStringContainsString('Cannot write the schema artifact', $refused->getMessage());
        $this->assertSame(
            [self::DIR.'/.registry.lock'],
            array_keys(ObservedRegistryDirectory::$files),
            'neither an empty artifact nor a temporary file is left; only the (empty) lock file',
        );
        $this->assertNull((new FilesystemSchemaRegistry(self::DIR))->get($this->schema['$id']));
    }

    public function test_replacing_an_artifact_keeps_its_mode(): void
    {
        $dir = self::realDirectory();
        $registry = new FilesystemSchemaRegistry($dir);

        try {
            $registry->register($this->schema);
            [$artifact] = glob($dir.'/*.schema.json') ?: [null];
            $this->assertNotNull($artifact);

            // A host set the artifact's mode itself, and the file then lost its JSON: get() is null, so register() writes.
            chmod($artifact, 0640);
            file_put_contents($artifact, '{ not json');
            clearstatcache();
            $this->assertNull($registry->get($this->schema['$id']));

            $registry->register($this->schema);
            clearstatcache();

            $this->assertSame($this->schema, $registry->get($this->schema['$id']));
            $this->assertSame('0640', sprintf('%04o', fileperms($artifact) & 07777), 'the replaced artifact keeps its mode');
        } finally {
            self::removeRealDirectory($dir);
        }
    }

    public function test_a_new_artifact_gets_the_mode_the_in_place_write_gave(): void
    {
        $dir = self::realDirectory();

        try {
            (new FilesystemSchemaRegistry($dir))->register($this->schema);
            [$artifact] = glob($dir.'/*.schema.json') ?: [null];
            clearstatcache();

            $this->assertSame(sprintf('%04o', 0666 & ~umask()), sprintf('%04o', fileperms($artifact) & 07777));
        } finally {
            self::removeRealDirectory($dir);
        }
    }

    /** @var array<string, mixed> a second shape under the same `$id` */
    private array $otherShape = [
        '$id' => 'https://example.test/schemas/thing/1',
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string'], 'colour' => ['type' => 'string']],
    ];

    public function test_a_second_writer_inside_the_lock_window_waits_and_is_then_refused(): void
    {
        $first = new FilesystemSchemaRegistry(self::DIR);
        $second = new FilesystemSchemaRegistry(self::DIR);
        $insideTheWindow = null;

        // While the first writer is writing its temporary file (past its get(), before its rename), the second
        // writer registers another shape under the same $id.
        ObservedRegistryDirectory::$onFirstWrite = function () use ($second, &$insideTheWindow): void {
            try {
                $second->register($this->otherShape);
                $insideTheWindow = 'completed';
            } catch (WouldWaitForTheLock) {
                $insideTheWindow = 'waited';
            }
        };

        $first->register($this->schema);

        $this->assertSame(
            'waited',
            $insideTheWindow,
            "the second writer's registration {$insideTheWindow} inside the first's write window: both registrations succeed and the last rename wins",
        );
        $this->assertSame([], array_filter(
            ObservedRegistryDirectory::$renames,
            fn (array $rename) => $rename[0] !== ObservedRegistryDirectory::$renames[0][0],
        ), 'only the first writer renamed an artifact into place');

        // Once the lock is free the second writer goes on: it now sees the first's artifact and is refused.
        $refused = null;
        try {
            $second->register($this->otherShape);
        } catch (SchemaRegistryConflict $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'the second writer of another shape is refused once it can look');
        $this->assertSame($this->schema, $first->get($this->schema['$id']));
    }

    public function test_a_second_process_waits_for_the_lock_and_is_then_refused(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are needed to run a second process.');
        }

        $dir = self::realDirectory();
        $signals = $dir.'-signals';
        mkdir($signals);
        $registry = new FilesystemSchemaRegistry($dir);
        $await = static function (string $file): void {
            $deadline = microtime(true) + 10;
            while (! is_file($file)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException("timed out waiting for [{$file}]");
                }
                usleep(5000);
            }
        };

        $child = pcntl_fork();
        if ($child === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($child === 0) {
            // The second process: once the first holds the lock, it registers another shape.
            try {
                $await($signals.'/first-holds');
                file_put_contents($signals.'/second-registering', '');
                (new FilesystemSchemaRegistry($dir))->register($this->otherShape);
                $outcome = 'registered';
            } catch (SchemaRegistryConflict) {
                $outcome = 'refused';
            } catch (\Throwable $e) {
                $outcome = 'error: '.$e->getMessage();
            }
            file_put_contents($signals.'/second-result', sprintf('%.6f', microtime(true))."\n".$outcome);
            posix_kill(posix_getpid(), SIGKILL); // leave without running the parent's PHPUnit shutdown
        }

        try {
            $wrote = null;
            $registry->withLock(function (FilesystemSchemaRegistry $locked) use ($signals, $await, &$wrote): void {
                touch($signals.'/first-holds');
                $await($signals.'/second-registering');
                usleep(500_000); // the second process is now inside register(), at the lock
                $locked->register($this->schema);
                $wrote = microtime(true);
            });

            pcntl_waitpid($child, $status);
            $child = 0;
            [$finished, $outcome] = explode("\n", (string) file_get_contents($signals.'/second-result'), 2);

            $this->assertSame('refused', $outcome, 'the second process is refused, not clobbered or clobbering');
            $this->assertGreaterThan($wrote, (float) $finished, 'the second process finished only after the first had written');
            $this->assertSame($this->schema, $registry->get($this->schema['$id']));
        } finally {
            if ($child > 0) {
                posix_kill($child, SIGKILL);
                pcntl_waitpid($child, $status);
            }
            self::removeRealDirectory($signals);
            self::removeRealDirectory($dir);
        }
    }

    private static function realDirectory(): string
    {
        return sys_get_temp_dir().'/lds-atomic-'.bin2hex(random_bytes(6));
    }

    private static function removeRealDirectory(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_a_real_directory_holds_only_the_artifact_after_register(): void
    {
        $dir = self::realDirectory();

        try {
            $registry = new FilesystemSchemaRegistry($dir);
            $registry->register($this->schema);
            $registry->register($this->schema); // the idempotent re-publish writes nothing

            $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..', '.registry.lock']));
            $this->assertCount(1, $entries, 'no temporary file left: '.implode(', ', $entries));
            $this->assertStringEndsWith('.schema.json', $entries[0]);
            $this->assertFileExists($dir.'/.registry.lock', 'the lock file stays: unlinking a lock file races its next holder');
            $this->assertSame($this->schema, $registry->get($this->schema['$id']));
        } finally {
            self::removeRealDirectory($dir);
        }
    }
}

/**
 * One directory held in memory. At every write it snapshots the directory: the simulated view a reader WOULD have
 * at that instant. Nothing reads concurrently; the tests assert over the snapshots.
 */
class ObservedRegistryDirectory
{
    /** @var array<string, string> path => contents */
    public static array $files = [];

    /** @var list<array<string, string>> the directory as a reader saw it at the start of each write */
    public static array $seenDuringWrites = [];

    /** @var list<array{0: string, 1: string}> */
    public static array $renames = [];

    public static bool $diskFull = false;

    /** Called once, at the first write: lets a test act inside a writer's window. */
    public static ?\Closure $onFirstWrite = null;

    /** The open stream holding the exclusive lock, if any. */
    private static ?self $lockHolder = null;

    /** @var resource|null */
    public $context;

    private string $path = '';

    private int $position = 0;

    public static function reset(): void
    {
        self::$files = [];
        self::$seenDuringWrites = [];
        self::$renames = [];
        self::$diskFull = false;
        self::$onFirstWrite = null;
        self::$lockHolder = null;
    }

    /** A held lock makes a second holder wait. One thread cannot wait, so the attempt is recorded by throwing. */
    public function stream_lock(int $operation): bool
    {
        if (($operation & ~LOCK_NB) === LOCK_UN) {
            if (self::$lockHolder === $this) {
                self::$lockHolder = null;
            }

            return true;
        }
        if (self::$lockHolder !== null && self::$lockHolder !== $this) {
            throw new WouldWaitForTheLock;
        }
        self::$lockHolder = $this;

        return true;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        if (rtrim($path, '/') === 'observedreg://fleet') {
            return ['mode' => 0040775] + array_fill(0, 13, 0);
        }
        if (! array_key_exists($path, self::$files)) {
            return false;
        }

        return ['mode' => 0100664, 'size' => strlen(self::$files[$path])] + array_fill(0, 13, 0);
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->path = $path;
        $this->position = 0;

        if ($mode[0] === 'r') {
            return array_key_exists($path, self::$files);
        }
        if ($mode[0] === 'x' && array_key_exists($path, self::$files)) {
            return false;
        }
        if ($mode[0] === 'w' || ! array_key_exists($path, self::$files)) {
            self::$files[$path] = ''; // Opening for write makes the (empty) file visible at once, as a real one does.
        }

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$seenDuringWrites[] = self::$files;

        if (self::$onFirstWrite !== null) {
            $act = self::$onFirstWrite;
            self::$onFirstWrite = null;
            $act();
        }

        if (self::$diskFull) {
            return 0;
        }
        self::$files[$this->path] .= $data;

        return strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr(self::$files[$this->path] ?? '', $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$files[$this->path] ?? '');
    }

    public function stream_stat(): array|false
    {
        return $this->url_stat($this->path, 0);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        if (self::$lockHolder === $this) {
            self::$lockHolder = null; // closing a file releases its lock
        }
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function rename(string $from, string $to): bool
    {
        if (! array_key_exists($from, self::$files)) {
            return false;
        }
        self::$renames[] = [$from, $to];
        self::$files[$to] = self::$files[$from];
        unset(self::$files[$from]);

        return true;
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }
}

/** A second process would wait at the lock here; the simulated view records that it reached it. */
class WouldWaitForTheLock extends \Exception {}
