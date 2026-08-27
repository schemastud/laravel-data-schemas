<?php

use Schemastud\DataSchemas\Collectors\DataObjectCollector;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\PathGenerators\DefaultPathGenerator;
use Schemastud\DataSchemas\Strategies\KeywordAttributesStrategy;
use Schemastud\DataSchemas\Strategies\MigrationAttributesStrategy;
use Schemastud\DataSchemas\Strategies\ValidationAttributeStrategy;
use Schemastud\DataSchemas\Writers\SchemaFileWriter;

return [
    /*
    |--------------------------------------------------------------------------
    | Auto Discover Types
    |--------------------------------------------------------------------------
    |
    | Paths to scan for Data objects. The generator will recursively search
    | these directories for classes extending Spatie\LaravelData\Data.
    |
    | SCOPE — app-only BY DESIGN (particle-doctrine-followups 14b). The on-disk
    | schema tree (`output_directory`) is the APP's own published, committed
    | schema artifact set — which is why discovery defaults to the app's data
    | dir and no fleet host widens it. This is deliberately NARROWER than the
    | TypeScript leg (which scans package data dirs and gap-fills off the live
    | route table): a package-owned DTO still reaches the OpenAPI spec, because
    | the Scribe strategies invoke this generator IN MEMORY rather than reading
    | this tree — the disk tree and the spec are two differently-scoped
    | projections of one generator, on purpose. A package that wants published
    | schema files ships its own; an app that wants package DTOs in ITS tree
    | widens this list knowingly. Beam's `schema.projection-drift` audit scopes
    | itself to these paths for the same reason.
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
    | Handles persistence of JSON Schema files to the schema disk.
    |
    | Renamed from `JsonSchemaWriter` (the payload every Writer shares, so it
    | distinguished nothing) to name its STRATEGY, matching SchemaRegistry ←
    | FilesystemSchemaRegistry and PathGenerator ← DefaultPathGenerator. The old
    | class survives as a deprecated subclass, because this key is PUBLISHED and
    | hosts already carry the old name in their own config file.
    |
    */
    'writer' => SchemaFileWriter::class,

    /*
    |--------------------------------------------------------------------------
    | Schema Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk schema files are written to and read back from. The
    | provider DEFINES this disk (a `local` driver rooted at `output_directory`)
    | unless the host has already defined one under the same name, so
    | `Storage::fake('data-schemas')` is the testing story and an S3-backed
    | schema tree is a config change rather than a rewrite.
    |
    | Named explicitly rather than reusing `local` or `public`: those are a
    | HOST's disks, and a package writing into them is squatting. Note that
    | `output_directory` defaults OUTSIDE any stock disk root
    | (`resource_path('schemas')`), which is why this needs its own disk at all.
    |
    */
    'disk' => 'data-schemas',

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
    | THE AUTHORITY IS THE ORIGIN THAT SERVES THE SCHEMA, so this package ships
    | no default: a fleet-wide default can only be one vendor's domain, stamped
    | onto every other vendor's schemas. Tri-state:
    |
    |   null (unset) — throws MissingSchemaBaseUri the moment a SchemaIdentity
    |                  class is generated. An `$id` is write-once, so an
    |                  undecided authority must fail, never fall back.
    |   false        — this host opts out of versioned identity: SchemaIdentity
    |                  classes keep the short-name `$id`, and no schema-serving
    |                  route is mounted.
    |   a URI string — mint `<base_uri>/<name>/<version>` and serve at that origin.
    |
    | Keep it PATH-shaped (`https://app.example.com/schemas`), never a query
    | string: `SchemaId`/`NamespaceUri` (ADR-0191) parse the stem and version as
    | trailing path segments, and JSON Schema resolves a relative `$ref` against
    | this base per RFC 3986 — a query base would silently resolve refs against
    | the path and drop the id.
    |
    */
    'base_uri' => null,

    /*
    |--------------------------------------------------------------------------
    | Schema Id Parsers (how a package's refs parse)
    |--------------------------------------------------------------------------
    |
    | An ordered LIST of Schemastud\DataSchemas\Ids\SchemaIdParser class-strings.
    | A package that spells its schema refs its own way appends its parser here
    | from its own provider, instead of minting privately with an authority it
    | had to invent (beam-facade tickets 139/140).
    |
    | Ships EMPTY, and that is not an oversight. This package's own grammar —
    | an absolute $id is itself, anything else is relative to `base_uri` — is
    | RelativeSchemaIdParser, which SchemaIdResolver falls to when no registrant
    | claims a ref. Keeping it out of this list is what makes it unshadowable:
    | it claims every ref, so listing it first would starve every registrant,
    | and listing it last is where appends land, so it would be shadowed by
    | accident.
    |
    | First parser to `handles()` a ref wins — a ref has exactly one grammar,
    | so this is a PickOne registry and not a pipeline.
    |
    */
    'id_parsers' => [],

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
    | Served Directories (the public schema door)
    |--------------------------------------------------------------------------
    |
    | Which of this host's committed artifacts are served at `base_uri`
    | (beam-facade ticket 82). Read in order, first hit wins, lazily — so a
    | directory is not created merely by being listed.
    |
    | A LIST, because a host's frozen artifacts routinely span more than one
    | directory: `splicewire-app` freezes to both `schemas/fleet` and
    | `schemas/lifecycle`, so "the filesystem registry" has no single referent.
    | Empty or unset falls back to `registry_directory` above.
    |
    | This is the WHOLE of the door's gate, and the omission is the point: the
    | tenant/runtime tier is not listable here, because tenant `$id`s are
    | payload-supplied and nothing validates that a tenant owns the authority it
    | claims. Serving those from this host's origin would mint exactly the
    | unowned-authority claim ticket 64 removed. Bind
    | Contracts\ServedSchemaRegistry to change what the door can see.
    |
    */
    'served_directories' => [],

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
