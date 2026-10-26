# MCP Connect campaign

End-to-end checks of the MCP Connect module (issue #46) against the Docker bench of `tools/e2e/`: discovery, the full OAuth flow with Claude Code's real client metadata document, token audience (POST-body `rest_route` and method-override variants included, nested `rest_do_request()` calls of an ability allowed), refresh rotation with its 60 s grace window, concurrent refreshes and reuse detection, the optional `resource` / `scope` of the refresh grant, secrets refused in the query string, authorization codes, `/authorize` errors, SSRF guards, the per-IP `/authorize` cap and the CIMD budgets (known client refetched outside the site-wide budget, stale row served), revocation, rate limiting (well-formed dead tokens never counted, malformed ones locking the address), kill switches and the status badge, the agent account (role repaired, role resynced on an admin load, forbidden capabilities denied at check time, uninstall reassignment), a replayed code under another `client_id` leaving the connection alone, the Activity Log (MCP events kept when administrators are untracked), consent refusal and editor approvers, user deletion while the module is off, the daily purge and the admin screens. Bench only: nothing in `tools/` is shipped.

## Run

```bash
tools/e2e/run.sh up                      # if the bench is not running
# deploy the working tree into the bench (see tools/e2e/README.md), then:
tools/e2e/mcp-connect/run.sh             # the campaign
tools/e2e/mcp-connect/run.sh --uninstall # + uninstall, cleanup checks, redeploy and reactivate
```

Each check prints `PASS` or `FAIL`, then `RESULT pass=N fail=N`; the exit code is non-zero when anything failed. A full run takes about two minutes.

| Variable | Default | |
|---|---|---|
| `BASE_URL` | `http://localhost:8089` | site under test |
| `ADMIN_USER` / `ADMIN_PASS` | `admin` / `admin` | administrator who approves |
| `DB_PREFIX` | `wp_` | table prefix |

Requirements on the host: bash, curl, openssl, `sha256sum`/`md5sum`, docker compose. The WordPress container must reach the Internet: the authorize step fetches `https://claude.ai/oauth/claude-code-client-metadata`.

## What it sets up

- Activates the Security, Activity Log and MCP Connect modules, resets the MCP Connect switches (`paused`, `trusted_only`, `dcr_enabled`) and clears the Security rate limiter and the MCP Connect throttles between sections (several checks fail on purpose and five malformed values lock the IP out; `/authorize` stores 30 requests per IP per 10 minutes; DCR allows 10 registrations per IP per hour).
- Creates the editor `e2e-editor` if missing (and throwaway administrators `e2e-admin2` and `e2e-admin3`, deleted by the run).
- Copies `fixtures/e2e-mcp-ability.php` into `wp-content/e2e-snippets/` (loaded by the `e2e-snippets` mu-plugin) and removes it at the end. It registers two public abilities: `e2e/create-draft`, so a write made through MCP can be found in the Activity Log, and `e2e/nested-rest`, which GETs `/wp/v2/posts` or `/wp/v2/settings` through `rest_do_request()` and returns the status, so the campaign checks that a dispatch nested in the MCP call is not refused by the token audience, and that forbidden capabilities written on the agent role are still denied through the token.

## Bench pitfalls

- **Nonces need a stable session.** The `X-E2E-User` mu-plugin creates a new session on every request, and a WordPress nonce is bound to the session token: a nonce read with the header never verifies on the next request. Every form POST (consent, AJAX) therefore uses a real sign-in through the login form, with a cookie jar. `X-E2E-User` is only used for GET checks (consent page headers, editor refusal, agent on `/wp-admin/`).
- **OPcache caches `wp-config.php`** (`revalidate_freq` 2 s on the image): after `wp config set LUMIA_DISABLE_MCP_CONNECT`, the web server keeps the old value for up to two seconds. The script waits 3 s.
- **Git Bash.** `MSYS_NO_PATHCONV=1` is only set for docker commands: the native curl of Git Bash, given it, no longer understands `-o /dev/null`. A request carrying a value such as `rest_route=/wp/v2/…` goes through `curl_np`, which turns the conversion off for that one call (Git Bash would rewrite the value into a Windows path).
- **Plain HTTP and loopback.** Documents carry `http://` URLs here, and the Site Health tests that call the site back (`discovery`, `authorization_header`) fail from inside the container (`localhost:8089` is the host port): the campaign only checks that they ran, and tests the `/ping` probe directly.
- **`--uninstall` wipes every Lümia Tools option**, Security and the migrated SKMT data included: run it last, on a bench you can re-seed.
- **Transactions for destructive checks.** The uninstall reassignment checks demote every administrator and run `Agent::remove()` inside `START TRANSACTION` … `ROLLBACK` in a single `wp eval`: nothing persists, and the run checks the roles afterwards.
- **Locale.** The bench site is in French, but most text checks match English strings: they hold while the new strings have no French translation. A check on markup (the status badge) uses classes only; when the `.po` gains a string a check matches, switch that check to markup or to the French text.
