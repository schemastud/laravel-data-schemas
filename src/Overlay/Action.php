<?php

namespace Schemastud\DataSchemas\Overlay;

// One overlay action: a `target` JSONPath plus exactly one op. The op vocabulary
// is fixed (override/merge/unset); an action carrying zero or more than one op
// key is malformed. Only `override` is applied by the fold in this slice; the
// other two ops are recognised here so malformed-action rejection is complete.
class Action
{
    const OP_OVERRIDE = 'override';

    const OP_MERGE = 'merge';

    const OP_UNSET = 'unset';

    const OPS = [self::OP_OVERRIDE, self::OP_MERGE, self::OP_UNSET];

    protected string $target;

    protected string $op;

    protected mixed $value;

    public function __construct(string $target, string $op, mixed $value)
    {
        $this->target = $target;
        $this->op = $op;
        $this->value = $value;
    }

    public static function fromArray(array $raw): self
    {
        if (! isset($raw['target']) || ! is_string($raw['target']) || $raw['target'] === '') {
            throw new OverlayException('An overlay action must carry a non-empty string "target".');
        }

        $ops = array_values(array_intersect(self::OPS, array_keys($raw)));

        if (count($ops) !== 1) {
            $found = count($ops) === 0 ? 'none' : implode('+', $ops);

            throw new OverlayException(
                'An overlay action must carry exactly one op key ('.
                implode('/', self::OPS).") — got {$found}."
            );
        }

        $op = $ops[0];

        return new self($raw['target'], $op, $raw[$op]);
    }

    public function target(): string
    {
        return $this->target;
    }

    public function op(): string
    {
        return $this->op;
    }

    public function value(): mixed
    {
        return $this->value;
    }
}
