<?php

namespace Rushing\LaravelDataSchemas\Strategies;

use InvalidArgumentException;
use ReflectionProperty;
use Rushing\LaravelDataSchemas\Attributes\Keyword;

/**
 * Projects repeatable #[Keyword('x-…', value)] annotations onto the property
 * schema. A strict no-op for properties without the attribute, so registering
 * it in the default set never changes other output. Non-`x-` names throw —
 * structural keywords are the generator's, not an annotation's.
 */
class KeywordAttributesStrategy implements SchemaStrategy
{
    public function apply(ReflectionProperty $property, array $schema, SchemaStrategyContext $context): array
    {
        foreach ($property->getAttributes(Keyword::class) as $attribute) {
            $keyword = $attribute->newInstance();

            if (! str_starts_with($keyword->name, 'x-')) {
                throw new InvalidArgumentException(
                    "#[Keyword] only stamps x-* extension keywords, got '{$keyword->name}' on {$property->getDeclaringClass()->getName()}::\${$property->getName()}."
                );
            }

            $schema[$keyword->name] = $keyword->value;
        }

        return $schema;
    }
}
