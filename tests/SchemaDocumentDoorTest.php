<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;

/**
 * The public schema door — `GET <$id>` returns the artifact (beam-facade ticket 82).
 *
 * Ticket 64 ruled that the `$id` authority is the origin that serves the schema, and landed the
 * identity half only; nothing dereferenced. These pin the serving half, and in particular the two
 * properties the ticket turned on:
 *
 *  - the `$id` is reconstructed from the INCOMING REQUEST, never from `base_uri`, so a document is
 *    only ever served at the URI that IS its identity (and the conformance audit that follows is
 *    checking two independently-derived values rather than config against itself);
 *  - the door resolves {@see ServedSchemaRegistry}, never the general {@see SchemaRegistry}, so a
 *    host whose general binding is a composite over a tenant tier cannot leak it through this door.
 */
class SchemaDocumentDoorTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [LaravelDataSchemasServiceProvider::class];
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lds-door-'.uniqid();
        mkdir($this->dir, 0775, true);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    protected function defineEnvironment($app): void
    {
        // Routes mount in boot(), which testbench runs AFTER this — the inverse of the
        // register-time trap ticket 79 recorded one package over.
        $app['config']->set('data-schemas.base_uri', $this->baseUri());
        $app['config']->set('data-schemas.served_directories', [$this->dir]);
    }

    protected function baseUri(): string|bool|null
    {
        return 'http://localhost/schemas';
    }

    private function artifact(string $id): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => $id,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
        ];
    }

    private function register(array $schema): void
    {
        (new FilesystemSchemaRegistry($this->dir))->register($schema);
    }

    public function test_it_serves_the_bare_document_at_its_own_id(): void
    {
        $id = 'http://localhost/schemas/content/cell/2';
        $this->register($this->artifact($id));

        $response = $this->get($id);

        $response->assertOk();
        $this->assertSame('application/schema+json', $response->headers->get('Content-Type'));

        // The BARE document, not an envelope — a $ref-following client fetches a URI and expects a
        // schema at it. Both pre-existing surfaces returned ResponseBody::from(['data' => ...]).
        $body = $response->json();
        $this->assertSame($id, $body['$id']);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame($this->artifact($id), $body);
    }

    public function test_the_id_is_reconstructed_from_the_request_not_from_base_uri(): void
    {
        // An artifact minted on an authority that is NOT this host's base_uri. Asking for it at the
        // authority it names resolves it; the door is domain-agnostic by construction.
        $tenant = 'http://acme.localhost/schemas/content/cell/2';
        $this->register($this->artifact($tenant));

        $this->get($tenant)->assertOk()->assertJsonPath('$id', $tenant);

        // ...and the SAME path on the configured authority does not serve that document, because the
        // reconstruction names a different identity. This is what Route::domain() was going to enforce
        // and what request-derived reconstruction enforces for free.
        $this->get('http://localhost/schemas/content/cell/2')->assertNotFound();
    }

    public function test_it_never_reads_the_general_schema_registry(): void
    {
        $id = 'http://localhost/schemas/private/thing/1';

        // The general binding — at a beam host this is the tiered composite that reaches the tenant
        // database. Q5's line is that the door cannot see it.
        $general = sys_get_temp_dir().'/lds-door-general-'.uniqid();
        $registry = new FilesystemSchemaRegistry($general);
        $registry->register($this->artifact($id));
        $this->app->instance(SchemaRegistry::class, $registry);

        $this->get($id)->assertNotFound();

        foreach (glob($general.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($general);
    }

    public function test_a_miss_is_a_bare_uncached_404(): void
    {
        $response = $this->get('http://localhost/schemas/nope/1');

        $response->assertNotFound();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_it_is_cacheable_forever_because_the_registry_is_write_once(): void
    {
        $id = 'http://localhost/schemas/content/cell/2';
        $this->register($this->artifact($id));

        $response = $this->get($id);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);

        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertStringStartsNotWith('W/', $etag);

        $this->get($id, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_it_offers_no_latest_and_no_versions_alias(): void
    {
        $this->register($this->artifact('http://localhost/schemas/content/cell/2'));

        // A `$id` is versioned by construction; `latest` would be a URI that is no artifact's identity.
        $this->get('http://localhost/schemas/content/cell/latest')->assertNotFound();
        $this->get('http://localhost/schemas/content/cell/versions')->assertNotFound();
    }

    public function test_it_ignores_query_parameters_rather_than_honouring_them(): void
    {
        $id = 'http://localhost/schemas/content/cell/2';
        $this->register($this->artifact($id));

        // `?resolve=inline` exists on tower's authenticated endpoint and is deliberately NOT carried
        // over: bundling is a second way to say the same thing, and it would inline across the tier
        // boundary this door draws.
        $this->get($id.'?resolve=inline')->assertOk()->assertJsonPath('$id', $id);
    }
}
