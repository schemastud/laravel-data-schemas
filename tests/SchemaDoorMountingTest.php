<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\DataSchemas\Http\SchemaDoorClaimsTheSite;
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
            // Ticket 112 — `/schemas` clears the is_string guard but names no origin, so it mounts a
            // door the absolute-URL-keyed registry could only ever 404 at. Nothing mounts.
            'a relative authority names no origin, so nothing mounts' => ['/schemas', null],
            'a bare host with no scheme names no origin either' => ['schemas.example.test', null],
        ];
    }

    public static function domains(): array
    {
        return [
            'a path-shaped authority stays unconstrained' => ['https://app.splicewire.com/schemas', null],
            'a deeper path stays unconstrained too' => ['https://example.test/json/schema', null],
            'a PATH-LESS authority is constrained to its host' => ['https://schemas.splicewire.com', 'schemas.splicewire.com'],
            'the port is not part of the domain constraint' => ['https://schemas.example.test:8443', 'schemas.example.test'],
            'nothing mounted means nothing to constrain' => [false, null],
            'a relative authority mounts nothing, so no domain' => ['/schemas', null],
        ];
    }

    #[DataProvider('domains')]
    public function test_only_a_path_less_authority_is_domain_constrained(
        string|bool|null $baseUri,
        ?string $expected,
    ): void {
        $this->assertSame($expected, SchemaDoorMount::domainFor($baseUri));
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

    /**
     * Ticket 111 — the whole point of the domain constraint.
     *
     * Measured at `~/Herd/splicewire`: a sibling bare `GET {path}` (beam-ux's site entry) had
     * REPLACED the door in `RouteCollection`, which keys by domain+method+URI. Registering the
     * sibling first here reproduces that ordering exactly; the door must survive it.
     */
    public function test_a_path_less_authority_survives_a_sibling_root_catch_all(): void
    {
        $this->app['router']->get('{path}', fn () => 'site')->where('path', '.*')->name('sibling.root');

        $this->app['config']->set('data-schemas.base_uri', 'https://schemas.splicewire.com');
        (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();

        $routes = $this->app['router']->getRoutes();

        $door = collect($routes)->first(fn ($r) => $r->getName() === 'data-schemas.document');

        $this->assertNotNull($door, 'the door was silently overwritten by the sibling root catch-all');
        $this->assertSame('schemas.splicewire.com', $door->getDomain());
        $this->assertNotNull(
            collect($routes)->first(fn ($r) => $r->getName() === 'sibling.root'),
            'the door must not overwrite the sibling either — both survive',
        );

        $matched = $routes->match(Request::create('https://schemas.splicewire.com/intake/request-access/1'));
        $this->assertSame('data-schemas.document', $matched->getName());

        $elsewhere = $routes->match(Request::create('https://splicewire.com/about'));
        $this->assertSame('sibling.root', $elsewhere->getName());
    }

    public static function siteClaims(): array
    {
        return [
            'a bare origin on the app host itself' => ['http://localhost', 'http://localhost', null],
            'the port does not separate them (nor does the domain constraint)' => ['http://localhost:9000', 'http://localhost:8000', null],
            'hosts compare case-insensitively' => ['https://App.Example.test', 'https://app.example.test', null],
            'a trailing dot names the same host' => ['https://app.example.test.', 'https://app.example.test', null],
            'the loopback sink a proof host is pointed at' => ['http://127.0.0.1:9', 'http://127.0.0.1:9', null],
            'a tier declared UNCONSTRAINED claims every host' => ['https://schemas.example.test', 'https://splicewire.com', ['public' => ['domain' => null]]],
            'a tier constrained to the app host' => ['https://schemas.example.test', 'https://splicewire.com', ['public' => ['domain' => 'splicewire.com']]],
            'unconstrained is refused even with no app host to judge' => ['https://schemas.example.test', '', ['public' => ['domain' => null]]],
        ];
    }

    /**
     * Launch ticket 70 — a ROOT door (from a path-less authority) on a tier that is unconstrained, or constrained to
     * the app's OWN host, is not the dedicated-host shape above: it is a catch-all over the whole site. Measured on
     * the starters: laravel-beam's ApiRoot then refused the boot ("API root [beam/accounts] is shadowed by earlier
     * catch-all [data-schemas.document]"), naming the door but not the setting. The refusal must come from here,
     * name SCHEMA_BASE_URI and the '/schemas' fix, judge each resolved tier, and leave no door behind.
     */
    #[DataProvider('siteClaims')]
    public function test_a_root_door_that_would_claim_the_whole_site_is_refused(string $baseUri, string $appUrl, ?array $tiers): void
    {
        $this->app['config']->set('app.url', $appUrl);
        $this->app['config']->set('data-schemas.base_uri', $baseUri);
        $this->app['config']->set('data-schemas.served_tiers', $tiers);

        try {
            (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();
            $this->fail('a root door that claims the whole site was mounted instead of refused');
        } catch (SchemaDoorClaimsTheSite $refused) {
            $this->assertStringContainsString('SCHEMA_BASE_URI', $refused->getMessage());
            $this->assertStringContainsString('/schemas', $refused->getMessage());
        }

        $this->assertNull(
            collect($this->app['router']->getRoutes())->first(fn ($r) => str_starts_with((string) $r->getName(), 'data-schemas.document')),
            'a refused authority must not leave a door behind',
        );
    }

    public function test_the_refusal_names_the_origin_only_never_credentials_or_query(): void
    {
        $this->app['config']->set('app.url', 'https://app.example.test');
        $this->app['config']->set('data-schemas.base_uri', 'https://user:s3cret@app.example.test?token=abc');

        try {
            (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();
            $this->fail('expected the site-claiming door to be refused');
        } catch (SchemaDoorClaimsTheSite $refused) {
            $this->assertStringContainsString('https://app.example.test/schemas', $refused->getMessage());
            $this->assertStringNotContainsString('s3cret', $refused->getMessage());
            $this->assertStringNotContainsString('token=abc', $refused->getMessage());
        }
    }

    public static function stillMounts(): array
    {
        return [
            'a dedicated schemas host beside the app' => ['https://schemas.splicewire.com', 'https://splicewire.com', null, '{path}', 'schemas.splicewire.com'],
            'a path on the app host is the documented form' => ['http://localhost/schemas', 'http://localhost', null, 'schemas/{path}', null],
            'a path-shaped door never claims the site, even unconstrained' => ['http://localhost/schemas', 'http://localhost', ['public' => ['domain' => null]], 'schemas/{path}', null],
            'an app-host authority whose tier is constrained ELSEWHERE' => ['http://localhost', 'http://localhost', ['public' => ['domain' => 'schemas.localhost.test']], '{path}', 'schemas.localhost.test'],
            'an app URL with no host cannot be judged against' => ['http://localhost', 'localhost', null, '{path}', 'localhost'],
        ];
    }

    #[DataProvider('stillMounts')]
    public function test_every_other_declared_authority_still_mounts(string $baseUri, string $appUrl, ?array $tiers, string $uri, ?string $domain): void
    {
        $this->app['config']->set('app.url', $appUrl);
        $this->app['config']->set('data-schemas.base_uri', $baseUri);
        $this->app['config']->set('data-schemas.served_tiers', $tiers);
        (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();

        $door = collect($this->app['router']->getRoutes())->first(fn ($r) => str_starts_with((string) $r->getName(), 'data-schemas.document'));

        $this->assertNotNull($door, "{$baseUri} beside {$appUrl} must still mount its door");
        $this->assertSame($uri, $door->uri());
        $this->assertSame($domain, $door->getDomain());
    }

    public function test_a_relative_authority_mounts_no_door_at_all(): void
    {
        // Ticket 112 — the three starters shipped `/schemas`. `schemas/{path}` would have mounted a
        // door that answers nothing, because the served registry is keyed by the absolute request
        // URL. Mounting nothing is the honest report; the loud one is on the identity half.
        $this->app['config']->set('data-schemas.base_uri', '/schemas');
        (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();

        $uris = collect($this->app['router']->getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertNotContains('schemas/{path}', $uris);
    }

    public function test_an_opted_out_host_registers_nothing(): void
    {
        $this->app['config']->set('data-schemas.base_uri', false);
        (new LaravelDataSchemasServiceProvider($this->app))->mountSchemaDoor();

        $uris = collect($this->app['router']->getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertNotContains('schemas/{path}', $uris);
    }
}
