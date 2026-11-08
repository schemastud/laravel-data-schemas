<?php

namespace Schemastud\DataSchemas\Lifecycle;

use InvalidArgumentException;
use Schemastud\DataSchemas\Keywords;

/** Enforces the migration rung's top-level-only declared rename contract. */
final class MigrationKeywordPlacement
{
    /** Schema-valued object keywords whose values are schemas keyed by name. */
    private const SCHEMA_MAP_KEYWORDS = [
        '$defs',
        'dependencies',
        'definitions',
        'dependentSchemas',
        'patternProperties',
        'properties',
    ];

    /** Schema-valued keywords whose values are a single schema or a list of schemas. */
    private const SCHEMA_KEYWORDS = [
        'additionalItems',
        'additionalProperties',
        'contains',
        'contentSchema',
        'else',
        'if',
        'items',
        'not',
        'propertyNames',
        'then',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];

    private const SCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * Refuse x-migrate-from anywhere except a direct property of the schema document. Nested objects,
     * array items and reusable definitions are deliberately outside the keyword's declared semantics.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function assertTopLevelOnly(array $schema): void
    {
        if (array_key_exists(Keywords::MigrateFrom, $schema)) {
            self::refuse('/'.Keywords::MigrateFrom);
        }

        self::walkSubschemas($schema, '', true);
    }

    private static function assertSchema(mixed $schema, string $path, bool $allowMigrationKeyword): void
    {
        if (! is_array($schema)) {
            return;
        }

        if (! $allowMigrationKeyword && array_key_exists(Keywords::MigrateFrom, $schema)) {
            self::refuse($path.'/'.Keywords::MigrateFrom);
        }

        self::walkSubschemas($schema, $path);
    }

    /**
     * Traverse only JSON Schema positions. Examples, defaults, const/enum values and vendor
     * extension payloads are instance data, even when they contain a key named x-migrate-from.
     *
     * @param  array<string, mixed>  $schema
     */
    private static function walkSubschemas(array $schema, string $path, bool $root = false): void
    {
        foreach (self::SCHEMA_MAP_KEYWORDS as $keyword) {
            $map = $schema[$keyword] ?? null;
            if (! is_array($map)) {
                continue;
            }

            foreach ($map as $name => $subschema) {
                self::assertSchema(
                    $subschema,
                    $path.'/'.self::escape($keyword).'/'.self::escape((string) $name),
                    $root && $keyword === 'properties',
                );
            }
        }

        foreach (self::SCHEMA_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                self::assertSchema($schema[$keyword], $path.'/'.self::escape($keyword), false);
            }
        }

        foreach (self::SCHEMA_LIST_KEYWORDS as $keyword) {
            $schemas = $schema[$keyword] ?? null;
            if (! is_array($schemas)) {
                continue;
            }

            foreach ($schemas as $index => $subschema) {
                self::assertSchema($subschema, $path.'/'.self::escape($keyword).'/'.$index, false);
            }
        }
    }

    private static function refuse(string $path): never
    {
        throw new InvalidArgumentException(
            'The x-migrate-from keyword is top-level only; nested schema migration is unsupported at '.$path.'.'
        );
    }

    private static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
