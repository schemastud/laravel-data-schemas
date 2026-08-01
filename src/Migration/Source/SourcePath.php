<?php

namespace Schemastud\DataSchemas\Migration\Source;

/**
 * A minimal, execution-free dot-path extractor for the `x-source` projection
 * dialect. This is data-not-code: it walks a nested foreign array/object payload
 * by a bounded path grammar and returns the value found (or a MISSING sentinel).
 * It is deliberately NOT a query/expression language (no wildcards, filters,
 * predicates, or functions) — anything richer routes to the custom-transform rung.
 *
 * Path grammar (minimal, defensible):
 *  - dot-separated segments: `author.name` descends object/array keys left-to-right.
 *  - an integer segment indexes a list: `meta.tags.0` reads the first element.
 *  - a segment matching an integer key is tried BOTH as a string key and as a
 *    numeric list index, so it works over PHP assoc arrays and JSON arrays alike.
 *  - an empty path ('') resolves to the whole payload (identity extraction).
 *
 * There is no escaping: a literal dot inside a key is out of grammar — such a
 * shape is exactly what the custom-transform escape hatch exists for.
 */
class SourcePath
{
    /**
     * Sentinel distinguishing "path absent" from "path present but null", so a
     * caller can apply a `default` only on genuine absence.
     */
    public const MISSING = "\0__x_source_missing__\0";

    /**
     * Extract the value at $path from the foreign payload, or {@see MISSING} when
     * any segment along the way is absent.
     *
     * @param  mixed  $payload  the foreign source shape (array/object graph)
     * @return mixed the extracted value, or SourcePath::MISSING
     */
    public static function extract(mixed $payload, string $path): mixed
    {
        if ($path === '') {
            return $payload;
        }

        $cursor = $payload;
        foreach (explode('.', $path) as $segment) {
            $cursor = self::step($cursor, $segment);
            if ($cursor === self::MISSING) {
                return self::MISSING;
            }
        }

        return $cursor;
    }

    /**
     * Descend ONE segment into the cursor value.
     */
    protected static function step(mixed $cursor, string $segment): mixed
    {
        if (is_array($cursor)) {
            if (array_key_exists($segment, $cursor)) {
                return $cursor[$segment];
            }
            // Integer segment may index a JSON list stored as a 0-based array.
            if (self::isIntSegment($segment)) {
                $index = (int) $segment;
                if (array_key_exists($index, $cursor)) {
                    return $cursor[$index];
                }
            }

            return self::MISSING;
        }

        if (is_object($cursor)) {
            if (isset($cursor->{$segment}) || property_exists($cursor, $segment)) {
                return $cursor->{$segment};
            }

            return self::MISSING;
        }

        // A scalar/null cursor cannot be descended further.
        return self::MISSING;
    }

    protected static function isIntSegment(string $segment): bool
    {
        return $segment !== '' && ctype_digit($segment);
    }
}
