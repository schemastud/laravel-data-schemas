<?php

namespace Schemastud\DataSchemas\Tests;

use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Overlay\InMemoryOverlayRegistry;
use Schemastud\DataSchemas\Overlay\StaticOverlayResolver;

class OverlayResolverRegistryTest extends TestCase
{
    private function overlay(string $target, mixed $value): array
    {
        return ['overlay' => '1.0.0', 'actions' => [['target' => $target, 'override' => $value]]];
    }

    public function test_static_resolver_returns_its_declared_key_order(): void
    {
        $resolver = new StaticOverlayResolver(['vertical:healthcare', 'tenant:acme', 'locale:es-MX']);

        $this->assertSame(
            ['vertical:healthcare', 'tenant:acme', 'locale:es-MX'],
            $resolver->resolve(),
        );
    }

    public function test_registry_registers_and_reports_keys(): void
    {
        $registry = new InMemoryOverlayRegistry;
        $registry->register('tenant:acme', $this->overlay('$.brand', 'Acme'));

        $this->assertTrue($registry->has('tenant:acme'));
        $this->assertFalse($registry->has('tenant:nope'));
    }

    public function test_documents_for_preserves_declared_key_order_and_skips_empty_keys(): void
    {
        $registry = new InMemoryOverlayRegistry;
        $registry->register('a', $this->overlay('$.x', 1));
        $registry->register('c', $this->overlay('$.x', 3));

        // 'b' has no docs and is silently skipped; order follows the key list.
        $documents = $registry->documentsFor(['a', 'b', 'c']);

        $this->assertCount(2, $documents);
        $this->assertSame(1, $documents[0]->actions()[0]->value());
        $this->assertSame(3, $documents[1]->actions()[0]->value());
    }

    public function test_stack_assembles_in_resolver_declared_order_last_writer_wins(): void
    {
        $registry = new InMemoryOverlayRegistry;
        $registry->register('vertical:healthcare', $this->overlay('$.label', 'from-vertical'));
        $registry->register('tenant:acme', $this->overlay('$.label', 'from-tenant'));

        // The host resolver declares tenant AFTER vertical, so tenant wins.
        $resolver = new StaticOverlayResolver(['vertical:healthcare', 'tenant:acme']);

        $result = $registry->stackFor($resolver->resolve())->apply(['label' => 'base']);

        $this->assertSame('from-tenant', $result['label']);
    }

    public function test_reversing_resolver_order_flips_the_winner(): void
    {
        $registry = new InMemoryOverlayRegistry;
        $registry->register('vertical:healthcare', $this->overlay('$.label', 'from-vertical'));
        $registry->register('tenant:acme', $this->overlay('$.label', 'from-tenant'));

        // Specificity is purely the host's ordering — no auto-specificity in the
        // primitive. Declaring vertical last makes vertical win.
        $resolver = new StaticOverlayResolver(['tenant:acme', 'vertical:healthcare']);

        $result = $registry->stackFor($resolver->resolve())->apply(['label' => 'base']);

        $this->assertSame('from-vertical', $result['label']);
    }
}
