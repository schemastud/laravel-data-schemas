<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

use InvalidArgumentException;

// The registry of declared lenses — the answer to "which lenses exist in this
// estate?", a question that until now had no way to be asked.
//
// ## Why this exists at all
// Lenses are real, bidirectional, law-checked and in production use, and were
// registered nowhere. The consequence was not theoretical: an exhaustive search of
// the estate for lens usage, keyed on the known lens classes and the engine's lens
// directory, concluded there were ZERO production consumers — and it was wrong.
// The live lenses were host-defined and enumerated only by a private static facade
// in one satellite, so a search keyed on a registry found nothing precisely because
// there was no registry. A mechanism in real use and structurally invisible is the
// worst state a seam can be in: the next agent does not extend it, it rebuilds it.
//
// ## What it is NOT
// Not a dispatch path. Nothing resolves "the lens for this record" through here and
// no call site is expected to migrate onto it — host call sites keep applying their
// own associations directly, exactly as before. Adding a lookup that *applies*
// whatever it finds would quietly make every registered host-applied lens
// authoritative for its `@id`, which is the ADR-0155 line this registry is built to
// preserve (see {@see LensTier}). It is an enumeration surface: registration buys
// visibility and nothing else.
//
// ## Where it lives, and why here
// Beside the mechanism (`LensAssociation`, `DirectedLens`, `LensLaws`,
// `ReversibleResolver`) in the fully-open package, per this directory's README
// vendor seam: the mechanism is open, applied lenses are paid. Enumerating who
// declared a lens is mechanism-tier work — it decides nothing and applies nothing —
// and a registry parked in a paid engine would be unreachable from the very hosts
// whose invisible lenses motivated it. A host composing the lens mechanism without
// the paid kernels can still be discovered.
class LensRegistry
{
    /** @var array<string, LensRegistration> */
    private array $registrations = [];

    public function __construct(private ReversibleResolver $resolver = new ReversibleResolver) {}

    /**
     * Register a declared lens. Duplicate keys throw rather than overwrite: a silent
     * last-write-wins is how a registry of three lenses reports two, which is the
     * class of invisibility this exists to end.
     */
    public function register(LensRegistration $registration): self
    {
        if (isset($this->registrations[$registration->key])) {
            throw new InvalidArgumentException(sprintf(
                'A lens is already registered under key [%s] (by [%s]). Lens keys are vendor/lens-name and must be unique; two lenses over the same @id need two keys.',
                $registration->key,
                $this->registrations[$registration->key]->owner ?? 'app',
            ));
        }

        $this->registrations[$registration->key] = $registration;

        return $this;
    }

    /**
     * Every registered lens, in registration order. The read that matters — the whole
     * point is the enumeration.
     *
     * @return list<LensRegistration>
     */
    public function all(): array
    {
        return array_values($this->registrations);
    }

    /** One registration by key, or null. */
    public function get(string $key): ?LensRegistration
    {
        return $this->registrations[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->registrations);
    }

    /**
     * Registrations at one tier. The read an auditor wants: "what does the engine stand
     * behind" is a different question from "what has some host declared over this `@id`".
     *
     * @return list<LensRegistration>
     */
    public function ofTier(LensTier $tier): array
    {
        return array_values(array_filter(
            $this->registrations,
            fn (LensRegistration $registration) => $registration->tier === $tier,
        ));
    }

    /**
     * Every registration declaring an association over one canonical `@id` — the lookup
     * a record-level consumer needs, deliberately returning ALL of them rather than one.
     * An `@id` with three declared lenses has three; picking one for the caller would be
     * this registry deciding which host is right about a canonical it does not own.
     *
     * @return list<LensRegistration>
     */
    public function forId(string $id): array
    {
        return array_values(array_filter(
            $this->registrations,
            fn (LensRegistration $registration) => $registration->association()->id === $id,
        ));
    }

    /**
     * The certified fidelity of one registration — the laws exercised against its
     * submitted evidence, never its claim. An unknown key is `Lossy`: "I have never
     * heard of this lens" must not be a more generous answer than "this lens failed".
     */
    public function certifiedFidelity(string $key): Fidelity
    {
        return $this->get($key)?->certifiedFidelity($this->resolver) ?? Fidelity::Lossy;
    }

    /**
     * The flat rows a manifest/CLI renders — one per registration, each carrying its
     * tier and its certified fidelity.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return array_map(
            fn (LensRegistration $registration) => $registration->describe($this->resolver),
            $this->all(),
        );
    }
}
