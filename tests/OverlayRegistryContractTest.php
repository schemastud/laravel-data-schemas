<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\Registry;
use Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry;
use Schemastud\DataSchemas\Overlay\OverlayDocument;

/**
 * The class declares `#[IsRegistry]`, so it must BE one (launch 00, 2026-10-18:
 * `popcorn:registries:cache` refused to bake any host carrying it — the declared-but-not-implemented
 * gap blocked a fresh-clone audiostud on every fleet Mac). Red against the pre-fix class, which
 * implemented only its own DataOverlayRegistry.
 */
class OverlayRegistryContractTest extends TestCase
{
    public function test_it_implements_the_popcorn_registry_contract_it_declares(): void
    {
        $registry = new InMemoryOverlayRegistry;

        $this->assertInstanceOf(Registry::class, $registry);
        $this->assertSame('schemas.overlays', $registry->declaration()->root);
    }

    public function test_the_kernel_surface_and_the_fold_surface_agree(): void
    {
        $registry = new InMemoryOverlayRegistry;
        $a = OverlayDocument::fromArray(['actions' => [['target' => '$.title', 'override' => 'A']]]);
        $b = OverlayDocument::fromArray(['actions' => [['target' => '$.title', 'override' => 'B']]]);

        $registry->register('contact', $a, by: 'test');
        $registry->register('contact', $b, by: 'test');

        // Fold surface: exact key, registration order — unchanged semantics.
        $this->assertSame([$a, $b], $registry->documentsFor(['contact']));
        $this->assertTrue($registry->has('contact'));
        $this->assertFalse($registry->has('missing'));

        // Kernel surface: the same two entries are enumerable; Admit keeps them under ONE key.
        $this->assertCount(2, $registry->matches('contact'));
        $this->assertCount(1, $registry->keys());
        $this->assertSame($a, $registry->matches('contact')[0]);
    }

    public function test_register_still_accepts_the_array_shape(): void
    {
        $registry = new InMemoryOverlayRegistry;

        $registry->register('legacy', ['actions' => [['target' => '$.x', 'unset' => true]]]);

        $this->assertCount(1, $registry->documentsFor(['legacy']));
        $this->assertInstanceOf(OverlayDocument::class, $registry->documentsFor(['legacy'])[0]);
    }
}
