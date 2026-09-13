# Laravel Data Schemas

Generate JSON Schemas from Laravel Data objects with validation attributes.

This package automatically converts your [Spatie Laravel Data](https://github.com/spatie/laravel-data) objects into JSON Schema files, preserving validation rules, descriptions, and examples. Perfect for AI-powered applications, API documentation, and frontend validation.

## Features

- 🎯 **Single Source of Truth**: Define structure, validation, types, and schemas all in your Data objects
- 🔄 **Automatic Generation**: Run `schemas:generate` similar to `typescript:transform`
- 📝 **Rich Metadata**: Add descriptions and examples via custom attributes
- 🎨 **Flexible Output**: Configure path structure (namespace, flat, or custom)
- 🔌 **Extensible**: Custom collectors, generators, and path generators
- ✅ **Validation Mapping**: Automatic conversion of Spatie validation attributes to JSON Schema constraints

## Installation

Install via Composer:

```bash
composer require rushing/laravel-data-schemas
```

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag=data-schemas-config
```

## Usage

### 1. Add Attributes to Your Data Objects

```php
<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Email;
use Rushing\LaravelDataSchemas\Attributes\Description;
use Rushing\LaravelDataSchemas\Attributes\Example;

class UserData extends Data
{
    public function __construct(
        #[Description('User full name')]
        #[Example('John Doe')]
        #[Max(255)]
        public string $name,

        #[Description('User email address')]
        #[Example('john@example.com')]
        #[Email]
        public string $email,

        #[Description('User age in years')]
        #[Example(25)]
        #[Example(42)]
        public ?int $age = null,
    ) {}
}
```

### 2. Generate JSON Schemas

```bash
# Generate all schemas
php artisan schemas:generate

# Generate for specific path
php artisan schemas:generate --path=app/Data/Schemas

# Generate for specific class
php artisan schemas:generate --class="App\Data\UserData"

# Override output directory
php artisan schemas:generate --output=storage/schemas
```

### 3. Use Generated Schemas

```json
// resources/schemas/App/Data/UserData.schema.json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "UserData",
  "type": "object",
  "title": "UserData",
  "properties": {
    "name": {
      "type": "string",
      "description": "User full name",
      "examples": ["John Doe"],
      "maxLength": 255
    },
    "email": {
      "type": "string",
      "description": "User email address",
      "examples": ["john@example.com"],
      "format": "email"
    },
    "age": {
      "type": ["integer", "null"],
      "description": "User age in years",
      "examples": [25, 42]
    }
  },
  "required": ["name", "email"]
}
```

## Configuration

The `config/data-schemas.php` file provides extensive configuration options:

```php
return [
    // Paths to scan for Data objects
    'auto_discover_types' => [
        app_path('Data'),
    ],

    // Filter by namespace patterns (optional)
    'namespaces' => [
        // 'App\\Data\\Schemas\\*',
    ],

    // Output directory
    'output_directory' => resource_path('schemas'),

    // Path structure: 'namespace', 'flat', or 'custom'
    'path_structure' => 'namespace',

    // Custom path generator callable
    'custom_path_generator' => null,

    // Pretty print JSON
    'format_output' => true,

    // JSON Schema version
    'schema_version' => 'https://json-schema.org/draft/2020-12/schema',

    // Include $schema and $id
    'schema_metadata' => [
        '$schema' => true,
        '$id' => true,
    ],

    // Custom validation attribute mappings
    'validation_mapping' => [],
];
```

## Path Structure Options

### Namespace Structure (default)
```
resources/schemas/
  └── App/
      └── Data/
          └── UserData.schema.json
```

### Flat Structure
```
resources/schemas/
  ├── UserData.schema.json
  ├── ProductData.schema.json
  └── OrderData.schema.json
