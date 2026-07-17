<?php

namespace Schemastud\DataSchemas\Overlay;

// The core fold. An ordered stack of overlay documents laid, in order, over a
// base array (a schema or an instance — the fold is array-native and does not
// care which). Within one document, actions fold in array order; across the
// stack, documents fold in list order. Last-writer-wins at every JSONPath, at
// both levels: order is meaning. Each action applies against the running
// result, so an op sees the effect of the ops before it.
class OverlayStack
{
    /** @var OverlayDocument[] */
    protected array $documents;

    protected JsonPathMutator $mutator;

    // Accepts already-parsed OverlayDocuments or raw `{overlay, actions}` arrays.
    public function __construct(array $documents = [], ?JsonPathMutator $mutator = null)
    {
        $this->documents = array_map(
            fn ($document) => $document instanceof OverlayDocument
                ? $document
                : OverlayDocument::fromArray($document),
            array_values($documents)
        );

        $this->mutator = $mutator ?? new JsonPathMutator;
    }

    public function apply(array $base): array
    {
        $result = $base;

        foreach ($this->documents as $document) {
            foreach ($document->actions() as $action) {
                $this->applyAction($result, $action);
            }
        }

        return $result;
    }

    protected function applyAction(array &$result, Action $action): void
    {
        switch ($action->op()) {
            case Action::OP_OVERRIDE:
                $this->mutator->override($result, $action->target(), $action->value());
                break;

            case Action::OP_MERGE:
                $this->mutator->merge($result, $action->target(), $action->value());
                break;

            case Action::OP_UNSET:
                $this->mutator->unset($result, $action->target());
                break;

            default:
                throw new OverlayException(
                    "op '{$action->op()}' is recognised but not applied by the fold yet."
                );
        }
    }
}
