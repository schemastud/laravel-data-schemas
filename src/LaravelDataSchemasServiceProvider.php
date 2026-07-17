<?php

namespace Schemastud\DataSchemas;

use Illuminate\Support\ServiceProvider;
use Schemastud\DataSchemas\Commands\GenerateJsonSchemaCommand;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Overlay\DataOverlayRegistry;
use Schemastud\DataSchemas\Overlay\DataOverlayResolver;
use Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry;
use Schemastud\DataSchemas\Overlay\StaticOverlayResolver;

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
    }

    public function boot(): void
    {
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
