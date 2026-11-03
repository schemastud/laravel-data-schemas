<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/**
 * This package adopts the package-tier registry docs check over the whole of `src/`: every declared
 * root has its `docs/registries/<root>.mdx` page, and every page's generated facts block is
 * byte-identical to a fresh render of its `#[IsRegistry]` declaration.
 *
 * The coverage count is asserted too, so a check that went inactive (no `docs/registries/`) or a scan
 * that found nothing cannot pass by reporting no failures.
 */
class RegistryDocsAdoptionTest extends TestCase
{
    public function test_every_declared_root_is_documented_with_its_facts_in_step(): void
    {
        $report = RegistryDocsCheck::forPackage(dirname(__DIR__))->run();

        $this->assertSame([], $report->failures(), $report->summary());
        $this->assertCount(6, $report->coverage, 'the schemas.* roots: '.implode(', ', array_keys($report->coverage)));
    }
}
