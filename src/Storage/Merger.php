<?php

namespace Elcreator\aEvoAST\Storage;

class Merger
{
    /**
     * Layer priority: higher number = higher priority.
     */
    private const LAYER_PRIORITY = [
        'core'  => 0,
        'extra' => 1,
        'local' => 2,
    ];

    /**
     * Merge multiple chunk sets, tracking overrides.
     *
     * Each chunk must have 'id', 'text', 'meta' (with 'layer'), and optionally 'embedding'.
     *
     * When two chunks share the same class+method (or function fqcn),
     * the higher-layer chunk wins, but the lower-layer chunk is kept
     * with meta.overridden = true.
     *
     * @param  array<int, array> $chunkSets  Multiple arrays of chunks
     * @return array  Merged chunks
     */
    public function merge(array $chunkSets): array
    {
        $all = [];
        foreach ($chunkSets as $chunks) {
            foreach ($chunks as $chunk) {
                $all[] = $chunk;
            }
        }

        // Group by semantic key (class::method or function fqcn)
        $groups = [];
        foreach ($all as $chunk) {
            $key = $this->semanticKey($chunk);
            $groups[$key][] = $chunk;
        }

        $merged = [];
        foreach ($groups as $key => $candidates) {
            if (count($candidates) === 1) {
                $chunk = $candidates[0];
                $chunk['meta']['overridden'] = false;
                $chunk['meta']['overridden_by'] = null;
                $merged[] = $chunk;
                continue;
            }

            // Sort by layer priority descending
            usort($candidates, fn($a, $b) =>
                $this->layerPriority($b) <=> $this->layerPriority($a)
            );

            $winner = $candidates[0];
            $winner['meta']['overridden'] = false;
            $winner['meta']['overridden_by'] = null;
            $merged[] = $winner;

            // Keep losers marked as overridden
            for ($i = 1; $i < count($candidates); $i++) {
                $loser = $candidates[$i];
                $loser['meta']['overridden'] = true;
                $loser['meta']['overridden_by'] = $winner['meta']['source'] ?? 'unknown';
                $merged[] = $loser;
            }
        }

        return $merged;
    }

    /**
     * Get only active (non-overridden) chunks.
     */
    public function active(array $merged): array
    {
        return array_values(array_filter(
            $merged,
            fn($chunk) => !($chunk['meta']['overridden'] ?? false)
        ));
    }

    /**
     * Build a semantic key that identifies a unique symbol.
     */
    private function semanticKey(array $chunk): string
    {
        $meta = $chunk['meta'] ?? [];
        $kind = $meta['kind'] ?? 'unknown';

        if ($kind === 'method') {
            return 'method::' . ($meta['class'] ?? '') . '::' . ($meta['method'] ?? '');
        }

        if ($kind === 'function') {
            return 'function::' . ($meta['fqcn'] ?? $meta['name'] ?? '');
        }

        // class / interface / trait / enum header
        return $kind . '::' . ($meta['fqcn'] ?? $meta['name'] ?? '');
    }

    private function layerPriority(array $chunk): int
    {
        $layer = $chunk['meta']['layer'] ?? 'core';
        return self::LAYER_PRIORITY[$layer] ?? 0;
    }
}
