<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Tests\Fixtures\ComputedFieldData;

/**
 * A spatie #[Computed] property is output-only — spatie never fills it from
 * input — so the request/form schema must drop it, while the response and
 * collapsed (default) schemas keep it. The `.d.ts` pipeline is spatie's own
 * transformer and is unaffected by this generator, so the property still types.
 */
class ComputedRequestModeTest extends TestCase
{
    private function generate(JsonSchemaGenerator $generator): array
    {
        return $generator->generate(new ReflectionClass(ComputedFieldData::class));
    }

    public function test_for_request_drops_computed_properties(): void
    {
        $schema = $this->generate((new JsonSchemaGenerator)->forRequest());

        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayNotHasKey('slug', $schema['properties']);
        // A dropped property is not required either.
        $this->assertNotContains('slug', $schema['required'] ?? []);
    }

    public function test_for_response_keeps_computed_properties(): void
    {
        $schema = $this->generate((new JsonSchemaGenerator)->forResponse());

        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('slug', $schema['properties']);
    }

    public function test_collapsed_default_mode_keeps_computed_properties(): void
    {
        $schema = $this->generate(new JsonSchemaGenerator);

        $this->assertArrayHasKey('slug', $schema['properties']);
    }
}
