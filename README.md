<p align="center">
  <img src="docs/banner.png" alt="deeno — upload. login. publish." width="720">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/License-MIT-green" alt="License: MIT">
  <img src="https://img.shields.io/badge/version-1.1.0-4F6EF7" alt="Version 1.1.0">
  <img src="https://img.shields.io/badge/database-none-4F6EF7" alt="No database">
</p>

<h3 align="center">🦖 A simple system, simple for everyone.</h3>

**deeno** is a website in three steps. Upload the files, log in, and publish your
first post. No database, no setup rituals, no build tools — it runs on the cheapest
shared hosting you can find, and the whole thing is just files you own.

Write in a normal editor. Your content is plain text files, so nothing is ever
locked inside a database you can't reach.

## Why deeno

- **No database.** Nothing to create, configure, or back up separately.
- **Runs anywhere.** Any shared hosting with PHP 8.0+. Nothing to compile.
- **Yours, in plain files.** Content is Markdown, settings are plain files. Move it,
  copy it, edit it in Notepad — it's just files.
- **Everything included.** Editor, media library, SEO, RSS, search, dark mode,
  statistics, revision history, one-click backups, roles, themes and plugins —
  out of the box.
- **Fast by default.** Full-page cache, optimized images, no bloat.

## Get started

1. Upload the files to your host.
2. Open `https://your-site/install.php`.
3. Create your account — and publish.

That's it. The wizard checks everything for you and cleans up after itself.

## Updating

Replace the code, keep your data:

- **Overwrite:** `index.php`, `admin/`, `system/`, `themes/`, `plugins/` — except
  the runtime files listed below, which live inside those folders
- **Leave alone:** `config.php`, `users/`, `content/` (posts, pages and their
  revision history), `media/`, `cache/`, `backups/`, `system/secret.key`,
  `system/security-data.php`, `system/security.json`, `system/categories.php`,
  `system/redirects.json`, `system/plugin-data.php`, `system/logs/`,
  `system/sessions/`

Overwriting any of those loses real data: `plugin-data.php` holds every plugin's
settings, `categories.php` your category names, `redirects.json` the 301s created
when an address changed.

Then open the admin panel once. deeno compares the data version stored in
`config.php` with the code version and applies any pending migrations itself —
you'll see a toast when it does. Nothing else is required; the release notes
will say so explicitly if a release ever needs a manual step.

Back up before updating: **Backups → Create backup**.

## Screenshots

<p align="center">
  <img src="docs/screenshot1.png" alt="deeno admin dashboard" width="860">
</p>
<p align="center">
  <img src="docs/screenshot2.png" alt="deeno post editor" width="860">
</p>
<p align="center">
  <img src="docs/screenshot3.png" alt="deeno command palette (Cmd+K)" width="860">
</p>

## Documentation

Full guides for using, theming and extending deeno live in
**[UserDoc/](UserDoc/)** — usage, themes, plugins and the changelog.

Building on deeno? Start with [UserDoc/USER-GUIDE.md](UserDoc/USER-GUIDE.md),
then [THEME.md](UserDoc/THEME.md) and [PLUGINS.md](UserDoc/PLUGINS.md).

## License

MIT — see [LICENSE](LICENSE). Do what you like with it.
