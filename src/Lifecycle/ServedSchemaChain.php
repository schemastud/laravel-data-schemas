<?php

namespace Schemastud\DataSchemas\Lifecycle;

use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;

/**
 * The default {@see ServedSchemaRegistry} — a read-only chain over the host's declared served
 * directories (beam-facade ticket 82).
 *
 * The chain and the marker are kept apart on purpose. {@see ChainedSchemaRegistry} is a general
 * composition with no opinion about doors; this is the one the public door resolves, and a host that
 * wants to serve something else rebinds the contract rather than subclassing the composition.
 */
class ServedSchemaChain extends ChainedSchemaRegistry implements ServedSchemaRegistry
{
    /**
     * @param  array<int, string>  $directories
     */
    public static function overDirectories(array $directories): static
    {
        return new static(array_map(
            fn (string $dir) => fn () => new FilesystemSchemaRegistry($dir),
            array_values($directories),
        ));
    }
}
