<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Parsers\SymbolMapParser;
use Elcreator\aEvoAST\Parsers\Chunker;
use Elcreator\aEvoAST\Parsers\SourceDiscovery;
use Elcreator\aEvoAST\Embeddings\OllamaEmbedder;
use Elcreator\aEvoAST\Storage\FileWriter;

class AstParseCommand extends Command
{
    protected $signature = 'ast:parse
        {--source= : Parse only a specific source by name (e.g. "evolution-cms", "seiger/slang")}
        {--layer= : Parse only a specific layer: core, extra, or local}
        {--path= : Parse a specific directory path with --layer and --name}
        {--name= : Source name when using --path}
        {--format=json : Output format: json or csv}
        {--no-embeddings : Skip embedding generation, produce symbol maps only}
        {--force : Regenerate even if cache exists}';

    protected $description = 'Parse Evolution CMS sources into symbol maps and embeddings';

    public function handle(
        SymbolMapParser $parser,
        OllamaEmbedder  $embedder,
        SourceDiscovery $discovery,
    ): int {
        $format = $this->option('format');
        $noEmbeddings = $this->option('no-embeddings');
        $force = $this->option('force');
        $cachePath = config('aevoast.cache_path');
        $chunkBy = config('aevoast.chunk_by', 'method');
        $batchSize = config('aevoast.batch_size', 32);

        $writer = new FileWriter();
        $chunker = new Chunker();

        // Determine sources to parse
        if ($this->option('path')) {
            $sources = [[
                'path'    => $this->option('path'),
                'layer'   => $this->option('layer') ?? 'local',
                'name'    => $this->option('name') ?? basename($this->option('path')),
                'version' => null,
            ]];
        } elseif ($this->option('source')) {
            $all = $discovery->discover(base_path());
            $sources = array_filter($all, fn($s) => $s['name'] === $this->option('source'));
            if (empty($sources)) {
                $this->error("Source '{$this->option('source')}' not found.");
                return 1;
            }
        } elseif ($this->option('layer')) {
            $all = $discovery->discover(base_path());
            $sources = array_filter($all, fn($s) => $s['layer'] === $this->option('layer'));
        } else {
            $sources = $discovery->discover(base_path());
        }

        if (empty($sources)) {
            $this->warn('No sources found to parse.');
            return 1;
        }

        // Check Ollama if we need embeddings
        if (!$noEmbeddings && !$embedder->isAvailable()) {
            $this->warn('Ollama is not reachable or model "' . $embedder->getModel() . '" is not pulled.');
            $this->warn('Run: ollama pull ' . $embedder->getModel());
            $this->info('Continuing with --no-embeddings mode.');
            $noEmbeddings = true;
        }

        $totalSymbols = 0;
        $totalChunks = 0;

        foreach ($sources as $source) {
            $safeName = str_replace(['/', '\\'], '_', $source['name']);
            $versionSuffix = $source['version'] ? '_' . $source['version'] : '';
            $cacheKey = "{$safeName}{$versionSuffix}";

            $symbolsFile = "{$cachePath}/{$cacheKey}.symbols.{$format}";
            $embeddingsFile = "{$cachePath}/{$cacheKey}.embeddings.{$format}";

            // Check cache
            if (!$force && file_exists($symbolsFile)) {
                $this->line("  <comment>cached</comment>  {$source['name']} → {$symbolsFile}");

                if (!$noEmbeddings && file_exists($embeddingsFile)) {
                    $this->line("  <comment>cached</comment>  {$source['name']} → {$embeddingsFile}");
                    continue;
                }
            }

            $this->info("Parsing: {$source['name']} ({$source['layer']})");
            $this->line("  Path: {$source['path']}");

            $result = $parser->parseDirectory(
                $source['path'],
                $source['layer'],
                $source['name']
            );

            if (!empty($result['errors'])) {
                foreach (array_slice($result['errors'], 0, 5) as $err) {
                    $this->warn("  ⚠ {$err}");
                }
                if (count($result['errors']) > 5) {
                    $this->warn('  … and ' . (count($result['errors']) - 5) . ' more errors');
                }
            }

            $symbolCount = count($result['symbols']);
            $totalSymbols += $symbolCount;
            $this->line("  Found {$symbolCount} symbols");

            // Write symbol map
            $writer->writeSymbols($symbolsFile, $result['symbols'], $format);
            $this->info("  ✓ Symbols → {$symbolsFile}");

            // Generate chunks and embeddings
            if (!$noEmbeddings) {
                $chunks = $chunker->chunk($result['symbols'], $chunkBy);
                $chunkCount = count($chunks);
                $totalChunks += $chunkCount;

                $this->line("  Embedding {$chunkCount} chunks…");

                $bar = $this->output->createProgressBar($chunkCount);
                $bar->start();

                // Batch embed
                for ($i = 0; $i < $chunkCount; $i += $batchSize) {
                    $batch = array_slice($chunks, $i, $batchSize);
                    $texts = array_map(fn($c) => $c['text'], $batch);

                    try {
                        $embeddings = $embedder->embedBatch($texts);

                        foreach ($embeddings as $j => $embedding) {
                            $chunks[$i + $j]['embedding'] = $embedding;
                        }
                    } catch (\Throwable $e) {
                        $this->newLine();
                        $this->error("  Embedding failed at batch {$i}: {$e->getMessage()}");
                        // Mark failed chunks without embedding
                        for ($j = 0; $j < count($batch); $j++) {
                            $chunks[$i + $j]['embedding'] = [];
                        }
                    }

                    $bar->advance(count($batch));
                }

                $bar->finish();
                $this->newLine();

                $writer->writeEmbeddings($embeddingsFile, $chunks, $format);
                $this->info("  ✓ Embeddings → {$embeddingsFile}");
            }
        }

        $this->newLine();
        $this->info("Done. {$totalSymbols} symbols" .
            ($totalChunks ? ", {$totalChunks} chunks embedded" : '') . '.');

        return 0;
    }
}
