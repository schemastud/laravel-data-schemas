<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaBundler;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;
use Schemastud\DataSchemas\Tests\Fixtures\VersionedArticleData;

class SchemaBundlerTest extends TestCase
{
    private function generate(string $class, bool $strict = false): array
    {
        $gen = new JsonSchemaGenerator([
            'base_uri' => 'https://app.example.test/schemas',
            'schema_metadata' => ['$id' => true, '$schema' => true],
        ]);

        return $strict
            ? $gen->forLlmStrict()->generate(new ReflectionClass($class))
            : $gen->generate(new ReflectionClass($class));
    }

    public function test_bundle_embeds_addressable_resources_retaining_their_id(): void
    {
        $document = $this->generate(VersionedArticleData::class);
        $bundle = (new SchemaBundler)->bundle($document);

        $authorId = 'https://app.example.test/schemas/content/author/2';

        // The embedded resource is present and retains its own $id (a 2020-12
        // bundled resource — offline-portable yet re-resolvable).
        $this->assertArrayHasKey($authorId, $bundle['$defs']);
        $this->assertSame($authorId, $bundle['$defs'][$authorId]['$id']);

        // The absolute $ref is left intact so it re-resolves against the embedded $id.
        $this->assertSame($authorId, $bundle['properties']['author']['$ref']);

        // The bundle is a complete, resolvable 2020-12 document (opis parses it).
        $this->assertTrue((new SchemaBundler)->assertResolvable($bundle));
    }

    public function test_dereference_inlines_refs_into_a_complete_schema(): void
    {
        $document = $this->generate(VersionedArticleData::class);
        $deref = (new SchemaBundler)->dereference($document);

        $json = json_encode($deref);

        // No external/absolute $ref remains; the author node is inlined in full.
        $this->assertStringNotContainsString('content/author/2', $json);
        $this->assertStringNotContainsString('$ref', $json);
        $this->assertSame('object', $deref['properties']['author']['type']);
        $this->assertSame('string', $deref['properties']['author']['properties']['email']['type']);
    }

    public function test_for_llm_strict_bundle_is_complete_and_has_no_x_keywords(): void
    {
        // forLlmStrict already strips x-* and root metadata; the bundler then
        // dereferences to a complete schema the model can consume directly.
        $strict = $this->generate(SampleData::class, strict: true);
        $deref = (new SchemaBundler)->dereference($strict);

        $json = json_encode($deref);

        // No vendor keywords, no examples, no leftover $defs/$ref.
        $this->assertStringNotContainsString('x-', $json);
        $this->assertStringNotContainsString('$ref', $json);
        $this->assertArrayNotHasKey('$defs', $deref);

        // The nested UserData is fully inlined.
        $this->assertSame('object', $deref['properties']['user']['anyOf'][0]['type']);
    }

    public function test_bundle_sources_addressable_resources_from_the_registry(): void
    {
        // An author resource registered separately is pulled into a root that only
        // references it by absolute $id (no inline $defs entry).
        $dir = sys_get_temp_dir().'/lds-bundler-'.uniqid();
        $registry = new FilesystemSchemaRegistry($dir);
        $authorId = 'https://app.example.test/schemas/content/author/2';
        $registry->register([
            '$id' => $authorId,
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);

        $root = [
            '$id' => 'https://app.example.test/schemas/content/article/3',
            'type' => 'object',
            'properties' => ['author' => ['$ref' => $authorId]],
        ];

        $bundle = (new SchemaBundler($registry))->bundle($root);

        $this->assertArrayHasKey($authorId, $bundle['$defs']);
        $this->assertSame($authorId, $bundle['$defs'][$authorId]['$id']);

        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function test_dereference_keeps_recursive_nodes_resolvable_via_local_defs(): void
    {
        // A self-referential schema cannot be fully flattened; the bundler keeps
        // a minimal local $defs so it stays a valid, self-contained document.
        $document = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => ['child' => ['$ref' => '#/$defs/Node']],
            '$defs' => [
                'Node' => [
                    'type' => 'object',
                    'properties' => ['child' => ['$ref' => '#/$defs/Node']],
                ],
            ],
        ];

        $deref = (new SchemaBundler)->dereference($document);

        $this->assertArrayHasKey('$defs', $deref);
        $this->assertTrue((new SchemaBundler)->assertResolvable($deref));
    }
}
