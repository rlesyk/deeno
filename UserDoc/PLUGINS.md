# PLUGINS.md — deeno plugins

A plugin is a folder in `/plugins/` with two files:

```
/plugins/
  my-plugin/
    plugin.json   ← metadata (shown in the admin panel)
    plugin.php    ← code: attaches listeners to hooks
```

Install via **Plugins → + Plugin**; the ZIP archive must contain `plugin.json`
(in the archive root or in a single folder). Or manually: copy the folder into
`/plugins/` over FTP. Enable and disable it in the same place, in the plugin list
(or via ⌘K). The list of enabled plugins is stored in `config.json` →
`"plugins": ["my-plugin", ...]`. The page cache is cleared automatically — both
when a plugin is toggled and when its code is edited.

> ⚠️ A plugin is executable PHP code with full CMS privileges.
> Install plugins only from trusted sources — the same rule as for themes.

## plugin.json

```json
{
  "name": "My name",
  "description": "One sentence: what the plugin does.",
  "version": "1.0",
  "author": "You"
}
```

### Settings (optional)

Declare a `settings` array and deeno renders the form for you: the plugin row on
the **Plugins** page gets a gear button that opens a modal. Values are stored
outside your plugin folder (`system/plugin-data.php`), so reinstalling or
updating the plugin never wipes them.

```json
{
  "name": "Share buttons",
  "version": "2.0",
  "settings": [
    { "key": "networks", "label": "Networks", "type": "text",
      "default": "telegram,vk,x", "hint": "Comma-separated." },
    { "key": "limit",    "label": "How many", "type": "number",  "default": 5 },
    { "key": "compact",  "label": "Compact",  "type": "checkbox", "default": false },
    { "key": "mode",     "label": "Mode",     "type": "select",  "default": "full",
      "options": { "full": "Full", "short": "Short" } }
  ]
}
```

Field types: `text`, `textarea`, `number`, `checkbox`, `select` (a `select`
requires `options`). A field without a valid `key`/`type` is ignored, so a
malformed manifest can never break the Plugins page.

Read the values in `plugin.php`:

```php
$networks = PluginManager::setting('share-buttons', 'networks');   // one value
$all      = PluginManager::settings('share-buttons');              // all of them
```

The first argument is your plugin's **folder name**. Values come back typed
(`checkbox` → bool, `number` → int); if nothing is saved yet, you get the
`default` from the manifest.

## plugin.php

The file is simply executed on CMS startup (both on the site and in the admin
panel). It usually attaches one or two listeners via `Hooks::add()`:

```php
<?php
declare(strict_types=1);

Hooks::add('post.content', function (string $html, array $ctx): string {
    // $ctx['post'] — the Post object (title, slug, category, tags…)
    return $html . '<p>Made it to the end? Thanks!</p>';
});
```

## Hooks

### Filters — change a value

The listener receives a value and a context and returns a new value
(returning `null` = leave it unchanged).

| Filter | Value | Context | When |
|---|---|---|---|
| `post.content` | article HTML | `['post' => Post]` | When a theme renders a post/page |
| `site.head` | HTML string | — | The theme inserts it into `<head>` |
| `site.footer` | HTML string | — | The theme inserts it before `</body>` |
| `admin.head` | HTML string | — | Inserted into `<head>` of the admin panel |
| `editor.toolbar` | HTML string | — | Extra buttons at the end of the editor toolbar |

`admin.head` and `editor.toolbar` run in the admin panel, which sends a strict
CSP with a nonce — inline `<script>` will be blocked, link an external file
instead. Toolbar buttons should copy the markup of their neighbours:
`<button type="button" data-md="…">`.

Example — an analytics counter in the footer:

```php
Hooks::add('site.footer', function (string $html): string {
    return $html . '<script src="/media/counter.js" defer></script>';
});
```

### Events — notify that something happened

The listener receives a payload and returns nothing.

| Event | Payload | When |
|---|---|---|
| `post.saved` | `['filename' => ..., 'type' => post\|page]` | After saving from the admin |
| `post.deleted` | `['file' => ..., 'type' => ...]` | After deletion |
| `media.uploaded` | `['url' => ..., 'path' => ...]` | After a file is uploaded |

Example — ping a search engine on publish:

```php
Hooks::add('post.saved', function (array $p): void {
    @file_get_contents('https://example.com/ping?updated=' . urlencode($p['filename']));
});
```

### Priority — who runs first

`Hooks::add()` takes an optional third argument: lower runs earlier, default
`10`. Plugins load in alphabetical order of their folders, so never rely on that
— set a priority when your listener must run before or after someone else's.
Listeners with the same priority keep their registration order.

```php
Hooks::add('post.content', $fn, 5);    // early: before the default listeners
Hooks::add('post.content', $fn);       // 10 — the usual case
Hooks::add('post.content', $fn, 50);   // late: after everyone else
```

## Own URLs

A plugin can serve its own address. Register it in `plugin.php`; the handler
prints the response itself.

```php
PluginManager::route('robots-extra.txt', function (array $segments): void {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /admin/\n";
});
```

deeno asks plugins **only when nothing of its own matched** the address, so a
plugin can add URLs but can never shadow an existing post, page or category.
`$segments` is the URL split by `/`.

## Rules

1. **Escape everything you output** from post data: `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
2. Never output anything directly (`echo`) from a hook — only through a filter's return value, or you'll break the full-page cache and headers. (Inside your own route handler `echo` is expected: there you *are* the response.)
3. Heavy work in `post.saved`/`media.uploaded` slows down saving — move it out or make it lazy.
4. One plugin, one job. See the examples in `/plugins/`: `reading-time` (a content filter), `external-links` (HTML parsing), `lazy-images` (a minimal filter), `table-of-contents` (a heading-based TOC), `share-buttons` (share buttons + reusing `SocialIcons`).

## For theme authors

For plugins using `site.head`/`site.footer` to work in your theme,
add this to `layout.php`:

```php
<?= Hooks::filter('site.head', '') ?>   <!-- before </head> -->
<?= Hooks::filter('site.footer', '') ?> <!-- before </body> -->
```

`post.content` works in any theme automatically — the filter is applied
inside `$post->content()`.
