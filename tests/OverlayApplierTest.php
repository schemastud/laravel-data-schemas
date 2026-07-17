<?php

namespace Schemastud\DataSchemas\Tests;

use Orchestra\Testbench\TestCase;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Overlay\InstanceOverlayApplier;
use Schemastud\DataSchemas\Overlay\OverlayStack;
use Schemastud\DataSchemas\Overlay\SchemaOverlayApplier;
use Schemastud\DataSchemas\Tests\Fixtures\SampleWidgetData;
use Spatie\LaravelData\LaravelDataServiceProvider;

// Boots a minimal app so spatie's Data::from (container-backed) works for the
// opt-in hydration assertions.
class OverlayApplierTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
        ];
    }

    private function stack(array $actions): OverlayStack
    {
        return new OverlayStack([['overlay' => '1.0.0', 'actions' => $actions]]);
    }

    public function test_schema_applier_folds_a_schema_and_returns_an_array(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['email' => ['type' => 'string']],
        ];

        $result = (new SchemaOverlayApplier(
            $schema,
            $this->stack([['target' => "$.properties.email['x-widget']", 'override' => 'email-input']]),
        ))->apply();

        $this->assertSame('email-input', $result['properties']['email']['x-widget']);
        $this->assertIsArray($result);
    }

    public function test_instance_applier_folds_a_plain_array(): void
    {
        $result = (new InstanceOverlayApplier(
            ['label' => 'Name', 'widget' => 'text'],
            $this->stack([['target' => '$.widget', 'override' => 'email']]),
        ))->apply();

        $this->assertSame(['label' => 'Name', 'widget' => 'email'], $result);
    }

    public function test_instance_applier_folds_a_data_object(): void
    {
        $result = (new InstanceOverlayApplier(
            new SampleWidgetData('Name', 'text'),
            $this->stack([['target' => '$.widget', 'override' => 'email']]),
        ))->apply();

        $this->assertSame(['label' => 'Name', 'widget' => 'email'], $result);
    }

    public function test_opt_in_hydration_round_trips_a_valid_result(): void
    {
        $hydrated = (new InstanceOverlayApplier(
            new SampleWidgetData('Name', 'text'),
            $this->stack([['target' => '$.widget', 'override' => 'email']]),
        ))->hydrate(SampleWidgetData::class);

        $this->assertInstanceOf(SampleWidgetData::class, $hydrated);
        $this->assertSame('email', $hydrated->widget);
    }

    public function test_unset_shaped_result_stays_an_array_and_is_not_forced_to_hydrate(): void
    {
        $applier = new InstanceOverlayApplier(
            new SampleWidgetData('Name', 'text'),
            $this->stack([['target' => '$.widget', 'unset' => true]]),
        );

        // apply() returns the unset-shaped array even though it no longer
        // satisfies the DTO (the 'widget' field is gone).
        $this->assertSame(['label' => 'Name'], $applier->apply());

        // Hydrating that shape would fail — which is exactly why hydration is
        // opt-in, not the default return.
        $this->expectException(\Throwable::class);
        $applier->hydrate(SampleWidgetData::class);
    }
}
