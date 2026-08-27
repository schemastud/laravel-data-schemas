<?php

namespace Schemastud\DataSchemas\Fixtures;

use Closure;
use InvalidArgumentException;
use Spatie\LaravelData\Support\Creation\CreationContextFactory;

/**
 * Laravel-model-factory ergonomics over a declared Data class, built by EXTENDING spatie's own
 * creation factory rather than shadowing `::factory()`.
 *
 * ## Why extension is available, checked against the vendored source
 *
 * - `CreationContextFactory` is not `final`.
 * - Every fluent setter `return $this`, so a subclass survives the whole chain at runtime.
 * - Only the two static builders `return new self(...)` — the one place inheritance does not carry,
 *   and the reason {@see promote()} exists to adopt an instance spatie already built.
 *
 * The setters are re-declared with a `static` return type. Spatie types them `self`, which is true at
 * runtime but tells a static analyser the chain has degraded to the parent, so
 * `->withoutValidation()->enterprise()` would fail analysis without the narrowing. Legal covariance.
 *
 * ## Why extending rather than sitting beside it is the whole point
 *
 * {@see make()} builds through `parent::from()`, so the validation strategy, property-name mapping
 * and casts configured on **this same object** are the ones that apply —
 * `->alwaysValidate()->enterprise()->make()` really validates. A factory constructed alongside
 * spatie's would be a second construction path, and every spatie setter on it would be silently
 * inert. That is this estate's recurring defect (an instrument that reports success by not running)
 * in the one place it would be hardest to notice.
 *
 * ## What this deliberately does NOT do
 *
 * There is no `create()` that persists. This buys **shape** coverage, not persistence coverage: no
 * rows, no primary keys, no foreign keys, no relationships, no model events. A seeder that must pin
 * an `id` or upsert on a natural key still wants a model factory, and saying otherwise would be
 * claiming coverage the object cannot deliver.
 */
class FixtureFactory extends CreationContextFactory
{
    private FixtureIndex $index;

    private string $shape;

    /** @var list<Closure(array<string, mixed>): array<string, mixed>> */
    private array $states = [];

    /** @var array<string, mixed> */
    private array $overrides = [];

    private int $count = 1;

    /** @var list<array<string, mixed>>|null */
    private ?array $sequence = null;

    /** Adopt an instance spatie built — the seam the missing `new static` forces. */
    public static function promote(CreationContextFactory $base, string $shape, FixtureIndex $index): static
    {
        $self = new static(
            dataClass: $base->dataClass,
            validationStrategy: $base->validationStrategy,
            mapPropertyNames: $base->mapPropertyNames,
            disableMagicalCreation: $base->disableMagicalCreation,
            useOptionalValues: $base->useOptionalValues,
            ignoredMagicalMethods: $base->ignoredMagicalMethods,
            casts: $base->casts,
        );

        $self->index = $index;
        $self->shape = $shape;

        return $self;
    }

    /* ---- covariant re-declarations, so the chain keeps its type through spatie's setters ---- */

    public function withoutValidation(): static
    {
        parent::withoutValidation();

        return $this;
    }

    public function alwaysValidate(): static
    {
        parent::alwaysValidate();

        return $this;
    }

    public function withoutMagicalCreation(bool $withoutMagicalCreation = true): static
    {
        parent::withoutMagicalCreation($withoutMagicalCreation);

        return $this;
    }

    /* ---- the model-factory patterns ---- */

    /** @param  array<string, mixed>  $attributes */
    public function state(string|Closure $state, array $attributes = []): static
    {
        if ($state instanceof Closure) {
            $this->states[] = $state;

            return $this;
        }

        $resolved = $this->index->stateFor($this->shape, $this->normalise($state));

        if ($resolved === null) {
            throw new InvalidArgumentException(sprintf(
                'No fixture state [%s] for shape [%s]. Known states: %s',
                $state,
                $this->shape,
                implode(', ', $this->index->statesOf($this->shape)) ?: '(none)',
            ));
        }

        $this->states[] = $resolved;
        $this->overrides = array_merge($this->overrides, $attributes);

        return $this;
    }

    public function count(int $count): static
    {
        $this->count = $count;

        return $this;
    }

    /** @param  array<string, mixed>  ...$sets cycled across the generated items, as Laravel's does */
    public function sequence(array ...$sets): static
    {
        $this->sequence = array_values($sets);

        return $this;
    }

    /**
     * `->budgetCapped()` resolves the registered `budget-capped`.
     *
     * The mapping happens HERE and not in the registry, because {@see \Rushing\Popcorn\Registries\Key}
     * does no folding by design — "no case folding, no separator unification, no trimming" — so the
     * kernel will not paper over a spelling mismatch, and is right not to. An unknown name throws with
     * the known-key list rather than returning `$this`: a silent no-op would leave the object
     * constructed and the test passing with the state never applied.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): static
    {
        return $this->state($method, $arguments[0] ?? []);
    }

    /**
     * Defaults, then states in CALL order, then the caller's overrides, then the shape's hooks in
     * REGISTRATION order. Hooks run last so a shape can stamp something no individual state should
     * have to remember.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function raw(array $attributes = [], int $index = 0): array
    {
        $defaults = $this->index->defaultsFor($this->shape);

        $built = $defaults ? $defaults() : [];

        foreach ($this->states as $state) {
            $built = array_merge($built, $state($built));
        }

        if ($this->sequence !== null && $this->sequence !== []) {
            $built = array_merge($built, $this->sequence[$index % count($this->sequence)]);
        }

        $built = array_merge($built, $this->overrides, $attributes);

        foreach ($this->index->hooksFor($this->shape) as $hook) {
            $built = array_merge($built, $hook($built));
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return object|list<object>
     */
    public function make(array $attributes = []): object|array
    {
        if ($this->count === 1) {
            return parent::from($this->raw($attributes));
        }

        return array_map(
            fn (int $i): object => parent::from($this->raw($attributes, $i)),
            range(0, $this->count - 1),
        );
    }

    /** camelCase and snake_case both land on the kebab spelling a registration uses. */
    private function normalise(string $key): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', str_replace('_', '-', $key)));
    }
}
