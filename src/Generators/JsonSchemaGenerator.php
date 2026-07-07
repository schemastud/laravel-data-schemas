<?php

namespace Rushing\LaravelDataSchemas\Generators;

use BackedEnum;
use DateTimeInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Rushing\LaravelDataSchemas\Attributes\ArrayItems;
use Rushing\LaravelDataSchemas\Attributes\Description;
use Rushing\LaravelDataSchemas\Attributes\Example;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Rushing\LaravelDataSchemas\Keywords;
use Rushing\LaravelDataSchemas\Strategies\MigrationAttributesStrategy;
use Rushing\LaravelDataSchemas\Strategies\SchemaStrategy;
use Rushing\LaravelDataSchemas\Strategies\SchemaStrategyContext;
use Rushing\LaravelDataSchemas\Strategies\ValidationAttributeStrategy;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Optional;

class JsonSchemaGenerator implements Generator
{
    /** @var string collapsed|request|response|llm_strict */
    protected string $mode = 'collapsed';

    /**
     * Definitions hoisted for the document currently being generated.
     *
     * @var array<string, array>
     */
    protected array $defs = [];

    /**
     * Short names already being (or already) generated — guards self-references.
     *
     * @var array<string, true>
     */
    protected array $visited = [];

    /**
     * Resolved property strategies, memoized per generator instance.
     *
     * @var array<int, SchemaStrategy>|null
     */
    protected ?array $resolvedStrategies = null;

    public function __construct(protected array $config = []) {}

    public function forRequest(): static
    {
        return $this->schemaMode('request');
    }

    public function forResponse(): static
    {
        return $this->schemaMode('response');
    }

    /**
     * Emit a schema compatible with strict LLM structured output (OpenAI/others):
     * every object sets `additionalProperties: false` and lists every property in
     * `required`, with properties that would otherwise be optional made nullable
     * (their type union gains `"null"`, refs become `anyOf [ref, null]`) instead of
     * omitted. Root `$schema`/`$id` metadata is dropped — providers reject it.
     */
    public function forLlmStrict(): static
    {
        return $this->schemaMode('llm_strict');
    }

    public function schemaMode(string $mode): static
    {
        $clone = clone $this;
        $clone->mode = $mode;

        return $clone;
    }

    public function canGenerate(ReflectionClass $class): bool
    {
        return $class->isSubclassOf(Data::class);
    }

    public function generate(ReflectionClass $class): array
    {
        $this->defs = [];
        $this->visited = [];

        $schema = $this->buildObjectSchema($class);

        // Strict LLM schemas must not carry $schema/$id metadata — providers reject it.
        if ($this->mode !== 'llm_strict') {
            // Metadata only decorates the root document.
            if ($this->config['schema_metadata']['$schema'] ?? false) {
                $schema = ['$schema' => $this->config['schema_version'] ?? 'https://json-schema.org/draft/2020-12/schema'] + $schema;
            }
            if ($this->config['schema_metadata']['$id'] ?? false) {
                $schema['$id'] = $this->generateId($class);
            }
        }

        if (! empty($this->defs)) {
            $schema['$defs'] = $this->defs;
        }

        return $schema;
    }

    protected function buildObjectSchema(ReflectionClass $class): array
    {
        $schema = [
            'type' => 'object',
            'title' => $class->getShortName(),
            'properties' => [],
        ];

        if ($description = $this->getClassDescription($class)) {
            $schema['description'] = $description;
        }

        $required = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $schema['properties'][$property->getName()] = $this->generatePropertySchema($property);

            if ($this->isRequired($property)) {
                $required[] = $property->getName();
            }
        }

        if ($this->mode === 'llm_strict') {
            // Strict providers require additionalProperties:false and every property in
            // `required`; properties that would be optional are made nullable instead.
            // They also reject unsupported keywords (examples, title).
            $schema['additionalProperties'] = false;
            unset($schema['title']);

            foreach ($schema['properties'] as $name => $propSchema) {
                // Strip keywords strict providers reject (examples) or that we re-express
                // through the type union / anyOf below (readOnly, nullable). Every `x-*`
                // vendor keyword is an out-of-band annotation for our own consumers
                // (x-optional/x-lazy and downstream x-beat/x-ground/x-generate) — never
                // part of the LLM-facing contract — so strip all of them.
                unset($propSchema['examples'], $propSchema['readOnly'], $propSchema['nullable']);

                foreach (array_keys($propSchema) as $key) {
                    if (is_string($key) && str_starts_with($key, 'x-')) {
                        unset($propSchema[$key]);
                    }
                }

                if (! in_array($name, $required, true)) {
                    $propSchema = $this->makeNullable($propSchema);
                }

                $schema['properties'][$name] = $propSchema;
            }

            $required = array_keys($schema['properties']);
        }

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Make a property schema accept null, for strict LLM mode.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function makeNullable(array $schema): array
    {
        if (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            unset($schema['$ref'], $schema['nullable']);

            return ['anyOf' => [['$ref' => $ref], ['type' => 'null']]] + $schema;
        }

        unset($schema['nullable']);
        $type = $schema['type'] ?? null;

        if ($type === null) {
            $schema['type'] = 'null';
        } elseif (is_array($type)) {
            if (! in_array('null', $type, true)) {
                $type[] = 'null';
            }
            $schema['type'] = array_values($type);
        } else {
            $schema['type'] = [$type, 'null'];
        }

        return $schema;
    }

