<?php

namespace Schemastud\DataSchemas\Tests;

use ErrorException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;

/**
 * agreements 07a: the registry is built on every request and worker that resolves it, so two processes can race to
 * create its directory. The losing process sees no directory, its mkdir fails with "File exists" (the winner made
 * it in between), and Laravel's error handler turns that warning into an exception. The race is replayed
 * deterministically through a stream wrapper that answers exactly as the loser's filesystem does, under an error
 * handler that throws on warnings as Laravel's does.
 */
class SchemaRegistryDirectoryRaceTest extends TestCase
{
    protected function setUp(): void
    {
        LosingRaceDirectory::$calls = [];
        stream_wrapper_register('losingrace', LosingRaceDirectory::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('losingrace');
    }

    /**
     * Run $build under an error handler that throws on any unsuppressed warning, as Laravel's HandleExceptions does.
     * It is installed HERE, around the construction, and not in setUp(): PHPUnit installs its own handler after setUp()
     * and only records warnings, which would let the losing worker's warning pass silently.
     */
    private function underLaravelsErrorHandling(callable $build): void
    {
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            if (! (error_reporting() & $level)) {
                return false; // Suppressed with @, which Laravel's HandleExceptions honours.
            }

            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $build();
        } finally {
            restore_error_handler();
        }
    }

    public function test_losing_the_race_to_create_the_directory_is_not_an_error(): void
    {
        $this->underLaravelsErrorHandling(fn () => new FilesystemSchemaRegistry('losingrace://fleet'));

        $this->assertSame(['stat', 'mkdir', 'stat'], LosingRaceDirectory::$calls);
    }

    public function test_a_directory_that_still_cannot_be_made_is_an_error(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lds-not-a-dir-');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot create the schema registry directory');
            $this->underLaravelsErrorHandling(fn () => new FilesystemSchemaRegistry($file.'/registry'));
        } finally {
            @unlink($file);
        }
    }
}

/** A directory another process creates between this process's check and its mkdir. */
class LosingRaceDirectory
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var resource|null */
    public $context;

    private static bool $made = false;

    public function url_stat(string $path, int $flags): array|false
    {
        self::$calls[] = 'stat';
        if (self::$calls === ['stat']) {
            self::$made = false;

            return false; // The loser looks first: no directory yet.
        }

        return self::$made ? ['mode' => 0040775] + array_fill(0, 13, 0) : false;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        self::$calls[] = 'mkdir';
        self::$made = true; // The winner made it in the meantime…
        trigger_error('mkdir(): File exists', E_USER_WARNING); // …so the loser's own mkdir fails, as PHP's does.

        return false;
    }
}
