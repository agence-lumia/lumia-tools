# A site ready for Claude

What a new agency site needs so that Claude, connected through MCP Connect, can do everything: Bricks, Lümia Motion, JavaScript and GSAP animations, snippets, SEO, plugins, diagnostics. The agency accepts the risk on its sites and revokes the connections once the work is done (MCP Connect > Connections > Revoke all). Detail of each piece: [modules/mcp-connect.md](modules/mcp-connect.md) and [modules/motion.md](modules/motion.md).

## wp-config.php

Nothing is required there since 2.4.1: under the Administrator preset (the default), Lümia Tools itself defines `BRICKS_ENABLE_PHP_ABILITIES` (bricks/execute-php) and `ALLOW_UNFILTERED_UPLOADS` (SVG, fonts, JSON) when wp-config.php does not, so a site created from the seed has them although the hosting template generates wp-config.php. A value written in wp-config.php always wins, `false` included.

Optional, when the template lets you add lines:

```php
// Lock the preset against a change in the admin.
define( 'LUMIA_MCP_AGENT_PRESET', 'admin' );

// A debug log Claude reads with lumia/read-error-log, never shown to visitors.
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '/path/outside/the/webroot/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
```

Never define `DISALLOW_UNFILTERED_HTML` (no JavaScript for Claude), `DISALLOW_FILE_MODS` (no plugin installs or updates) or `DISALLOW_FILE_EDIT`. The MCP Connect Help tab and Site Health list what still blocks.

## Plugins

| Plugin | Why |
|---|---|
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) 0.7.1 or later | serves the abilities over MCP |
| Lümia Tools, MCP Connect and Motion on | connection, `lumia/*` abilities, animations |
| FluentSnippets | `lumia/*-snippet` abilities |
| Rank Math | `rank-math/*` abilities and `lumia/seo-*` |
| Secure Custom Fields | SCF abilities (custom post types, field groups) |

## Seed

The seed carries the database and `wp-content`: the Bricks > AI toggles, the Bricks code execution roles, the Lümia Tools settings (MCP Connect, Tools for Claude, Motion) and the plugin settings all travel with it. Update Lümia Tools on the reference site before publishing a seed.

## Bricks

- Bricks > AI: "Enable Bricks abilities" on (MCP Connect turns it on when it is activated), and every ability on, `bricks/execute-php` and the four builder-permission abilities included (off by default in Bricks).
- Bricks > Settings > Custom code: code execution on, for administrators and the "Claude (MCP)" role.

## Server

- nginx: `location ^~ /.well-known/oauth- { try_files $uri /index.php?$args; }` (the `wp-dokploy-template` blocks hidden paths), and the `Authorization` header passed to PHP.
- PHP can write the plugin files directly (`get_filesystem_method()` = `direct`): an FTP prompt cannot be answered through MCP.

## Claude

- Add the site as a custom connector: `https://<site>/wp-json/mcp/mcp-adapter-default-server`, then approve in WordPress.
- In the connector settings, set `mcp-adapter-execute-ability` to "Always allow", otherwise every call asks for an approval.
