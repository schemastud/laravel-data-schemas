<?php

namespace Schemastud\DataSchemas\Generators;

use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

/**
 * The configured generator list, as one {@see Generator}.
 *
 * `data-schemas.generators` is a LIST, and the dispatch rule has always been "the first generator
 * whose `canGenerate()` accepts this class" — but that rule lived inside
 * {@see \Schemastud\DataSchemas\Actions\GenerateSchemasAction} and nowhere else, so every other
 * consumer either hand-picked one generator or newed the default. `~/Herd/thingsontv` configures
 * `[BlockJsonSchemaGenerator, JsonSchemaGenerator]`, so "the generator" has no referent there:
 * binding the first entry would hand a Block-only generator every ordinary Data class.
 *
 * Named for the strategy, like {@see \Schemastud\DataSchemas\Lifecycle\ChainedSchemaRegistry} —
 * the contract is `Generator`, this is one way of being one.
 *
 * A single-element chain is the ordinary case (13 of 14 hosts) and is deliberately NOT special-cased:
 * one code path, so the multi-generator host is not the only one exercising it.
 */
class ChainedGenerator implements Generator
{
    /** @var list<Generator> */
    protected array $generators;

    /** @param list<Generator> $generators */
    public function __construct(array $generators)
    {
        $this->generators = array_values($generators);
    }

    /**
     * Build the chain a host's `data-schemas` config describes.
     *
     * The ONE place that turns `generators` class-strings into instances. The provider binding and
     * {@see \Schemastud\DataSchemas\Commands\GenerateJsonSchemaCommand} both came through here
     * rather than each running their own `array_map(fn ($c) => new $c($config))` — two copies of
     * that loop is how the estate got a generator list nobody validated.
     *
     * A bad entry throws, and that is deliberate: a class-string in config is something the CONFIG'S
     * AUTHOR could have got right without knowing which host would load it, which is the estate's
     * stated bar for a fatal. What it must not do is throw ANONYMOUSLY — unguarded, an unknown class
     * surfaces as a bare `Error: Class "..." not found` from inside the container, attributable to
     * nothing. Entries that are already Generator instances pass through, matching the defensive
     * `instanceof` GenerateSchemasAction has always done.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): static
    {
        $entries = $config['generators'] ?? [JsonSchemaGenerator::class];

        return new static(array_map(
            fn (mixed $entry) => self::instantiate($entry, $config),
            array_values((array) $entries),
        ));
    }

    /** @param array<string, mixed> $config */
    protected static function instantiate(mixed $entry, array $config): Generator
    {
        if ($entry instanceof Generator) {
            return $entry;
        }

        if (! is_string($entry) || ! class_exists($entry)) {
            throw new InvalidArgumentException(sprintf(
                'config(\'data-schemas.generators\') names %s, which is not a loadable class.',
                is_string($entry) ? sprintf('"%s"', $entry) : get_debug_type($entry),
            ));
        }

        if (! is_a($entry, Generator::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'config(\'data-schemas.generators\') names %s, which does not implement %s.',
                $entry,
                Generator::class,
            ));
        }

        return new $entry($config);
    }

    public function canGenerate(ReflectionClass $class): bool
    {
        return $this->generatorFor($class) !== null;
    }

    public function generate(ReflectionClass $class): array
    {
        $generator = $this->generatorFor($class);

        if ($generator === null) {
            throw new RuntimeException(sprintf(
                'No configured generator accepts %s. Configured: %s.',
                $class->getName(),
                $this->generators === []
                    ? '(none)'
                    : implode(', ', array_map(fn (Generator $g) => $g::class, $this->generators)),
            ));
        }

        return $generator->generate($class);
    }

    public function forRequest(): static
    {
        return $this->eachMode(fn (Generator $g) => $g->forRequest());
    }

    public function forResponse(): static
    {
        return $this->eachMode(fn (Generator $g) => $g->forResponse());
    }

    public function forLlmStrict(): static
    {
        return $this->eachMode(fn (Generator $g) => $g->forLlmStrict());
    }

    public function schemaMode(string $mode): static
    {
        return $this->eachMode(fn (Generator $g) => $g->schemaMode($mode));
    }

    /** The members this chain dispatches over, outermost first. @return list<Generator> */
    public function generators(): array
    {
        return $this->generators;
    }

    protected function generatorFor(ReflectionClass $class): ?Generator
    {
        foreach ($this->generators as $generator) {
            if ($generator->canGenerate($class)) {
                return $generator;
            }
        }

        return null;
    }

    /**
     * Mode is set on every member, not just the one that will win — the member is chosen per class
     * at generate() time, so narrowing the mode to a pre-selected member would silently drop it.
     *
     * @param  callable(Generator): Generator  $mode
     */
    protected function eachMode(callable $mode): static
    {
        return new static(array_map($mode, $this->generators));
    }
}
