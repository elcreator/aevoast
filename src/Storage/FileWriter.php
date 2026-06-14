<?php

namespace Elcreator\aEvoAST\Storage;

class FileWriter
{
    /**
     * Write symbol map (no embeddings) to file.
     */
    public function writeSymbols(string $path, array $symbols, string $format = 'json'): void
    {
        $this->ensureDir(dirname($path));

        match ($format) {
            'csv'   => $this->writeSymbolsCsv($path, $symbols),
            default => $this->writeJson($path, $symbols),
        };
    }

    /**
     * Write chunks with embeddings to file.
     *
     * @param array $chunks Each chunk has 'id', 'text', 'meta', 'embedding'
     */
    public function writeEmbeddings(string $path, array $chunks, string $format = 'json'): void
    {
        $this->ensureDir(dirname($path));

        match ($format) {
            'csv'   => $this->writeEmbeddingsCsv($path, $chunks),
            default => $this->writeJson($path, $chunks),
        };
    }

    /**
     * Read embeddings from a previously generated file.
     *
     * @return array<int, array{id: string, text: string, meta: array, embedding: float[]}>
     */
    public function readEmbeddings(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'csv'   => $this->readEmbeddingsCsv($path),
            default => $this->readJson($path),
        };
    }

    /**
     * Read symbol map from a previously generated file.
     */
    public function readSymbols(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'csv'   => $this->readSymbolsCsv($path),
            default => $this->readJson($path),
        };
    }

    // ---------------------------------------------------------------
    // JSON
    // ---------------------------------------------------------------

    private function writeJson(string $path, array $data): void
    {
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function readJson(string $path): array
    {
        $content = file_get_contents($path);
        return json_decode($content, true) ?? [];
    }

    // ---------------------------------------------------------------
    // CSV — embeddings
    // ---------------------------------------------------------------

    private function writeEmbeddingsCsv(string $path, array $chunks): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open for writing: {$path}");
        }

        // Header
        $metaKeys = $this->collectMetaKeys($chunks);
        $header = array_merge(['id', 'text'], $metaKeys, ['embedding']);
        fputcsv($fp, $header);

        foreach ($chunks as $chunk) {
            $row = [
                $chunk['id'],
                $chunk['text'],
            ];
            foreach ($metaKeys as $key) {
                $val = $chunk['meta'][$key] ?? '';
                $row[] = is_bool($val) ? ($val ? '1' : '0') : (string) $val;
            }
            // Embedding as semicolon-separated floats (commas conflict with CSV)
            $row[] = isset($chunk['embedding'])
                ? implode(';', $chunk['embedding'])
                : '';

            fputcsv($fp, $row);
        }

        fclose($fp);
    }

    private function readEmbeddingsCsv(string $path): array
    {
        $fp = fopen($path, 'r');
        if ($fp === false) return [];

        $header = fgetcsv($fp);
        if ($header === false) {
            fclose($fp);
            return [];
        }

        $embIdx = array_search('embedding', $header);
        $idIdx  = array_search('id', $header);
        $txtIdx = array_search('text', $header);

        // Meta keys = everything that isn't id, text, embedding
        $metaKeys = array_values(array_diff($header, ['id', 'text', 'embedding']));

        $chunks = [];
        while (($row = fgetcsv($fp)) !== false) {
            $meta = [];
            foreach ($metaKeys as $key) {
                $colIdx = array_search($key, $header);
                if ($colIdx !== false) {
                    $meta[$key] = $row[$colIdx] ?? '';
                }
            }

            $embString = $row[$embIdx] ?? '';
            $embedding = $embString !== ''
                ? array_map('floatval', explode(';', $embString))
                : [];

            $chunks[] = [
                'id'        => $row[$idIdx] ?? '',
                'text'      => $row[$txtIdx] ?? '',
                'meta'      => $meta,
                'embedding' => $embedding,
            ];
        }

        fclose($fp);
        return $chunks;
    }

    // ---------------------------------------------------------------
    // CSV — symbol maps (no embeddings)
    // ---------------------------------------------------------------

    private function writeSymbolsCsv(string $path, array $symbols): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open for writing: {$path}");
        }

        fputcsv($fp, [
            'kind', 'fqcn', 'name', 'namespace', 'extends', 'implements',
            'file', 'source', 'layer', 'line_start', 'line_end',
            'methods_count', 'properties_count',
        ]);

        foreach ($symbols as $sym) {
            $extends = $sym['extends'] ?? '';
            if (is_array($extends)) $extends = implode(', ', $extends);

            fputcsv($fp, [
                $sym['kind'] ?? '',
                $sym['fqcn'] ?? $sym['name'] ?? '',
                $sym['name'] ?? '',
                $sym['namespace'] ?? '',
                $extends,
                implode(', ', $sym['implements'] ?? []),
                $sym['file'] ?? '',
                $sym['source'] ?? '',
                $sym['layer'] ?? '',
                $sym['line_start'] ?? 0,
                $sym['line_end'] ?? 0,
                count($sym['methods'] ?? []),
                count($sym['properties'] ?? []),
            ]);
        }

        fclose($fp);
    }

    private function readSymbolsCsv(string $path): array
    {
        $fp = fopen($path, 'r');
        if ($fp === false) return [];

        $header = fgetcsv($fp);
        $symbols = [];
        while (($row = fgetcsv($fp)) !== false) {
            $sym = [];
            foreach ($header as $i => $key) {
                $sym[$key] = $row[$i] ?? '';
            }
            $symbols[] = $sym;
        }

        fclose($fp);
        return $symbols;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function collectMetaKeys(array $chunks): array
    {
        $keys = [];
        foreach ($chunks as $chunk) {
            foreach (array_keys($chunk['meta'] ?? []) as $k) {
                $keys[$k] = true;
            }
        }
        return array_keys($keys);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
