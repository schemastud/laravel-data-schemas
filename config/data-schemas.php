<?php

use Schemastud\DataSchemas\Collectors\DataObjectCollector;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\PathGenerators\DefaultPathGenerator;
use Schemastud\DataSchemas\Strategies\KeywordAttributesStrategy;
use Schemastud\DataSchemas\Strategies\MigrationAttributesStrategy;
use Schemastud\DataSchemas\Strategies\ValidationAttributeStrategy;
use Schemastud\DataSchemas\Writers\JsonSchemaWriter;

return [
    /*
    |--------------------------------------------------------------------------
    | Auto Discover Types
    |--------------------------------------------------------------------------
    |
    | Paths to scan for Data objects. The generator will recursively search
    | these directories for classes extending Spatie\LaravelData\Data.
    |
    */
    'auto_discover_types' => [
        app_path('Data'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Namespace Filters
    |--------------------------------------------------------------------------
    |
    | Optional namespace patterns to filter discovered Data classes.
    | Leave empty to include all discovered classes.
    |
    | Example: ['App\\Data\\Schemas\\*']
    |
    */
    'namespaces' => [],

    /*
    |--------------------------------------------------------------------------
    | Collectors
    |--------------------------------------------------------------------------
    |
    | Collectors discover Data objects with validation attributes.
    | You can add custom collectors here.
    |
    */
    'collectors' => [
        DataObjectCollector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generators
    |--------------------------------------------------------------------------
    |
    | Generators convert Data objects to JSON Schema format.
    | You can add custom generators here.
    |
    */
    'generators' => [
        JsonSchemaGenerator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Path Generator
    |--------------------------------------------------------------------------
    |
    | Determines output file locations for generated schemas.
    | You can implement your own PathGenerator interface.
    |
    */
    'path_generator' => DefaultPathGenerator::class,

    /*
    |--------------------------------------------------------------------------
    | Output Directory
    |--------------------------------------------------------------------------
    |
    | Base directory where JSON Schema files will be generated.
    |
    */
    'output_directory' => resource_path('schemas'),

    /*
    |--------------------------------------------------------------------------
    | Path Structure
    |--------------------------------------------------------------------------
    |
    | Determines how output paths are structured:
    | - 'namespace': Mirrors namespace structure (App/Data/Schemas/Example.schema.json)
    | - 'flat': All files in output directory root (Example.schema.json)
    | - 'custom': Uses custom_path_generator callable
    |
    */
    'path_structure' => 'namespace',

    /*
    |--------------------------------------------------------------------------
    | Custom Path Generator
    |--------------------------------------------------------------------------
    |
    | Callable that receives (ReflectionClass $class, string $baseDir)
    | and returns the full output path. Only used if path_structure is 'custom'.
    |
    */
    'custom_path_generator' => null,

    /*
    |--------------------------------------------------------------------------
    | Writer
    |--------------------------------------------------------------------------
    |
    | Handles persistence of JSON Schema files to disk.
    |
    */
    'writer' => JsonSchemaWriter::class,

    /*
    |--------------------------------------------------------------------------
    | Format Output
    |--------------------------------------------------------------------------
    |
    | Pretty print JSON output with indentation.
    |
    */
    'format_output' => true,

    /*
    |--------------------------------------------------------------------------
    | JSON Schema Version
    |--------------------------------------------------------------------------
    |
    | JSON Schema specification version to use in $schema property.
    |
    */
    'schema_version' => 'https://json-schema.org/draft/2020-12/schema',

    /*
    |--------------------------------------------------------------------------
    | Schema Metadata
    |--------------------------------------------------------------------------
    |
    | Additional metadata to include in generated schemas.
    |
    */
    'schema_metadata' => [
        '$schema' => true,
        '$id' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioned Schema Base URI (opt-in lifecycle)
    |--------------------------------------------------------------------------
    |
    | Base URI for absolute, versioned `$id`s. A Data class that implements
    | Schemastud\DataSchemas\Contracts\SchemaIdentity opts into versioning:
    | its `$id` becomes `<base_uri>/<schemaName()>/<schemaVersion()>` and, when
    | nested, it is referenced by that absolute `$id` instead of `#/$defs/Short`.
    |
    | Classes that DO NOT implement SchemaIdentity are unaffected — they keep
    | the short-name `$id` and `#/$defs/Short` inlining (backward compatible).
    |
    */
    'base_uri' => 'https://schemas.splicewire.app',

    /*
    |--------------------------------------------------------------------------
    | Schema Registry Directory
    |--------------------------------------------------------------------------
    |
    | Directory of frozen, committed schema artifacts for the filesystem
    | SchemaRegistry. Entries are write-once: a changed shape needs a new $id.
    |
    */
    'registry_directory' => resource_path('schemas/registry'),

    /*
    |--------------------------------------------------------------------------
    | Validation Attribute Mapping
    |--------------------------------------------------------------------------
    |
    | Custom mappings for validation attributes to JSON Schema constraints.
    | These override or extend the default mappings. Consumed by the built-in
    | ValidationAttributeStrategy below.
    |
    */
    'validation_mapping' => [
        // Example: Max::class => fn($attr) => ['maxLength' => $attr->max],
    ],

    /*
    |--------------------------------------------------------------------------
    | Property Strategies
    |--------------------------------------------------------------------------
    |
    | An ordered pipeline of SchemaStrategy implementations. Each receives a
    | reflected property and the schema built so far, and may contribute extra
    | keywords (the Scribe pattern). The built-in set maps validation attributes;
    | downstream packages append their own (e.g. content-engine projects its
    | generation attributes to x-beat/x-ground/x-generate) without subclassing
    | the generator. `forLlmStrict` strips all `x-*` keywords for the LLM-facing
    | schema.
    |
    */
    'strategies' => [
        ValidationAttributeStrategy::class,
        // Projects the migration vocabulary (#[WasNamed]/#[MigrateWith]) to
        // x-migrate-from / x-migrate. VERSIONED-ONLY: a strict no-op for classes
        // that do not implement SchemaIdentity, so non-migration output is
        // unchanged. Stripped by forLlmStrict like every other x-* keyword.
        MigrationAttributesStrategy::class,
        // Projects repeatable #[Keyword('x-…', value)] annotations — the
        // generic channel for host-owned extension keywords (x-widget,
        // x-widget-options, …). No-op without the attribute.
        KeywordAttributesStrategy::class,
    ],
];
