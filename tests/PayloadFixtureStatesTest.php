<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Schemastud\DataSchemas\Fixtures\FixtureIndex;
use Schemastud\DataSchemas\Fixtures\PayloadState;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\DataSchemas\Tests\Fixtures\PayloadFixtureData;
use Spatie\LaravelData\LaravelDataServiceProvider;

class PayloadFixtureStatesTest extends TestCase
{
    private string $directory;

    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, LaravelDataSchemasServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/schema-payloads-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function index(): FixtureIndex
    {
        return app(FixtureIndex::class);
    }

    private function payload(string $name, array $attributes): string
    {
        $file = $this->directory.'/'.$name.'.json';
        file_put_contents($file, json_encode((object) $attributes, JSON_THROW_ON_ERROR));

        return $file;
    }

    public function test_payload_registration_builds_via_the_method_spelling(): void
    {
        $this->index()->definePayloadState('payloads', 'recorded-request', ['name' => 'Recorded'], by: 'a-recording', file: 'request.json');
        $this->assertSame('Recorded', PayloadFixtureData::factory()->recordedRequest()->make()->name);
    }

    public function test_directory_registers_each_file_with_given_registrant_and_verifies_it(): void
    {
        $this->payload('beta', ['name' => 'Beta']);
        $this->payload('alpha', ['name' => 'Alpha']);
        $index = $this->index()->loadPayloadStates('payloads', $this->directory, by: 'recording-123');
        $this->assertSame(['alpha', 'beta'], $index->statesOf('payloads'));
        $index->verifyPayloads('payloads', PayloadFixtureData::class);
        foreach (['alpha', 'beta'] as $state) {
            $index->defineState('payloads', $state, fn () => ['name' => 'Host'], by: 'host');
            $entry = $index->supersededAt('schemas.fixtures.payloads.'.$state)[0];
            $this->assertSame('recording-123', $entry->by);
            $this->assertInstanceOf(PayloadState::class, $entry->entry);
            $this->assertSame($this->directory.'/'.$state.'.json', $entry->entry->source);
        }
    }

    public function test_missing_required_property_fails_the_build_and_names_the_file(): void
    {
        $file = $this->payload('missing', []);
        $this->index()->loadPayloadStates('payloads', $this->directory);
        try {
            PayloadFixtureData::factory()->missing()->make();
            $this->fail('missing payload property should fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($file, $e->getMessage());
            $this->assertInstanceOf(ValidationException::class, $e->getPrevious());
        }
    }

    public function test_payload_alone_verification_cannot_be_repaired_by_defaults_or_hooks(): void
    {
        $file = $this->payload('drifted', []);
        $calls = 0;
        $defaults = function () use (&$calls) {
            $calls++;

            return ['name' => 'Default'];
        };
        $hook = function () use (&$calls) {
            $calls++;

            return ['name' => 'Hook'];
        };
        $index = $this->index()->defineShape('payloads', $defaults, [$hook])->loadPayloadStates('payloads', $this->directory);
        $this->assertSame('Hook', PayloadFixtureData::factory()->drifted()->make()->name);
        $calls = 0;
        try {
            $index->verifyPayloads('payloads', PayloadFixtureData::class);
            $this->fail('defaults/hooks must not mask payload drift');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($file, $e->getMessage());
            $this->assertInstanceOf(ValidationException::class, $e->getPrevious());
            $this->assertSame(0, $calls);
        }
    }

    public function test_payload_and_handwritten_states_compose_in_call_order_then_overrides_and_hooks(): void
    {
        $index = $this->index()->defineShape('payloads', fn () => ['name' => 'Default'])
            ->definePayloadState('payloads', 'recorded', ['name' => 'Recorded'])
            ->defineState('payloads', 'handwritten', fn () => ['name' => 'Hand']);
        $this->assertSame('Hand', PayloadFixtureData::factory()->recorded()->handwritten()->make()->name);
        $this->assertSame('Recorded', PayloadFixtureData::factory()->handwritten()->recorded()->make()->name);
        $this->assertSame('Override', PayloadFixtureData::factory()->recorded()->make(['name' => 'Override'])->name);
        $index->defineShape('payloads', fn () => [], [fn ($a) => ['name' => $a['name'].'-hook']]);
        $this->assertSame('Override-hook', PayloadFixtureData::factory()->recorded()->make(['name' => 'Override'])->name);
    }

    public function test_host_supersedes_a_payload_and_displaced_payload_remains_readable(): void
    {
        $index = $this->index()->definePayloadState('payloads', 'recorded', ['name' => 'Package'], by: 'package', file: 'package.json')
            ->defineState('payloads', 'recorded', fn () => ['name' => 'Host'], by: 'host');
        $this->assertSame('Host', PayloadFixtureData::factory()->recorded()->make()->name);
        $old = $index->supersededAt('schemas.fixtures.payloads.recorded')[0];
        $this->assertSame('package', $old->by);
        $this->assertSame(['name' => 'Package'], $old->entry->attributes);
        $this->assertSame('package.json', $old->entry->source);
    }

    public function test_payloads_validate_by_default_even_when_type_hydration_would_accept_them(): void
    {
        $this->index()->definePayloadState('payloads', 'invalid', ['name' => ''], file: 'invalid.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid.json');
        PayloadFixtureData::factory()->invalid()->make();
    }

    public function test_explicit_validation_opt_out_still_works_before_or_after_state_selection(): void
    {
        $this->index()->definePayloadState('payloads', 'invalid', ['name' => '']);
        $this->assertSame('', PayloadFixtureData::factory()->withoutValidation()->invalid()->make()->name);
        $this->assertSame('', PayloadFixtureData::factory()->invalid()->withoutValidation()->make()->name);
    }

    public function test_invalid_directory_input_names_the_file_without_partially_registering(): void
    {
        $this->payload('a-valid', ['name' => 'Valid']);
        $bad = $this->directory.'/z-invalid.json';
        file_put_contents($bad, '{broken');
        try {
            $this->index()->loadPayloadStates('payloads', $this->directory);
            $this->fail('invalid JSON should refuse');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($bad, $e->getMessage());
            $this->assertSame([], $this->index()->statesOf('payloads'));
        }
    }

    public function test_lists_are_not_attribute_objects_and_unreadable_directory_is_reported(): void
    {
        $file = $this->directory.'/list.json';
        file_put_contents($file, '[1,2]');
        try {
            $this->index()->loadPayloadStates('payloads', $this->directory);
            $this->fail('list should refuse');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($file, $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($this->directory.'/missing');
        $this->index()->loadPayloadStates('payloads', $this->directory.'/missing');
    }
}
