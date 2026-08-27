<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Schemastud\DataSchemas\Fixtures\FixtureFactory;
use Schemastud\DataSchemas\Fixtures\FixtureIndex;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\FixturePlanData;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * The factory EXTENDS `Spatie\LaravelData\Support\Creation\CreationContextFactory` rather than
 * shadowing `::factory()`. Three facts from the vendored source make that available: the class is
 * not `final`, every fluent setter `return $this` so a subclass survives the whole chain, and only
 * the two static builders `return new self(...)` — which is why {@see FixtureFactory::promote()}
 * exists to adopt a spatie instance.
 *
 * The payoff is {@see test_spatie_creation_config_applies_to_a_fixture_build}: `make()` builds
 * through `parent::from()`, so a validation strategy configured on the SAME object governs the
 * fixture. A factory sitting beside spatie's would silently ignore it.
 */
class FixtureFactoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    private function forge(FixtureIndex $index): FixtureFactory
    {
        return FixtureFactory::promote(FixturePlanData::factory(), 'plans', $index);
    }

    private function index(): FixtureIndex
    {
        return (new FixtureIndex)
            ->defineShape('plans', fn () => ['name' => 'Starter', 'slug' => 'starter'])
            ->defineState('plans', 'enterprise', fn (array $b) => ['name' => 'Enterprise'])
            ->defineState('plans', 'budget-capped', fn (array $b) => ['limit' => 99.0]);
    }

    public function test_it_builds_from_the_shape_defaults(): void
    {
        $plan = $this->forge($this->index())->make();

        $this->assertSame('Starter', $plan->name);
    }

    public function test_a_named_state_overrides_the_defaults(): void
    {
        $plan = $this->forge($this->index())->state('enterprise')->make();

        $this->assertSame('Enterprise', $plan->name);
    }

    /** `->budgetCapped()` resolves the registered `budget-capped`. */
    public function test_the_magic_method_maps_a_camel_name_onto_a_registered_key(): void
    {
        $plan = $this->forge($this->index())->budgetCapped()->make();

        $this->assertSame(99.0, $plan->limit);
    }

    /**
     * A `__call` that quietly returns `$this` is the worst version of this estate's recurring defect:
     * the object still constructs and the test still passes with the state never applied.
     */
    public function test_an_unknown_state_throws_and_names_the_ones_that_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/enterprise/');

        $this->forge($this->index())->noSuchState()->make();
    }

    public function test_states_compose_in_the_order_the_caller_chains_them(): void
    {
        $index = $this->index()->defineState('plans', 'renamed', fn (array $b) => ['name' => 'Renamed']);

        $this->assertSame('Renamed', $this->forge($index)->state('enterprise')->state('renamed')->make()->name);
        $this->assertSame('Enterprise', $this->forge($index)->state('renamed')->state('enterprise')->make()->name);
    }

    public function test_hooks_run_after_states_in_registration_order(): void
    {
        $index = (new FixtureIndex)->defineShape('plans', fn () => ['name' => 'Starter', 'slug' => 's'], hooks: [
            fn (array $a) => ['name' => $a['name'].'-one'],
            fn (array $a) => ['name' => $a['name'].'-two'],
        ]);

        $this->assertSame('Starter-one-two', $this->forge($index)->make()->name);
    }

    public function test_count_and_sequence_cycle_attribute_sets(): void
    {
        $plans = $this->forge($this->index())->count(3)->sequence(['slug' => 'a'], ['slug' => 'b'])->make();

        $this->assertSame(['a', 'b', 'a'], array_map(fn ($p) => $p->slug, $plans));
    }

    /** The whole reason to extend rather than sit beside spatie's factory. */
    public function test_spatie_creation_config_applies_to_a_fixture_build(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->forge($this->index())->alwaysValidate()->make(['name' => '']);
    }

    /** Spatie's own setters must not degrade the chain — these are re-declared covariantly. */
    public function test_a_spatie_setter_returns_the_fixture_factory_so_the_chain_survives(): void
    {
        $this->assertInstanceOf(FixtureFactory::class, $this->forge($this->index())->withoutValidation());
    }
}
