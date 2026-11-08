<?php

namespace Schemastud\DataSchemas\Lifecycle;

use InvalidArgumentException;
use Schemastud\DataSchemas\Keywords;

/** Enforces the migration rung's top-level-only declared rename contract. */
final class MigrationKeywordPlacement
{
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

        $properties = $schema['properties'] ?? [];
        if (is_array($properties)) {
            foreach ($properties as $name => $property) {
                self::walk($property, '/properties/'.self::escape((string) $name), true);
            }
        }

        foreach ($schema as $key => $value) {
            if ($key !== 'properties') {
                self::walk($value, '/'.self::escape((string) $key), false);
            }
        }
    }

    private static function walk(mixed $node, string $path, bool $allowHere): void
    {
        if (! is_array($node)) {
            return;
        }

        if (! $allowHere && array_key_exists(Keywords::MigrateFrom, $node)) {
            self::refuse($path.'/'.Keywords::MigrateFrom);
        }

        foreach ($node as $key => $value) {
            self::walk($value, $path.'/'.self::escape((string) $key), false);
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
