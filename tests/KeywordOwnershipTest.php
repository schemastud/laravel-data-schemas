<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Keywords;
use Schemastud\DataSchemas\Strategies\MigrationAttributesStrategy;
use Schemastud\DataSchemas\Tests\Fixtures\MigratableProfileData;
use Schemastud\DataSchemas\Tests\Fixtures\SampleData;

/**
 * Ownership guard (JSON-LD `@context` model): this package may only EMIT `x-` extension
 * keywords it declares in {@see Keywords}. If the generator or a strategy ever adds an
 * undeclared `x-foo`, this fails — enforcement local to the owning package, no central list.
 */
class KeywordOwnershipTest extends TestCase
{
    public function test_the_generator_only_emits_x_keywords_it_owns(): void
    {
        // SampleData exercises x-lazy / x-optional; MigratableProfileData (with the
        // migration strategy) exercises x-migrate / x-migrate-from.
        $schemas = [
            (new JsonSchemaGenerator)->generate(new ReflectionClass(SampleData::class)),
            (new JsonSchemaGenerator(['strategies' => [new MigrationAttributesStrategy]]))
                ->generate(new ReflectionClass(MigratableProfileData::class)),
        ];

        $emitted = [];
        foreach ($schemas as $schema) {
            $emitted = array_merge($emitted, $this->collectExtensionKeywords($schema));
        }
        $emitted = array_values(array_unique($emitted));
        $undeclared = array_values(array_diff($emitted, Keywords::owned()));

        $this->assertNotEmpty($emitted, 'Expected the fixtures to exercise the keyword surface.');
        $this->assertSame([], $undeclared, sprintf(
            'Emitted undeclared x- keyword(s): %s. Declare them in %s.',
            implode(', ', $undeclared),
            Keywords::class,
        ));
    }

    private function collectExtensionKeywords(mixed $node): array
    {
        $found = [];

        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($key) && str_starts_with($key, 'x-')) {
                    $found[] = $key;
                }

                $found = array_merge($found, $this->collectExtensionKeywords($value));
            }
        }

        return $found;
    }
}
