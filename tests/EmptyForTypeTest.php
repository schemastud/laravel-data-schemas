<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Schemastud\DataSchemas\Migration\MigrationRequest;
use Schemastud\DataSchemas\Migration\MigrationRung;
use Schemastud\DataSchemas\Migration\Rungs\CustomTransformRung;
use Schemastud\DataSchemas\Migration\Rungs\DeclaredMappingRung;
use Schemastud\DataSchemas\Migration\Rungs\LlmTryRung;
use Schemastud\DataSchemas\Migration\Rungs\SourceProjectionRung;
use Schemastud\DataSchemas\Migration\Rungs\StructuralRung;

/**
 * Ticket 120 — emptyForType() has ONE home, on MigrationRung, and lifting it
 * there changed nothing. The three former copies were byte-identical modulo a
 * dead `'null' => null` arm that `default => null` already covered.
 */
class EmptyForTypeTest extends TestCase
{
    protected function call(array $prop): mixed
    {
        $rung = new class extends MigrationRung
        {
            protected function propose(MigrationRequest $request): ?array
            {
                return null;
            }

            public function name(): string
            {
                return 'probe';
            }

            public function empty(array $prop): mixed
            {
                return $this->emptyForType($prop);
            }
        };

        return $rung->empty($prop);
    }

    public static function typeArms(): array
    {
        return [
            'string' => [['type' => 'string'], ''],
            'integer' => [['type' => 'integer'], 0],
            'number' => [['type' => 'number'], 0],
            'boolean' => [['type' => 'boolean'], false],
            'array' => [['type' => 'array'], []],
            'null' => [['type' => 'null'], null],
            'unknown type falls back' => [['type' => 'wat'], null],
            'absent type falls back' => [[], null],
            'nullable union prefers the concrete member' => [['type' => ['null', 'string']], ''],
            'union takes the first member' => [['type' => ['integer', 'null']], 0],
            'null-only union stays null' => [['type' => ['null']], null],
        ];
    }

    #[DataProvider('typeArms')]
    public function test_every_type_arm_is_unchanged(array $prop, mixed $expected): void
    {
        $this->assertSame($expected, $this->call($prop));
    }

    public function test_object_arm_yields_an_empty_stdclass(): void
    {
        $this->assertEquals((object) [], $this->call(['type' => 'object']));
    }

    public function test_the_helper_lives_only_on_the_base(): void
    {
        $rungs = [
            StructuralRung::class,
            DeclaredMappingRung::class,
            SourceProjectionRung::class,
            CustomTransformRung::class,
            LlmTryRung::class,
        ];

        foreach ($rungs as $rung) {
            $this->assertSame(
                MigrationRung::class,
                (new ReflectionMethod($rung, 'emptyForType'))->getDeclaringClass()->getName(),
                "{$rung} re-declares emptyForType() — it must inherit the one on MigrationRung.",
            );
        }
    }
}