    protected function generatePropertySchema(ReflectionProperty $property): array
    {
        $info = $this->analyzeType($property);

        $schema = [];

        if ($info['ref'] !== null) {
            $schema['$ref'] = $info['ref'];
            if ($info['nullable']) {
                $schema['nullable'] = true;
            }
        } elseif ($info['arrayItemRef'] !== null) {
            $schema['type'] = 'array';
            $schema['items'] = ['$ref' => $info['arrayItemRef']];
            if ($info['nullable']) {
                $schema['type'] = ['array', 'null'];
            }
        } else {
            $types = $info['jsonTypes'];
            if ($info['nullable'] && ! in_array('null', $types, true)) {
                $types[] = 'null';
            }
            $types = array_values(array_unique($types));
            if (count($types) === 1) {
                $schema['type'] = $types[0];
            } elseif (count($types) > 1) {
                $schema['type'] = $types;
            }
        }

        // Scalar array item type (string[], int[], …) via #[ArrayItems] — strict
        // providers require `items` on every array. A backed-enum class as the item
        // type inlines its values (a list of enum-valued scalars).
        if ($info['arrayItemRef'] === null && $this->schemaHasType($schema, 'array')) {
            $itemsAttrs = $property->getAttributes(ArrayItems::class);
            if (! empty($itemsAttrs)) {
                $schema['items'] = $this->arrayItemsSchema($itemsAttrs[0]->newInstance()->type);
            }
        }

        // Lazy => present in responses only when included.
        if ($info['lazy']) {
            $schema['readOnly'] = true;
            $schema[Keywords::Lazy] = true;
        }

        // Optional => key may be absent from input.
        if ($info['optional']) {
            $schema[Keywords::Optional] = true;
        }

        if (! empty($descAttrs = $property->getAttributes(Description::class))) {
            $schema['description'] = $descAttrs[0]->newInstance()->value;
        }

        // Run the configured property strategies in order (validation mapping by
        // default; downstream packages may contribute `x-*` keywords). Strategies
        // run before example inference so any `format` they set informs the baseline.
        $context = new SchemaStrategyContext($this->config, $this->mode);
        foreach ($this->strategies() as $strategy) {
            $schema = $strategy->apply($property, $schema, $context);
        }

        // Examples: explicit #[Example] wins, otherwise infer a baseline for leaves.
        $exampleAttrs = $property->getAttributes(Example::class);
        if (! empty($exampleAttrs)) {
            $schema['examples'] = array_map(
                fn (ReflectionAttribute $attr) => $attr->newInstance()->value,
                $exampleAttrs
            );
        } elseif ($info['ref'] === null && $info['arrayItemRef'] === null) {
            $inferred = $this->inferExample($schema);
            if ($inferred !== null) {
                $schema['examples'] = [$inferred];
            }
        }

        return $schema;
    }

