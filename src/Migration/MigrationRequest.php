<?php

namespace Rushing\LaravelDataSchemas\Migration;

/**
 * The immutable input a migration ladder resolves: an original payload shaped to
 * the OLD schema, the old and target (NEW) `$id` schemas, and the structural diff
 * between them.
 *
 * The original payload is preserved verbatim and NEVER mutated — every rung works
 * from a copy, and the quarantine floor returns this original untouched. This is
 * the data passed through the popcorn ladder as its `array $input` (carried under
 * a single key so the typed object survives the array contract).
 */
class MigrationRequest
{
    /**
     * @param  array<string, mixed>  $payload  the source record, shaped to $from
     * @param  array<string, mixed>  $from  the OLD schema (its `$id` is the source version)
     * @param  array<string, mixed>  $to  the TARGET schema (its `$id` is the destination version)
     * @param  array{added: array<int, string>, dropped: array<int, string>, widened: array<int, array<string, mixed>>, changed: array<int, array<string, mixed>>, breaking: bool}  $diff
     */
    public function __construct(
        public array $payload,
        public array $from,
        public array $to,
        public array $diff,
    ) {}

    /**
     * The source `$id`, or null if the old schema is anonymous.
     */
    public function fromId(): ?string
    {
        $id = $this->from['$id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * The target `$id`, or null if the target schema is anonymous.
     */
    public function toId(): ?string
    {
        $id = $this->to['$id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Pack into the popcorn `array $input` contract under a single key, so the
     * typed request survives the strategy interface's `array` parameter.
     *
     * @return array{request: self}
     */
    public function toInput(): array
    {
        return ['request' => $this];
    }

    /**
     * Unpack from the popcorn `array $input` contract.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): ?self
    {
        $request = $input['request'] ?? null;

        return $request instanceof self ? $request : null;
    }
}
