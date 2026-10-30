<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Tests\Fixtures\RetiredOptional\OptionalData;
use Schemastud\DataSchemas\Tests\Fixtures\RetiredOptional\OtherData;

class SchemaCheckRetiredOptionalTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('data-schemas.base_uri', 'https://example.test/schemas');
        $app['config']->set('data-schemas.scan_paths', [__DIR__.'/Fixtures/RetiredOptional']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/optional-check-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_only_optional_retirement_reports_its_reason_before_success_without_rewriting_frozen_bytes(): void
    {
        $before = $this->freeze(false);
        $this->artisan('schema:check', ['--dir' => $this->dir])
            ->expectsOutputToContain('Inert changes in 1 class(es) - NOT drift:')
            ->expectsOutputToContain('removed x-optional keyword(s) only')
            ->expectsOutputToContain('No schema drift detected.')
            ->assertExitCode(0);
        $this->assertSame($before, $this->hashes());
    }

    public function test_mixed_real_drift_and_retirement_reports_both_with_a_neutral_heading(): void
    {
        $before = $this->freeze(true);
        $this->artisan('schema:check', ['--dir' => $this->dir])
            ->expectsOutputToContain('Inert changes in 1 class(es) - NOT drift:')
            ->expectsOutputToContain('removed x-optional keyword(s) only')
            ->expectsOutputToContain('Schema drift detected in 1 class(es):')
            ->assertExitCode(1);
        $this->assertSame($before, $this->hashes());
    }

    private function freeze(bool $withDrift): array
    {
        $generator = new JsonSchemaGenerator(['base_uri' => 'https://example.test/schemas']);
        $registry = new FilesystemSchemaRegistry($this->dir);
        $optional = $generator->generate(new \ReflectionClass(OptionalData::class));
        $optional['properties']['nickname']['x-optional'] = true;
        $registry->register($optional);
        $other = $generator->generate(new \ReflectionClass(OtherData::class));
        if ($withDrift) {
            $other['properties']['name']['type'] = 'integer';
        }
        $registry->register($other);
        return $this->hashes();
    }

    private function hashes(): array
    {
        $files = (new Filesystem)->allFiles($this->dir);
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);
        return $hashes;
    }
}
