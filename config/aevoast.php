<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Embedding Settings
    |--------------------------------------------------------------------------
    */

    'ollama' => [
        'url'   => env('AST_OLLAMA_URL', 'http://localhost:11434'),
        'model' => env('AST_OLLAMA_MODEL', 'nomic-embed-text'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Settings
    |--------------------------------------------------------------------------
    */

    'output' => [
        // Where to store generated files relative to project root
        'path'   => env('AST_OUTPUT_PATH', '.ast'),
        // Default format: 'json' or 'csv'
        'format' => env('AST_OUTPUT_FORMAT', 'json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Pre-parsed symbol maps and embeddings for core and extras are cached
    | here so they're only regenerated when the package version changes.
    |
    */

    'cache_path' => env('AST_CACHE_PATH', storage_path('ast-cache')),

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Define which directories belong to which layer.
    | layer: 'core' | 'extra' | 'local'
    |
    | 'auto_extras' => true will auto-discover installed composer packages
    | under evolution-cms-extras/* and other known Evo extra vendors.
    |
    */

    'auto_extras' => true,

    'extra_vendors' => [
        'evolution-cms-extras',
        'seiger',
    ],

    'local_paths' => [
        'assets/snippets',
        'assets/plugins',
        'assets/modules',
        'core/custom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Batch Size
    |--------------------------------------------------------------------------
    |
    | How many chunks to send to Ollama in one request.
    | nomic-embed-text supports batched input via the /api/embed endpoint.
    |
    */

    'batch_size' => 32,

    /*
    |--------------------------------------------------------------------------
    | Chunking Strategy
    |--------------------------------------------------------------------------
    |
    | 'method'   — one chunk per method (best precision)
    | 'class'    — one chunk per class (fewer embeddings, less precise)
    | 'file'     — one chunk per file (fewest embeddings)
    |
    */

    'chunk_by' => 'method',
];
