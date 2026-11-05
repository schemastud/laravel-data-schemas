<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * The application the registry pages' data examples run in (docs-feedback ticket 09): this package's provider and its
 * declared dependencies. Both halves boot it: `composer docs:examples` once per example, and {@see DataExamplesTest}
 * per test.
 */
abstract class DataExamplesTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PopcornServiceProvider::class, LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }
}
