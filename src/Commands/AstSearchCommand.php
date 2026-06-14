<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Embeddings\OllamaEmbedder;
use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\VectorSearch;

class AstSearchCommand extends Command
{
    protected $signature = 'ast:search
        {query : Natural language search query}
        {--top=10 : Number of results}
        {--format=json : File format to read: json or csv}
        {--source= : Filter by source name}
        {--layer= : Filter by layer: core, extra, local}
        {--include-overridden : Include overridden symbols in results}
        {--input= : Custom path to embeddings file}';

    protected $description = 'Search the merged symbol index using natural language';

    public function handle(OllamaEmbedder $embedder): int
    {
        $query = $this->argument('query');
        $topK = (int) $this->option('top');
        $format = $this->option('format');

        $inputFile = $this->option('input')
            ?? base_path(config('aevoast.output.path', '.ast')) . "/merged.embeddings.{$format}";

        if (!file_exists($inputFile)) {
            $this->error("Embeddings file not found: {$inputFile}");
            $this->info('Run `php artisan ast:parse` then `php artisan ast:merge` first.');
            return 1;
        }

        if (!$embedder->isAvailable()) {
            $this->error('Ollama is not reachable. Cannot embed the query.');
            return 1;
        }

        $writer = new FileWriter();
        $search = new VectorSearch();

        $this->line("Loading embeddings from {$inputFile}…");
        $chunks = $writer->readEmbeddings($inputFile);
        $this->line('Loaded ' . count($chunks) . ' chunks.');

        $this->line("Embedding query: \"{$query}\"");
        $queryEmbedding = $embedder->embed($query);

        // Build meta filters
        $filterMeta = [];
        if ($this->option('source')) {
            $filterMeta['source'] = $this->option('source');
        }
        if ($this->option('layer')) {
            $filterMeta['layer'] = $this->option('layer');
        }

        $results = $search->search(
            $queryEmbedding,
            $chunks,
            $topK,
            activeOnly: !$this->option('include-overridden'),
            filterMeta: $filterMeta,
        );

        if (empty($results)) {
            $this->warn('No results found.');
            return 0;
        }

        $this->newLine();
        $this->info("Top {$topK} results for: \"{$query}\"");
        $this->newLine();

        foreach ($results as $i => $r) {
            $rank = $i + 1;
            $score = round($r['score'], 4);
            $source = $r['meta']['source'] ?? '?';
            $layer = $r['meta']['layer'] ?? '?';
            $file = $r['meta']['file'] ?? '';
            $overridden = ($r['meta']['overridden'] ?? false) ? ' [OVERRIDDEN]' : '';

            $this->line("<info>#{$rank}</info> (score: {$score}) [{$layer}:{$source}]{$overridden}");
            $this->line("   {$r['text']}");
            if ($file) {
                $this->line("   <comment>{$file}</comment>");
            }
            $this->newLine();
        }

        return 0;
    }
}
