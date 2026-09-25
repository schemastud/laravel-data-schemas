<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;

/**
 * ADR 0002 — the two nullable encodings share one identity.
 *
 * `e66c503` re-spelled a nullable Data reference from `{"$ref": X, "nullable": true}` to
 * `{"anyOf": [{"$ref": X}, {"type": "null"}]}`. The PHP declaration did not move, so a frozen
 * version-1 artifact in the old spelling must not read as drift against today's projection, and
 * `schema:freeze` must treat it as the idempotent no-op it is rather than a write-once conflict.
 *
 * The other half is asserted just as hard: folding the encoding must not fold anything else. A
 * nullable field that becomes required-non-null, or a `$ref` that points elsewhere, is still drift.
 */
class NullableEncodingEquivalenceTest extends TestCase
{
    private const SPLIT = 'https://example.test/schemas/commerce/split/1';

    public function test_a_nullable_ref_matches_its_any_of_spelling(): void
    {
        $frozen = $this->order(['$ref' => self::SPLIT, 'nullable' => true]);
        $current = $this->order(['anyOf' => [['$ref' => self::SPLIT], ['type' => 'null']]]);

        $this->assertSame(SchemaFingerprint::of($frozen), SchemaFingerprint::of($current));
    }

    public function test_sibling_keywords_survive_the_rewrite(): void
    {
        $frozen = $this->order(['$ref' => self::SPLIT, 'nullable' => true, 'readOnly' => true]);
        $current = $this->order(['anyOf' => [['$ref' => self::SPLIT], ['type' => 'null']], 'readOnly' => true]);

        $this->assertSame(SchemaFingerprint::of($frozen), SchemaFingerprint::of($current));
    }

    public function test_a_nullable_scalar_matches_its_type_union(): void
    {
        $this->assertSame(
            SchemaFingerprint::of($this->order(['type' => 'string', 'nullable' => true])),
            SchemaFingerprint::of($this->order(['type' => ['string', 'null']])),
        );
    }

    public function test_a_nullable_any_of_gains_the_null_member(): void
    {
        $this->assertSame(
            SchemaFingerprint::of($this->order(['anyOf' => [['type' => 'string'], ['type' => 'integer']], 'nullable' => true])),
            SchemaFingerprint::of($this->order(['anyOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'null']]])),
        );
    }

    public function test_nullable_false_is_the_default_and_drops_out(): void
    {
        $this->assertSame(
            SchemaFingerprint::of($this->order(['$ref' => self::SPLIT, 'nullable' => false])),
            SchemaFingerprint::of($this->order(['$ref' => self::SPLIT])),
        );
    }

    public function test_losing_nullability_is_still_drift(): void
    {
        $this->assertNotSame(
            SchemaFingerprint::of($this->order(['$ref' => self::SPLIT, 'nullable' => true])),
            SchemaFingerprint::of($this->order(['$ref' => self::SPLIT])),
        );
    }

    public function test_a_different_ref_is_still_drift(): void
    {
        $this->assertNotSame(
            SchemaFingerprint::of($this->order(['$ref' => self::SPLIT, 'nullable' => true])),
            SchemaFingerprint::of($this->order(['anyOf' => [['$ref' => 'https://example.test/schemas/commerce/split/2'], ['type' => 'null']]])),
        );
    }

    public function test_the_inert_default_predicate_sees_through_the_encoding(): void
    {
        // The live case: Order v1 changed its nullable spelling AND gained inert `default`s in the same
        // window. Neither alone is drift, so the pair must not be either.
        $frozen = $this->order(['$ref' => self::SPLIT, 'nullable' => true]);
        $current = $this->order(['anyOf' => [['$ref' => self::SPLIT], ['type' => 'null']]]);
        $current['properties']['paid'] = ['type' => 'boolean', 'default' => false];
        $frozen['properties']['paid'] = ['type' => 'boolean'];

        $this->assertNotSame(SchemaFingerprint::of($frozen), SchemaFingerprint::of($current));
        $this->assertTrue(SchemaFingerprint::inertDefaultAdditionOnly($frozen, $current));
    }

    public function test_freezing_the_new_spelling_over_the_old_is_an_idempotent_no_op(): void
    {
        $dir = sys_get_temp_dir().'/ds-nullable-equivalence-'.getmypid();
        @mkdir($dir, 0777, true);

        try {
            $registry = new FilesystemSchemaRegistry($dir);
            $id = 'https://example.test/schemas/commerce/order/1';

            $registry->register(['$id' => $id] + $this->order(['$ref' => self::SPLIT, 'nullable' => true]));
            $registry->register(['$id' => $id] + $this->order(['anyOf' => [['$ref' => self::SPLIT], ['type' => 'null']]]));

            // Write-once: the stored artifact keeps the spelling it was frozen in.
            $this->assertTrue($registry->get($id)['properties']['split']['nullable']);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    /**
     * @param  array<string, mixed>  $split
     * @return array<string, mixed>
     */
    private function order(array $split): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'split' => $split,
            ],
            'required' => ['id', 'split'],
        ];
    }
}
