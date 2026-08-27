<?php

namespace Schemastud\DataSchemas\Collectors;

use ReflectionClass;

abstract class Collector
{
    public function __construct(protected array $config) {}

    abstract public function canCollect(ReflectionClass $class): bool;

    /**
     * The configured collector list, instantiated — one loop, in one place.
     *
     * It was written out three times (the command, {@see \Schemastud\DataSchemas\Sources\PathScanSource},
     * and now a second source), which is the shape {@see \Schemastud\DataSchemas\Generators\ChainedGenerator::fromConfig()}
     * already collapsed for generators. A non-Collector entry is refused here rather than fataling
     * later inside `canCollect()` — the class list is something the CONFIG's author could have got
     * right without knowing which host would load it.
     *
     * @param  array<string, mixed>  $config
     * @return list<Collector>
     */
    public static function fromConfig(array $config): array
    {
        return array_values(array_map(function (string $class) use ($config): Collector {
            if (! is_subclass_of($class, self::class)) {
                throw new \InvalidArgumentException(sprintf(
                    '`data-schemas.collectors` entry `%s` is not a %s.',
                    $class,
                    self::class,
                ));
            }

            return new $class($config);
        }, array_values((array) ($config['collectors'] ?? []))));
    }
}
