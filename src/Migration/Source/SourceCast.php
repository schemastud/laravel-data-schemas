<?php

namespace Schemastud\DataSchemas\Migration\Source;

/**
 * The SMALL, fixed cast vocabulary of the `x-source` projection dialect. Each
 * cast is a pure, total array-value coercion — no expression language, no
 * user-supplied code. A cast the vocabulary does not name is out of grammar (the
 * projection rung treats an unknown cast as inexpressible and abstains, so the
 * shape falls to the custom-transform escape hatch).
 *
 * Vocabulary (each one line):
 *  - `string` — coerce scalars to their string form (bool → "1"/""; null stays null).
 *  - `int`    — coerce a numeric-ish value to an integer (PHP int cast).
 *  - `float`  — coerce a numeric-ish value to a float.
 *  - `bool`   — coerce to boolean, treating "false"/"0"/""/"no" as false.
 *  - `trim`   — string-coerce then strip surrounding whitespace.
 *
 * A null input is passed through unchanged (a cast never fabricates a value; the
 * `default` on the annotation handles absence, not the cast).
 */
class SourceCast
{
    /**
     * The recognised cast names.
     *
     * @return list<string>
     */
    public static function vocabulary(): array
    {
        return ['string', 'int', 'float', 'bool', 'trim'];
    }

    public static function knows(string $cast): bool
    {
        return in_array($cast, self::vocabulary(), true);
    }

    /**
     * Apply a named cast to a value. Null passes through; an unknown cast is a
     * programming/authoring error surfaced as an exception the caller guards with
     * {@see knows()} before invoking.
     */
    public static function apply(string $cast, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'string' => self::toStringish($value),
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => self::toBool($value),
            'trim' => trim(self::toStringish($value)),
            default => throw new \InvalidArgumentException("Unknown x-source cast: {$cast}"),
        };
    }

    protected static function toStringish(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        // Non-scalars (arrays/objects) are not string-castable in-grammar.
        return '';
    }

    protected static function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return ! in_array($normalized, ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }
}
