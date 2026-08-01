<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Invocables\LocalInvocable;
use Schemastud\DataSchemas\Migration\MigrationLadder;
use Schemastud\DataSchemas\Migration\Source\ForeignSource;
use Schemastud\DataSchemas\Migration\Source\SourceCast;
use Schemastud\DataSchemas\Migration\Source\SourcePath;
use Schemastud\DataSchemas\Migration\TransformRegistry;

/**
 * Pure unit tests for the `x-source` projection dialect (ticket 04). Framework-free
 * — no testbench boot — since the rung is pure array→array.
 */
class SourceProjectionRungTest extends TestCase
{
    // --- SourcePath grammar -------------------------------------------------

    public function test_dot_path_extracts_a_nested_object_value(): void
    {
        $foreign = ['author' => ['name' => 'Ada']];

        $this->assertSame('Ada', SourcePath::extract($foreign, 'author.name'));
    }

    public function test_integer_segment_indexes_a_list(): void
    {
        $foreign = ['meta' => ['tags' => ['red', 'green', 'blue']]];

        $this->assertSame('red', SourcePath::extract($foreign, 'meta.tags.0'));
        $this->assertSame('blue', SourcePath::extract($foreign, 'meta.tags.2'));
    }

    public function test_absent_path_returns_the_missing_sentinel(): void
    {
        $this->assertSame(SourcePath::MISSING, SourcePath::extract(['a' => 1], 'a.b.c'));
        $this->assertSame(SourcePath::MISSING, SourcePath::extract(['a' => 1], 'zzz'));
    }

    public function test_empty_path_is_identity(): void
    {
        $this->assertSame(['a' => 1], SourcePath::extract(['a' => 1], ''));
    }

    public function test_path_descends_stdclass_objects(): void
    {
        $foreign = (object) ['author' => (object) ['name' => 'Grace']];

        $this->assertSame('Grace', SourcePath::extract($foreign, 'author.name'));
    }

    // --- SourceCast vocabulary ---------------------------------------------

    public function test_each_cast_in_the_vocabulary(): void
    {
        $this->assertSame('7', SourceCast::apply('string', 7));
        $this->assertSame(7, SourceCast::apply('int', '7'));
        $this->assertSame(7.5, SourceCast::apply('float', '7.5'));
        $this->assertTrue(SourceCast::apply('bool', 'yes'));
        $this->assertFalse(SourceCast::apply('bool', 'false'));
        $this->assertFalse(SourceCast::apply('bool', '0'));
        $this->assertSame('trimmed', SourceCast::apply('trim', '  trimmed  '));
    }

    public function test_cast_passes_null_through_unchanged(): void
    {
        $this->assertNull(SourceCast::apply('int', null));
        $this->assertNull(SourceCast::apply('string', null));
    }

    public function test_vocabulary_is_the_documented_fixed_set(): void
    {
        $this->assertSame(['string', 'int', 'float', 'bool', 'trim'], SourceCast::vocabulary());
        $this->assertFalse(SourceCast::knows('json'));
        $this->assertFalse(SourceCast::knows('eval'));
    }

    // --- Projection through the ladder -------------------------------------

    private function targetSchema(array $overrides = []): array
    {
        return array_replace([
            '$id' => 'x://particle/1',
            'type' => 'object',
        ], $overrides);
    }

    public function test_projects_nested_path_and_casts_into_the_target(): void
    {
        $target = $this->targetSchema([
            'properties' => [
                'displayName' => ['type' => 'string', 'minLength' => 1, 'x-source' => ['path' => 'author.name', 'cast' => 'trim']],
                'stars' => ['type' => 'integer', 'x-source' => ['path' => 'rating.value', 'cast' => 'int']],
            ],
            'required' => ['displayName', 'stars'],
        ]);

        $foreign = ['author' => ['name' => '  Ada  '], 'rating' => ['value' => '5']];

        $result = MigrationLadder::forForeignSource()->project($foreign, $target);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('source-projection', $result->rung);
        $this->assertSame(['displayName' => 'Ada', 'stars' => 5], $result->migrated);
    }

    public function test_default_fills_an_absent_path(): void
    {
        $target = $this->targetSchema([
            'properties' => [
                'displayName' => ['type' => 'string', 'minLength' => 1, 'x-source' => ['path' => 'author.name', 'default' => 'anon']],
            ],
            'required' => ['displayName'],
        ]);

        // author.name is absent -> default kicks in.
        $result = MigrationLadder::forForeignSource()->project(['title' => 'x'], $target);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame(['displayName' => 'anon'], $result->migrated);
    }

