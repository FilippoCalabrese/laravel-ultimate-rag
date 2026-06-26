<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Sellinnate\RagEngine\Contracts\Embeddable;

/**
 * Compiles an {@see Embeddable} into a single composed document (FR-DX-05).
 *
 * Related embeddables declared via the definition are rendered recursively and
 * inlined under labelled sections, so the parent's embedding "contains" the
 * embedding of its relations. Recursion is bounded by {@see $maxDepth} and
 * guarded against cycles, so a graph like Post → Comment → Post terminates.
 */
final class EmbeddableCompiler
{
    public function __construct(
        private readonly int $maxDepth = 3,
    ) {}

    public function compile(Embeddable $root): CompiledEmbeddable
    {
        $visited = [];
        $includedKeys = [];

        $content = $this->render($root, 0, $visited, $includedKeys);

        $definition = $root->toEmbeddable();

        return new CompiledEmbeddable(
            content: trim($content),
            metadata: $definition->metadataArray(),
            includedKeys: array_values(array_unique($includedKeys)),
            options: $definition->optionsArray(),
            documentKey: $definition->documentKeyOverride() ?? $this->keyFor($root),
        );
    }

    /**
     * @param  array<string, true>  $visited
     * @param  list<string>  $includedKeys
     */
    private function render(Embeddable $node, int $depth, array &$visited, array &$includedKeys): string
    {
        $key = $this->keyFor($node);

        // Cycle guard: never render the same embeddable twice in one branch tree.
        if (isset($visited[$key])) {
            return '';
        }
        $visited[$key] = true;

        // The root (depth 0) is the document itself, not an "included" relation.
        if ($depth > 0) {
            $includedKeys[] = $key;
        }

        $definition = $node->toEmbeddable();
        $blocks = [];

        foreach ($definition->parts() as $part) {
            $blocks[] = $part['label'] === ''
                ? $part['value']
                : '['.$part['label'].']'."\n".$part['value'];
        }

        if ($depth < $this->maxDepth) {
            foreach ($definition->included() as $included) {
                $childText = $this->render($included['embeddable'], $depth + 1, $visited, $includedKeys);

                if (trim($childText) === '') {
                    continue;
                }

                $blocks[] = $included['relation'] !== ''
                    ? '['.$included['relation'].']'."\n".$childText
                    : $childText;
            }
        }

        return implode("\n\n", array_filter($blocks, static fn (string $block): bool => trim($block) !== ''));
    }

    /**
     * Stable identity used for cycle detection and provenance. Prefers the
     * model's own {@see embeddableKey()} (from HasEmbeddings), then its morph
     * identity, then an object hash for non-Eloquent embeddables.
     */
    private function keyFor(Embeddable $embeddable): string
    {
        if (method_exists($embeddable, 'embeddableKey')) {
            return (string) $embeddable->embeddableKey();
        }

        if ($embeddable instanceof Model) {
            return $embeddable->getMorphClass().':'.$this->scalar($embeddable->getKey());
        }

        return 'object:'.spl_object_hash($embeddable);
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
