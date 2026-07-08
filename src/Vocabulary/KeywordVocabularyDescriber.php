<?php

namespace Rushing\LaravelDataSchemas\Vocabulary;

use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;

/**
 * Projects an extension-keyword VOCABULARY — a set of {@see KeywordDescriptor}s — to a JSON-Schema
 * meta-schema + TypeScript, so satellites, SDKs, and docs can pin a keyword dialect without running
 * the platform. The mechanism is content-agnostic: it knows how to *reflect* a value domain (enum
 * `::cases()`, a `string|bool` return type, a constructor shape) and how to *render* it, but the
 * concrete keywords, their prefix, their titles, and any owned keywords no attribute emits are all
 * supplied by a subclass.
 *
 * The vocabulary is *derived*, never hand-authored — a new enum case or ctor param flows into the
 * described output with no edit here. A subclass typically feeds {@see descriptors()} from the same
 * {@see AttributeBinding}s its emit path consumes, so emit and describe cannot drift; a coverage
 * guard (test) asserts every keyword the owner names is described.
 *
 * Subclass hooks:
 *  - {@see descriptors()}   — the keyword descriptors this vocabulary comprises;
 *  - {@see keywordString()} — resolve a descriptor to its concrete (possibly prefixed) keyword string;
 *  - {@see title()} / {@see description()} — the artifact's identity strings.
 *
 * Overridable convention:
 *  - {@see parameterIsOptional()} — the default object-keyword optionality rule (null / empty-array
 *    parts are dropped, so their ctor params are not required). Override to declare a different rule.
 */
abstract class KeywordVocabularyDescriber
{
    /**
     * The keyword descriptors this vocabulary comprises — attribute-projected keywords plus any the
     * engine/base owns that no attribute emits.
     *
     * @return iterable<KeywordDescriptor>
     */
    abstract protected function descriptors(): iterable;

    /**
     * Resolve a descriptor to its concrete keyword string (e.g. via a prefix-aware vocabulary).
     */
    abstract protected function keywordString(KeywordDescriptor $keyword): string;

    /**
     * The artifact title (also the generated TypeScript interface name).
     */
    abstract protected function title(): string;

    /**
     * One line describing the dialect this vocabulary is.
     */
    abstract protected function description(): string;

    /**
     * The leading "do not hand-edit" comment for the generated TypeScript. Override to name the exact
     * command that regenerates the artifact.
     */
    protected function generatedByComment(): string
    {
        return '// Generated — do not hand-edit.';
    }

    /**
     * Every described keyword, keyed by its owner accessor.
     *
     * @return array<string, KeywordDescriptor>
     */
    public function keywords(): array
    {
        $keywords = [];

        foreach ($this->descriptors() as $keyword) {
            $keywords[$keyword->accessor] = $keyword;
        }

        return $keywords;
    }

