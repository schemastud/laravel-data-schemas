<?php

namespace Schemastud\DataSchemas\Migration;

/**
 * The outcome of a migration attempt through the {@see MigrationLadder}.
 *
 * Either a rung MIGRATED the payload (a conforming candidate, tagged with the
 * rung that produced it and its confidence), or every rung abstained and the
 * payload is QUARANTINED — declared unmigratable, with the ORIGINAL payload
 * preserved immutably (never mutated, never destroyed). The source record is
 * always recoverable from {@see $original}.
 */
class MigrationResult
{
    /**
     * @param  array<string, mixed>  $original  the source payload, preserved verbatim
     * @param  array<string, mixed>|null  $migrated  the conforming candidate, or null if quarantined
     */
    private function __construct(
        public array $original,
        public ?array $migrated,
        public bool $quarantined,
        public ?string $rung,
        public float $confidence,
    ) {}

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $migrated
     */
    public static function migrated(array $original, array $migrated, string $rung, float $confidence): self
    {
        return new self($original, $migrated, false, $rung, $confidence);
    }

    /**
     * The quarantine floor: nothing migrated, original preserved immutably.
     *
     * @param  array<string, mixed>  $original
     */
    public static function quarantined(array $original): self
    {
        return new self($original, null, true, null, 0.0);
    }

    public function wasMigrated(): bool
    {
        return ! $this->quarantined;
    }
}
