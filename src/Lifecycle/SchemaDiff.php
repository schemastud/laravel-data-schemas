<?php

namespace Schemastud\DataSchemas\Lifecycle;

/**
 * A structural old-vs-new diff between two object schemas, reporting which
 * fields were added, dropped, or widened (type-broadened). This is the input
 * slice 13's migration rungs consume, so it returns a plain, explicit data
 * structure rather than a formatted string.
 *
 * "Widened" = the new field accepts a strict superset of the old field's JSON
 * types (e.g. `string` → `string|null`, or `integer` → `number`). A field that
 * only narrows or otherwise changes type is reported under `changed`.
 */
class SchemaDiff
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{
     *     added: array<int, string>,
     *     dropped: array<int, string>,
     *     widened: array<int, array{field: string, from: array<int, string>, to: array<int, string>}>,
     *     changed: array<int, array{field: string, from: array<int, string>, to: array<int, string>}>,
     *     breaking: bool,
     * }
     */
    public static function between(array $old, array $new): array
    {
        $oldProps = $old['properties'] ?? [];
        $newProps = $new['properties'] ?? [];

        $oldNames = array_keys($oldProps);
        $newNames = array_keys($newProps);

        $added = array_values(array_diff($newNames, $oldNames));
        $dropped = array_values(array_diff($oldNames, $newNames));

        $widened = [];
        $changed = [];

        foreach (array_intersect($oldNames, $newNames) as $name) {
            $from = self::typesOf($oldProps[$name]);
            $to = self::typesOf($newProps[$name]);

            if ($from === $to) {
                continue;
            }

            // Widened: new accepts every old type plus more (strict superset),
            // honouring the numeric tower (integer ⊆ number).
            if (self::isSuperset($to, $from)) {
                $widened[] = ['field' => $name, 'from' => $from, 'to' => $to];

                continue;
            }

            $changed[] = ['field' => $name, 'from' => $from, 'to' => $to];
        }

        // Dropping a field or any non-widening type change breaks consumers.
        $breaking = ! empty($dropped) || ! empty($changed);

        return [
            'added' => $added,
            'dropped' => $dropped,
            'widened' => $widened,
            'changed' => $changed,
            'breaking' => $breaking,
        ];
    }

    /**
     * Normalise a property schema to its sorted set of accepted JSON types.
     * A `$ref` / `anyOf` is represented by a synthetic token so structural
     * changes around refs still register.
     *
     * @param  array<string, mixed>  $prop
     * @return array<int, string>
     */
    protected static function typesOf(array $prop): array
    {
        if (isset($prop['$ref'])) {
            return ['$ref:'.$prop['$ref']];
        }

        if (isset($prop['anyOf']) && is_array($prop['anyOf'])) {
            $types = [];
            foreach ($prop['anyOf'] as $member) {
                $types = array_merge($types, self::typesOf($member));
            }
            sort($types);

            return array_values(array_unique($types));
        }

        $type = $prop['type'] ?? [];
        $type = is_array($type) ? $type : [$type];
        $type = array_values(array_unique($type));
        sort($type);

        return $type;
    }

    /**
     * Whether $super accepts every type in $sub plus at least one more —
     * a strict superset under the numeric tower (integer is a subtype of
     * number).
     *
     * @param  array<int, string>  $super
     * @param  array<int, string>  $sub
     */
    protected static function isSuperset(array $super, array $sub): bool
    {
        foreach ($sub as $type) {
            $accepted = in_array($type, $super, true)
                || ($type === 'integer' && in_array('number', $super, true));

            if (! $accepted) {
                return false;
            }
        }

        // Strict: the new set must be genuinely broader, not merely equal.
        return count($super) > count($sub) || $super !== $sub;
    }
}
