<?php

namespace Schemastud\DataSchemas\Tests;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Generators\MissingSchemaBaseUri;

/**
 * The missing-authority failure used to surface over HTTP as a bare 500 that hid the exact fix its
 * own message carries (launch 00, 2026-10-18: a fresh starter's Studio request). The exception now
 * renders itself: 503, actionable text intact — misconfiguration, not a server fault.
 */
class MissingSchemaBaseUriRenderTest extends TestCase
{
    public function test_a_json_request_gets_a_503_that_names_the_env_line(): void
    {
        $e = new MissingSchemaBaseUri(\stdClass::class);

        $request = Request::create('/api/probe', 'GET');
        $request->headers->set('Accept', 'application/json');

        $response = $e->render($request);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('SCHEMA_BASE_URI', $response->getContent());
        $this->assertStringContainsString('data-schemas.base_uri', $response->getContent());
    }

    public function test_a_plain_request_gets_the_actionable_text_not_a_bare_500(): void
    {
        $e = new MissingSchemaBaseUri(\stdClass::class, 'SCHEMA_BASE_URI=https://example.test/schemas');

        $response = $e->render(Request::create('/probe', 'GET'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('SCHEMA_BASE_URI=https://example.test/schemas', $response->getContent());
    }
}