    public function test_structural_floor_fills_a_non_sourced_field_from_its_default(): void
    {
        // `active` has NO x-source; the projection rung fills it from the schema
        // default (the required-fields floor, reused from the structural rung).
        $target = $this->targetSchema([
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'x-source' => ['path' => 'n']],
                'active' => ['type' => 'boolean', 'default' => true],
            ],
            'required' => ['name', 'active'],
        ]);

        $result = MigrationLadder::forForeignSource()->project(['n' => 'Ada'], $target);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame(['name' => 'Ada', 'active' => true], $result->migrated);
    }

    public function test_missing_required_after_projection_quarantines_not_silent(): void
    {
        // The sourced path is absent and there is no default, so displayName lands
        // as an empty string, failing minLength+required -> the gate rejects, the
        // custom rung has nothing, and the ladder QUARANTINES.
        $target = $this->targetSchema([
            'properties' => [
                'displayName' => ['type' => 'string', 'minLength' => 1, 'x-source' => ['path' => 'author.name']],
            ],
            'required' => ['displayName'],
        ]);

        $foreign = ['unrelated' => 'value'];

        $result = MigrationLadder::forForeignSource()->project($foreign, $target);

        $this->assertFalse($result->wasMigrated());
        $this->assertTrue($result->quarantined);
        // Original foreign payload preserved verbatim.
        $this->assertSame($foreign, $result->original);
    }

    public function test_unschematized_target_cannot_enter_the_ladder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // No `$id` -> unschematized -> ephemeral-only, may not enter the ladder.
        MigrationLadder::forForeignSource()->project(['a' => 1], [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'integer', 'x-source' => ['path' => 'a']]],
        ]);
    }

    public function test_value_only_custom_transform_can_express_routes_to_the_custom_rung(): void
    {
        // A slug derived from a title by lowercasing + hyphenation is NOT expressible
        // by path+cast; it carries no x-source, so the projection rung abstains
        // (no x-source anywhere) and the custom-transform escape hatch runs.
        $target = $this->targetSchema([
            'properties' => [
                'slug' => ['type' => 'string', 'minLength' => 1, 'x-migrate' => 'slugger'],
            ],
            'required' => ['slug'],
        ]);

        $transforms = (new TransformRegistry)->registerName('slugger', new LocalInvocable(
            'slugger',
            fn (array $in) => ['slug' => str_replace(' ', '-', strtolower($in['title']))],
        ));

        $result = MigrationLadder::forForeignSource($transforms)->project(['title' => 'Hello World'], $target);

        $this->assertTrue($result->wasMigrated());
        $this->assertSame('custom-transform', $result->rung);
        $this->assertSame(['slug' => 'hello-world'], $result->migrated);
    }

    public function test_out_of_grammar_cast_is_not_treated_as_a_valid_x_source(): void
    {
        // An unknown cast ('json') means the field is NOT a valid x-source; it is
        // left to the structural floor (typed empty), which fails required here ->
        // quarantine rather than silently mis-coercing.
        $target = $this->targetSchema([
            'properties' => [
                'blob' => ['type' => 'string', 'minLength' => 1, 'x-source' => ['path' => 'data', 'cast' => 'json']],
            ],
            'required' => ['blob'],
        ]);

        $result = MigrationLadder::forForeignSource()->project(['data' => 'x'], $target);

        $this->assertTrue($result->quarantined);
    }

    public function test_foreign_source_sentinel_marks_the_from_descriptor(): void
    {
        $this->assertTrue(ForeignSource::isForeign(ForeignSource::descriptor()));
        $this->assertFalse(ForeignSource::isForeign(['$id' => 'x://p/1']));
    }

    public function test_foreign_source_ladder_has_projection_then_custom_rungs(): void
    {
        $this->assertSame(
            ['source-projection', 'custom-transform'],
            MigrationLadder::forForeignSource()->rungs(),
        );
    }

    public function test_no_splicewire_reference_in_the_source_dialect_files(): void
    {
        // Topology R1: the open foundation must not depend UP on splicewire/beam.
        // Detect an actual code reference (a `use Splicewire\…` import or a
        // `Splicewire\…::`/`new Splicewire\…` usage) — prose mentions in docblocks
        // (e.g. "no `Splicewire\*` type is referenced") are fine.
        $codeRef = '/(?:^\s*use\s+Splicewire\\\\|new\s+Splicewire\\\\|Splicewire\\\\[A-Za-z_][\w\\\\]*::)/m';

        $files = array_merge(
            glob(__DIR__.'/../src/Migration/Source/*.php') ?: [],
            [__DIR__.'/../src/Migration/Rungs/SourceProjectionRung.php'],
        );
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression($codeRef, file_get_contents($file), "$file must not reference a Splicewire type");
        }
    }
}
