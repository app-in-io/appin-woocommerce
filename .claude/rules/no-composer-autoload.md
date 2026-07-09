# No Composer Autoload in Production

> Applies to: `**/*.php`

Production does NOT use Composer autoload. The manual `autoload.php` maps `AppInIo\` → `src/`.

## Rules

- All new classes **MUST** follow PSR-4 naming: class name = file name, namespace = directory path
- Example: `AppInIo\Sync\ProductSync` → `src/Sync/ProductSync.php`
- **Never add runtime dependencies via Composer** — only dev dependencies (PHPUnit, Brain Monkey, Mockery)
- If you need a library at runtime, either vendor it manually or implement the functionality directly
- The `autoload.php` handles a simple namespace-to-directory mapping — no Composer classmap, no files autoload

## Why

WordPress plugins are distributed as zip files. Users install them via the WP admin panel. They don't run `composer install`. The plugin must work out of the box with just the `src/` directory and `autoload.php`.