<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\Popcorn\Registries\Docs\DataExamples;

/**
 * The registry pages' data examples are captured output (docs-feedback ticket 09). This checks the committed fixtures
 * and page blocks, and re-runs each example in this test's application. It never writes: regenerate with
 * `composer docs:examples -- --write`.
 */
class DataExamplesTest extends DataExamplesTestCase
{
    public function test_every_data_example_is_captured_placed_and_current_on_its_page(): void
    {
        $examples = DataExamples::forPackage(dirname(__DIR__));

        $this->assertSame(['schemas.projection/generate-a-schema'], array_keys($examples->cases()));
        $this->assertSame([], $examples->problems());
    }

    #[DataProvider('examples')]
    public function test_the_example_captures_what_the_page_shows(string $id): void
    {
        $this->assertSame([], DataExamples::forPackage(dirname(__DIR__))->drift($id));
    }

    /** @return array<string, array{string}> */
    public static function examples(): array
    {
        $ids = array_keys(DataExamples::forPackage(dirname(__DIR__))->cases());

        return array_combine($ids, array_map(fn (string $id) => [$id], $ids));
    }
}
