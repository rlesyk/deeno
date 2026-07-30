# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] — 2026-07-30

### Added

- **Plugins v2.** Plugins can now declare **settings** in `plugin.json` — deeno
  renders the form and adds a gear button on the Plugins page; values are kept
  in `system/plugin-data.php`, outside the plugin folder, so reinstalling or
  updating a plugin never wipes them (`PluginManager::setting()` reads them).
  Plugins can also **serve their own URLs** (`PluginManager::route()`), which are
  only consulted when nothing of deeno's own matched, so they can't shadow an
  existing post, page or category. Hook listeners take an optional **priority**
  (`Hooks::add($hook, $fn, 5)`), and two new filters — `admin.head` and
  `editor.toolbar` — extend the admin panel and the editor toolbar. The stock
  **Share buttons** plugin now uses this: the networks and the label are
  configurable.

- **Two new blog themes** in the deeno UI house style (indigo accent, Inter,
  hairlines, light/dark, RU/EN), each with a distinct idea and functionality:
  - **deeno-mag** — an editorial magazine: a curated homepage with a featured
    lead story pulled from sticky posts, per-category sections, reading time,
    and a top category navigation.
  - **deeno-author** — a personal author blog: an author hero with bio and
    social links, a chronological timeline grouped by year, a tag cloud,
    reading time, a drop-cap reading column, an author card and prev/next.

- **Demo mode** (`"demo_mode": true` in the config) for public sandbox
  deployments. Server-side (not just in the UI) it locks the sandbox down so a
  stranger can explore the panel without defacing or abusing it: deleting
  content, managing users, backups, themes, plugins and categories, changing
  site settings, uploading media, and editing the admin profile are all blocked;
  any post or page a visitor saves is forced to `draft`, so nothing they create
  reaches the public site. Visitors can still open the editor, write draft
  content, reorder, and switch their own panel theme and language. A banner
  marks the panel as a demo, blocked actions show a toast, and the login screen
  can display the demo credentials (`demo_login` / `demo_pass`).

### Changed

- **RSS and social links are plugins now.** The feed (`/rss.xml`, its
  autodiscovery link and the item count) moved into the stock **RSS feed**
  plugin, and the profile links moved into **Social links** — deeno's core no
  longer knows about either. Both ship enabled on a fresh install, so nothing
  changes out of the box; turn a plugin off and the feature is gone entirely,
  settings included. Themes need no changes: `$site->rss` and `$site->social`
  are now filled through the `site.rss` / `site.social` filters and are simply
  empty when the plugins are off.

  **Upgrading from 1.0:** enable *RSS feed* and *Social links* on the Plugins
  page — your existing feed setting and social URLs are picked up automatically.
  The Settings page no longer has those fields.

### Security

- **Backups could be downloaded by anyone who guessed the file name.** The
  protective `.htaccess` files for `/backups/`, `/users/`, `/cache/` and
  `/system/logs/` never made it into the distribution — `.gitignore` excluded the
  whole contents of those folders — so a copy downloaded from GitHub had them
  unprotected. Combined with a predictable backup name
  (`backup-YYYY-MM-DD-HHMMSS.zip`), that let anyone fetch a full site archive,
  including `config.php` and the `users/` files with bcrypt password hashes.
  Backup names now carry a random suffix, the `.htaccess` files ship with the
  distribution, and the installer recreates them if they are missing.

  **What to do after updating:** check `/backups/` on your site — if
  `https://yoursite/backups/<name>.zip` downloads instead of returning 403, move
  the existing archives out of the web root and change your admin password, as
  the hash may have leaked. On nginx use `nginx.conf.example`: `.htaccess` is
  ignored there, and on hosts where nginx serves static files directly it does
  not apply to `.zip` even on Apache.

## [1.0.0] — 2026-07-17

First public release. A complete, database-less flat-file CMS.

### Content

- Posts and static pages in Markdown with a YAML frontmatter header.
- Categories (title, slug, description, merging) and tags.
- A navigation menu built from static pages.
- Post statuses: draft, published, sticky, scheduled, unlisted.
- Site search and automatic 301 redirects when a post's address changes.
- RSS 2.0 feed and Sitemap 0.9.

### Editor

- Formatting toolbar: headings, bold/italic/strikethrough, highlight, text color,
  alignment, lists, quote, tables, inline/block code, links.
- Image and video (YouTube/Vimeo) embedding.
- Image crop and rotation on upload; live preview; scheduled publishing.
- Automatic draft recovery from the browser.
- Built with no external libraries.

### Media

- Media library with grid/list views and drag-and-drop upload.
- Automatic photo optimization on upload: downscale, compression, EXIF rotation
  baked into pixels, GPS metadata stripping.
- SVG and ICO support (SVG is sanitized on upload).

### Admin panel

- Dashboard with counters, a views chart, top pages and a security checklist.
- View statistics without cookies or IP storage (daily-salted hashes).
- Roles: administrator / editor / author, each with its own permissions.
- Jump bar (⌘K) for instant navigation.
- Light and dark admin themes; bilingual interface (English / Russian).
- One-click ZIP backups.

### Themes and plugins

- Themes are plain PHP templates with inheritance from `default`, no build step.
- Four bundled themes: `default`, `journal`, `deeno-news`, `deeno-docs`
  (a documentation/wiki theme with a navigation tree, on-page TOC with scroll-spy,
  breadcrumbs, and drag-and-drop arrangement for logged-in editors).
- An `example` starter theme (all markup and classes, no styling).
- Hook-based plugins (filters and events); five bundled: external links,
  lazy images, reading time, table of contents, share buttons.

### Security

- CSRF protection on every form; bcrypt password hashing.
- Brute-force protection by IP and by username.
- A strict Content-Security-Policy on the public site (inline scripts allowed by
  sha256 hash; external domains configurable in Settings → External scripts).
- HMAC-signed preview and password-reset tokens; sessions invalidated on password
  change.
- Self-protecting data files (`config.php`, `users/`, `secret.key` return 403).
- Upload type allowlist with MIME checked by content.
- HTML sanitization for non-administrator content.

### Reliability & performance

- Full-page cache with automatic content-based invalidation.
- Atomic writes for all data files (no corruption on interrupted writes).
- Append-based view statistics that don't slow down page serving.
- Cache-busting for theme assets and post covers.
