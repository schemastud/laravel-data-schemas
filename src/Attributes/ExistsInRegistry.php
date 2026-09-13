<?php

namespace Schemastud\DataSchemas\Attributes;

use Attribute;
use Rushing\Popcorn\Laravel\Rules\ExistsInRegistry as RegistryRule;
use Spatie\LaravelData\Attributes\Validation\ObjectValidationAttribute;
use Spatie\LaravelData\Support\Validation\ValidationPath;

/**
 * One registry declaration for Data validation and JSON Schema enumeration.
 *
 * The enum is a snapshot of the visible vocabulary at generation time. Generate under the intended
 * documentation audience; never reuse an actor-specific schema for another actor. A domain-specific
 * attribute may override constraint() to supply the same subset predicate to both projections.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ExistsInRegistry extends ObjectValidationAttribute
{
    public function __construct(
        protected string $prefix,
        protected bool $relative = false,
    ) {}

    public function constraint(): RegistryRule
    {
        return new RegistryRule($this->prefix, relative: $this->relative);
    }

    public function getRule(ValidationPath $path): object|string
    {
        return $this->constraint();
    }

    public static function keyword(): string
    {
        return 'exists_in_registry';
    }

    public static function create(string ...$parameters): static
    {
        return new static($parameters[0], ($parameters[1] ?? 'false') === 'true');
    }
}
