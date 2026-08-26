<?php

namespace Schemastud\DataSchemas;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rushing\PipelineRegistry\PipelineRegistry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Schemastud\DataSchemas\Commands\GenerateJsonSchemaCommand;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;
use Schemastud\DataSchemas\Generators\ChainedGenerator;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Http\SchemaDocumentController;
use Schemastud\DataSchemas\Http\SchemaDoorMount;
use Schemastud\DataSchemas\Ids\SchemaIdParsersRegistry;
use Schemastud\DataSchemas\Ids\SchemaIdResolver;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\ServedSchemaChain;
use Schemastud\DataSchemas\Overlay\DataOverlayRegistry;
use Schemastud\DataSchemas\Overlay\DataOverlayResolver;
use Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry;
use Schemastud\DataSchemas\Overlay\Lens\LensRegistry;
use Schemastud\DataSchemas\Overlay\Lens\ReversibleResolver;
use Schemastud\DataSchemas\Overlay\StaticOverlayResolver;
use Schemastud\DataSchemas\Strategies\SchemaStrategiesRegistry;

class LaravelDataSchemasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/data-schemas.php',
            'data-schemas'
        );

        // The immutable, $id-keyed registry of frozen schema artifacts. Bound to
        // the filesystem implementation by default; swap via the container.
        $this->app->singleton(SchemaRegistry::class, function ($app) {
            $dir = $app['config']->get('data-schemas.registry_directory')
                ?? storage_path('app/schemas/registry');

            return new FilesystemSchemaRegistry($dir);
        });

        // The subset of artifacts the PUBLIC schema door will serve (beam-facade ticket 82). A
        // separate container key from SchemaRegistry above, on purpose — see the contract's docblock:
        // the general binding is routinely a composite whose tiers are opaque, one of which resolves
        // against the active tenant connection, so the door must never resolve it.
        //
        // Defaults to the host's own frozen artifact store. It is a LIST because a host's committed
        // artifacts routinely span more than one directory (splicewire-app freezes to both
        // `schemas/fleet` and `schemas/lifecycle`), so "the filesystem registry" has no referent.
        $this->app->singleton(ServedSchemaRegistry::class, function ($app) {
            $dirs = $app['config']->get('data-schemas.served_directories');

            $dirs = is_array($dirs) && $dirs !== []
                ? $dirs
                : [$app['config']->get('data-schemas.registry_directory') ?? storage_path('app/schemas/registry')];

            return ServedSchemaChain::overDirectories($dirs);
        });

        // DataOverlay host-adapter seams (ADR-0089). The base binds trivial
        // defaults — an empty static resolver and an in-memory registry (a
        // singleton so registrations persist for the request). A host swaps in
        // a context-aware resolver (tenant/locale) via the container.
        $this->app->bind(DataOverlayResolver::class, StaticOverlayResolver::class);
        $this->app->singleton(DataOverlayRegistry::class, InMemoryOverlayRegistry::class);

        // The registry of declared lenses (see Overlay/Lens/LensRegistry). A singleton
        // so registrations from every provider accumulate into one enumerable surface;
        // seeded with nothing, because the mechanism ships no lenses of its own.
        $this->app->singleton(LensRegistry::class, fn () => new LensRegistry(new ReversibleResolver));

        // The declaration `config('data-schemas.strategies')` never had. The strategy pipeline takes
        // five cross-vendor registrants and had no class, so no attribute and no index membership —
        // this package owns the config key, so this package binds the adapter (registry-kernel
        // ticket 08 D6/D7: a registry describes itself, and nobody describes on another's behalf).
        // The array stays the storage; every existing consumer still reads the plain list.
        $this->app->singleton(SchemaStrategiesRegistry::class);

        // The ref-grammar seam (beam-facade ticket 140). Same adapter shape as the strategies
        // registry above and for the same reason — the storage is `config('data-schemas.id_parsers')`,
        // a list a package appends its own parser to from its own provider.
        $this->app->singleton(SchemaIdParsersRegistry::class);

        // The ONE config-aware step in schema identity. Bound rather than newed at call sites so a
        // host can swap the floor grammar; NOT a singleton, because `base_uri` and the parser list
        // are read at construction and a test that sets config after boot must get the new value.
        $this->app->bind(SchemaIdResolver::class, fn () => SchemaIdResolver::fromConfig());

        // "Build the generator this host configured" — a step with no home until now. A census found
        // 41 construction sites across 13 repos and ~26 of them are a bare `new JsonSchemaGenerator`,
        // which takes NO config: `strategies` and `id_parsers` self-heal (the generator falls back to
        // the container for those two), but `schema_metadata`, `schema_version` and `base_uri` do not.
        // So those sites silently emit documents with no `$schema` and no `$id` at hosts that
        // configured both, and the OpenAPI leg describes a different document from the on-disk leg.
        //
        // Resolves the whole `generators` LIST as one chain rather than the first entry, because at a
        // multi-generator host the first entry is not "the generator" — see {@see ChainedGenerator}.
        //
        // NOT a singleton, for the same reason recorded on the SchemaIdResolver binding directly
        // above: config is read at CONSTRUCTION, so a host (or a test) that sets config after boot
        // must get the new value rather than a generator built from the old one.
        $this->app->bind(
            Generator::class,
            fn ($app) => ChainedGenerator::fromConfig((array) $app['config']->get('data-schemas', [])),
        );
    }

    public function boot(): void
    {
        $this->mountSchemaDoor();

        // DECLARING and INDEXING are two acts (registry-kernel 21 D1), and ticket 25 landed only the
        // first for this adapter. The `#[IsRegistry]` on SchemaStrategiesRegistry names
        // `schemas.strategies`; this is where that root actually becomes routable. Described from the
        // owner's own boot — the package that owns the config key owns the describe (08 D6/D7).
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(SchemaStrategiesRegistry::class),
            by: self::class,
        );

        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(SchemaIdParsersRegistry::class),
            by: self::class,
        );

        // Contribute the resources:* projection pipelines (the open,
        // foundation-tier slice) into the shared registry. Guarded so the
        // package degrades gracefully if the pipeline-registry engine is absent.
        if (class_exists(PipelineRegistry::class)) {
            $this->app->make(PipelineRegistry::class)
                ->mergePipelinesFrom(__DIR__.'/../config/pipelines');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/data-schemas.php' => config_path('data-schemas.php'),
            ], 'data-schemas-config');

            $this->commands([
                GenerateJsonSchemaCommand::class,
            ]);
        }
    }

    /**
     * Mount the public schema door, if this host declared an authority (beam-facade ticket 82).
     *
     * A declared `base_uri` string is a PROMISE to answer there — one knob, three states, so a host
     * cannot carry a live-looking authority that serves nothing. `false` and unset mount nothing,
     * which is what `base_uri`'s own docblock has promised since ticket 64.
     *
     * Mounted BARE — no `web`, no `api`, no session, no CSRF, no auth. It is a public read of
     * committed artifacts, and a `$ref`-following client carries no cookies.
     *
     * NOT domain-constrained for a PATH-SHAPED authority, deliberately: the `$id` is reconstructed
     * from the incoming request, so a document can only ever be served at the URI that is its own
     * identity. Constraining the domain would additionally foreclose the tenant-authority case
     * ticket 64 asked to keep open.
     *
     * A PATH-LESS authority is the exception, and ticket 111 measured why: its pattern is the root
     * catch-all `{path}`, which Laravel's domain+method+URI keying lets any sibling bare `GET {path}`
     * replace outright, silently. The host is then the only discriminator there is, so the door is
     * constrained to it. See {@see SchemaDoorMount} for the whole argument.
     *
     * The domain is applied through the registrar BEFORE the route is added, never with `->domain()`
     * afterwards: `RouteCollection::addToCollections()` reads the domain at add time to choose which
     * bucket to index into, so a domain set after the fact would leave the route filed as undomained
     * and reintroduce exactly the collision this is here to remove.
     *
     * Public, and called from `boot()` rather than inlined, so the mounting rule is directly testable
     * — and boot is deliberate: testbench applies `defineEnvironment()` after providers REGISTER, so
     * a register-time config read could never observe a test's configuration (ticket 79's trap).
     */
    public function mountSchemaDoor(): void
    {
        $baseUri = $this->app['config']->get('data-schemas.base_uri');

        $pattern = SchemaDoorMount::patternFor($baseUri);

        if ($pattern === null) {
            return;
        }

        $domain = SchemaDoorMount::domainFor($baseUri);

        $route = $domain === null
            ? Route::get($pattern, SchemaDocumentController::class)
            : Route::domain($domain)->get($pattern, SchemaDocumentController::class);

        $route->where('path', '.*')->name('data-schemas.document');
    }
}