    /**
     * Walk a property's type union member-by-member, resolving wrapper tokens,
     * nullability, nested refs and a scalar fallback.
     *
     * `ref`/`arrayItemRef` are full `$ref` STRINGS — either `#/$defs/Short`
     * (legacy inlined nodes) or an absolute versioned `$id` (opt-in addressable
     * nodes).
     *
     * @return array{jsonTypes: string[], ref: ?string, arrayItemRef: ?string, nullable: bool, optional: bool, lazy: bool}
     */
    protected function analyzeType(ReflectionProperty $property): array
    {
        $type = $property->getType();
        $members = match (true) {
            $type instanceof ReflectionUnionType => $type->getTypes(),
            $type instanceof ReflectionNamedType => [$type],
            default => [],
        };

        $jsonTypes = [];
        $ref = null;
        $arrayItemRef = null;
        $nullable = false;
        $optional = false;
        $lazy = false;
        $hasArray = false;

        foreach ($members as $member) {
            if (! $member instanceof ReflectionNamedType) {
                continue;
            }

            $name = $member->getName();

            if ($member->allowsNull()) {
                $nullable = true;
            }

            if ($name === 'null') {
                $nullable = true;

                continue;
            }

            if ($name === Optional::class) {
                $optional = true;

                continue;
            }

            if ($name === Lazy::class) {
                $lazy = true;

                continue;
            }

            if ($member->isBuiltin()) {
                if ($name === 'array') {
                    $hasArray = true;

                    continue;
                }
                $jsonTypes[] = $this->mapBuiltin($name);

                continue;
            }

            // Class-typed member.
            if (is_subclass_of($name, Data::class)) {
                $ref = $this->ensureDef(new ReflectionClass($name));

                continue;
            }

            if (is_subclass_of($name, BackedEnum::class)) {
                $ref = $this->ensureEnumDef($name);

                continue;
            }

            if (is_a($name, DataCollection::class, true)) {
                $hasArray = true;

                continue;
            }

            if (is_a($name, DateTimeInterface::class, true)) {
                $jsonTypes[] = 'string';

                continue;
            }

            // Unknown class — degrade to string rather than crash.
            $jsonTypes[] = 'string';
        }

        // A #[DataCollectionOf] attribute names the element type of an array/collection.
        $itemClass = $this->dataCollectionItemClass($property);
        if ($itemClass !== null) {
            $arrayItemRef = $this->ensureDef(new ReflectionClass($itemClass));
        } elseif ($hasArray) {
            $jsonTypes[] = 'array';
        }

        return [
            'jsonTypes' => $jsonTypes,
            'ref' => $ref,
            'arrayItemRef' => $arrayItemRef,
            'nullable' => $nullable,
            'optional' => $optional,
            'lazy' => $lazy,
        ];
    }

    protected function dataCollectionItemClass(ReflectionProperty $property): ?string
    {
        $class = 'Spatie\\LaravelData\\Attributes\\DataCollectionOf';
        if (! class_exists($class)) {
            return null;
        }

        $attrs = $property->getAttributes($class);
        if (empty($attrs)) {
            return null;
        }

        $args = $attrs[0]->getArguments();
        $value = $args[0] ?? ($args['class'] ?? null);

        if (is_string($value) && (class_exists($value) || enum_exists($value))) {
            return $value;
        }

        return null;
    }

    /**
     * Hoist a nested Data class and return the `$ref` string pointing at it.
     *
     * Opt-in addressing: a nested class implementing {@see SchemaIdentity}
     * projects its OWN absolute versioned `$id` and is referenced by that
     * absolute `$id` (a 2020-12 bundled resource embedded under `$defs` keyed by
     * `$id`). A nested class that does NOT opt in keeps the historical
     * `#/$defs/Short` short-name inlining. A tree may freely mix the two.
     */
    protected function ensureDef(ReflectionClass $class): string
    {
        $versionedId = $this->versionedId($class);

        if ($versionedId !== null) {
            if (! isset($this->visited[$versionedId])) {
                $this->visited[$versionedId] = true;
                // Embedded resource retains its own $id (offline-portable, re-resolvable).
                $this->defs[$versionedId] = ['$id' => $versionedId] + $this->buildObjectSchema($class);
            }

            return $versionedId;
        }

        $short = $class->getShortName();

        if (isset($this->visited[$short])) {
            return '#/$defs/'.$short;
        }

        // Mark before recursing so self-references resolve to the same key.
        $this->visited[$short] = true;
        $this->defs[$short] = $this->buildObjectSchema($class);

        return '#/$defs/'.$short;
    }

    protected function ensureEnumDef(string $enumClass): string
    {
        $short = (new ReflectionClass($enumClass))->getShortName();

        if (isset($this->visited[$short])) {
            return '#/$defs/'.$short;
        }
        $this->visited[$short] = true;

        $reflection = new ReflectionEnum($enumClass);
        $backingType = $reflection->getBackingType()?->getName();

        $this->defs[$short] = [
            'type' => $backingType === 'int' ? 'integer' : 'string',
            'title' => $short,
            'enum' => array_map(fn (BackedEnum $case) => $case->value, $enumClass::cases()),
        ];

        return '#/$defs/'.$short;
    }

    /**
     * Resolve an #[ArrayItems] item type to its `items` schema. A backed-enum class inlines
     * the enum's values (no `$ref`); any other token is treated as a scalar JSON type.
     *
     * @return array<string, mixed>
     */
    protected function arrayItemsSchema(string $type): array
    {
        if (enum_exists($type) && is_subclass_of($type, BackedEnum::class)) {
            $backingType = (new ReflectionEnum($type))->getBackingType()?->getName();

            return [
                'type' => $backingType === 'int' ? 'integer' : 'string',
                'enum' => array_map(fn (BackedEnum $case) => $case->value, $type::cases()),
            ];
        }

        return ['type' => $type];
    }

