<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\DataSchemas\Http\SchemaDoorMount;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;

/**
 * The mount half of `data-schemas.base_uri`'s tri-state (beam-facade ticket 82).
 *
 * Ticket 64 made the key tri-state and landed the identity half; its own docblock already promised
 * that `false` mounts no serving route. 82 rules that the converse is a PROMISE: a declared URI
 * string obligates the host to answer there. One knob, three states — not two settings wearing one
 * name, which is what would let a host carry a live-looking authority that serves nothing.
 */
class SchemaDoorMountingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataSchemasServiceProvider::class];
    }

    public static function baseUris(): array
    {
        return [
            'unset means undecided, so nothing is served' => [null, null],
            'opted out of versioned identity entirely' => [false, null],
            'empty string is not a declaration' => ['', null],
            'the declared path becomes the prefix' => ['https://app.splicewire.com/schemas', 'schemas/{path}'],
            'a deeper path is honoured verbatim' => ['https://example.test/json/schema', 'json/schema/{path}'],
            'a trailing slash does not become an empty segment' => ['https://example.test/schemas/', 'schemas/{path}'],
            'an authority with no path mounts at the root' => ['https://schemas.example.test', '{path}'],
        ];
    }

    #[DataProvider('baseUris')]
    public function test_the_mount_pattern_is_derived_from_the_declared_authority(
        string|bool|null $baseUri,
        ?string $expected,
    ): void {
        $this->assertSame($expected, SchemaDoorMount::patternFor($baseUri));
    }

    public function test_a_declared_authority_actually_registers_the_route(): void
    {
        $this->app['config']->set('data-schemas.base_uri', 'https://app.splicewire.com/schemas');
        (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();

        $uris = collect($this->app['router']->getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertContains('schemas/{path}', $uris);
    }

    public function test_an_opted_out_host_registers_nothing(): void
    {
        $this->app['config']->set('data-schemas.base_uri', false);
        (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();

        $uris = collect($this->app['router']->getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertNotContains('schemas/{path}', $uris);
    }
}
