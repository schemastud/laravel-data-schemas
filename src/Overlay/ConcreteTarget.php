<?php

namespace Schemastud\DataSchemas\Overlay;

// A DataOverlay target is an RFC 9535 JSONPath. RFC 9535 addresses *existing*
// nodes, so an `override` that must create an absent leaf needs a **concrete**
// target — a path built only from literal child keys and array indices, with no
// multi-node construct (wildcard, filter, slice, union, recursive descent).
//
// This helper is the one place that decides "is this target concrete?" and, if
// so, walks/creates the path. Create-absent is never delegated to the JSONPath
// library, which will happily fabricate nodes through a wildcard.
class ConcreteTarget
{
    // Returns the literal segment list (string keys / int indices) when $target
    // is a concrete path, or null when it uses any multi-node construct.
    public static function parse(string $target): ?array
    {
        $s = trim($target);
        $len = strlen($s);

        if ($len === 0 || $s[0] !== '$') {
            return null;
        }

        $i = 1;
        $segments = [];

        while ($i < $len) {
            $c = $s[$i];

            if ($c === '.') {
                // '..' is recursive descent — never concrete.
                if ($i + 1 < $len && $s[$i + 1] === '.') {
                    return null;
                }

                $i++;
                $start = $i;
                while ($i < $len && $s[$i] !== '.' && $s[$i] !== '[') {
                    $i++;
                }
                $name = substr($s, $start, $i - $start);

                if ($name === '' || str_contains($name, '*')) {
                    return null;
                }

                $segments[] = $name;

                continue;
            }

            if ($c === '[') {
                $end = strpos($s, ']', $i);
                if ($end === false) {
                    return null;
                }

                $inner = trim(substr($s, $i + 1, $end - $i - 1));
                $i = $end + 1;

                $quoted = strlen($inner) >= 2
                    && (($inner[0] === "'" && $inner[-1] === "'")
                        || ($inner[0] === '"' && $inner[-1] === '"'));

                if ($quoted) {
                    // A quoted key is a literal — any character is allowed
                    // inside it (including ':' and ',' that would otherwise
                    // read as a slice or union).
                    $segments[] = substr($inner, 1, -1);
                } elseif ($inner !== '' && ctype_digit($inner)) {
                    $segments[] = (int) $inner;
                } else {
                    // Unquoted, non-numeric: a wildcard, filter, slice, union or
                    // anything else we cannot treat as a single literal node.
                    return null;
                }

                continue;
            }

            return null;
        }

        return $segments;
    }

    // Walks $doc along $segments, creating intermediate arrays as needed, and
    // sets the leaf to $value (create-absent upsert for the `override` op).
    public static function set(array &$doc, array $segments, mixed $value): void
    {
        $ref = &$doc;

        foreach ($segments as $segment) {
            if (! is_array($ref)) {
                $ref = [];
            }

            if (! array_key_exists($segment, $ref)) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }

        $ref = $value;
        unset($ref);
    }
}
