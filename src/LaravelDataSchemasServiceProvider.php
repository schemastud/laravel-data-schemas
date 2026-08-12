<?php

namespace Schemastud\DataSchemas;

use Illuminate\Support\ServiceProvider;
use Rushing\PipelineRegistry\PipelineRegistry;
use Schemastud\DataSchemas\Commands\GenerateJsonSchemaCommand;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Overlay\DataOverlayRegistry;
use Schemastud\DataSchemas\Overlay\DataOverlayResolver;
use Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry;
use Schemastud\DataSchemas\Overlay\Lens\LensRegistry;
use Schemastud\DataSchemas\Overlay\Lens\ReversibleResolver;
use Schemastud\DataSchemas\Overlay\StaticOverlayResolver;
use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;

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
    }

    /**
     * Self-describe this package's registries into beam-core's {@see ManifestIndex} — the "index of
     * indexes" `splicewire:beam:manifests --json` renders, which is what an agent is told to read
     * before designing an I/O surface (particle-doctrine-convergence, tickets 11 and 13).
     *
     * This package does NOT depend on `splicewire/laravel-beam` and must keep booting without it, so
     * the call is guarded on the class existing as well as on the singleton being bound — the same
     * inert-branch pattern the composition engine and blockdoc take. Outside a beam host (including
     * this package's own suite) the branch is never entered and nothing is described; the integration
     * proof is the live host's manifest JSON. `class_exists()` on an imported name resolves the
     * `::class` constant at compile time, so the guard itself never autoloads the absent package.
     *
     * Both registries are argued rather than labelled, because the two axes genuinely differ here even
     * though both classes end in `Registry`:
     *
     * - **LensRegistry** — singleton-accumulator, RUN-ALL. Singleton-accumulator and not config-source:
     *   a `LensRegistration` carries a live `LensAssociation` (or a closure building one) plus its
     *   evidence samples, none of which survives a config array of class-strings, so the only seam is
     *   resolving the singleton in your own provider's `boot()` and calling `register()`. RUN-ALL, and
     *   this is the load-bearing half: the read that matters is `all()`/`ofTier()`, the whole
     *   enumeration, because the registry's product is DISCOVERABILITY rather than dispatch. Even the
     *   keyed reads refuse to narrow — `forId()` returns every lens over an `@id`, not the one, since
     *   picking would mean this registry deciding which host is right about a canonical it does not own.
     *   Contrast the composition engine's `ResourceRenderingRegistry`, also run-all but for the opposite
     *   reason: there a read mounts a route per entry. Here a read mounts nothing at all.
     * - **DataOverlayRegistry** — singleton-accumulator, COMPOSE-MANY. Also accumulator (a host calls
     *   `register($key, $document)`), but its read is `stackFor($keys)`, which concatenates every
     *   document registered under the requested keys and folds them in order — one ordered chain where
     *   the last write at a JSONPath target wins. Not run-all: a read engages the documents for the
     *   keys it was asked about, and the composition, not the enumeration, is the answer.
     *
     * `order` places both after beam-core's own foundation entries and beside the overlay/lens seam
     * they belong to.
     */
    protected function describeDataSchemasManifests(): void
    {
        if (! class_exists(ManifestIndex::class) || ! $this->app->bound(ManifestIndex::class)) {
            return;
        }

        $index = $this->app->make(ManifestIndex::class);
        $package = 'schemastud/laravel-data-schemas';

        $index->describe(new ManifestDescriptor(
            name: 'LensRegistry',
            of: 'declared lenses (canonical ↔ rendering, law-checked), each tiered host-applied or engine-authoritative',
            seam: ManifestSeam::SingletonAccumulator,
            arity: ManifestArity::RunAll,
            registerHint: 'from your provider\'s boot(), resolve LensRegistry and register(new LensRegistration(key: \'vendor/lens-name\', tier: LensTier::HostApplied, association: fn () => ..., evidence: new LensEvidence(...))) — the tier is visibility, never endorsement, and fidelity is certified from the evidence, never claimed',
            where: LensRegistry::class,
            package: $package,
            order: 30,
        ));

        $index->describe(new ManifestDescriptor(
            name: 'DataOverlayRegistry',
            of: 'DataOverlay documents by key — the forward-only override/merge/unset deltas laid over a canonical',
            seam: ManifestSeam::SingletonAccumulator,
            arity: ManifestArity::ComposeMany,
            registerHint: 'resolve DataOverlayRegistry and register($key, $overlayDocument) from your provider; a key may carry several documents and they fold in registration order',
            where: DataOverlayRegistry::class.' (default '.InMemoryOverlayRegistry::class.')',
            package: $package,
            order: 31,
        ));
    }

    public function boot(): void
    {
        $this->describeDataSchemasManifests();

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
}
