<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;
use Schemastud\DataSchemas\Http\SchemaDocumentController;
use Schemastud\DataSchemas\Http\ServedTier;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;

/**
 * The served-tier seam on the public schema door — beam-facade tickets 170 and 180.
 *
 * 180 ruled the per-tenant tier **authenticated**, and that ruling has one structural consequence: the
 * two things `SchemaDocumentController` treated as constants — which registry answers, and what
 * `Cache-Control` it answers with — become properties of *which tier matched*.
 *
 * The load-bearing assertion in this file is not that tiers exist. It is that **a request on one
 * tenant's authority can never be answered from another tenant's registry**, and that a tenant tier's
 * response can never carry `public`. Both are disclosures rather than bugs, and a same-origin
 * round-trip test passes under either of them, which is why they are asserted directly.
 */
class ServedTierDoorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataSchemasServiceProvider::class];
    }

    /** Read by {@see self::defineEnvironment()} on every (re)boot — the door mounts during boot. */
    protected array $tiers = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('data-schemas.base_uri', 'https://app.example.test/schemas');
        $app['config']->set('data-schemas.served_tiers', $this->tiers);
    }

    /** A registry holding exactly the given `$id => artifact` pairs and nothing else. */
    private function registry(array $documents): ServedSchemaRegistry
    {
        return new class($documents) implements ServedSchemaRegistry
        {
            public function __construct(private array $documents) {}

            public function get(string $id): ?array
            {
                return $this->documents[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->documents[$id]);
            }

            public function ids(): array
            {
                return array_keys($this->documents);
            }

            public function register(array $schema): void {}
        };
    }

    public function test_a_host_declaring_no_tiers_gets_exactly_todays_door(): void
    {
        $route = Route::getRoutes()->getByName('data-schemas.document');

        $this->assertNotFalse($route, 'the historical route name must survive the seam');
        $this->assertSame([], $route->gatherMiddleware(), 'the default tier mounts bare, as it always has');
        $this->assertNull($route->getDomain());
    }

    public function test_the_default_tier_still_answers_the_public_immutable_header(): void
    {
        $this->app->instance(ServedSchemaRegistry::class, $this->registry([
            'https://app.example.test/schemas/thing/1' => ['$id' => 'https://app.example.test/schemas/thing/1'],
        ]));

        $header = $this->get('https://app.example.test/schemas/thing/1')->assertOk()->headers->get('Cache-Control');

        // Asserted by PARTS: Symfony normalizes and re-orders Cache-Control, so a literal-string
        // assertion here passes or fails on alphabetical ordering rather than on the directive set.
        $this->assertStringContainsString('public', $header);
        $this->assertStringContainsString('max-age=31536000', $header);
        $this->assertStringContainsString('immutable', $header);
    }

    public function test_each_declared_tier_mounts_its_own_route_with_its_own_middleware_and_domain(): void
    {
        $this->refreshApplicationWithTiers([
            'host' => ['cache' => ServedTier::PUBLIC_IMMUTABLE],
            'tenant' => [
                'middleware' => ['throttle:60,1'],
                'cache' => 'private, max-age=31536000, immutable',
                'domain' => '{tenant}.example.test',
            ],
        ]);

        $host = Route::getRoutes()->getByName('data-schemas.document');
        $tenant = Route::getRoutes()->getByName('data-schemas.document.tenant');

        $this->assertNotFalse($host);
        $this->assertNotFalse($tenant);
        $this->assertNull($host->getDomain());
        $this->assertSame('{tenant}.example.test', $tenant->getDomain());
        $this->assertContains('throttle:60,1', $tenant->gatherMiddleware());
        $this->assertNotContains('throttle:60,1', $host->gatherMiddleware());
    }

    /**
     * ⚠️ The disclosure assertion. `public` authorizes any shared cache to store the response and hand
     * it to the next caller; on a per-tenant tier that IS the leak ticket 180 was filed for. The tenant
     * tier answers `private` and the host tier is unaffected by its presence.
     */
    public function test_a_tenant_tier_never_answers_public_and_the_host_tier_is_unchanged(): void
    {
        $this->refreshApplicationWithTiers([
            'host' => [],
            'tenant' => [
                'registry' => 'tier.tenant.registry',
                'cache' => 'private, max-age=31536000, immutable',
                'domain' => 'acme.example.test',
            ],
        ]);

        $this->app->instance(ServedSchemaRegistry::class, $this->registry([
            'https://app.example.test/schemas/host-thing/1' => ['$id' => 'https://app.example.test/schemas/host-thing/1'],
        ]));
        $this->app->instance('tier.tenant.registry', $this->registry([
            'https://acme.example.test/schemas/acme-thing/1' => ['$id' => 'https://acme.example.test/schemas/acme-thing/1'],
        ]));

        $tenant = $this->get('https://acme.example.test/schemas/acme-thing/1')->assertOk();
        $this->assertStringContainsString('private', $tenant->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $tenant->headers->get('Cache-Control'));

        $host = $this->get('https://app.example.test/schemas/host-thing/1')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $host);
        $this->assertStringContainsString('immutable', $host);
    }

    /**
     * ⚠️ The cross-tenant assertion, and the reason a same-origin round-trip test is not enough: with
     * `$id` = request URL, serving tenant B's bytes under tenant A's URL requires the wrong registry to
     * answer — which is exactly what a tenancy-resolution defect produces, and what a test that only
     * asks for its own document cannot see. Asked for A's `$id`; B's registry must not supply it.
     */
    public function test_one_tenants_authority_is_never_answered_from_another_tenants_registry(): void
    {
        $this->refreshApplicationWithTiers([
            'host' => [],
            'tenant' => [
                'registry' => 'tier.tenant.registry',
                'cache' => 'private, max-age=31536000, immutable',
                'domain' => '{tenant}.example.test',
            ],
        ]);

        // The registry bound for this request holds only BETA's document. ALPHA asks for its own.
        $this->app->instance('tier.tenant.registry', $this->registry([
            'https://beta.example.test/schemas/shared-name/1' => ['$id' => 'https://beta.example.test/schemas/shared-name/1', 'title' => 'BETA ONLY'],
        ]));

        $response = $this->get('https://alpha.example.test/schemas/shared-name/1');

        $response->assertNotFound();
        $this->assertStringNotContainsString('BETA ONLY', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'a miss must never be cached — the artifact may be registered later');
    }

    /**
     * A tier's registry is a container KEY resolved per request, never an instance held at boot. A
     * tenant tier must resolve against whatever connection its middleware just established; an instance
     * captured at mount time would be bound to the central one forever, and would answer every tenant
     * from it.
     */
    public function test_a_tiers_registry_is_resolved_per_request_not_captured_at_boot(): void
    {
        $this->refreshApplicationWithTiers([
            'host' => [],
            'tenant' => ['registry' => 'tier.tenant.registry', 'domain' => 'acme.example.test', 'cache' => 'private'],
        ]);

        $resolutions = 0;
        $this->app->bind('tier.tenant.registry', function () use (&$resolutions) {
            $resolutions++;

            return $this->registry([
                'https://acme.example.test/schemas/thing/1' => ['$id' => 'https://acme.example.test/schemas/thing/1'],
            ]);
        });

        $this->get('https://acme.example.test/schemas/thing/1')->assertOk();
        $this->get('https://acme.example.test/schemas/thing/1')->assertOk();

        $this->assertSame(2, $resolutions, 'the tier registry must be resolved on every request');
    }

    /**
     * A misdeclared tier refuses rather than falling back. Falling back to the host tier's registry
     * would serve the WRONG population under the tenant's own URL — a silent 200 with someone else's
     * bytes, which is worse than any error.
     */
    public function test_a_misdeclared_tier_registry_refuses_instead_of_falling_back(): void
    {
        $this->refreshApplicationWithTiers([
            'host' => [],
            'tenant' => ['registry' => 'tier.broken', 'domain' => 'acme.example.test', 'cache' => 'private'],
        ]);

        $this->app->instance('tier.broken', new \stdClass);
        $this->app->instance(ServedSchemaRegistry::class, $this->registry([
            'https://acme.example.test/schemas/thing/1' => ['$id' => 'HOST REGISTRY'],
        ]));

        $response = $this->get('https://acme.example.test/schemas/thing/1');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('HOST REGISTRY', $response->getContent());
    }

    /**
     * ⚠️ **The regression this file exists for, and the one the header assertions could not see.**
     *
     * The first version of the seam registered tiers in declaration order, on the belief that Laravel
     * tries domain-constrained routes before undomained ones. It does not: `matchAgainstRoutes()` takes
     * the first route that matches in **insertion order**, and an undomained route matches every host.
     *
     * Measured end-to-end at `~/Herd/splicewire-app` on 2026-08-27 — a request to a tenant subdomain
     * matched `data-schemas.document`, the **host** tier, so the tenant tier's middleware never ran and
     * an unauthenticated request reached the door. Every header assertion in this file still passed,
     * because with a host-tier registry that holds nothing for that URL the answer is a 404 either way.
     *
     * So this asserts **which route matched**, which is the only thing that can see it.
     */
    public function test_a_tenant_subdomain_matches_the_tenant_tier_even_when_the_host_tier_is_declared_first(): void
    {
        $this->refreshApplicationWithTiers([
            // Declared host-first ON PURPOSE: the ordering must be guaranteed by the seam, not by how a
            // host happened to write its config.
            'host' => [],
            'tenant' => [
                'registry' => 'tier.tenant.registry',
                'middleware' => ['throttle:60,1'],
                'cache' => 'private, max-age=31536000, immutable',
                // The estate's real shape: the tenant pattern is one label DEEPER than the central
                // host, so the two cannot overlap. See the next test for what happens when they do.
                'domain' => '{tenant}.app.example.test',
            ],
        ]);

        $matched = Route::getRoutes()->match(\Illuminate\Http\Request::create('https://acme.app.example.test/schemas/thing/1', 'GET'));

        $this->assertSame('data-schemas.document.tenant', $matched->getName());
        $this->assertSame('tenant', $matched->defaults[SchemaDocumentController::TIER] ?? null);
        $this->assertContains('throttle:60,1', $matched->gatherMiddleware(), 'the tenant tier’s middleware must be the stack that runs');

        // And the host tier still wins on the host authority.
        $host = Route::getRoutes()->match(\Illuminate\Http\Request::create('https://app.example.test/schemas/thing/1', 'GET'));
        $this->assertSame('data-schemas.document', $host->getName());
    }

    /**
     * ⚠️ **A hazard the domained-first ordering makes strictly more likely, recorded rather than fixed.**
     *
     * A wildcard tenant pattern that is a SIBLING of the central host — `{tenant}.example.test` against a
     * central `app.example.test` — matches the central host too (`{tenant}` = `app`). Registering domained
     * tiers first then hands every central request to the tenant tier, behind the tenant tier's auth.
     *
     * This package cannot fix it: it is told a domain string and has no way to know a host's central
     * domain, and inventing one would be the tenancy vocabulary ticket 82 forbids it. So it is asserted
     * as the documented behaviour and called out in `config/data-schemas.php`. **A host whose tenant
     * pattern can match its own central host must make the pattern deeper**, which the estate's real
     * shape (`{tenant}.app.splicewire.test` under a central `app.splicewire.test`) already is.
     */
    public function test_a_sibling_wildcard_pattern_swallows_the_central_host_and_that_is_the_hosts_to_avoid(): void
    {
        $this->refreshApplicationWithTiers([
            'host' => [],
            'tenant' => ['registry' => 'tier.tenant.registry', 'cache' => 'private', 'domain' => '{tenant}.example.test'],
        ]);

        $matched = Route::getRoutes()->match(\Illuminate\Http\Request::create('https://app.example.test/schemas/thing/1', 'GET'));

        $this->assertSame(
            'data-schemas.document.tenant',
            $matched->getName(),
            'a sibling wildcard DOES swallow the central host — deepen the pattern rather than expecting the package to disambiguate',
        );
    }

    public function test_a_tier_may_declare_itself_unconstrained_explicitly(): void
    {
        $tiers = ServedTier::declared(['host' => ['domain' => null]], 'schemas.example.test');

        $this->assertNull($tiers[0]->domain, 'an explicit null must not be overwritten by the default domain');

        $inherited = ServedTier::declared(['host' => []], 'schemas.example.test');
        $this->assertSame('schemas.example.test', $inherited[0]->domain, 'an absent key inherits the base_uri domain');
    }

    public function test_shared_cacheability_is_askable_of_a_declaration(): void
    {
        $this->assertTrue((new ServedTier('host'))->isSharedCacheable());
        $this->assertFalse((new ServedTier('tenant', cache: 'private, max-age=31536000, immutable'))->isSharedCacheable());
    }

    /**
     * Rebuild the app with the given tiers declared. It has to be a reboot rather than a config set:
     * the door mounts in `boot()`, so a tier declared after boot mounts nothing — and testbench applies
     * `defineEnvironment()` on refresh, which is the only hook that lands before the provider boots.
     */
    private function refreshApplicationWithTiers(array $tiers): void
    {
        $this->tiers = $tiers;
        $this->refreshApplication();
    }
}
