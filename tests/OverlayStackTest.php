<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Overlay\OverlayException;
use Schemastud\DataSchemas\Overlay\OverlayStack;

class OverlayStackTest extends TestCase
{
    public function test_single_override_replaces_the_targeted_value(): void
    {
        $base = ['title' => 'Old', 'keep' => true];

        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.title', 'override' => 'New'],
            ]],
        ]))->apply($base);

        $this->assertSame(['title' => 'New', 'keep' => true], $result);
    }

    public function test_override_replaces_wholesale_without_recursing(): void
    {
        $base = ['field' => ['type' => 'string', 'x-widget' => 'text']];

        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.field', 'override' => ['type' => 'integer']],
            ]],
        ]))->apply($base);

        // The whole subtree is replaced — the old 'x-widget' key is gone.
        $this->assertSame(['field' => ['type' => 'integer']], $result);
    }

    public function test_actions_fold_in_array_order_last_writer_wins(): void
    {
        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.status', 'override' => 'first'],
                ['target' => '$.status', 'override' => 'second'],
            ]],
        ]))->apply(['status' => 'base']);

        $this->assertSame('second', $result['status']);
    }

    public function test_an_action_sees_the_effect_of_earlier_actions(): void
    {
        // Create a node, then override a child of it in a later action.
        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.meta', 'override' => ['a' => 1]],
                ['target' => '$.meta.b', 'override' => 2],
            ]],
        ]))->apply([]);

        $this->assertSame(['meta' => ['a' => 1, 'b' => 2]], $result);
    }

    public function test_documents_fold_in_stack_order_last_writer_wins(): void
    {
        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [['target' => '$.label', 'override' => 'from-first']]],
            ['overlay' => '1.0.0', 'actions' => [['target' => '$.label', 'override' => 'from-second']]],
        ]))->apply(['label' => 'base']);

        $this->assertSame('from-second', $result['label']);
    }

    public function test_create_absent_upsert_on_a_concrete_target(): void
    {
        $base = ['properties' => ['email' => ['type' => 'string']]];

        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => "$.properties.email['x-widget']", 'override' => 'email-input'],
            ]],
        ]))->apply($base);

        $this->assertSame('email-input', $result['properties']['email']['x-widget']);
        $this->assertSame('string', $result['properties']['email']['type']);
    }

    public function test_create_absent_builds_missing_intermediate_containers(): void
    {
        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.a.b.c', 'override' => 'deep'],
            ]],
        ]))->apply([]);

        $this->assertSame(['a' => ['b' => ['c' => 'deep']]], $result);
    }

    public function test_multi_node_override_hits_every_matching_node(): void
    {
        $base = ['items' => [
            ['id' => 'a', 'flag' => false],
            ['id' => 'b', 'flag' => false],
        ]];

        $result = (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.items[*].flag', 'override' => true],
            ]],
        ]))->apply($base);

        $this->assertTrue($result['items'][0]['flag']);
        $this->assertTrue($result['items'][1]['flag']);
    }

    public function test_override_no_match_on_non_concrete_target_errors_clearly(): void
    {
        $this->expectException(OverlayException::class);
        $this->expectExceptionMessage('create-absent requires a concrete');

        (new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.items[*].missing', 'override' => 'x'],
            ]],
        ]))->apply(['items' => [['id' => 'a']]]);
    }

    public function test_action_with_no_op_key_is_rejected(): void
    {
        $this->expectException(OverlayException::class);
        $this->expectExceptionMessage('exactly one op key');

        new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [['target' => '$.a']]],
        ]);
    }

    public function test_action_with_two_op_keys_is_rejected(): void
    {
        $this->expectException(OverlayException::class);
        $this->expectExceptionMessage('exactly one op key');

        new OverlayStack([
            ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.a', 'override' => 1, 'unset' => true],
            ]],
        ]);
    }

    public function test_document_without_actions_array_is_rejected(): void
    {
        $this->expectException(OverlayException::class);
        $this->expectExceptionMessage('"actions" array');

        new OverlayStack([['overlay' => '1.0.0']]);
    }

    public function test_empty_stack_returns_the_base_unchanged(): void
    {
        $base = ['a' => 1, 'b' => ['c' => 2]];

        $this->assertSame($base, (new OverlayStack)->apply($base));
    }
}
