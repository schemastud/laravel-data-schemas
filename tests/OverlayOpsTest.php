<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Overlay\OverlayStack;

class OverlayOpsTest extends TestCase
{
    private function fold(array $actions, array $base): array
    {
        return (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => $actions],
        ]))->apply($base);
    }

    public function test_merge_deep_folds_nested_objects(): void
    {
        $result = $this->fold(
            [['target' => '$.field', 'merge' => ['x-widget' => 'email', 'options' => ['clearable' => true]]]],
            ['field' => ['type' => 'string', 'options' => ['dense' => true]]],
        );

        $this->assertSame([
            'field' => [
                'type' => 'string',
                'options' => ['dense' => true, 'clearable' => true],
                'x-widget' => 'email',
            ],
        ], $result);
    }

    public function test_merge_recursion_stops_at_scalars(): void
    {
        $result = $this->fold(
            [['target' => '$', 'merge' => ['a' => 5]]],
            ['a' => ['nested' => true]],
        );

        // A scalar patch value replaces the subtree — no recursion into it.
        $this->assertSame(['a' => 5], $result);
    }

    public function test_merge_replaces_arrays_wholesale(): void
    {
        $result = $this->fold(
            [['target' => '$', 'merge' => ['tags' => ['x', 'y']]]],
            ['tags' => ['a', 'b', 'c']],
        );

        $this->assertSame(['tags' => ['x', 'y']], $result);
    }

    public function test_array_element_edit_via_jsonpath_filter(): void
    {
        $base = ['items' => [
            ['id' => 'a', 'label' => 'A'],
            ['id' => 'b', 'label' => 'B'],
        ]];

        $result = $this->fold(
            [['target' => "$.items[?(@.id=='b')]", 'merge' => ['label' => 'edited']]],
            $base,
        );

        $this->assertSame('A', $result['items'][0]['label']);
        $this->assertSame('edited', $result['items'][1]['label']);
        $this->assertSame('b', $result['items'][1]['id']);
    }

    public function test_unset_deletes_exactly_the_targeted_node(): void
    {
        $result = $this->fold(
            [['target' => '$.properties.secret', 'unset' => true]],
            ['properties' => ['keep' => ['type' => 'string'], 'secret' => ['type' => 'string']]],
        );

        $this->assertSame(['properties' => ['keep' => ['type' => 'string']]], $result);
    }

    public function test_unset_does_not_cascade_leaving_a_dangling_required(): void
    {
        $base = [
            'required' => ['email'],
            'properties' => ['email' => ['type' => 'string']],
        ];

        $result = $this->fold(
            [['target' => '$.properties.email', 'unset' => true]],
            $base,
        );

        // Dumb delete: 'email' is gone from properties, but the 'required' entry
        // survives untouched — the author must scrub it with a companion unset.
        $this->assertSame(['email'], $result['required']);
        $this->assertArrayNotHasKey('email', $result['properties']);
    }

    public function test_unset_companion_action_scrubs_the_dangling_required(): void
    {
        $base = [
            'required' => ['name', 'email'],
            'properties' => ['name' => [], 'email' => []],
        ];

        $result = $this->fold(
            [
                ['target' => '$.properties.email', 'unset' => true],
                ['target' => "$.required[?(@=='email')]", 'unset' => true],
            ],
            $base,
        );

        $this->assertSame(['name'], array_values($result['required']));
        $this->assertArrayNotHasKey('email', $result['properties']);
    }

    public function test_unset_reindexes_a_list_so_it_stays_an_array(): void
    {
        $result = $this->fold(
            [['target' => '$.tags[1]', 'unset' => true]],
            ['tags' => ['a', 'b', 'c']],
        );

        $this->assertSame(['tags' => ['a', 'c']], $result);
    }

    public function test_merge_and_override_compose_at_different_depths(): void
    {
        $base = ['field' => ['type' => 'string', 'options' => ['dense' => true]]];

        $result = $this->fold(
            [
                ['target' => '$.field', 'merge' => ['options' => ['clearable' => true]]],
                ['target' => '$.field.options', 'override' => ['fresh' => true]],
            ],
            $base,
        );

        // merge folded clearable in; the later override at the deeper path
        // replaced the whole options subtree wholesale.
        $this->assertSame(['fresh' => true], $result['field']['options']);
    }

    public function test_fold_is_idempotent_under_re_application(): void
    {
        $actions = [
            ['target' => '$.field', 'merge' => ['x-widget' => 'email']],
            ['target' => '$.field.legacy', 'unset' => true],
        ];
        $base = ['field' => ['type' => 'string', 'legacy' => true]];

        $once = $this->fold($actions, $base);
        $twice = $this->fold($actions, $once);

        $this->assertSame($once, $twice);
    }
}
