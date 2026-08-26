<?php

namespace Schemastud\DataSchemas\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;

class SchemaRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lds-registry-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_it_registers_and_resolves_a_schema_by_id(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);
        $schema = [
            '$id' => 'https://schemas.splicewire.app/content/article/3',
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
        ];

        $registry->register($schema);

        $this->assertTrue($registry->has($schema['$id']));
        $this->assertSame($schema, $registry->get($schema['$id']));
        $this->assertSame([$schema['$id']], $registry->ids());
    }

    public function test_it_resolves_a_nested_addressable_node_by_its_own_id(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);
        $author = [
            '$id' => 'https://schemas.splicewire.app/content/author/2',
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ];

        $registry->register($author);

        $this->assertNotNull($registry->get($author['$id']));
    }

    public function test_re_registering_an_identical_shape_is_an_idempotent_no_op(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);
        $schema = ['$id' => 'https://x.test/a/1', 'type' => 'object', 'properties' => ['x' => ['type' => 'string']]];

        $registry->register($schema);
        // Re-publishing the same shape (even with reworded description) is allowed.
        $registry->register($schema + ['description' => 'reworded']);

        $this->assertTrue($registry->has($schema['$id']));
    }

    public function test_overwriting_a_frozen_id_with_a_different_fingerprint_is_rejected(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);
        $id = 'https://x.test/a/1';

        $registry->register(['$id' => $id, 'type' => 'object', 'properties' => ['x' => ['type' => 'string']]]);

        $this->expectException(SchemaRegistryConflict::class);
        $registry->register(['$id' => $id, 'type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]);
    }

    public function test_registering_without_an_id_throws(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $registry->register(['type' => 'object']);
    }

    public function test_it_enumerates_the_versions_registered_under_a_stem(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);
        $stem = 'https://schemas.splicewire.app/content/article';

        // Register out of order and across stems to prove filtering + ordering.
        $registry->register(['$id' => $stem.'/3', 'type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
        $registry->register(['$id' => $stem.'/1', 'type' => 'object', 'properties' => ['b' => ['type' => 'string']]]);
        $registry->register(['$id' => 'https://schemas.splicewire.app/content/author/9', 'type' => 'object', 'properties' => ['c' => ['type' => 'string']]]);

        $this->assertSame([1, 3], $registry->versionsFor($stem));
    }

    public function test_versions_for_an_unknown_stem_is_empty(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);

        $this->assertSame([], $registry->versionsFor('https://schemas.splicewire.app/nope'));
    }

    /**
     * beam-facade 147 — the filename must let a human tell the authority and the stem apart.
     *
     * This is a LEGIBILITY test, and it is written as one deliberately: correctness never depended on
     * the slug (the 16-hex fingerprint of the `$id` does all the disambiguating work), so the only
     * thing that can regress here is the reader's ability to see what a directory holds. Before this,
     * `substr($slug, -60)` ate the head, and four of the flagship's eight `content-schema` artifacts
     * carried no readable authority at all — two `.com` and two `.app` stems truncating to lookalikes.
     */
    public function test_a_long_id_keeps_both_its_authority_and_its_stem_in_the_filename(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);

        $id = 'https://app.splicewire.com/schemas/content-schema/food-safety/'
            .'food-code-compliance-intake-with-a-deliberately-overlong-tail/1';

        $registry->register(['$id' => $id, 'type' => 'object']);

        $name = basename((string) (glob($this->dir.'/*.schema.json') ?: [])[0]);

        $this->assertStringStartsWith('https-app-splicewire-com-schemas-content-schema', $name);
        $this->assertStringContainsString('-1.', $name, 'the version tail must survive');
        $this->assertStringContainsString(FilesystemSchemaRegistry::ELISION, $name);

        // And it is still a direct lookup: the encoding is the same on both sides.
        $this->assertNotNull($registry->get($id));
    }

    /**
     * The estate's real ids fit under the cap, so the common case is not truncated at all. 100 was
     * chosen because the longest `$id` in the estate slugs to 88 characters — the cap exists for the
     * pathological case, not the normal one.
     */
    public function test_an_estate_length_id_is_not_truncated_at_all(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);

        $id = 'https://app.splicewire.com/schemas/content-schema/food-safety/food-code-compliance-intake/1';
        $registry->register(['$id' => $id, 'type' => 'object']);

        $name = basename((string) (glob($this->dir.'/*.schema.json') ?: [])[0]);

        $this->assertStringNotContainsString(FilesystemSchemaRegistry::ELISION, $name);
        $this->assertStringStartsWith(
            'https-app-splicewire-com-schemas-content-schema-food-safety-food-code-compliance-intake-1.',
            $name,
        );
    }

    /**
     * Two ids differing ONLY in authority must not produce the same filename. Under left-truncation
     * they shared a prefix and were told apart solely by the hash; that is still true of the hash, but
     * the name is now discriminating on its own.
     */
    public function test_two_ids_differing_only_in_authority_produce_distinguishable_names(): void
    {
        $registry = new FilesystemSchemaRegistry($this->dir);

        $stem = '/schemas/content-schema/food-safety/food-code-compliance-intake/1';
        $registry->register(['$id' => 'https://app.splicewire.com'.$stem, 'type' => 'object']);
        $registry->register(['$id' => 'https://schemas.splicewire.app'.$stem, 'type' => 'object']);

        $names = array_map('basename', glob($this->dir.'/*.schema.json') ?: []);

        $this->assertCount(2, $names);
        $this->assertCount(2, array_unique(array_map(
            fn (string $n) => substr($n, 0, 40),
            $names,
        )), 'the first 40 characters alone must tell them apart');
    }
}
