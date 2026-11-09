<?php

namespace Schemastud\DataSchemas\Writers;

use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\SchemaDisk;
use Schemastud\DataSchemas\Support\WrittenSchema;

/**
 * Writes generated schema documents as JSON files on the `data-schemas` disk.
 *
 * NAMING. The contract names the idea (`Writer`), the implementation names the strategy —
 * the convention this package already keeps with `SchemaRegistry` ← `FilesystemSchemaRegistry`
 * and `PathGenerator` ← `DefaultPathGenerator`. `JsonSchemaWriter` named the payload, which
 * every implementation of `Writer` will always share, and so distinguished nothing.
 *
 * STORAGE. Through `Storage`, not `File`: see {@see SchemaDisk} for why the absolute-path
 * `File::put` had to go. Directory creation is the disk's job now, so there is no
 * `makeDirectory` here.
 */
class SchemaFileWriter implements Writer
{
    /** @param array<string, mixed> $config the `data-schemas` config */
    public function __construct(protected array $config) {}

    public function write(FileSchemaCollection $collection): void
    {
        $disk = SchemaDisk::for($this->config);

        foreach ($collection as $schema) {
            $disk->put(
                SchemaDisk::relative($schema->outputPath, $this->config),
                $this->encode($schema),
            );
        }
    }

    /** Prettier's defaults for a host that commits the schemas (`tabWidth: 4`, `printWidth: 80`). */
    protected const INDENT = '    ';

    protected const PRINT_WIDTH = 80;

    /**
     * Formatted output is the layout prettier gives the file, with a trailing newline, so a host that commits the
     * schemas and runs `prettier --check` finds them clean straight from the generator. `JSON_PRETTY_PRINT` breaks
     * every array onto one line per element and ends without a newline, so every written schema used to fail the
     * check (calcucrypt MAP-24: 7 files).
     *
     * A schema json_encode cannot encode (invalid UTF-8, say) throws, naming its class, in both layouts; it never
     * writes an empty file or a valid but wrong `null` document.
     *
     * @throws \JsonException
     */
    protected function encode(WrittenSchema $schema): string
    {
        try {
            $json = json_encode($schema->schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \JsonException("Cannot encode the schema for {$schema->className}: {$e->getMessage()}", $e->getCode(), $e);
        }

        if (! ($this->config['format_output'] ?? true)) {
            return $json;
        }

        // One round trip fixes the JSON shape ({} vs []) exactly as json_encode sees it, objects as stdClass.
        $value = json_decode($json, flags: JSON_THROW_ON_ERROR);

        return $this->print($value, '', 0, '')."\n";
    }

    /**
     * Prettier's JSON layout: a non-empty object always breaks (its source does, and prettier keeps that); an array
     * prints on one line when every element is flat and the whole line, including what follows it, fits the print
     * width; a broken array of numbers packs them (prettier's fill); any other broken array takes one element a line.
     *
     * @param  int  $lead  the width already on this line before the value (indent and key)
     * @param  string  $tail  what follows the value on its line (a comma, or nothing)
     */
    protected function print(mixed $value, string $indent, int $lead, string $tail): string
    {
        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            if ($members === []) {
                return '{}';
            }
            $inner = $indent.self::INDENT;
            $keys = array_keys($members);
            $lines = [];
            foreach ($keys as $i => $key) {
                $prefix = $this->scalar((string) $key).': ';
                $comma = $i < count($keys) - 1 ? ',' : '';
                $lines[] = $inner.$prefix.$this->print($members[$key], $inner, mb_strlen($inner.$prefix), $comma).$comma;
            }

            return "{\n".implode("\n", $lines)."\n".$indent.'}';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $flat = $this->flat($value);
            if ($flat !== null && $lead + mb_strlen($flat.$tail) <= self::PRINT_WIDTH) {
                return $flat;
            }
            $inner = $indent.self::INDENT;
            $last = count($value) - 1;

            if ($this->numbers($value)) {
                $rows = [];
                $row = '';
                foreach ($value as $i => $item) {
                    $cell = $this->scalar($item).($i < $last ? ',' : '');
                    if ($row !== '' && mb_strlen($inner.$row.' '.$cell) > self::PRINT_WIDTH) {
                        $rows[] = $inner.$row;
                        $row = '';
                    }
                    $row = $row === '' ? $cell : $row.' '.$cell;
                }
                $rows[] = $inner.$row;

                return "[\n".implode("\n", $rows)."\n".$indent.']';
            }

            $lines = [];
            foreach ($value as $i => $item) {
                $comma = $i < $last ? ',' : '';
                $lines[] = $inner.$this->print($item, $inner, mb_strlen($inner), $comma).$comma;
            }

            return "[\n".implode("\n", $lines)."\n".$indent.']';
        }

        return $this->scalar($value);
    }

    /**
     * The one-line form of an array, or null when prettier always breaks it: it holds a non-empty object, or it has
     * more than one element and every element is an array of more than one element (a matrix).
     *
     * @param  array<int, mixed>  $items
     */
    protected function flat(array $items): ?string
    {
        $matrix = count($items) > 1;
        $parts = [];
        foreach ($items as $item) {
            $matrix = $matrix && is_array($item) && count($item) > 1;
            if ($item instanceof \stdClass) {
                if (get_object_vars($item) !== []) {
                    return null;
                }
                $parts[] = '{}';
            } elseif (is_array($item)) {
                $nested = $item === [] ? '[]' : $this->flat($item);
                if ($nested === null) {
                    return null;
                }
                $parts[] = $nested;
            } else {
                $parts[] = $this->scalar($item);
            }
        }

        return $matrix ? null : '['.implode(', ', $parts).']';
    }

    /** @param  array<int, mixed>  $items */
    protected function numbers(array $items): bool
    {
        foreach ($items as $item) {
            if (! is_int($item) && ! is_float($item)) {
                return false;
            }
        }

        return true;
    }

    protected function scalar(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
