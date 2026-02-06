# WordPress Plugin Development Rules

## Requirements
- WordPress 6.9+
- PHP 8.2+ with `declare(strict_types=1);`
- Node.js 18+ for blocks
- Follow WordPress PHP Coding Standards

## Security (Always)
- Sanitize all input
- Escape all output (`esc_html`, `esc_attr`, `esc_url`)
- Verify nonces for forms/AJAX
- Use `$wpdb->prepare()` for queries
- Check user capabilities

## Code Structure
- OOP with single responsibility
- One class per file
- Use namespaces (PSR-4)
- Descriptive naming
- Directories: `lowercase-with-hyphens/`

## WordPress Core
- Never modify core - use hooks only
- Use `wp_enqueue_script/style()` for assets
- Use Options API for settings
- Use `WP_Query` for posts (never direct SQL)
- Implement i18n functions
- Use transients for caching

## Gutenberg Blocks
- Use `@wordpress/create-block` for scaffolding
- **Always use `block.json`** for metadata
- Use `register_block_type()` for registration
- Use `useBlockProps()` in edit and save
- Structure:
```
  blocks/my-block/
    block.json
    edit.js
    save.js (or render.php for dynamic)
    view.js (for interactivity)
    style.scss
    editor.scss
```
- Use `@wordpress/scripts` for build process
- Enqueue assets via `block.json`, not manually

## Interactivity API
- Use for dynamic, interactive blocks
- Namespace stores: `pluginName/blockName`
- Directives: `data-wp-interactive`, `data-wp-context`, `data-wp-bind`, `data-wp-on`, `data-wp-text`, `data-wp-class`
- Server-side: `wp_interactivity_state()` for initial data
- Client-side: `store()` from `@wordpress/interactivity`
- Structure:
```js
  import { store } from '@wordpress/interactivity';
  
  store('myPlugin/myBlock', {
    state: { ... },
    actions: { ... },
    callbacks: { ... }
  });
```
- Use contexts for component-level state
- Use global store for app-level state
- Prefer declarative directives over imperative JS
- Server-render initial HTML
- Keep state immutable

## Database
- Use `$wpdb->prepare()` always
- Use `dbDelta()` for schema changes

## WooCommerce (when needed)
- Use WC functions: `wc_get_product()`, `wc_get_order()`
- Use WC CRUD classes
- Check WC activation before use

## Build Process
- Development: `npm run start`
- Production: `npm run build`
- Enqueue built files only

## What NOT to Do
- ❌ Direct `$_POST`/`$_GET` without sanitization
- ❌ Unescaped output
- ❌ God classes/functions
- ❌ Skip `block.json`
- ❌ Mix Interactivity API with custom React
- ❌ Mutate state directly in Interactivity API
- ❌ Forget store namespacing