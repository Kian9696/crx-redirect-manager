# CRX Redirect Manager

A lightweight, no‑nonsense WordPress plugin to **create and manage redirects** with two interchangeable execution engines:

- **.htaccess engine** (fast, handled by the web server; Apache/LiteSpeed)
- **PHP engine** (handled by WordPress; useful when `.htaccess` is unavailable)

> Built to support clean path redirects, **Unicode‑safe regex rules**, and **301 / 302 / 410** responses.  
> Current plugin version: **2.1.0**

---

## Table of Contents

- [Key Features](#key-features)
- [How It Works](#how-it-works)
  - [Engines](#engines)
  - [Rule Schema](#rule-schema)
  - [Routing & Normalization](#routing--normalization)
  - [Regex Support](#regex-support)
  - [Query Strings & Trailing Slashes](#query-strings--trailing-slashes)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Add a redirect](#add-a-redirect)
  - [Use regex rules](#use-regex-rules)
  - [Choose the execution engine](#choose-the-execution-engine)
  - [Write to .htaccess](#write-to-htaccess)
  - [Delete a rule](#delete-a-rule)
- [Admin Location](#admin-location)
- [Security Notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Programmatic API (for developers)](#programmatic-api-for-developers)
- [Changelog](#changelog)
- [License](#license)

---

## Key Features

- **Two execution engines**
  - **.htaccess (Apache/LiteSpeed)**: Generates a dedicated block `# BEGIN/END CRX Redirects` with optimized `RewriteRule`s (`QSA`, `NE`, `L`) and `G` for 410.
  - **PHP (WordPress)**: Runs on the `template_redirect` hook (priority `0`) and applies rules early in the WP request.
- **Redirect types**: `301 (Permanent)`, `302 (Temporary)`, `410 (Gone)`.
- **Regex & plain path rules**: Use simple paths or **regex** patterns (Unicode‑safe) to match and transform URLs.
- **Safe path normalization**: Supports plain paths or full URLs; converts to normalized site‑relative paths when needed.
- **Query string preservation**: Appends the original query string to the destination by default.
- **Trailing slash friendly**: Matches both `/path` and `/path/` for non‑regex rules.
- **Server detection**: Only writes `.htaccess` when the server looks Apache/LiteSpeed.
- **Minimal, focused UI**: Manage everything under **Tools → CRX Redirects**.
- **Clean storage**: Rules are stored in one option: `crx_redirect_rules`.

> **Note:** The header comment in the main plugin file mentions *405*; the actual implemented status for “removed” URLs is **410 (Gone)** and is what the UI provides.

---

## How It Works

### Engines

- **.htaccess engine** (`includes/class-crx-engine-htaccess.php`)
  - Writes a single managed block to your site’s `.htaccess` (`# BEGIN CRX Redirects … # END CRX Redirects`).
  - For **410** it emits `RewriteRule … - [G,L]`.
  - For **301/302** it emits `RewriteRule … {target} [R=301|302,L,NE,QSA]` and sets `Options -MultiViews` + `RewriteEngine On`.
  - Computes the `.htaccess` path using WordPress’s `get_home_path()` when available.

- **PHP engine** (`includes/class-crx-engine-php.php`)
  - Evaluates only the rules tagged with engine = `php`.
  - Fires on `template_redirect` to run **before** theme rendering.
  - **Unicode‑safe regex**: ensures the `u` modifier is present so Persian/RTL and other Unicode paths work reliably.
  - Preserves the incoming query string and issues `wp_redirect()` with the chosen code.

The main plugin class lives in `crx-redirect-manager.php` and wires up the admin UI, saving, activation/deactivation hooks, and the engines.

### Rule Schema

Each rule is stored as an array element in the `crx_redirect_rules` option:

```php
[
  'from'   => '/source-path' | 'regex:^blog/(.*)$',
  'to'     => '/target-path' | 'https://example.com/anywhere' | ''  // empty when type = 410
  'type'   => 301 | 302 | 410,
  'regex'  => 0 | 1,
  'engine' => 'htaccess' | 'php',
]
```

### Routing & Normalization

- You can enter a **full URL** or a **relative path** in **From** and **To** fields.
- The plugin **normalizes** input:
  - Extracts the path part of any full URL you enter (for matching).
  - Ensures site‑relative targets start with `/`.
  - Collapses duplicate slashes and handles leading/trailing slashes predictably.
- For **410** rules, the **To** field is ignored and stored as an empty string.

### Regex Support

- To create a regex rule, prefix the **From** value with `regex:` in the UI.
  - Example: `regex:^blog/(.*)$`
- For **PHP engine**, the plugin automatically adds the `u` modifier to make the pattern **Unicode‑aware**.
- For **.htaccess engine**, the plugin wraps your pattern with `^…$` if you didn’t, so it matches the full path.
- **Back‑references** work:
  - In the PHP engine, `preg_replace()` is used.
  - In `.htaccess`, Apache `RewriteRule` handles grouping/refs as usual.

### Query Strings & Trailing Slashes

- **Query strings** from the original request are **preserved and appended** to the target (`QSA` in `.htaccess` and manual append in PHP).
- **Trailing slashes** on non‑regex rules are matched both ways: `/old` and `/old/`.

---

## Requirements

- WordPress (modern versions; uses standard admin APIs and hooks).
- PHP **7.4+** recommended (uses scalar type hints and return types).
- For `.htaccess` engine:
  - Apache or LiteSpeed.
  - `.htaccess` must be **writable** (or its directory, if the file doesn’t exist yet).

---

## Installation

1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate **CRX Redirect Manager** from **Plugins** in your WordPress admin.
3. Go to **Tools → CRX Redirects** to add and manage rules.

---

## Usage

### Add a redirect

1. Go to **Tools → CRX Redirects**.
2. Fill **From** (source) and **To** (destination). You can paste a full URL or a relative path.
3. Choose **Type**: `301`, `302`, or `410 (Gone)`.
4. Pick the **Engine** (`.htaccess` for performance, `PHP` if web‑server rules aren’t an option).
5. Click **Add Redirect**. The plugin will save the rule and (re)write the `.htaccess` block if needed.

### Use regex rules

- In **From**, start with `regex:` followed by your pattern.  
  Example: `regex:^category/(.+)/page/([0-9]+)/?$`
- In **To**, use standard back‑references if you need them: `/new/$1?page=$2`.
- Ensure your pattern matches the **path without the leading slash** in the PHP engine’s match context (the plugin already normalizes appropriately for both engines).

### Choose the execution engine

- **.htaccess**: Best performance; runs at the web server level. Requires Apache/LiteSpeed and a writable `.htaccess`.
- **PHP**: Runs inside WordPress; useful on hosts where `.htaccess` cannot be modified or when you prefer to keep redirect logic in WP.

### Write to .htaccess

- On **add** and **delete**, the plugin attempts to rebuild the managed `.htaccess` block automatically.
- You’ll see an admin notice confirming whether writing succeeded. If it didn’t, see **Troubleshooting**.

### Delete a rule

- In the rules table, click **Delete** on the desired row. The plugin removes the rule and rewrites `.htaccess` accordingly.

---

## Admin Location

- **Menu**: `Tools → CRX Redirects`
- The page shows:
  - Server software detection (`Apache`/`LiteSpeed` hint).
  - Detected `.htaccess` path and writability checks.
  - A form to add new rules.
  - A table listing all existing rules with index, from, to, type, regex flag, engine, and actions.

---

## Security Notes

- Admin actions are protected by **nonces** and **capability checks** (`manage_options`).
- Inputs are sanitized/normalized:
  - URLs are validated with `esc_url_raw()` when external.
  - Paths are normalized via `wp_parse_url()` and custom logic.
- Rules are stored in a single WordPress option: **`crx_redirect_rules`**.

---

## Troubleshooting

- **“.htaccess could not be written”**
  - Ensure you are on **Apache/LiteSpeed**.
  - Verify the file path shown in the admin is correct.
  - Make sure **`.htaccess`** or its directory is **writable** by the web server user.
  - Some security plugins may lock `.htaccess` — temporarily disable lock protection to update rules.
- **Redirect doesn’t trigger**
  - If you used **regex**, confirm the pattern matches the **path** (without protocol/domain) and consider anchors `^` and `$`.
  - Clear any caching layers (server cache, CDN, browser).
  - If using the PHP engine, make sure no earlier exit/redirect happens from other plugins or custom code.
- **Infinite loop**
  - Do not redirect `/new` → `/new` nor create rules that target a URL that matches the same rule again.

---

## Programmatic API (for developers)

You can read/update the option directly (be careful to preserve structure):

```php
$rules = get_option('crx_redirect_rules', []);

$rules[] = [
  'from'   => '/old',
  'to'     => '/new',
  'type'   => 301,
  'regex'  => 0,
  'engine' => 'htaccess',
];

update_option('crx_redirect_rules', $rules);

// Rebuild the managed .htaccess block
if ( class_exists('CRX_Engine_Htaccess') ) {
    CRX_Engine_Htaccess::write_block($rules);
}
```

> Tip: To create a **410 (Gone)** rule programmatically, set `'type' => 410` and `'to' => ''`.

---

## Changelog

### 2.1.0
- Unicode‑safe regex handling in PHP engine.
- Cleaner path normalization for both engines.
- Explicit support for `410 (Gone)`.
- Managed `.htaccess` block with `Options -MultiViews`, `NE`, and `QSA` flags.
- Admin UI under **Tools → CRX Redirects** with add/delete and status notices.

> Earlier versions are not cataloged here.

---

## License

This project is licensed under the **GPLv2 or later**. See the plugin header for details.
