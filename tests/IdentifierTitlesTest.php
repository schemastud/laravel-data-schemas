<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\LabelledData;

/**
 * app-walkthrough APP-09 (APP-21): a rendered label comes from declared display metadata. With
 * `schema_metadata.identifier_titles => false` the generator emits no `title` for an object or enum that declares
 * none, rather than its class short name; a declared #[Title] still wins, and a ProvidesEnumLabel enum still emits
 * `enumNames`. The default (true) keeps today's short-name titles, so a host that does not opt in is unchanged.
 */
class IdentifierTitlesTest extends TestCase
{
    private function generate(array $config = []): array
    {
        return (new JsonSchemaGenerator($config))->generate(new ReflectionClass(LabelledData::class));
    }

    public function test_off_an_undeclared_object_or_enum_carries_no_title(): void
    {
        $schema = $this->generate(['schema_metadata' => ['identifier_titles' => false]]);

        $this->assertArrayNotHasKey('title', $schema);
        $this->assertArrayNotHasKey('title', $schema['$defs']['StatusEnum']);
        $this->assertArrayNotHasKey('title', $schema['$defs']['FrequencyEnum']);
        $this->assertArrayNotHasKey('title', $schema['$defs']['UserData']);
    }

    public function test_off_a_declared_title_and_enum_labels_survive(): void
    {
        $schema = $this->generate(['schema_metadata' => ['identifier_titles' => false]]);

        $this->assertSame('A Custom Title', $schema['$defs']['TitledData']['title']);
        $this->assertSame(['Every day', 'Every week'], $schema['$defs']['FrequencyEnum']['enumNames']);
        $this->assertArrayNotHasKey('enumNames', $schema['$defs']['StatusEnum']);
    }

    public function test_the_default_keeps_short_name_titles(): void
    {
        $schema = $this->generate();

        $this->assertSame('LabelledData', $schema['title']);
        $this->assertSame('StatusEnum', $schema['$defs']['StatusEnum']['title']);
        $this->assertSame('UserData', $schema['$defs']['UserData']['title']);
    }
}
