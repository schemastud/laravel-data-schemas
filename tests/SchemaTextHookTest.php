<?php

namespace Schemastud\DataSchemas\Tests;

use BackedEnum;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaText;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\FrequencyEnum;
use Schemastud\DataSchemas\Tests\Fixtures\LabelledData;
use Schemastud\DataSchemas\Text\IdentitySchemaText;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * localization 09 — the display-text hook for the text the generator writes outside its property-strategy loop: a
 * class's title and description, an enum's title and its case labels. Unbound (or bound to the identity default) the
 * output is byte-identical; a bound hook is consulted at every one of those sites; and the enum-label half is callable
 * outside schema generation, because a filter's options read it (localization 14).
 */
class SchemaTextHookTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function generate(): array
    {
        return (new JsonSchemaGenerator)->generate(new ReflectionClass(LabelledData::class));
    }

    public function test_the_identity_default_is_bound_and_leaves_output_byte_identical(): void
    {
        $this->assertInstanceOf(IdentitySchemaText::class, $this->app->make(SchemaText::class));
        $bound = json_encode($this->generate());

        $this->app->offsetUnset(SchemaText::class);
        $unbound = json_encode($this->generate());

        $this->assertSame($unbound, $bound);
        $this->assertSame('A Custom Title', $this->generate()['$defs']['TitledData']['title']);
        $this->assertSame(['Every day', 'Every week'], $this->generate()['$defs']['FrequencyEnum']['enumNames']);
    }

    public function test_a_bound_hook_rewrites_class_and_enum_titles_descriptions_and_case_labels(): void
    {
        $this->app->instance(SchemaText::class, new class implements SchemaText
        {
            public function classTitle(ReflectionClass $class, ?string $declared): ?string
            {
                return $declared === null ? null : "[{$declared}]";
            }

            public function classDescription(ReflectionClass $class, ?string $declared): ?string
            {
                return $declared === null ? null : "[{$declared}]";
            }

            public function enumTitle(string $enumClass, ?string $declared): ?string
            {
                return "[enum {$enumClass}]";
            }

            public function enumCaseLabel(BackedEnum $case, string $declared): string
            {
                return "[{$declared}]";
            }
        });

        $schema = $this->generate();

        $this->assertSame('[A Custom Title]', $schema['$defs']['TitledData']['title']);
        $this->assertSame('[A resource whose root title comes from a class-level attribute.]', $schema['$defs']['TitledData']['description']);
        $this->assertSame('[enum '.FrequencyEnum::class.']', $schema['$defs']['FrequencyEnum']['title']);
        $this->assertSame(['[Every day]', '[Every week]'], $schema['$defs']['FrequencyEnum']['enumNames']);
        // An undeclared class title still falls back to the generator's own identifier title.
        $this->assertSame('LabelledData', $schema['title']);
    }

    public function test_the_enum_label_hook_is_callable_outside_schema_generation(): void
    {
        $this->assertSame('Every day', IdentitySchemaText::current()->enumCaseLabel(FrequencyEnum::Daily, FrequencyEnum::Daily->label()));

        $this->app->offsetUnset(SchemaText::class);
        $this->assertInstanceOf(IdentitySchemaText::class, IdentitySchemaText::current());
    }
}
