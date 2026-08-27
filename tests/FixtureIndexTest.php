<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\ClassKey;
use Schemastud\DataSchemas\Fixtures\FixtureIndex;

/**
 * ONE keyspace at TWO levels, which is what lets named states and positional hooks share a
 * registry without lying about their orderings.
 *
 *     schemas.fixtures.{shape}            the shape node — defaults + the ordered hook list
 *     schemas.fixtures.{shape}.{state}    one named state, addressable
 *
 * `PipelineRegistry` documents why they cannot be siblings: its stages *"are not addressable
 * entries — they have no keys, so the same stage class legitimately appears many times."* A state
 * must be addressable, because a caller names it. So states are child NODES composed in the order
 * the caller chains them, and hooks are a positional list on the parent composed in REGISTRATION
 * order.
 */
class FixtureIndexTest extends TestCase
{
    private function index(): FixtureIndex
    {
        return new FixtureIndex;
    }

    public function test_it_stamps_the_declared_root_onto_a_shape(): void
    {
        $index = $this->index()->defineShape('plans', fn () => ['name' => 'Starter']);

        $this->assertSame(['schemas.fixtures.plans'], array_map('strval', $index->keys()));
    }

    public function test_it_nests_states_under_their_shape(): void
    {
        $index = $this->index()
            ->defineShape('plans', fn () => ['name' => 'Starter'])
            ->defineState('plans', 'enterprise', fn (array $b) => ['name' => 'Enterprise']);

        $this->assertSame(['enterprise'], $index->statesOf('plans'));
    }

    /** The walk is segment-wise: `schemas.fixtures.plan` is not a child of `schemas.fixtures.plans`. */
    public function test_it_walks_segment_wise_not_by_string_prefix(): void
    {
        $index = $this->index()
            ->defineShape('plans', fn () => [])
            ->defineState('plans', 'enterprise', fn (array $b) => [])
            ->defineState('plan', 'decoy', fn (array $b) => []);

        $this->assertSame(['enterprise'], $index->statesOf('plans'));
    }

    public function test_it_resolves_defaults_states_and_hooks_separately(): void
    {
        $index = $this->index()
            ->defineShape('plans', fn () => ['name' => 'Starter'], hooks: [fn (array $a) => $a + ['stamped' => true]])
            ->defineState('plans', 'enterprise', fn (array $b) => ['name' => 'Enterprise']);

        $this->assertSame(['name' => 'Starter'], ($index->defaultsFor('plans'))());
        $this->assertSame(['name' => 'Enterprise'], ($index->stateFor('plans', 'enterprise'))([]));
        $this->assertCount(1, $index->hooksFor('plans'));
    }

    public function test_it_returns_null_for_an_unregistered_state_rather_than_throwing(): void
    {
        $index = $this->index()->defineShape('plans', fn () => []);

        $this->assertNull($index->stateFor('plans', 'nope'));
        $this->assertSame([], $index->hooksFor('never-defined'));
    }

    /**
     * Last-wins, and the kernel records what was displaced. Package providers boot before the app's,
     * so a host is the last registrant by construction — `Supersede` is what makes
     * host-overrides-package work with no ceremony, exactly like a container binding.
     */
    public function test_a_later_registration_supersedes_and_the_kernel_records_the_displacement(): void
    {
        $index = $this->index()
            ->defineShape('plans', fn () => [])
            ->defineState('plans', 'enterprise', fn (array $b) => ['by' => 'package'], by: 'a-package')
            ->defineState('plans', 'enterprise', fn (array $b) => ['by' => 'host'], by: 'the-host');

        $this->assertSame(['by' => 'host'], ($index->stateFor('plans', 'enterprise'))([]));

        $displaced = $index->supersededAt('schemas.fixtures.plans.enterprise');

        $this->assertCount(1, $displaced);
        $this->assertSame('a-package', $displaced[0]->by);
        // `sequence` is monotonic PER REGISTRY, not per key — `defineShape` took 0, so the state
        // this displaced is 1. That is the answer to "in what order across the whole registry",
        // which is strictly more than the hand-rolled provenance this replaced could record.
        $this->assertSame(1, $displaced[0]->sequence);
    }

    /** A shape with no better name falls back to `ClassKey` — the owner's call, and why it is not `Rootable`. */
    public function test_a_class_keyed_shape_carries_its_namespace(): void
    {
        $index = $this->index()->defineShape(
            (string) ClassKey::of('Splicewire\Beam\Commerce\Data\PlanEditData'),
            fn () => ['name' => 'Starter'],
        );

        $this->assertSame(
            ['schemas.fixtures.splicewire.beam.commerce.data.plan-edit-data'],
            array_map('strval', $index->keys()),
        );
    }
}
