<?php

namespace Schemastud\DataSchemas\Strategies;

use BackedEnum;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;

/**
 * The default strategy that maps spatie/laravel-data validation attributes to
 * JSON Schema constraints (maxLength/minimum/format/enum, …). A per-attribute
 * override may be supplied via `config('data-schemas.validation_mapping')`
 * keyed by attribute class — preserving the original `validation_mapping` seam,
 * now expressed as one strategy in the pipeline.
 */
class ValidationAttributeStrategy implements SchemaStrategy
{
    public function apply(ReflectionProperty $property, array $schema, SchemaStrategyContext $context): array
    {
        $validationAttrs = $property->getAttributes(
            ValidationAttribute::class,
            ReflectionAttribute::IS_INSTANCEOF
        );

        $mapping = $context->config['validation_mapping'] ?? [];

        foreach ($validationAttrs as $attr) {
            $instance = $attr->newInstance();
            $attrClass = get_class($instance);

            $customMapping = $mapping[$attrClass] ?? null;
            if (is_callable($customMapping)) {
                $result = $customMapping($instance);
                if (is_array($result)) {
                    $schema = array_merge($schema, $result);
                }

                continue;
            }

            match ($attrClass) {
                Max::class => $this->applyMaxConstraint($instance, $schema),
                Min::class => $this->applyMinConstraint($instance, $schema),
                Between::class => $this->applyBetweenConstraint($instance, $schema),
                Email::class => $schema['format'] = 'email',
                Uuid::class => $schema['format'] = 'uuid',
                Url::class => $schema['format'] = 'uri',
                Enum::class => $this->applyEnumConstraint($instance, $schema),
                default => null,
            };
        }

        return $schema;
    }

    protected function schemaHasType(array $schema, string $needle): bool
    {
        $type = $schema['type'] ?? null;

        return $type === $needle || (is_array($type) && in_array($needle, $type, true));
    }

    protected function applyMaxConstraint(Max $attr, array &$schema): void
    {
        $value = $attr->parameters()[0] ?? null;
        if ($value === null) {
            return;
        }

        if ($this->schemaHasType($schema, 'string')) {
            $schema['maxLength'] = $value;
        }
        if ($this->schemaHasType($schema, 'integer') || $this->schemaHasType($schema, 'number')) {
            $schema['maximum'] = $value;
        }
        if ($this->schemaHasType($schema, 'array')) {
            $schema['maxItems'] = $value;
        }
    }

    protected function applyMinConstraint(Min $attr, array &$schema): void
    {
        $value = $attr->parameters()[0] ?? null;
        if ($value === null) {
            return;
        }

        if ($this->schemaHasType($schema, 'string')) {
            $schema['minLength'] = $value;
        }
        if ($this->schemaHasType($schema, 'integer') || $this->schemaHasType($schema, 'number')) {
            $schema['minimum'] = $value;
        }
        if ($this->schemaHasType($schema, 'array')) {
            $schema['minItems'] = $value;
        }
    }

    protected function applyBetweenConstraint(Between $attr, array &$schema): void
    {
        $params = $attr->parameters();
        if (count($params) < 2) {
            return;
        }

        if ($this->schemaHasType($schema, 'integer') || $this->schemaHasType($schema, 'number')) {
            $schema['minimum'] = $params[0];
            $schema['maximum'] = $params[1];
        }
    }

    protected function applyEnumConstraint(Enum $attr, array &$schema): void
    {
        $reflection = new ReflectionClass($attr);
        $enumProperty = $reflection->getProperty('enum');
        $enumProperty->setAccessible(true);
        $enumClass = $enumProperty->getValue($attr);

        if (is_string($enumClass) && enum_exists($enumClass) && is_subclass_of($enumClass, BackedEnum::class)) {
            $schema['enum'] = array_map(
                fn (BackedEnum $case) => $case->value,
                $enumClass::cases()
            );
        }
    }
}
