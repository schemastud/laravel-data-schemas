<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Support\OpenApi;

class OpenApiTest extends TestCase
{
    public function test_it_hoists_defs_and_rewrites_refs(): void
    {
        $document = [
            'type' => 'object',
            'title' => 'SampleData',
            'properties' => [
                'user' => ['$ref' => '#/$defs/UserData'],
                'collaborators' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/$defs/UserData'],
                ],
            ],
            '$defs' => [
                'UserData' => [
                    'type' => 'object',
                    'properties' => [
                        'manager' => ['$ref' => '#/$defs/UserData'],
                    ],
                ],
            ],
        ];

        $result = OpenApi::toOpenApiComponents($document);

        $this->assertArrayNotHasKey('$defs', $result);
        $this->assertSame('#/components/schemas/UserData', $result['properties']['user']['$ref']);
        $this->assertSame('#/components/schemas/UserData', $result['properties']['collaborators']['items']['$ref']);

        // Defs hoisted, and refs inside defs rewritten too.
        $this->assertArrayHasKey('UserData', $result['components']['schemas']);
        $this->assertSame(
            '#/components/schemas/UserData',
            $result['components']['schemas']['UserData']['properties']['manager']['$ref']
        );
    }

    public function test_it_is_idempotent_on_a_document_without_defs(): void
    {
        $document = [
            'type' => 'object',
            'title' => 'Flat',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $result = OpenApi::toOpenApiComponents($document);

        $this->assertSame($document, $result);
        $this->assertArrayNotHasKey('components', $result);
    }
}
