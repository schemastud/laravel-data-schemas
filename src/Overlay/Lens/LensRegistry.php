<?php

namespace Schemastud\DataSchemas\Overlay\Lens;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

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
//
// ## Lens keys are DOTTED, `vendor.lens-name`
// `audiostud.song-to-timeline`, not `audiostud/song-to-timeline`. The registry is
// addressed by {@see RegistryKey} like every other registry in the estate, and `/`
// is not a key character — it means a composer coordinate and a MIME type and has no
// business inside an address ({@see Key}). A slashed spelling would have needed a
// second key type (`RelativeUriKey`) purely so three lenses could keep a punctuation
// mark, which is how a keyspace forks: the owner's call was to normalise rather than
// diverge. The `vendor.` prefix survives intact and does the same work it always
// did — it is the joiner that changed, not the convention.
//
// Note this is the KEY only. An association's canonical `@id`
// (`audiostud/timeline-otio`) is a schema identifier, not an address, and keeps its
// slash — which is exactly why the two were never the same string (see
// {@see LensRegistration::$key}).
//
// ## It HOLDS a `BasicRegistry`, it does not extend one
// The kernel contract is an interface and composition is the sanctioned shape: the
// store is a private field, the domain vocabulary below (`all()`, `get()`,
// `ofTier()`, `forId()`) stays exactly what a lens reader wants, and the six kernel
// methods delegate. See {@see lensKeys()} for the one place where the two
// vocabularies collided and had to be separated by hand.
//
// @implements Registry<LensRegistration>
#[IsRegistry(
    root: 'schemas.lenses',
    of: 'declared lenses (canonical ↔ rendering, law-checked), each tiered host-applied or engine-authoritative',
    arity: RegistryArity::RunAll,
    entryType: LensRegistration::class,
    onDuplicate: OnDuplicate::Reject,
    note: 'Keys are dotted `vendor.lens-name` — the estate keyspace, not a slashed dialect: `/` is not a '
        .'Key character, and diverging would have cost a second key type to preserve one punctuation '
        .'mark. Reject is declared, not inherited, and this class is the estate\'s argument for the policy '
        .'existing at all: a silent last-write-wins is how a registry of three lenses reports two. RunAll '
        .'because the product is DISCOVERABILITY rather than dispatch — even forId() returns every lens '
        .'over an @id, since picking would mean this registry deciding which host is right about a '
        .'canonical it does not own.',
    order: 30,
)]
class LensRegistry implements Registry
{
    private BasicRegistry $store;

    public function __construct(private ReversibleResolver $resolver = new ReversibleResolver)
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Register a declared lens. Duplicate keys throw rather than overwrite: a silent
     * last-write-wins is how a registry of three lenses reports two, which is the
     * class of invisibility this exists to end.
     *
     * One-argument and self-keying — `register(new LensRegistration(key: 'audiostud.song-to-timeline', …))`
     * — which is the shape `InvocableRegistry` established and the shape every existing
     * call site uses. The kernel's four-argument signature is accepted too, so a generic
     * registrar can write here without knowing the entry type; the declaration's own
     * `key` still wins when it is the one given, because a registration that carried a
     * different address from the one it is filed under is a defect no reader could see.
     *
     * A malformed key throws {@see \Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey}
     * from the store's door, which is what now ENFORCES the dotted convention rather
     * than merely documenting it — a slashed key is refused at its declaration site,
     * where the author can fix it, rather than silently becoming an unaddressable row.
     *
     * @param  LensRegistration|null  $entry  the declaration when `$key` is a key; unused
     *                                        when `$key` is the declaration itself
     */
    public function register(RegistryKey|string|LensRegistration $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $registration = $key instanceof LensRegistration ? $key : $entry;

        if (! $registration instanceof LensRegistration) {
            throw new InvalidArgumentException(sprintf(
                'LensRegistry stores LensRegistration declarations; `%s` was given for key [%s].',
                get_debug_type($entry),
                (string) $key,
            ));
        }

        $existing = $this->lookup($registration->key);

        if ($existing !== null) {
            throw new InvalidArgumentException(sprintf(
                'A lens is already registered under key [%s] (by [%s]). Lens keys are dotted vendor.lens-name and must be unique; two lenses over the same @id need two keys.',
                $registration->key,
                $existing->owner ?? 'app',
            ));
        }

        $this->store->register($registration->key, $registration, $by ?? $registration->owner, $ability);

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
        return $this->store->matches($this->store->root());
    }

    /** One registration by key, or null. */
    public function get(string $key): ?LensRegistration
    {
        return $this->lookup($key);
    }

    /**
     * A READ answers "absent" for a key that is not even a legal address, rather than
     * throwing. Registration still throws on a malformed key — a declaration that cannot
     * be addressed is a defect and must be loud — but a LOOKUP for `host/never-registered`
     * is a caller asking about something that is not here, and the honest answer to that
     * is the same `null` any other miss gets.
     */
    private function lookup(RegistryKey|string $key): ?LensRegistration
    {
        if (is_string($key) && Key::tryParse($key) === null) {
            return null;
        }

        return $this->store->tryResolve($key);
    }

    /**
     * The lens keys as this registry's own vocabulary renders them — bare, relative,
     * `audiostud.song-to-timeline`.
     *
     * **This used to be `keys()`, and the rename is the point.** The kernel contract
     * declares `keys(): list<RegistryKey>` returning ABSOLUTE keys
     * (`schemas.lenses.audiostud.song-to-timeline`); the domain method returned bare
     * strings. The two are signature-compatible, so letting them merge would have
     * compiled, passed the suite, and silently changed what every enumeration of this
     * registry reads — the collision registry-kernel 38's fourth amendment exists to
     * name. The contract's `keys()` wins because the index and the doctor read it; the
     * domain form keeps its behaviour under a name that says which one it is.
     *
     * @return list<string>
     */
    public function lensKeys(): array
    {
        return $this->store->relativeKeys();
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
            $this->all(),
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
            $this->all(),
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

    // ── The kernel contract ─────────────────────────────────────────────────────────

    public function has(RegistryKey|string $key): bool
    {
        return $this->lookup($key) !== null;
    }

    /** @return LensRegistration */
    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    /** @return LensRegistration|null */
    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return list<LensRegistration> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->store = $this->store->unfiltered();

        return $unfiltered;
    }
}