```

### Custom Structure
```php
'path_structure' => 'custom',
'custom_path_generator' => function (ReflectionClass $class, string $baseDir) {
    return $baseDir . '/api/' . $class->getShortName() . '.json';
},
```

## Supported Validation Attributes

The package automatically maps Spatie validation attributes to JSON Schema constraints:

| Validation Attribute | JSON Schema Constraint |
|---------------------|------------------------|
| `#[Max(255)]` | `maxLength` (string), `maximum` (number), `maxItems` (array) |
| `#[Min(1)]` | `minLength` (string), `minimum` (number), `minItems` (array) |
| `#[Between(1, 100)]` | `minimum` + `maximum` |
| `#[Email]` | `format: "email"` |
| `#[Uuid]` | `format: "uuid"` |
| `#[Url]` | `format: "uri"` |
| `#[Enum(StatusEnum::class)]` | `enum: [values]` |
| `#[Nullable]` | `type: ["string", "null"]` |

## Registry values

Declare a live registry constraint on a Data property to use the same membership rule for Spatie
validation and generated JSON Schema:

```php
use Schemastud\DataSchemas\Attributes\ExistsInRegistry;
use Spatie\LaravelData\Data;

class ExportData extends Data
{
    public function __construct(
        #[ExistsInRegistry('app.formats', relative: true)]
        public string $format,
    ) {}
}
```

For registrations `app.formats.json` and `app.formats.markdown`, validation accepts `json` and
`markdown`, and the schema publishes those strings as its `enum`. Omit `relative: true` to use fully
qualified keys. A domain attribute can extend `ExistsInRegistry` and override `constraint()` to
return a Popcorn rule with a subset predicate; both projections use that rule.

Enumeration honors current registry visibility and is recomputed during generation. Publish under
the intended documentation audience; a saved enum is a snapshot, not an authorization policy.
An empty vocabulary emits `not: {}` (no valid value), while a nullable field still admits `null`.
Registry rules returned only by a Data class's `rules()` method continue to validate, but require the
attribute declaration above to project their enum into schemas.

## Custom Attributes

### Description
Add human-readable descriptions to properties or classes:

```php
#[Description('User profile information')]
class UserData extends Data
{
    #[Description('Primary email address for notifications')]
    public string $email;
}
```

### Example
Add one or multiple examples (repeatable attribute):

```php
#[Example('John Doe')]
#[Example('Jane Smith')]
public string $name;
```

## Integration with Build Process

Add to your `package.json` scripts:

```json
{
  "scripts": {
    "types:generate": "php artisan typescript:transform",
    "schemas:generate": "php artisan schemas:generate",
    "build": "npm run types:generate && npm run schemas:generate && vite build"
  }
}
```

## Advanced Usage

### Custom Validation Mapping

Override or extend validation attribute mappings:

```php
// config/data-schemas.php
'validation_mapping' => [
    CustomValidationAttribute::class => function ($attr) {
        return ['pattern' => $attr->getPattern()];
    },
],
```

### Custom Path Generator

Implement the `PathGenerator` interface:

```php
use Rushing\LaravelDataSchemas\PathGenerators\PathGenerator;

class ApiPathGenerator implements PathGenerator
{
    public function getSchemaPath(ReflectionClass $class): string
    {
        return resource_path('api/schemas/' . $class->getShortName() . '.json');
    }
}
```

Register in config:

```php
'path_generator' => App\Generators\ApiPathGenerator::class,
```

### Custom Generator

Implement the `Generator` interface for custom schema formats:

```php
use Rushing\LaravelDataSchemas\Generators\Generator;

class OpenApiGenerator implements Generator
{
    public function canGenerate(ReflectionClass $class): bool
    {
        // Your logic
    }

    public function generate(ReflectionClass $class): array
    {
        // Generate OpenAPI schema format
    }
}
```

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+
- spatie/laravel-data 4.0+ or 5.0+

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Stephen Rushing](https://github.com/stephenr85)
- Inspired by [spatie/laravel-typescript-transformer](https://github.com/spatie/laravel-typescript-transformer)
- Built for the [Spatie Laravel Data](https://github.com/spatie/laravel-data) ecosystem

## Strict referenced properties

The `forLlmStrict()` projection wraps an annotated `$ref` in a single-branch `anyOf`, retaining annotations on the outer property. OpenAI rejects annotations directly beside `$ref`; the equivalent wrapper preserves referenced Data validation and nonnullability. A reference already carrying a sibling `anyOf` is explicitly refused in strict mode rather than dropping its existing constraint. Ordinary schema modes retain their original reference and annotations. A validator-backed regression checks valid, null and malformed values alongside the provider restriction.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.
