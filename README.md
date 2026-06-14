# elcreator/aEvoAST

AST symbol maps and embeddings generator for Evolution CMS.

Parses your project's **core**, **extras**, and **custom code** into compact, searchable symbol indexes — so AI tools don't need to re-analyze the same open-source codebase for every developer, every time.

## How it works

1. **Parse** — uses `nikic/php-parser` to extract class/method/function signatures (no bodies)
2. **Embed** — sends signatures to local Ollama (`nomic-embed-text`) for 768-dim vectors
3. **Cache** — each source gets its own cached file, regenerated only when version changes
4. **Merge** — combines core + extras + your code into one index with layer-based override tracking
5. **Search** — brute-force cosine similarity over the flat file (fast enough for PHP codebases)

## Requirements

- Evolution CMS 3.3+
- PHP 8.2+
- [Ollama](https://ollama.com/) running locally (for embeddings)

## Install

```bash
cd core
composer require elcreator/aevoast
php artisan vendor:publish --provider="Elcreator\aEvoAST\aEvoASTServiceProvider"
```

Pull the embedding model:

```bash
ollama pull nomic-embed-text
```

## Usage

### Parse all sources

```bash
# Parse everything: core + installed extras + local custom code
php artisan ast:parse

# Parse only core
php artisan ast:parse --layer=core

# Parse a single extra
php artisan ast:parse --source=seiger/slang

# Parse a custom directory
php artisan ast:parse --path=./assets/snippets/mySnippet --layer=local --name=my-snippet

# Symbol maps only (no Ollama needed)
php artisan ast:parse --no-embeddings

# Force regenerate (ignore cache)
php artisan ast:parse --force

# Output as CSV instead of JSON
php artisan ast:parse --format=csv
```

### Merge into project index

```bash
# Merge all cached sources
php artisan ast:merge

# Merge as CSV
php artisan ast:merge --format=csv

# Only active (non-overridden) symbols
php artisan ast:merge --active-only
```

### Search

```bash
# Natural language search
php artisan ast:search "how to get document TV values"

# Filter by layer
php artisan ast:search "user authentication" --layer=core

# Filter by source
php artisan ast:search "multilingual routing" --source=seiger/slang

# More results
php artisan ast:search "cache clear" --top=20
```

### Status

```bash
php artisan ast:status
```

Shows: Ollama status, discovered sources, cache state, merged index info.

## Layer System

Symbols are organized into three layers with override tracking:

| Layer | Priority | What |
|-------|----------|------|
| `core` | 0 (lowest) | `evolution-cms/evolution` |
| `extra` | 1 | Installed packages (`seiger/*`, `evolution-cms-extras/*`) |
| `local` | 2 (highest) | Your custom snippets, plugins, modules |

When the same class or method exists in multiple layers, the **highest layer wins**. Lower-layer versions are kept with `overridden: true` so AI can understand what was changed and why.

## Output Files

```
storage/ast-cache/                          # Per-source cache
  evolution-cms_v3.3.0.symbols.json         # Compact symbol map
  evolution-cms_v3.3.0.embeddings.json      # Chunks + 768-dim vectors
  seiger_slang_v3.0.symbols.json
  seiger_slang_v3.0.embeddings.json

.ast/                                       # Merged project index
  merged.symbols.json                       # All symbols, all sources
  merged.embeddings.json                    # All chunks, with override flags
```

## Configuration

Publish and edit `config/aevoast.php`:

```php
return [
    'ollama' => [
        'url'   => env('AST_OLLAMA_URL', 'http://localhost:11434'),
        'model' => env('AST_OLLAMA_MODEL', 'nomic-embed-text'),
    ],
    'output' => [
        'path'   => '.ast',
        'format' => 'json',       // 'json' or 'csv'
    ],
    'auto_extras'   => true,      // auto-discover installed extras
    'extra_vendors' => [          // vendor prefixes to scan
        'evolution-cms-extras',
        'seiger',
    ],
    'local_paths'   => [          // your custom code directories
        'assets/snippets',
        'assets/plugins',
        'assets/modules',
        'core/custom',
    ],
    'chunk_by'   => 'method',     // 'method', 'class', or 'file'
    'batch_size' => 32,
];
```

## Using with AI

The `merged.symbols.json` file is small enough to inject into an AI context window as a skill/reference. The `merged.embeddings.json` file enables semantic search to find relevant symbols before asking the AI about them — reducing token usage dramatically.

Typical workflow:

```
Developer question → embed → search merged.embeddings.json → top 10 chunks → inject into AI context
```

## License

MIT
