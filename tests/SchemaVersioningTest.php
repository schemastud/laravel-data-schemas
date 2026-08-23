<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Generators\MissingSchemaBaseUri;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;
use Schemastud\DataSchemas\Tests\Fixtures\VersionedArticleData;
use Schemastud\DataSchemas\Tests\Fixtures\VersionedAuthorData;

class SchemaVersioningTest extends TestCase
{
    /** The base every test declares explicitly — there is no package default to lean on. */
    private const BASE = 'https://app.example.test/schemas';

    private function generate(string $class, array $config = []): array
    {
        return (new JsonSchemaGenerator($config + [
            'base_uri' => self::BASE,
            'schema_metadata' => ['$id' => true],
        ]))->generate(new ReflectionClass($class));
    }

    public function test_a_versioned_class_emits_an_absolute_versioned_id(): void
    {
        $schema = $this->generate(VersionedAuthorData::class);

        $this->assertSame(self::BASE.'/content/author/2', $schema['$id']);
    }

    public function test_base_uri_is_configurable(): void
    {
        $schema = $this->generate(VersionedAuthorData::class, ['base_uri' => 'https://example.test/schemas']);

        $this->assertSame('https://example.test/schemas/content/author/2', $schema['$id']);
    }

    public function test_an_unconfigured_base_uri_throws_rather_than_minting_a_guessed_authority(): void
    {
        // An $id is write-once, so a plausible fallback is unrecoverable. The
        // historical default named a domain that was never registered and reached
        // three vendors before anyone noticed.
        $this->expectException(MissingSchemaBaseUri::class);
        $this->expectExceptionMessageMatches('/base_uri/');

        (new JsonSchemaGenerator(['schema_metadata' => ['$id' => true]]))
            ->generate(new ReflectionClass(VersionedAuthorData::class));
    }

    public function test_base_uri_false_opts_the_host_out_of_versioned_identity(): void
    {
        // Opted out: the class keeps the short-name $id it would have had without
        // the interface — the same shape as a non-versioned class, not an error.
        $schema = $this->generate(VersionedAuthorData::class, ['base_uri' => false]);

        $this->assertSame('VersionedAuthorData', $schema['$id']);
    }

    public function test_a_non_versioned_class_keeps_the_short_name_id_unchanged(): void
    {
        // Backward-compat: no SchemaIdentity → historical short-name $id and
        // #/$defs/Short inlining are preserved exactly.
        $schema = $this->generate(SampleData::class);

        $this->assertSame('SampleData', $schema['$id']);
        $this->assertSame('#/$defs/UserData', $schema['properties']['user']['$ref']);
        $this->assertArrayHasKey('UserData', $schema['$defs']);
        $this->assertSame('#/$defs/UserData', $schema['properties']['collaborators']['items']['$ref']);
    }

    public function test_a_versionable_nested_node_gets_its_own_id_and_absolute_ref(): void
    {
        $schema = $this->generate(VersionedArticleData::class);

        // Root is versioned.
        $this->assertSame(self::BASE.'/content/article/3', $schema['$id']);

        // The versionable nested node is referenced by its ABSOLUTE $id.
        $authorId = self::BASE.'/content/author/2';
        $this->assertSame($authorId, $schema['properties']['author']['$ref']);

        // It is embedded under $defs keyed by $id, retaining its own $id.
        $this->assertArrayHasKey($authorId, $schema['$defs']);
        $this->assertSame($authorId, $schema['$defs'][$authorId]['$id']);
    }

    public function test_a_non_versionable_nested_node_stays_inlined_in_a_versioned_tree(): void
    {
        // A tree mixes addressable + inlined nodes: UserData does not opt in, so
        // it keeps #/$defs/Short even inside a versioned root.
        $schema = $this->generate(VersionedArticleData::class);

        $this->assertSame('#/$defs/UserData', $schema['properties']['editor']['$ref']);
        $this->assertArrayHasKey('UserData', $schema['$defs']);
        $this->assertArrayNotHasKey('$id', $schema['$defs']['UserData']);
    }
}
