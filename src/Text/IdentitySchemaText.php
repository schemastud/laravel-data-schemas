<?php

namespace Schemastud\DataSchemas\Text;

use BackedEnum;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaText;

/**
 * The default {@see SchemaText}: every text is the declared text, unchanged. With it bound (or nothing bound), the
 * generator writes exactly what it wrote before the hook existed.
 */
final class IdentitySchemaText implements SchemaText
{
    public function classTitle(ReflectionClass $class, ?string $declared): ?string
    {
        return $declared;
    }

    public function classDescription(ReflectionClass $class, ?string $declared): ?string
    {
        return $declared;
    }

    public function enumTitle(string $enumClass, ?string $declared): ?string
    {
        return $declared;
    }

    public function enumCaseLabel(BackedEnum $case, string $declared): string
    {
        return $declared;
    }

    /** The bound {@see SchemaText}, or this identity default where nothing is bound (or no container exists). */
    public static function current(): SchemaText
    {
        if (function_exists('app')) {
            try {
                if (app()->bound(SchemaText::class)) {
                    $text = app(SchemaText::class);
                    if ($text instanceof SchemaText) {
                        return $text;
                    }
                }
            } catch (\Throwable) {
                // No application: the identity default.
            }
        }

        return new self;
    }
}
