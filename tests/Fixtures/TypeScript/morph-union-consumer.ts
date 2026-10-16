// Append to the generated fixture types; compile with tsc --strict --noEmit.
function readNode(node: MorphNode): string {
    if (node.kind === 'text') return node.text;
    return node.child === null ? '' : readNode(node.child);
}
const text: MorphNode = {kind: 'text', text: 'hello'};
const block: MorphNode = {kind: 'block', child: text};
// @ts-expect-error unknown discriminator
const unknown: MorphNode = {kind: 'other', text: 'hello'};
// @ts-expect-error wrong arm payload
const wrong: MorphNode = {kind: 'text', child: null};
// @ts-expect-error discriminator is required
const missing: MorphNode = {text: 'hello'};
