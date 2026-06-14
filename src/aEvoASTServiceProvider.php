<?php

namespace Elcreator\aEvoAST;

use Illuminate\Support\ServiceProvider;
use Elcreator\aEvoAST\Commands\AstParseCommand;
use Elcreator\aEvoAST\Commands\AstMergeCommand;
use Elcreator\aEvoAST\Commands\AstSearchCommand;
use Elcreator\aEvoAST\Commands\AstStatusCommand;
use Elcreator\aEvoAST\Embeddings\OllamaEmbedder;
use Elcreator\aEvoAST\Parsers\SymbolMapParser;

class aEvoASTServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AstParseCommand::class,
                AstMergeCommand::class,
                AstSearchCommand::class,
                AstStatusCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/../config/aevoast.php' => config_path('aevoast.php'),
        ], 'aevoast-config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/aevoast.php', 'aevoast');

        $this->app->singleton(SymbolMapParser::class, function () {
            return new SymbolMapParser();
        });

        $this->app->singleton(OllamaEmbedder::class, function ($app) {
            $config = $app['config']['aevoast.ollama'];
            return new OllamaEmbedder($config['url'], $config['model']);
        });
    }
}
