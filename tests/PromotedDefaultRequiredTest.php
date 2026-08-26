<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\RequiredShapesData;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * api-surface-coherence 70 — the fix half of 31.
 *
 * `isRequired()` kept a second, wrong copy of a property model spatie already computes
 * correctly: `ReflectionProperty::hasDefaultValue()` returns FALSE for a promoted property
 * whose constructor parameter has a default, so 533 of 1409 Data properties across the estate
 * landed in `required` that should not have.
 *
 * Booted through Testbench with spatie's provider deliberately: `DataConfig::getDataClass()`
 * goes through `DataContainer`, so a plain PHPUnit harness cannot answer the question at all —
 * which is the same trap 63 recorded in tower, where the package's own suite could not execute
 * one `validateAndCreate()` because the provider was never registered.
 */
class PromotedDefaultRequiredTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function required(string $mode): array
    {
        $schema = (new JsonSchemaGenerator)
            ->schemaMode($mode)
            ->generate(new ReflectionClass(RequiredShapesData::class));

        return $schema['required'] ?? [];
    }

    public function test_raw_reflection_cannot_answer_the_question_at_all(): void
    {
        // The premise, pinned rather than asserted in prose: every one of the four promoted
        // properties reports "no default" to reflection, including the two that plainly have one.
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->assertFalse(
                (new ReflectionClass(RequiredShapesData::class))->getProperty($name)->hasDefaultValue(),
                "reflection unexpectedly saw a default on \${$name}",
            );
        }
    }

    public function test_a_defaulted_promoted_property_is_optional_on_the_request_axis(): void
    {
        $required = $this->required('request');

        $this->assertNotContains('a', $required, 'nullable + defaulted must be omittable on input');
        $this->assertNotContains('c', $required, 'non-nullable + defaulted must be omittable on input');
        $this->assertContains('b', $required, 'nullable with NO default is still required');
        $this->assertNotContains('d', $required, 'Optional is absent from required everywhere');
    }

    public function test_the_response_axis_still_guarantees_a_defaulted_property(): void
    {
        // The half that has to be asserted explicitly. This bug survived because nothing
        // asserted the negative, and the mode split makes it possible to fix one axis by
        // silently breaking the other: spatie serializes a defaulted property on every
        // response, so it is guaranteed on the way OUT even though it is omittable on the way IN.
        $required = $this->required('response');

        $this->assertContains('a', $required);
        $this->assertContains('b', $required);
        $this->assertContains('c', $required);
        $this->assertNotContains('d', $required, 'Optional is absent from required everywhere');
    }

    public function test_collapsed_keeps_the_response_rule(): void
    {
        $required = $this->required('collapsed');

        $this->assertEqualsCanonicalizing(['b', 'a', 'c'], $required);
    }

    public function test_llm_strict_lists_every_property_and_makes_the_optional_ones_nullable(): void
    {
        // llm_strict's documented contract: everything required, optionality re-expressed as a
        // nullable type. It keeps the response rule, so `a` and `c` stay genuinely required and
        // only `d` gains the null arm — the shift 31 §Q5 wanted visible rather than inferred.
        $schema = (new JsonSchemaGenerator)
            ->forLlmStrict()
            ->generate(new ReflectionClass(RequiredShapesData::class));

        $this->assertEqualsCanonicalizing(['b', 'd', 'a', 'c'], $schema['required']);
        $this->assertContains('null', (array) $schema['properties']['d']['type']);
        $this->assertNotContains('null', (array) $schema['properties']['c']['type']);
    }
}
