<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\Merger;

class AstMergeCommand extends Command
{
    protected $signature = 'ast:merge
        {--format=json : Output format: json or csv}
        {--output= : Custom output directory (default: project_root/.ast)}
        {--active-only : Write only active (non-overridden) chunks}';

    protected $description = 'Merge all cached symbol maps and embeddings into a unified project index';

    public function handle(): int
    {
        $format = $this->option('format');
        $cachePath = config('aevoast.cache_path');
        $outputPath = $this->option('output')
            ?? base_path(config('aevoast.output.path', '.ast'));
        $activeOnly = $this->option('active-only');

        $writer = new FileWriter();
        $merger = new Merger();

        if (!is_dir($cachePath)) {
            $this->error("Cache directory not found: {$cachePath}");
            $this->info('Run `php artisan ast:parse` first.');
            return 1;
        }

        // Collect all cached source files
        $pattern = $cachePath . '/*.embeddings.' . $format;
        $files = glob($pattern);

        $symbolPattern = $cachePath . '/*.symbols.' . $format;
        $symbolFiles = glob($symbolPattern);

        if (empty($files) && empty($symbolFiles)) {
            $this->error("No cached files found in {$cachePath}");
            $this->info('Run `php artisan ast:parse` first.');
            return 1;
        }

        // Merge embeddings
        if (!empty($files)) {
            $this->info('Merging embeddings from ' . count($files) . ' sources…');

            $chunkSets = [];
            foreach ($files as $file) {
                $sourceName = basename($file, ".embeddings.{$format}");
                $this->line("  Loading: {$sourceName}");
                $chunkSets[] = $writer->readEmbeddings($file);
            }

            $merged = $merger->merge($chunkSets);

            if ($activeOnly) {
                $merged = $merger->active($merged);
            }

            $overrideCount = count(array_filter(
                $merged,
                fn($c) => $c['meta']['overridden'] ?? false
            ));

            $activeCount = count($merged) - $overrideCount;

            $embFile = "{$outputPath}/merged.embeddings.{$format}";
            $writer->writeEmbeddings($embFile, $merged, $format);

            $this->info("✓ Merged embeddings → {$embFile}");
            $this->line("  {$activeCount} active chunks, {$overrideCount} overridden");
        }

        // Merge symbol maps
        if (!empty($symbolFiles)) {
            $this->info('Merging symbol maps from ' . count($symbolFiles) . ' sources…');

            $allSymbols = [];
            foreach ($symbolFiles as $file) {
                $sourceName = basename($file, ".symbols.{$format}");
                $this->line("  Loading: {$sourceName}");
                $symbols = $writer->readSymbols($file);
                $allSymbols = array_merge($allSymbols, $symbols);
            }

            $symFile = "{$outputPath}/merged.symbols.{$format}";
            $writer->writeSymbols($symFile, $allSymbols, $format);

            $this->info("✓ Merged symbols → {$symFile}");
            $this->line("  " . count($allSymbols) . " total symbols");
        }

        $this->newLine();
        $this->info('Merge complete.');

        return 0;
    }
}
