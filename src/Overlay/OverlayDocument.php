<?php

namespace Schemastud\DataSchemas\Overlay;

// A parsed overlay document: `{ "overlay": "1.0.0", "actions": [ … ] }`.
// Externally keyed and context-agnostic — it never declares which context it
// serves (that binding lives in the registry, a later slice). Actions fold in
// array order.
class OverlayDocument
{
    protected string $version;

    protected array $actions;

    public function __construct(string $version, array $actions)
    {
        $this->version = $version;
        $this->actions = $actions;
    }

    public static function fromArray(array $raw): self
    {
        $version = $raw['overlay'] ?? '1.0.0';

        if (! is_string($version)) {
            throw new OverlayException('The "overlay" version marker must be a string.');
        }

        if (! isset($raw['actions']) || ! is_array($raw['actions'])) {
            throw new OverlayException('An overlay document must carry an "actions" array.');
        }

        $actions = [];

        foreach ($raw['actions'] as $index => $rawAction) {
            if (! is_array($rawAction)) {
                throw new OverlayException("Overlay action at index {$index} must be an object.");
            }

            $actions[] = Action::fromArray($rawAction);
        }

        return new self($version, $actions);
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return Action[] */
    public function actions(): array
    {
        return $this->actions;
    }
}
