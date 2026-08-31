<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Schemastud\DataSchemas\Migration\MigrationRequest;
use Schemastud\DataSchemas\Migration\MigrationRung;

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

    /**
     * The ratchet against a copy of `emptyForType()` reappearing on a rung.
     *
     * The rung list is DISCOVERED, not spelled. An earlier form of this test named the five rungs as
     * literals, which meant a SIXTH rung shipping its own copy — the exact regression the lift exists to
     * prevent — was invisible to it: the copy would have to land inside one of the five already listed
     * to be caught. Reading the directory is what makes the population the real one.
     */
    public function test_the_helper_lives_only_on_the_base(): void
    {
        $files = glob(__DIR__.'/../src/Migration/Rungs/*.php');

        $rungs = [];

        foreach ($files as $file) {
            $class = 'Schemastud\\DataSchemas\\Migration\\Rungs\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, MigrationRung::class)) {
                $rungs[] = $class;
            }
        }

        // A glob that matches nothing passes vacuously, which is the failure mode this whole file was
        // written against. Five is the count at the time of writing; a sixth rung should RAISE this.
        $this->assertGreaterThanOrEqual(5, count($rungs), 'No rungs discovered — the ratchet is vacuous.');

        foreach ($rungs as $rung) {
            $this->assertSame(
                MigrationRung::class,
                (new ReflectionMethod($rung, 'emptyForType'))->getDeclaringClass()->getName(),
                "{$rung} re-declares emptyForType() — it must inherit the one on MigrationRung.",
            );
        }
    }
}