    protected function mapBuiltin(string $name): string
    {
        return match ($name) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'string' => 'string',
            'object' => 'object',
            'iterable' => 'array',
            default => 'string',
        };
    }

    protected function isRequired(ReflectionProperty $property): bool
    {
        if ($property->hasDefaultValue()) {
            return false;
        }

        $info = $this->analyzeType($property);

        // Optional and Lazy are absent from required; plain nullable stays required.
        if ($info['optional'] || $info['lazy']) {
            return false;
        }

        return true;
    }

    protected function inferExample(array $schema): mixed
    {
        $format = $schema['format'] ?? null;
        $example = match ($format) {
            'email' => 'user@example.com',
            'uri' => 'https://example.com',
            'uuid' => '00000000-0000-0000-0000-000000000000',
            'date-time' => '2024-01-01T00:00:00Z',
            default => null,
        };
        if ($example !== null) {
            return $example;
        }

        if (! empty($schema['enum'])) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $type = $type[0] ?? null;
        }

        return match ($type) {
            'string' => 'string',
            'integer' => 0,
            'number' => 0,
            'boolean' => true,
            'array' => [],
            default => null,
        };
    }

    /**
     * The document `$id`.
     *
     * Opt-in versioning: a class implementing {@see SchemaIdentity} emits an
     * absolute versioned URI carried natively in `$id`
     * (`<base_uri>/<schemaName>/<schemaVersion>`). Any other class keeps the
     * historical short-name `$id` — unchanged, backward compatible.
     */
    protected function generateId(ReflectionClass $class): string
    {
        return $this->versionedId($class) ?? $class->getShortName();
    }

    /**
     * The absolute versioned `$id` for a class that opts into versioning, or
     * null when it does not implement {@see SchemaIdentity}.
     */
    protected function versionedId(ReflectionClass $class): ?string
    {
        if (! $class->implementsInterface(SchemaIdentity::class)) {
            return null;
        }

        $name = $class->getName();
        $base = rtrim($this->config['base_uri'] ?? 'https://schemas.splicewire.app', '/');

        return $base.'/'.trim($name::schemaName(), '/').'/'.$name::schemaVersion();
    }

    protected function getClassDescription(ReflectionClass $class): ?string
    {
        $descAttrs = $class->getAttributes(Description::class);

        return ! empty($descAttrs) ? $descAttrs[0]->newInstance()->value : null;
    }

    /**
     * Resolve the ordered property strategies for this generator.
     *
     * Resolution order: an explicit `strategies` config slot wins; otherwise the
     * app's `config('data-schemas.strategies')` (where downstream packages append
     * their own); falling back to the built-in default set when no container is
     * available (the generator is constructed bare in unit tests). String entries
     * are resolved to instances via the container when present, else `new`.
     *
     * @return array<int, SchemaStrategy>
     */
    protected function strategies(): array
    {
        if ($this->resolvedStrategies !== null) {
            return $this->resolvedStrategies;
        }

        $configured = $this->config['strategies'] ?? $this->strategyConfigFromContainer();

        if (! is_array($configured)) {
            $configured = $this->defaultStrategies();
        }

        $this->resolvedStrategies = array_values(array_filter(array_map(
            fn ($strategy) => $this->resolveStrategy($strategy),
            $configured
        )));

        return $this->resolvedStrategies;
    }

    /**
     * The built-in default strategy set, used when no config is reachable.
     *
     * @return array<int, class-string<SchemaStrategy>>
     */
    protected function defaultStrategies(): array
    {
        return [
            ValidationAttributeStrategy::class,
            // Versioned-only migration vocabulary projection; a no-op for any
            // class that does not opt into SchemaIdentity, so the default set
            // never changes non-migration schema output.
            MigrationAttributesStrategy::class,
        ];
    }

    /**
     * Read the configured strategies from a booted container, tolerating its
     * absence (bare unit tests construct the generator without one).
     *
     * @return array<int, mixed>|null
     */
    protected function strategyConfigFromContainer(): ?array
    {
        if (! function_exists('config')) {
            return $this->defaultStrategies();
        }

        try {
            $configured = config('data-schemas.strategies');
        } catch (\Throwable) {
            return $this->defaultStrategies();
        }

        return is_array($configured) ? $configured : $this->defaultStrategies();
    }

    protected function resolveStrategy(SchemaStrategy|string $strategy): ?SchemaStrategy
    {
        if ($strategy instanceof SchemaStrategy) {
            return $strategy;
        }

        if (function_exists('app')) {
            try {
                $resolved = app($strategy);
                if ($resolved instanceof SchemaStrategy) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Fall through to a plain instantiation.
            }
        }

        return class_exists($strategy) ? new $strategy : null;
    }

    protected function schemaHasType(array $schema, string $needle): bool
    {
        $type = $schema['type'] ?? null;

        return $type === $needle || (is_array($type) && in_array($needle, $type, true));
    }
}