    /**
     * The vocabulary as a JSON-Schema meta-schema: an object whose properties ARE the keywords, each
     * carrying its reflected value domain and one-line description. Keys are the live keyword strings,
     * sorted for a stable, diff-friendly artifact.
     *
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $properties = [];

        foreach ($this->keywords() as $keyword) {
            $name = $this->keywordString($keyword);
            $properties[$name] = ['description' => $keyword->description] + $this->jsonSchemaFor($keyword);
        }

        ksort($properties);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $this->title(),
            'description' => $this->description(),
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    /**
     * The vocabulary as TypeScript: the named value types (enum unions, object interfaces) followed by
     * an interface named for {@see title()} whose optional members are the keyword strings.
     */
    public function toTypeScript(): string
    {
        $definitions = [];
        $members = [];

        foreach ($this->keywords() as $keyword) {
            foreach ($this->tsDefinitionsFor($keyword) as $defName => $defBody) {
                $definitions[$defName] = $defBody;
            }
            $members[$this->keywordString($keyword)] = $this->tsTypeFor($keyword);
        }

        ksort($definitions);
        ksort($members);

        $out = $this->generatedByComment()."\n\n";

        foreach ($definitions as $body) {
            $out .= $body."\n\n";
        }

        $out .= 'export interface '.$this->title()." {\n";
        foreach ($members as $name => $type) {
            $out .= "  '{$name}'?: {$type};\n";
        }
        $out .= "}\n";

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSchemaFor(KeywordDescriptor $keyword): array
    {
        return match ($keyword->source) {
            ValueSource::Enum => ['type' => 'string', 'enum' => $this->enumValues($keyword)],
            ValueSource::Union => $this->unionSchema($keyword),
            ValueSource::Boolean => ['type' => 'boolean'],
            ValueSource::Integer => ['type' => 'integer'],
            ValueSource::Text => ['type' => 'string'],
            ValueSource::Object_ => $this->objectSchema($keyword),
        };
    }

    private function tsTypeFor(KeywordDescriptor $keyword): string
    {
        return match ($keyword->source) {
            ValueSource::Enum, ValueSource::Object_ => $this->namedTsType($keyword),
            ValueSource::Union => $this->unionTsType($keyword),
            ValueSource::Boolean => 'boolean',
            ValueSource::Integer => 'number',
            ValueSource::Text => 'string',
        };
    }

    private function namedTsType(KeywordDescriptor $keyword): string
    {
        return $keyword->tsType ?? throw new RuntimeException("Keyword [{$keyword->accessor}] needs a tsType for its named TypeScript projection.");
    }

    /**
     * @return array<string, string>
     */
    private function tsDefinitionsFor(KeywordDescriptor $keyword): array
    {
        if ($keyword->source === ValueSource::Enum) {
            $name = $this->namedTsType($keyword);
            $union = implode(' | ', array_map(
                fn (string $value): string => "'{$value}'",
                $this->enumValues($keyword),
            ));

            return [$name => "export type {$name} = {$union};"];
        }

        if ($keyword->source === ValueSource::Object_) {
            $name = $this->namedTsType($keyword);
            $lines = [];
            foreach ($this->objectProperties($keyword) as $property => [$jsonType, $tsType, $required]) {
                $optional = $required ? '' : '?';
                $lines[] = "  {$property}{$optional}: {$tsType};";
            }

            return [$name => "export interface {$name} {\n".implode("\n", $lines)."\n}"];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function enumValues(KeywordDescriptor $keyword): array
    {
        $class = $keyword->sourceClass ?? throw new RuntimeException("Enum keyword [{$keyword->accessor}] declares no source enum.");

        return array_map(
            fn (\BackedEnum $case): string => (string) $case->value,
            $class::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function unionSchema(KeywordDescriptor $keyword): array
    {
        return ['oneOf' => array_values(array_map(
            fn (string $type): array => $this->scalarJsonType($type),
            $this->unionMemberTypes($keyword),
        ))];
    }

    private function unionTsType(KeywordDescriptor $keyword): string
    {
        return implode(' | ', array_map(
            fn (string $type): string => $this->scalarTsType($type),
            $this->unionMemberTypes($keyword),
        ));
    }

    /**
     * @return list<string>
     */
    private function unionMemberTypes(KeywordDescriptor $keyword): array
    {
        $class = $keyword->sourceClass ?? throw new RuntimeException("Union keyword [{$keyword->accessor}] declares no source class.");
        $method = $keyword->sourceMethod ?? throw new RuntimeException("Union keyword [{$keyword->accessor}] declares no source method.");

        $returnType = (new ReflectionMethod($class, $method))->getReturnType();

        if ($returnType instanceof ReflectionUnionType) {
            return array_map(
                fn (ReflectionNamedType $t): string => $t->getName(),
                array_filter(
                    $returnType->getTypes(),
                    fn ($t): bool => $t instanceof ReflectionNamedType,
                ),
            );
        }

        if ($returnType instanceof ReflectionNamedType) {
            return [$returnType->getName()];
        }

        throw new RuntimeException("Union keyword [{$keyword->accessor}] source method has no reflectable return type.");
    }

    /**
     * @return array<string, mixed>
     */
    private function objectSchema(KeywordDescriptor $keyword): array
    {
        $properties = [];
        $required = [];

        foreach ($this->objectProperties($keyword) as $property => [$jsonType, $tsType, $isRequired]) {
            $properties[$property] = $jsonType;
            if ($isRequired) {
                $required[] = $property;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Reflect a keyword's source constructor into `property => [jsonSchema, tsType, required]`.
     * Optionality follows {@see parameterIsOptional()} (a subclass-overridable convention).
     *
     * @return array<string, array{array<string, mixed>, string, bool}>
     */
    private function objectProperties(KeywordDescriptor $keyword): array
    {
        $class = $keyword->sourceClass ?? throw new RuntimeException("Object keyword [{$keyword->accessor}] declares no source class.");

        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $out = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
            $required = ! $this->parameterIsOptional($parameter, $typeName);

            $out[$parameter->getName()] = [
                $this->scalarJsonType($typeName),
                $this->scalarTsType($typeName),
                $required,
            ];
        }

        return $out;
    }

    /**
     * The default object-keyword optionality convention: a ctor param is optional when its type is
     * nullable, or when it is an array (array-valued keyword parts are dropped when empty, so they are
     * never guaranteed present). Override to declare a different rule for a keyword.
     */
    protected function parameterIsOptional(ReflectionParameter $parameter, string $typeName): bool
    {
        $type = $parameter->getType();

        if ($type !== null && $type->allowsNull()) {
            return true;
        }

        return $typeName === 'array';
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarJsonType(string $type): array
    {
        return match ($type) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            'null' => ['type' => 'null'],
            default => throw new RuntimeException("Cannot project non-scalar keyword value type [{$type}] to JSON Schema."),
        };
    }

    private function scalarTsType(string $type): string
    {
        return match ($type) {
            'string' => 'string',
            'int', 'float' => 'number',
            'bool' => 'boolean',
            'array' => 'unknown[]',
            'null' => 'null',
            default => throw new RuntimeException("Cannot project non-scalar keyword value type [{$type}] to TypeScript."),
        };
    }
}
