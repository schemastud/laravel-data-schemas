<?php

namespace Schemastud\DataSchemas\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;

class MigrationKeywordPlacementTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/schema-keyword-placement-'.getmypid().'-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_top_level_property_may_declare_x_migrate_from(): void
    {
        $schema = $this->schema([
            'properties' => [
                'displayName' => ['type' => 'string', 'x-migrate-from' => 'full_name'],
            ],
        ]);

        $registry = new FilesystemSchemaRegistry($this->directory);
        $registry->register($schema);

        $this->assertSame($schema, $registry->get($schema['$id']));
    }

    public function test_instance_data_with_an_x_migrate_from_key_is_not_treated_as_a_schema_keyword(): void
    {
        $schema = $this->schema([
            'properties' => [
                'metadata' => [
                    'type' => 'object',
                    'default' => ['x-migrate-from' => 'ordinary instance value'],
                    'examples' => [['nested' => ['x-migrate-from' => 'also ordinary data']]],
                    'x-vendor-extension' => ['x-migrate-from' => 'vendor payload'],
                ],
            ],
        ]);

        $registry = new FilesystemSchemaRegistry($this->directory);
        $registry->register($schema);

        $this->assertSame($schema, $registry->get($schema['$id']));
    }

    public function test_registration_refuses_x_migrate_from_on_a_nested_object_property(): void
    {
        $schema = $this->schema([
            'properties' => [
                'profile' => ['type' => 'object', 'properties' => [
                    'displayName' => ['type' => 'string', 'x-migrate-from' => 'full_name'],
                ]],
            ],
        ]);

        try {
            (new FilesystemSchemaRegistry($this->directory))->register($schema);
            $this->fail('Expected nested x-migrate-from to be refused at registration.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('x-migrate-from keyword is top-level only', $exception->getMessage());
        }
    }

    public function test_registration_refuses_x_migrate_from_on_an_array_item_property(): void
    {
        $schema = $this->schema([
            'properties' => [
                'people' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'displayName' => ['type' => 'string', 'x-migrate-from' => 'full_name'],
                ]]],
            ],
        ]);

        try {
            (new FilesystemSchemaRegistry($this->directory))->register($schema);
            $this->fail('Expected array-item x-migrate-from to be refused at registration.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('x-migrate-from keyword is top-level only', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function schema(array $body): array
    {
        return ['$id' => 'https://schemas.test/ticket31/1', 'type' => 'object'] + $body;
    }
}
