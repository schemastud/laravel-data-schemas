<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;

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
        $this->assertSame([], ObservedRegistryDirectory::$files, 'neither an empty artifact nor a temporary file is left');
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

            $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
            $this->assertCount(1, $entries, 'no temporary file left: '.implode(', ', $entries));
            $this->assertStringEndsWith('.schema.json', $entries[0]);
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

    public function stream_close(): void {}

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
