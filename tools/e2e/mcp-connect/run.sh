#!/usr/bin/env bash
# MCP Connect end-to-end campaign (issue #46), against the Docker bench of
# tools/e2e. Prints PASS/FAIL per check, a final count, and exits non-zero on
# any failure. See README.md in this folder. TEST BENCH ONLY, never shipped.
#
# Usage: tools/e2e/mcp-connect/run.sh [--uninstall]
#   BASE_URL     site under test (default http://localhost:8089)
#   ADMIN_USER   administrator login (default admin)
#   ADMIN_PASS   its password (default admin)
#   DB_PREFIX    table prefix (default wp_)
#   --uninstall  last step: uninstall the plugin, check the cleanup, then
#                redeploy the working tree and reactivate (only if all passed)

set -u
# Git Bash: MSYS_NO_PATHCONV is set for docker only (see dc()); a native curl
# given it would no longer understand -o /dev/null.

BASE_URL=${BASE_URL:-http://localhost:8089}
BASE_URL=${BASE_URL%/}
ADMIN_USER=${ADMIN_USER:-admin}
ADMIN_PASS=${ADMIN_PASS:-admin}
DB_PREFIX=${DB_PREFIX:-wp_}
DO_UNINSTALL=0
[ "${1:-}" = "--uninstall" ] && DO_UNINSTALL=1

HERE=$(cd "$(dirname "$0")" && pwd)
E2E_DIR=$(cd "$HERE/.." && pwd)
REPO_DIR=$(cd "$E2E_DIR/../.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

CLIENT_ID=https://claude.ai/oauth/claude-code-client-metadata
MCP="$BASE_URL/wp-json/mcp/mcp-adapter-default-server"
OAUTH="$BASE_URL/wp-json/lumia-mcp/v1"
PORT=$((20000 + RANDOM % 40000))
REDIRECT="http://localhost:$PORT/callback"
LABEL="E2E MacBook"
EDITOR_LOGIN=e2e-editor
EDITOR_PASS=e2e-editor-pass-1
H_JSON='Content-Type: application/json'
H_ACCEPT='Accept: application/json, text/event-stream'
H_PROTO='MCP-Protocol-Version: 2025-06-18'
T_TOKENS="${DB_PREFIX}lumia_mcp_tokens"
T_CONN="${DB_PREFIX}lumia_mcp_connections"
T_CLIENTS="${DB_PREFIX}lumia_mcp_clients"
T_LOG="${DB_PREFIX}lumia_activity_log"

pass=0
fail=0
FAILED=()

# ------------------------------------------------------------------ reporting

ok() { echo "PASS $1"; pass=$((pass + 1)); }
ko() {
	echo "FAIL $1${2:+ ($2)}"
	fail=$((fail + 1))
	FAILED+=("$1")
}
# check_eq <name> <got> <expected>
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else ko "$1" "expected [$3], got [$2]"; fi; }
# check_re <name> <text> <extended regex>
check_re() {
	if printf '%s' "$2" | grep -qE -- "$3"; then ok "$1"; else
		ko "$1" "no /$3/"
		printf '%s\n' "$2" | head -15 | sed 's/^/      | /'
	fi
}
# check_no_re <name> <text> <extended regex>
check_no_re() {
	if printf '%s' "$2" | grep -qE -- "$3"; then
		ko "$1" "unexpected /$3/"
		printf '%s\n' "$2" | grep -E -- "$3" | head -5 | sed 's/^/      | /'
	else ok "$1"; fi
}
section() { echo; echo "=== $1"; }

# ------------------------------------------------------------------ bench access

dc() { (cd "$E2E_DIR" && MSYS_NO_PATHCONV=1 docker compose "$@"); }
wpcli() { dc run --rm -T cli wp "$@" 2>/dev/null | tr -d '\r'; }
sql() { dc exec -T db mariadb -uwordpress -pwordpress wordpress -N -B -e "$1" 2>/dev/null | tr -d '\r'; }
sha256() { printf '%s' "$1" | sha256sum | cut -c1-64; }
md5() { printf '%s' "$1" | md5sum | cut -c1-32; }

# Clears the Security rate limiter (several checks fail on purpose, and five
# malformed values lock the bench IP out) and the MCP Connect throttles: the
# per-IP /authorize counter (30 per 10 min), the per-IP and site-wide CIMD
# fetch budgets, the per-IP DCR counter (10 per hour: two runs a day hit it).
reset_limiter() {
	sql "DELETE FROM ${DB_PREFIX}options WHERE option_name LIKE '%\\_lumia\\_rl\\_%' OR option_name LIKE '%\\_lumia\\_mcp\\_authz\\_%' OR option_name LIKE '%\\_lumia\\_mcp\\_cimd\\_ip\\_%' OR option_name LIKE '%\\_lumia\\_mcp\\_cimd\\_budget' OR option_name LIKE '%\\_lumia\\_mcp\\_dcr\\_%'" >/dev/null
}
# A native curl given a value like rest_route=/wp/v2/… under Git Bash rewrites
# it into a Windows path: path conversion off for that call only (never with
# -o /dev/null, see above).
curl_np() { MSYS_NO_PATHCONV=1 curl "$@"; }
# set_transient <name> <php value> <ttl>
set_transient() { wpcli eval "set_transient('$1', $2, $3);" >/dev/null; }
# Rows of the Security limiter (one per IP with a counted failure).
limiter_rows() { sql "SELECT COUNT(*) FROM ${DB_PREFIX}options WHERE option_name LIKE '\\_transient\\_\\_lumia\\_rl\\_%'"; }

# set_setting <key> <php value>
set_setting() {
	wpcli eval "\$o = get_option('lumia_module_mcp_connect', []); \$o = is_array(\$o) ? \$o : []; \$o['$1'] = $2; update_option('lumia_module_mcp_connect', \$o);" >/dev/null
}
# set_module <id> <activate|deactivate>
set_module() {
	wpcli eval "\$m = \\Lumia\\Tools\\Core\\Plugin::instance()->modules; \$m->register_default_modules(false); var_export(\$m->$2('$1'));"
}

# wp-config.php is cached by OPcache (revalidate_freq 2 s on the bench image):
# a constant written by wp-cli is only seen by the web server after that delay.
opcache_wait() { sleep 3; }

debug_log_lines() { dc exec -T wordpress bash -c 'wc -l < /var/www/html/wp-content/debug.log 2>/dev/null || echo 0' | tr -d '\r '; }

# ------------------------------------------------------------------ HTTP helpers

status() { printf '%s' "$1" | head -1 | awk '{print $2}'; }
header() { printf '%s' "$1" | tr -d '\r' | awk -v h="$(echo "$2" | tr 'A-Z' 'a-z')" 'BEGIN{FS=": "} /^$/{exit} tolower($1)==h{sub(/^[^:]*: /,""); print; exit}'; }
body() { printf '%s' "$1" | tr -d '\r' | awk 'b{print} /^$/{b=1}'; }
json_str() { printf '%s' "$1" | grep -oE "\"$2\":\"[^\"]*\"" | head -1 | sed -E 's/^"[^"]*":"//; s/"$//'; }

pkce_new() {
	VERIFIER=$(openssl rand -base64 96 | tr -d '\n=' | tr '+/' '-_' | cut -c1-64)
	CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')
	STATE="st-$RANDOM-$RANDOM"
}

# auth_req [key=value ...]: GET /authorize with the standard parameters,
# overridden by the arguments (value __unset__ drops a parameter).
auth_req() {
	declare -A p=(
		[response_type]=code [client_id]="$CLIENT_ID" [redirect_uri]="$REDIRECT" [state]="$STATE"
		[code_challenge]="$CHALLENGE" [code_challenge_method]=S256 [resource]="$MCP" [scope]="mcp offline_access"
	)
	local kv k args=()
	for kv in "$@"; do p[${kv%%=*}]="${kv#*=}"; done
	for k in "${!p[@]}"; do [ "${p[$k]}" = "__unset__" ] || args+=(--data-urlencode "$k=${p[$k]}"); done
	curl -s -i -G "${args[@]}" "$OAUTH/authorize"
}

# token_req key=value ...: form-encoded POST to /token.
token_req() {
	local kv args=()
	for kv in "$@"; do args+=(--data-urlencode "$kv"); done
	curl -s -i "${args[@]}" "$OAUTH/token"
}
exchange() { token_req grant_type=authorization_code "code=$1" "redirect_uri=${3:-$REDIRECT}" "client_id=$CLIENT_ID" "code_verifier=$2" "resource=$MCP"; }
# refresh <refresh token> [key=value ...]
refresh() {
	local t=$1
	shift
	token_req grant_type=refresh_token "refresh_token=$t" "client_id=$CLIENT_ID" "$@"
}
CLIENT_ID_ENC=$(printf '%s' "$CLIENT_ID" | sed 's/:/%3A/g; s#/#%2F#g')
UNKNOWN_AT="lmc_at_$(printf '0%.0s' {1..64})"

# login <login> <password> <cookie jar>: signs in through the real login form
# (custom login slug included), so nonces are bound to a stable session.
login() {
	local loc url
	loc=$(header "$(curl -s -i "$BASE_URL/wp-admin/")" location)
	url=${loc%%\?*}
	[ -n "$url" ] || url="$BASE_URL/wp-login.php"
	rm -f "$3"
	curl -s -c "$3" -b "$3" -o /dev/null "$url"
	curl -s -c "$3" -b "$3" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		-d 'wp-submit=Log+In&testcookie=1' --data-urlencode "redirect_to=$BASE_URL/wp-admin/" "$url"
	grep -q 'wordpress_logged_in' "$3"
}

consent_url() { echo "$BASE_URL/wp-admin/admin.php?page=lumia-mcp-consent&request=$1"; }
nonce_of() { printf '%s' "$1" | grep -oE 'name="lumia_nonce" value="[^"]+"' | head -1 | sed -E 's/.*value="//; s/"$//'; }
# consent_post <jar> <request> <nonce> <decision> [label] [acting: agent|me]
consent_post() {
	curl -s -i -b "$1" --data-urlencode "lumia_nonce=$3" -d action=lumia_mcp_consent --data-urlencode "request=$2" \
		--data-urlencode "label=${5:-$LABEL}" -d "acting=${6:-agent}" -d "decision=$4" "$BASE_URL/wp-admin/admin-post.php"
}

# flow_code [label] [acting] [cookie jar]: authorize (plus the AUTH_EXTRA
# parameters) + consent; sets RQ, CODE, CONSENT_PAGE, INTERSTITIAL.
AUTH_EXTRA=()
flow_code() {
	local jar=${3:-$ADMIN_JAR}
	pkce_new
	RQ=$(header "$(auth_req "${AUTH_EXTRA[@]}")" location | grep -oE 'lmc_rq_[0-9a-f]{64}')
	CONSENT_PAGE=$(curl -s -i -b "$jar" "$(consent_url "$RQ")")
	INTERSTITIAL=$(consent_post "$jar" "$RQ" "$(nonce_of "$CONSENT_PAGE")" approve "${1:-$LABEL}" "${2:-agent}")
	CODE=$(printf '%s' "$INTERSTITIAL" | grep -oE 'code=lmc_ac_[0-9a-f]{64}' | head -1 | cut -d= -f2)
}
# flow [label] [acting] [cookie jar] [client_id]: flow_code + exchange; sets AT, RT, CONN.
flow() {
	flow_code "${1:-$LABEL}" "${2:-agent}" "${3:-$ADMIN_JAR}"
	TOKEN_RESP=$(token_req grant_type=authorization_code "code=$CODE" "redirect_uri=$REDIRECT" "client_id=${4:-$CLIENT_ID}" "code_verifier=$VERIFIER" "resource=$MCP")
	AT=$(json_str "$TOKEN_RESP" access_token)
	RT=$(json_str "$TOKEN_RESP" refresh_token)
	CONN=$(conn_of "$AT")
}
conn_of() { sql "SELECT connection_id FROM $T_TOKENS WHERE token_hash = '$(sha256 "$1")'"; }
conn_status() { sql "SELECT status FROM $T_CONN WHERE id = ${1:-0}"; }

MCP_INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"e2e","version":"0"}}}'
# mcp_raw <token> <json> [url]: one POST to the MCP route.
mcp_raw() {
	local auth=()
	[ -n "$1" ] && auth=(-H "Authorization: Bearer $1")
	curl -s -i -X POST -H "$H_JSON" -H "$H_ACCEPT" "${auth[@]}" -d "$2" "${3:-$MCP}"
}
mcp_status() { status "$(mcp_raw "$1" "$MCP_INIT")"; }
# mcp_session <token>: initialize + initialized; sets SID.
mcp_session() {
	local r
	r=$(mcp_raw "$1" "$MCP_INIT")
	SID=$(header "$r" mcp-session-id)
	curl -s -o /dev/null -X POST -H "$H_JSON" -H "$H_ACCEPT" -H "$H_PROTO" -H "Authorization: Bearer $1" -H "Mcp-Session-Id: $SID" \
		-d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$MCP"
}
mcp_call() {
	curl -s -X POST -H "$H_JSON" -H "$H_ACCEPT" -H "$H_PROTO" -H "Authorization: Bearer $1" -H "Mcp-Session-Id: $SID" -d "$2" "$MCP"
}
execute_ability() {
	local params=${3:-}
	[ -n "$params" ] || params="{}"
	mcp_call "$1" "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"$2\",\"parameters\":$params}}}"
}

# ================================================================== setup

section "Setup"
curl -s -o /dev/null --max-time 10 "$BASE_URL/" || { echo "Bench unreachable at $BASE_URL"; exit 2; }
LOG_START=$(debug_log_lines)
# The checks read English UI text: the bench runs in fr_FR (the rename capture
# needs it), so switch the site to English for the run and restore it on exit.
SITE_LANG=$(wpcli option get WPLANG)
wpcli option update WPLANG '' >/dev/null
trap 'wpcli option update WPLANG "$SITE_LANG" >/dev/null; rm -rf "$WORK"' EXIT
wpcli config delete LUMIA_DISABLE_MCP_CONNECT >/dev/null && opcache_wait
for m in security activity_log mcp_connect; do set_module "$m" activate >/dev/null; done
set_setting paused false
set_setting trusted_only true
set_setting dcr_enabled false
reset_limiter
dc exec -T wordpress bash -c 'mkdir -p /var/www/html/wp-content/e2e-snippets && cat > /var/www/html/wp-content/e2e-snippets/e2e-mcp-ability.php && chown -R www-data:www-data /var/www/html/wp-content/e2e-snippets' < "$HERE/fixtures/e2e-mcp-ability.php"
[ -n "$(wpcli user get "$EDITOR_LOGIN" --field=ID)" ] || wpcli user create "$EDITOR_LOGIN" e2e-editor@example.com --role=editor --user_pass="$EDITOR_PASS" >/dev/null
ADMIN_JAR="$WORK/admin.jar"
if login "$ADMIN_USER" "$ADMIN_PASS" "$ADMIN_JAR"; then ok "setup: admin signs in"; else ko "setup: admin signs in"; exit 1; fi
echo "      redirect_uri $REDIRECT, debug.log at line $LOG_START"

# ================================================================== 1. Discovery

section "1. Discovery"
r=$(curl -s -i -X POST -H "$H_JSON" -H "$H_ACCEPT" -d "$MCP_INIT" "$MCP")
check_eq "1 MCP route without token -> 401" "$(status "$r")" 401
check_re "1 WWW-Authenticate Bearer resource_metadata + scope" "$(header "$r" www-authenticate)" "^Bearer resource_metadata=\"$BASE_URL/\\.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server\", scope=\"mcp\"$"

PRM_URL="$BASE_URL/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server"
AS_URL="$BASE_URL/.well-known/oauth-authorization-server"
for u in "$PRM_URL" "$AS_URL"; do
	r=$(curl -s -i "$u")
	n=${u#"$BASE_URL"}
	check_eq "1 $n -> 200" "$(status "$r")" 200
	check_re "1 $n JSON" "$(header "$r" content-type)" '^application/json'
	check_re "1 $n no-store" "$(header "$r" cache-control)" 'no-store'
done
prm=$(curl -s "$PRM_URL" | tr -d ' \n')
as=$(curl -s "$AS_URL" | tr -d ' \n')
check_re "1 PRM resource = MCP URL" "$prm" "\"resource\":\"${MCP//\//\\/}\""
check_re "1 PRM authorization_servers = issuer" "$prm" "\"authorization_servers\":\\[\"${BASE_URL//\//\\/}\"\\]"
check_re "1 PRM scopes_supported = [mcp]" "$prm" '"scopes_supported":\["mcp"\]'
check_no_re "1 PRM without offline_access" "$prm" 'offline_access'
check_re "1 AS issuer" "$as" "\"issuer\":\"${BASE_URL//\//\\/}\""
check_re "1 AS S256 only" "$as" '"code_challenge_methods_supported":\["S256"\]'
check_re "1 AS CIMD supported" "$as" '"client_id_metadata_document_supported":true'
check_re "1 AS auth method none" "$as" '"token_endpoint_auth_methods_supported":\["none"\]'
check_re "1 AS iss parameter supported" "$as" '"authorization_response_iss_parameter_supported":true'
check_re "1 AS offline_access" "$as" '"scopes_supported":\["mcp","offline_access"\]'
check_re "1 AS endpoints" "$as" '"authorization_endpoint":"[^"]*/lumia-mcp/v1/authorize".*"token_endpoint":"[^"]*/lumia-mcp/v1/token".*"revocation_endpoint"'
check_no_re "1 AS no registration_endpoint while DCR off" "$as" 'registration_endpoint'
check_eq "1 /register 404 while DCR off" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "$H_JSON" -d '{}' "$OAUTH/register")" 404
set_setting dcr_enabled true
as=$(curl -s "$AS_URL" | tr -d ' \n')
check_re "1 AS registration_endpoint once DCR on" "$as" '"registration_endpoint":"[^"]*/lumia-mcp/v1/register"'
r=$(curl -s -i -X POST -H "$H_JSON" -d "{\"client_name\":\"E2E DCR\",\"redirect_uris\":[\"$REDIRECT\"],\"token_endpoint_auth_method\":\"none\"}" "$OAUTH/register")
check_eq "1 DCR registration -> 201" "$(status "$r")" 201
check_re "1 DCR client id issued" "$(body "$r")" '"client_id":"lmc_cl_[0-9a-f]{32}"'
DCR_ID=$(json_str "$(body "$r")" client_id)
pkce_new
r=$(auth_req "client_id=$DCR_ID")
# The DCR client and its redirect URI are valid: the refusal goes back to it.
check_re "1 DCR client refused while trusted_only on -> access_denied" "$(header "$r" location)" "^http://localhost:$PORT/callback\?error=access_denied&"
set_setting dcr_enabled false
check_eq "1 unknown .well-known stays 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/.well-known/oauth-other")" 404

# ================================================================== 2. Full flow

section "2. Full flow"
pkce_new
r=$(auth_req)
loc=$(header "$r" location)
check_eq "2 /authorize -> 302" "$(status "$r")" 302
check_re "2 redirect to the consent page" "$loc" "^$BASE_URL/wp-admin/admin\\.php\\?page=lumia-mcp-consent&request=lmc_rq_[0-9a-f]{64}$"
RQ=$(printf '%s' "$loc" | grep -oE 'lmc_rq_[0-9a-f]{64}')
r=$(curl -s -i "$(consent_url "$RQ")")
check_eq "2 consent page signed out -> 302 to the login" "$(status "$r")" 302
check_re "2 login keeps the request in redirect_to" "$(header "$r" location)" "redirect_to=.*lumia-mcp-consent%26request%3D$RQ"

r=$(curl -s -i -H "X-E2E-User: $ADMIN_USER" "$(consent_url "$RQ")")
check_eq "2 consent page (X-E2E-User admin) -> 200" "$(status "$r")" 200
check_re "2 consent shows the client name" "$r" 'Claude Code'
check_re "2 consent shows the verified badge" "$r" 'lumia-badge--success'
check_re "2 consent shows the redirect host" "$r" '<strong>localhost</strong>'
check_re "2 consent says the connection stays connected" "$r" 'and stay connected \(refresh\)'
check_re "2 consent X-Frame-Options DENY" "$(header "$r" x-frame-options)" '^DENY$'
check_re "2 consent CSP frame-ancestors none" "$(header "$r" content-security-policy)" "frame-ancestors 'none'"
check_re "2 consent no-store" "$(header "$r" cache-control)" 'no-store'

# The nonce is bound to the session: the POST uses the same signed-in cookie session.
CONSENT_PAGE=$(curl -s -i -b "$ADMIN_JAR" "$(consent_url "$RQ")")
NONCE=$(nonce_of "$CONSENT_PAGE")
check_re "2 consent form carries a nonce" "$NONCE" '^[0-9a-f]{10}$'
INTERSTITIAL=$(consent_post "$ADMIN_JAR" "$RQ" "$NONCE" approve)
check_eq "2 approve -> loopback interstitial 200" "$(status "$INTERSTITIAL")" 200
ISS_ENC=$(printf '%s' "$BASE_URL" | sed 's/:/%3A/g; s#/#%2F#g')
check_re "2 interstitial meta refresh to the callback with code, state, iss" "$INTERSTITIAL" \
	"http-equiv=\"refresh\" content=\"0;url=http://localhost:$PORT/callback\\?code=lmc_ac_[0-9a-f]{64}&#038;state=$STATE&#038;iss=$ISS_ENC\""
check_re "2 interstitial return link to the same URL" "$INTERSTITIAL" "href=\"http://localhost:$PORT/callback\\?code=lmc_ac_[0-9a-f]{64}&#038;state=$STATE&#038;iss=$ISS_ENC\""
CODE=$(printf '%s' "$INTERSTITIAL" | grep -oE 'code=lmc_ac_[0-9a-f]{64}' | head -1 | cut -d= -f2)
r=$(consent_post "$ADMIN_JAR" "$RQ" "$NONCE" approve)
check_eq "2 second approve of the same request refused -> 400" "$(status "$r")" 400

r=$(exchange "$CODE" "$VERIFIER")
b=$(body "$r")
check_eq "2 code exchange -> 200" "$(status "$r")" 200
check_re "2 token response no-store" "$(header "$r" cache-control)" 'no-store'
check_re "2 access token" "$b" '"access_token":"lmc_at_[0-9a-f]{64}"'
check_re "2 refresh token" "$b" '"refresh_token":"lmc_rt_[0-9a-f]{64}"'
check_re "2 token_type Bearer" "$b" '"token_type":"Bearer"'
check_re "2 expires_in" "$b" '"expires_in":[0-9]+'
AT=$(json_str "$b" access_token)
RT=$(json_str "$b" refresh_token)
CONN=$(conn_of "$AT")
MAIN_CONN=$CONN
check_eq "2 connection active" "$(conn_status "$CONN")" active
check_eq "2 connection label stored" "$(sql "SELECT label FROM $T_CONN WHERE id = $CONN")" "$LABEL"

r=$(mcp_raw "$AT" "$MCP_INIT")
check_eq "2 MCP initialize with the token -> 200" "$(status "$r")" 200
mcp_session "$AT"
check_re "2 MCP session id" "$SID" '.+'
r=$(mcp_call "$AT" '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')
check_re "2 tools/list returns tools" "$r" '"tools":\[\{"name":"mcp-adapter-'
r=$(execute_ability "$AT" core/get-user-info)
check_re "2 acting user is claude-mcp" "$r" '"user_login":"claude-mcp"'
check_re "2 acting user has the agent role" "$r" '"roles":\["lumia_mcp_agent"\]'

# ================================================================== 3. Audience

section "3. Audience"
r=$(curl -s -i -H "Authorization: Bearer $AT" "$BASE_URL/wp-json/wp/v2/users/me")
anon=$(curl -s -i "$BASE_URL/wp-json/wp/v2/users/me")
check_re "3 token on /wp/v2/users/me -> 401/403" "$(status "$r")" '^40[13]$'
# The Security module may answer 403 to anonymous /users requests: same answer as without a token.
check_eq "3 /wp/v2/users/me answers as for an anonymous request" "$(body "$r")" "$(body "$anon")"
r=$(curl -s -i -H "Authorization: Bearer $AT" "$BASE_URL/wp-json/wp/v2/settings")
check_eq "3 token on /wp/v2/settings -> 401" "$(status "$r")" 401
check_no_re "3 no Bearer challenge on another route" "$(header "$r" www-authenticate)" 'resource_metadata'
r=$(curl -s -X POST -H "Authorization: Bearer $AT" -d 'action=heartbeat&data[x]=1' "$BASE_URL/wp-admin/admin-ajax.php?rest_route=/mcp/mcp-adapter-default-server")
check_re "3 admin-ajax.php?rest_route=MCP with the token stays anonymous" "$r" '"wp-auth-check":false'
r=$(curl -s -i -H "Authorization: Bearer $AT" "$BASE_URL/wp-json/wp/v2/settings?rest_route=/mcp/mcp-adapter-default-server")
check_eq "3 other route + rest_route query -> 401" "$(status "$r")" 401
check_eq "3 ?rest_route= form of the MCP route with the token -> 200" "$(status "$(mcp_raw "$AT" "$MCP_INIT" "$BASE_URL/?rest_route=/mcp/mcp-adapter-default-server")")" 200
# WordPress lets a POST rest_route override the path and the query string:
# the MCP path with a form body naming another route must not carry the token
# there (the route would run as the acting user).
for route in /wp/v2/users/me /wp/v2/settings; do
	r=$(curl_np -s -i -H "Authorization: Bearer $AT" --data-urlencode "rest_route=$route" "$MCP")
	check_re "3 MCP path + POST rest_route=$route -> 401/403" "$(status "$r")" '^40[13]$'
	check_no_re "3 MCP path + POST rest_route=$route -> nothing of the acting user" "$(body "$r")" 'claude-mcp|"title":|"slug":'
	r=$(curl_np -s -i -H "Authorization: Bearer $AT" -H 'X-HTTP-Method-Override: GET' --data-urlencode "rest_route=$route" "$MCP")
	check_re "3 MCP path + POST rest_route=$route + method override -> 401/403" "$(status "$r")" '^40[13]$'
	check_no_re "3 MCP path + POST rest_route=$route + method override -> no data" "$(body "$r")" 'claude-mcp|"title":|"slug":'
	r=$(curl_np -s -i -H "Authorization: Bearer $AT" --data-urlencode "rest_route=$route" "$BASE_URL/")
	check_re "3 front controller + POST rest_route=$route -> 401/403" "$(status "$r")" '^40[13]$'
	check_no_re "3 front controller + POST rest_route=$route -> no data" "$(body "$r")" 'claude-mcp|"title":|"slug":'
	r=$(curl_np -s -i -H "Authorization: Bearer $AT" --data-urlencode "rest_route=$route" "$BASE_URL/?rest_route=/mcp/mcp-adapter-default-server")
	check_no_re "3 ?rest_route=MCP + POST rest_route=$route -> not served" "$(status "$r")" '^200$'
	check_no_re "3 ?rest_route=MCP + POST rest_route=$route -> no data" "$(body "$r")" 'claude-mcp|"title":|"slug":'
done
check_eq "3 the token still works on the MCP route" "$(mcp_status "$AT")" 200
# An ability wrapping a REST endpoint dispatches again (rest_do_request())
# while the MCP route's callback runs: the audience check must let that nested
# dispatch through, only the outermost one is held to the MCP route.
mcp_session "$AT"
r=$(execute_ability "$AT" e2e/nested-rest '{"route":"/wp/v2/posts"}')
check_re "3 nested rest_do_request(/wp/v2/posts) during the MCP call -> 200" "$r" '\\?"status\\?":200'
check_no_re "3 nested rest_do_request(/wp/v2/posts) -> no invalid_token" "$r" 'invalid_token|only valid for the MCP server'
r=$(execute_ability "$AT" e2e/nested-rest '{"route":"/wp/v2/settings"}')
check_re "3 nested rest_do_request(/wp/v2/settings) -> refused by capability (403), not by the audience" "$r" '\\?"status\\?":403'
# After the MCP call the bracket is closed: the outer audience checks above
# still hold on a fresh request.
r=$(curl -s -i -H "Authorization: Bearer $AT" "$BASE_URL/wp-json/wp/v2/settings")
check_eq "3 token on /wp/v2/settings after a nested call -> 401" "$(status "$r")" 401
r=$(curl_np -s -i -H "Authorization: Bearer $AT" --data-urlencode "rest_route=/wp/v2/settings" "$MCP")
check_re "3 MCP path + POST rest_route=/wp/v2/settings after a nested call -> 401/403" "$(status "$r")" '^40[13]$'
r=$(mcp_raw "$UNKNOWN_AT" "$MCP_INIT")
check_eq "3 unknown token -> 401" "$(status "$r")" 401
check_re "3 unknown token -> error=invalid_token" "$(header "$r" www-authenticate)" 'error="invalid_token"'
reset_limiter

# ================================================================== 4. Refresh

section "4. Refresh rotation and reuse"
flow "E2E refresh"
R_CONN=$CONN
r=$(refresh "$RT")
b=$(body "$r")
check_eq "4 refresh -> 200" "$(status "$r")" 200
AT2=$(json_str "$b" access_token)
RT2=$(json_str "$b" refresh_token)
check_re "4 new access token" "$AT2" '^lmc_at_[0-9a-f]{64}$'
check_re "4 new refresh token" "$RT2" '^lmc_rt_[0-9a-f]{64}$'
if [ "$AT2" != "$AT" ] && [ "$RT2" != "$RT" ]; then ok "4 tokens rotated"; else ko "4 tokens rotated"; fi
check_eq "4 new access token works" "$(mcp_status "$AT2")" 200
check_re "4 used_at recorded on the rotated token" "$(sql "SELECT used_at FROM $T_TOKENS WHERE token_hash = '$(sha256 "$RT")'")" '^20[0-9]{2}-'

# Grace window: the same token again within 60 s is a retry (lost response),
# refused without revoking anything.
r=$(refresh "$RT")
check_eq "4 replay within the grace window -> 400" "$(status "$r")" 400
check_re "4 replay within the grace window -> invalid_grant" "$(body "$r")" '"error":"invalid_grant"'
check_eq "4 replay within the grace window keeps the connection" "$(conn_status "$R_CONN")" active
check_eq "4 replay within the grace window: no reuse event" "$(sql "SELECT COUNT(*) FROM $T_LOG WHERE event = 'mcp_refresh_reuse_detected' AND object_id = $R_CONN")" 0
check_eq "4 the successor access token still works" "$(mcp_status "$AT2")" 200

# Two refreshes racing with the same token: one wins, the other is refused,
# the connection survives.
refresh "$RT2" >"$WORK/race1" &
refresh "$RT2" >"$WORK/race2" &
wait
race="$(status "$(cat "$WORK/race1")") $(status "$(cat "$WORK/race2")")"
case "$race" in "200 400" | "400 200") ok "4 concurrent refreshes -> one 200, one 400" ;; *) ko "4 concurrent refreshes -> one 200, one 400" "got [$race]" ;; esac
check_eq "4 concurrent refreshes keep the connection" "$(conn_status "$R_CONN")" active
for f in race1 race2; do
	t=$(json_str "$(body "$(cat "$WORK/$f")")" refresh_token)
	[ -n "$t" ] && RT3=$t && AT3=$(json_str "$(body "$(cat "$WORK/$f")")" access_token)
done
check_eq "4 the race winner's access token works" "$(mcp_status "${AT3:-none}")" 200

# Optional parameters of the refresh grant, checked before the token is consumed.
check_eq "4 refresh with another resource -> invalid_target" "$(json_str "$(body "$(refresh "$RT3" resource=https://other.example/mcp)")" error)" invalid_target
check_eq "4 refresh with an unknown scope -> invalid_scope" "$(json_str "$(body "$(refresh "$RT3" "scope=mcp admin")")" error)" invalid_scope
# Secrets in the query string are never read (they would reach access logs).
r=$(curl -s -i -X POST "$OAUTH/token?grant_type=refresh_token&refresh_token=$RT3&client_id=$CLIENT_ID_ENC")
check_eq "4 refresh with the parameters in the query string -> invalid_request" "$(json_str "$(body "$r")" error)" invalid_request
r=$(refresh "$RT3" "resource=$MCP" scope=mcp)
check_eq "4 refresh with the right resource and a narrower scope -> 200 (token not burnt before)" "$(status "$r")" 200
check_re "4 refreshed scope" "$(body "$r")" '"scope":"mcp offline_access"'
AT4=$(json_str "$(body "$r")" access_token)

# Outside the grace window the old token is a reuse: theft, the connection goes.
sql "UPDATE $T_TOKENS SET used_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE token_hash = '$(sha256 "$RT")'"
r=$(refresh "$RT")
check_eq "4 old refresh replay after the window -> 400" "$(status "$r")" 400
check_re "4 old refresh replay after the window -> invalid_grant" "$(body "$r")" '"error":"invalid_grant"'
check_eq "4 connection revoked after reuse" "$(conn_status "$R_CONN")" revoked
check_eq "4 latest access token -> 401 after reuse" "$(mcp_status "$AT4")" 401
check_eq "4 refresh refused after reuse" "$(json_str "$(body "$(refresh "$RT3")")" error)" invalid_grant
check_eq "4 Activity Log mcp_refresh_reuse_detected" "$(sql "SELECT COUNT(*) FROM $T_LOG WHERE event = 'mcp_refresh_reuse_detected' AND object_id = $R_CONN")" 1

# A client asking for mcp alone still gets offline_access, and the consent says so.
AUTH_EXTRA=(scope=mcp)
flow "E2E scope mcp"
AUTH_EXTRA=()
check_re "4 scope=mcp -> consent says stay connected" "$CONSENT_PAGE" 'and stay connected \(refresh\)'
check_re "4 scope=mcp -> granted scope mcp offline_access" "$(body "$TOKEN_RESP")" '"scope":"mcp offline_access"'
check_re "4 scope=mcp -> refresh token issued" "$RT" '^lmc_rt_[0-9a-f]{64}$'
check_eq "4 scope=mcp -> stored scope" "$(sql "SELECT scope FROM $T_CONN WHERE id = $CONN")" "mcp offline_access"
curl -s -o /dev/null --data-urlencode "token=$RT" "$OAUTH/revoke"
reset_limiter

# ================================================================== 5. Code

section "5. Authorization code"
flow_code "E2E code"
C_CODE=$CODE
C_VERIFIER=$VERIFIER
r=$(exchange "$C_CODE" "$(pkce_new; echo "$VERIFIER")")
check_eq "5 wrong code_verifier -> invalid_grant" "$(json_str "$(body "$r")" error)" invalid_grant
r=$(exchange "$C_CODE" "$C_VERIFIER" "http://localhost:$((PORT + 1))/callback")
check_eq "5 wrong redirect_uri -> invalid_grant" "$(json_str "$(body "$r")" error)" invalid_grant
r=$(token_req grant_type=authorization_code "code=$C_CODE" "redirect_uri=$REDIRECT" "client_id=https://claude.ai/oauth/other" "code_verifier=$C_VERIFIER")
check_eq "5 wrong client_id -> invalid_grant" "$(json_str "$(body "$r")" error)" invalid_grant
r=$(exchange "$C_CODE" "$C_VERIFIER")
check_eq "5 the code still works after the failed attempts -> 200" "$(status "$r")" 200
C_AT=$(json_str "$(body "$r")" access_token)
C_CONN=$(conn_of "$C_AT")
# A used code replayed under another client_id (a third party that read it in
# a log or the browser history): refused, the connection survives.
r=$(token_req grant_type=authorization_code "code=$C_CODE" "redirect_uri=$REDIRECT" "client_id=https://claude.ai/oauth/other" "code_verifier=$C_VERIFIER")
check_eq "5 code replay with another client_id -> invalid_grant" "$(json_str "$(body "$r")" error)" invalid_grant
check_eq "5 code replay with another client_id keeps the connection" "$(conn_status "$C_CONN")" active
check_eq "5 access token still works after the foreign replay" "$(mcp_status "$C_AT")" 200
r=$(exchange "$C_CODE" "$C_VERIFIER")
check_eq "5 code replay -> invalid_grant" "$(json_str "$(body "$r")" error)" invalid_grant
check_eq "5 code replay with the code's client_id revokes the connection" "$(conn_status "$C_CONN")" revoked
check_eq "5 access token from the replayed code -> 401" "$(mcp_status "$C_AT")" 401
flow_code "E2E expired"
sql "UPDATE $T_TOKENS SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = '$(sha256 "$CODE")'"
r=$(exchange "$CODE" "$VERIFIER")
check_eq "5 expired code -> invalid_grant" "$(json_str "$(body "$r")" error)" invalid_grant
# A connection revoked between the consent and the exchange: no token, no
# 'authorized' row (the race loser's revoke leaves the same state).
flow_code "E2E revoked before exchange"
P_CONN=$(conn_of "$CODE")
wpcli eval "\\Lumia\\Tools\\Modules\\McpConnect\\Connections::revoke($P_CONN, 'manual');" >/dev/null
r=$(exchange "$CODE" "$VERIFIER")
check_eq "5 exchange for a revoked connection -> invalid_grant" "$(json_str "$(body "$r")" error)" invalid_grant
check_no_re "5 exchange for a revoked connection -> no token" "$(body "$r")" 'access_token'
check_eq "5 exchange for a revoked connection -> nothing authorized" "$(sql "SELECT COUNT(*) FROM $T_LOG WHERE event = 'mcp_connection_authorized' AND object_id = $P_CONN")" 0
check_eq "5 activate() refuses a revoked connection" "$(wpcli eval "var_export(\\Lumia\\Tools\\Modules\\McpConnect\\Connections::activate($P_CONN, time() + 60));")" false
check_eq "5 the connection stays revoked" "$(conn_status "$P_CONN")" revoked
check_eq "5 unsupported grant -> unsupported_grant_type" "$(json_str "$(body "$(token_req grant_type=password username=a password=b)")" error)" unsupported_grant_type
reset_limiter

# ================================================================== 6. /authorize errors

section "6. Authorization request errors"
pkce_new
u=https://example.com/client.json
r=$(auth_req "client_id=$u")
check_eq "6 untrusted client (trusted_only on) -> 400" "$(status "$r")" 400
check_eq "6 untrusted client: no redirect" "$(header "$r" location)" ""
check_eq "6 untrusted client: no fetch (no negative cache)" "$(sql "SELECT COUNT(*) FROM ${DB_PREFIX}options WHERE option_name = '_transient_lumia_mcp_cimd_fail_$(md5 "$u")'")" 0
r=$(auth_req "redirect_uri=https://evil.example/callback")
check_eq "6 unregistered redirect_uri -> 400" "$(status "$r")" 400
check_eq "6 unregistered redirect_uri: no redirect" "$(header "$r" location)" ""
r=$(auth_req "redirect_uri=http://localhost:$PORT/other")
check_eq "6 loopback redirect_uri with another path -> 400" "$(status "$r")" 400
r=$(auth_req code_challenge_method=plain)
loc=$(header "$r" location)
check_eq "6 PKCE plain -> 302 to the client" "$(status "$r")" 302
check_re "6 PKCE plain -> error=invalid_request + state + iss" "$loc" "^http://localhost:$PORT/callback\\?error=invalid_request&error_description=[^&]+&state=$STATE&iss=http%3A%2F%2F"
r=$(auth_req code_challenge=__unset__)
check_re "6 missing code_challenge -> invalid_request" "$(header "$r" location)" 'error=invalid_request'
r=$(auth_req "resource=$BASE_URL/wp-json/wp/v2")
check_re "6 wrong resource -> invalid_target + state" "$(header "$r" location)" "error=invalid_target&.*state=$STATE"
r=$(auth_req resource=__unset__)
check_re "6 missing resource -> invalid_target" "$(header "$r" location)" 'error=invalid_target'
r=$(auth_req "scope=mcp admin")
check_re "6 unknown scope -> invalid_scope" "$(header "$r" location)" 'error=invalid_scope'
r=$(auth_req response_type=token)
check_re "6 response_type token -> unsupported_response_type" "$(header "$r" location)" 'error=unsupported_response_type'

set_setting trusted_only false
budget_before=$(sql "SELECT option_value FROM ${DB_PREFIX}options WHERE option_name = '_transient_lumia_mcp_cimd_budget'")
for u in https://127.0.0.1/x https://localhost/x https://10.0.0.1/x http://claude.ai/x https://claude.ai:8443/x "https://[::1]/x" "https://user@claude.ai/x" "https://claude.ai/x#frag" "https://claude.ai/$(printf 'a%.0s' {1..200})"; do
	r=$(auth_req "client_id=$u")
	check_eq "6 trusted_only off, $u -> 400" "$(status "$r")" 400
	if [ -z "$(header "$r" location)" ] && [ "$(sql "SELECT COUNT(*) FROM ${DB_PREFIX}options WHERE option_name = '_transient_lumia_mcp_cimd_fail_$(md5 "$u")'")" = 0 ]; then
		ok "6 $u refused before any fetch"
	else
		ko "6 $u refused before any fetch"
	fi
done
check_eq "6 no CIMD fetch budget spent" "$(sql "SELECT option_value FROM ${DB_PREFIX}options WHERE option_name = '_transient_lumia_mcp_cimd_budget'")" "$budget_before"
check_eq "6 no client row for the refused ids" "$(sql "SELECT COUNT(*) FROM $T_CLIENTS WHERE client_id <> '$CLIENT_ID' AND type = 'cimd'")" 0
set_setting trusted_only true
reset_limiter

# CIMD budgets: a known client (row in the table, cache expired) is refetched
# outside the site-wide budget; an unknown one is not fetched once it is spent.
set_transient lumia_mcp_cimd_budget "['start' => time(), 'count' => 20]" 60
sql "UPDATE $T_CLIENTS SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE client_id = '$CLIENT_ID'"
pkce_new
check_re "6 known client, expired cache, site budget spent -> consent" "$(header "$(auth_req)" location)" 'lumia-mcp-consent&request=lmc_rq_'
check_eq "6 known client refetched (cache fresh again)" "$(sql "SELECT expires_at > UTC_TIMESTAMP() FROM $T_CLIENTS WHERE client_id = '$CLIENT_ID'")" 1
r=$(auth_req "client_id=https://claude.ai/e2e-unknown-$RANDOM")
check_eq "6 unknown claude.ai client, site budget spent -> 400" "$(status "$r")" 400
check_re "6 unknown claude.ai client, site budget spent -> busy message" "$(body "$r")" 'Too many connection attempts'
# Refetch failing (negative cache): the stale row is served for up to 24 h.
set_transient "lumia_mcp_cimd_fail_$(md5 "$CLIENT_ID")" "['code' => 'invalid_client', 'message' => 'e2e failure']" 60
sql "UPDATE $T_CLIENTS SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE client_id = '$CLIENT_ID'"
check_re "6 refetch failing, row expired 1 h ago -> stale row served" "$(header "$(auth_req)" location)" 'lumia-mcp-consent&request=lmc_rq_'
sql "UPDATE $T_CLIENTS SET expires_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE client_id = '$CLIENT_ID'"
check_eq "6 refetch failing, row expired 2 days ago -> 400" "$(status "$(auth_req)")" 400
sql "DELETE FROM ${DB_PREFIX}options WHERE option_name LIKE '%\\_lumia\\_mcp\\_cimd\\_fail\\_%'"
sql "UPDATE $T_CLIENTS SET expires_at = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE client_id = '$CLIENT_ID'"
reset_limiter

# Loopback redirect URI with an upper-case host: same rule as the validation,
# so the notice and the interstitial (not a 302 blocked by form-action).
pkce_new
RQ=$(header "$(auth_req "redirect_uri=http://LOCALHOST:$PORT/callback")" location | grep -oE 'lmc_rq_[0-9a-f]{64}')
CONSENT_PAGE=$(curl -s -i -b "$ADMIN_JAR" "$(consent_url "$RQ")")
check_re "6 LOCALHOST redirect -> loopback notice" "$CONSENT_PAGE" 'application running on your computer'
r=$(consent_post "$ADMIN_JAR" "$RQ" "$(nonce_of "$CONSENT_PAGE")" deny)
check_eq "6 LOCALHOST redirect -> interstitial 200, not a 302" "$(status "$r")" 200
check_re "6 LOCALHOST redirect -> meta refresh to the client" "$r" "http-equiv=\"refresh\" content=\"0;url=http://LOCALHOST:$PORT/callback\\?error=access_denied"

# /authorize: expired requests are cleared on each call, and one address
# stores at most 30 requests per 10 minutes.
sql "DELETE FROM $T_TOKENS WHERE type = 'request' AND expires_at < UTC_TIMESTAMP()"
pkce_new
OLD_RQ=$(header "$(auth_req)" location | grep -oE 'lmc_rq_[0-9a-f]{64}')
sql "UPDATE $T_TOKENS SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_hash = '$(sha256 "$OLD_RQ")'"
auth_req >/dev/null
check_eq "6 an expired request row is deleted by the next /authorize" "$(sql "SELECT COUNT(*) FROM $T_TOKENS WHERE token_hash = '$(sha256 "$OLD_RQ")'")" 0
reset_limiter
rows_before=$(sql "SELECT COUNT(*) FROM $T_TOKENS WHERE type = 'request' AND expires_at > UTC_TIMESTAMP()")
codes=""
for i in $(seq 1 31); do codes="$codes $(status "$(auth_req)")"; done
check_eq "6 30 requests stored per address" "$(printf '%s' "$codes" | tr ' ' '\n' | grep -c '^302$')" 30
check_eq "6 the 31st request -> 429" "${codes##* }" 429
check_eq "6 no row stored for the refused request" "$(sql "SELECT COUNT(*) FROM $T_TOKENS WHERE type = 'request' AND expires_at > UTC_TIMESTAMP()")" $((rows_before + 30))
check_re "6 429 page explains" "$(body "$(auth_req)")" 'Too many connection attempts from this address'
reset_limiter

# ================================================================== 7. Revocation

section "7. Revocation"
flow "E2E revoke endpoint"
curl -s -o /dev/null -X POST "$OAUTH/revoke?token=$RT&client_id=$CLIENT_ID_ENC"
check_eq "7 /revoke with the token in the query string -> ignored" "$(conn_status "$CONN")" active
r=$(curl -s -i --data-urlencode "token=$RT" -d token_type_hint=refresh_token --data-urlencode "client_id=$CLIENT_ID" "$OAUTH/revoke")
check_eq "7 /revoke refresh token -> 200" "$(status "$r")" 200
check_eq "7 /revoke revokes the connection" "$(conn_status "$CONN")" revoked
check_eq "7 access token -> 401 after /revoke" "$(mcp_status "$AT")" 401
check_eq "7 /revoke unknown token -> 200" "$(curl -s -o /dev/null -w '%{http_code}' -d token=lmc_rt_unknown "$OAUTH/revoke")" 200

ADMIN_PAGE=$(curl -s -i -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools&tab=module_mcp_connect")
AJAX_NONCE=$(printf '%s' "$ADMIN_PAGE" | grep -oE '"nonce":"[0-9a-f]{10}"' | head -1 | cut -d'"' -f4)
check_re "7 admin nonce from lumiaAdmin" "$AJAX_NONCE" '^[0-9a-f]{10}$'
flow "E2E ajax revoke"
r=$(curl -s -b "$ADMIN_JAR" -d action=lumia_mcp_connect_list -d "nonce=$AJAX_NONCE" "$BASE_URL/wp-admin/admin-ajax.php")
check_re "7 AJAX list shows the connection" "$r" "\"success\":true.*\"id\":$CONN,"
r=$(curl -s -H "X-E2E-User: $EDITOR_LOGIN" -d action=lumia_mcp_connect_revoke -d "id=$CONN" -d "nonce=$AJAX_NONCE" "$BASE_URL/wp-admin/admin-ajax.php")
check_no_re "7 AJAX revoke refused to an editor" "$r" '"success":true'
r=$(curl -s -b "$ADMIN_JAR" -d action=lumia_mcp_connect_revoke -d "id=$CONN" -d "nonce=$AJAX_NONCE" "$BASE_URL/wp-admin/admin-ajax.php")
check_re "7 AJAX revoke -> success" "$r" '"success":true'
check_eq "7 access token -> 401 after AJAX revoke" "$(mcp_status "$AT")" 401
check_eq "7 refresh refused after AJAX revoke" "$(json_str "$(body "$(refresh "$RT")")" error)" invalid_grant

flow "E2E all 1"
AT_A=$AT
flow "E2E all 2"
AT_B=$AT
check_eq "7 two live connections before revoke all" "$(mcp_status "$AT_A")/$(mcp_status "$AT_B")" 200/200
r=$(curl -s -b "$ADMIN_JAR" -d action=lumia_mcp_connect_revoke_all -d "nonce=$AJAX_NONCE" "$BASE_URL/wp-admin/admin-ajax.php")
check_re "7 AJAX revoke all -> success" "$r" '"success":true'
check_eq "7 every token -> 401 after revoke all" "$(mcp_status "$AT_A")/$(mcp_status "$AT_B")" 401/401
check_eq "7 no active connection left" "$(sql "SELECT COUNT(*) FROM $T_CONN WHERE status = 'active'")" 0
reset_limiter

# Normal life cycle never counts as failed attempts: after revocations Claude
# keeps sending tokens and codes we deleted, from shared egress addresses.
flow "E2E dead tokens"
DEAD_AT=$AT
DEAD_RT=$RT
flow_code "E2E dead code"
DEAD_CODE=$CODE
DEAD_VERIFIER=$VERIFIER
curl -s -o /dev/null -b "$ADMIN_JAR" -d action=lumia_mcp_connect_revoke_all -d "nonce=$AJAX_NONCE" "$BASE_URL/wp-admin/admin-ajax.php"
dead=""
for i in 1 2 3 4 5 6 7; do
	dead="$dead $(mcp_status "$DEAD_AT")/$(json_str "$(body "$(refresh "$DEAD_RT")")" error)/$(json_str "$(body "$(exchange "$DEAD_CODE" "$DEAD_VERIFIER")")" error)"
	dead="$dead $(mcp_status "$UNKNOWN_AT")"
done
check_no_re "7 revoked/unknown well-formed values -> 401 / invalid_grant" "$dead" '200|invalid_request'
check_eq "7 revoked/unknown well-formed values are not counted" "$(limiter_rows)" 0
flow "E2E after dead tokens"
check_eq "7 a new connection works after 28 dead-token calls" "$(mcp_status "$AT")" 200
# A value of the wrong shape is counted: five lock the address out.
for i in 1 2 3 4 5 6; do mcp_status "lmc_at_not-a-token" >/dev/null; done
r=$(mcp_raw "$AT" "$MCP_INIT")
check_eq "7 malformed Bearers are counted: valid token locked out -> 401" "$(status "$r")" 401
check_re "7 lockout message" "$(body "$r")" 'Too many failed attempts'
check_eq "7 lockout on /token -> invalid_request" "$(json_str "$(body "$(refresh "$RT")")" error)" invalid_request
reset_limiter
check_eq "7 limiter cleared -> the token works again" "$(mcp_status "$AT")" 200
curl -s -o /dev/null --data-urlencode "token=$RT" "$OAUTH/revoke"

# ================================================================== 8. Kill switches

section "8. Pause, module off, constant"
flow "E2E switches"
check_eq "8 token works" "$(mcp_status "$AT")" 200
set_setting paused true
check_eq "8 paused -> Bearer 401" "$(mcp_status "$AT")" 401
check_eq "8 paused -> refresh refused (invalid_request, not invalid_grant)" "$(json_str "$(body "$(refresh "$RT")")" error)" invalid_request
pkce_new
check_re "8 paused -> authorize temporarily_unavailable" "$(header "$(auth_req)" location)" 'error=temporarily_unavailable'
set_setting paused false
check_eq "8 unpaused -> token works again" "$(mcp_status "$AT")" 200

switch_checks() {
	local what=$1 r
	check_eq "8 $what -> PRM 404" "$(curl -s -o /dev/null -w '%{http_code}' "$PRM_URL")" 404
	check_eq "8 $what -> AS metadata 404" "$(curl -s -o /dev/null -w '%{http_code}' "$AS_URL")" 404
	check_eq "8 $what -> /authorize 404" "$(curl -s -o /dev/null -w '%{http_code}' "$OAUTH/authorize")" 404
	check_eq "8 $what -> /token 404" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$OAUTH/token")" 404
	r=$(mcp_raw "$AT" "$MCP_INIT")
	check_eq "8 $what -> Bearer ignored (401)" "$(status "$r")" 401
	check_no_re "8 $what -> no Bearer challenge" "$(header "$r" www-authenticate)" 'resource_metadata'
}
# The toolbar status badge (the admin's locale may be French: tested on classes).
status_badge() { curl -s -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools&tab=module_mcp_connect" | tr -d '\r\n\t' | grep -oE 'lumia-mc__status"> *<span class="lumia-badge [a-z-]+">[^<]*<' | head -1; }
check_re "8 status badge success while on" "$(status_badge)" 'lumia-badge--success">'
set_module mcp_connect deactivate >/dev/null
switch_checks "module off"
set_module mcp_connect activate >/dev/null
check_eq "8 module back on -> token works" "$(mcp_status "$AT")" 200
wpcli config set LUMIA_DISABLE_MCP_CONNECT true --raw >/dev/null
opcache_wait
switch_checks "LUMIA_DISABLE_MCP_CONNECT"
check_re "8 constant -> status badge danger (Off), not Active" "$(status_badge)" 'lumia-badge--danger">'
wpcli config delete LUMIA_DISABLE_MCP_CONNECT >/dev/null
opcache_wait
check_eq "8 constant removed -> token works" "$(mcp_status "$AT")" 200

# ================================================================== 9. Agent

section "9. Agent account"
# Administrator is the default preset (2.4.0): with nothing stored, the role is
# resynced to it on the next request (Agent::maybe_sync(), no admin load needed)
# and holds the code capabilities even if the administrator role lacks them.
wpcli eval '$o = get_option("lumia_module_mcp_connect", []); unset($o["agent_preset"]); update_option("lumia_module_mcp_connect", $o); delete_option("lumia_mcp_agent_sync"); get_role("administrator")->remove_cap("unfiltered_upload");' >/dev/null
CODE_PHP='$u = get_user_by("login", "claude-mcp"); $r = get_role("lumia_mcp_agent"); foreach (["manage_options", "unfiltered_html", "unfiltered_upload", "bricks_execute_code", "bricks_full_access"] as $c) { echo $r->has_cap($c) ? "y" : "n"; } echo "/", user_can($u, "unfiltered_html") ? "yes" : "no";'
check_eq "9 default preset Administrator: role resynced without an admin load, code caps granted" "$(wpcli eval "$CODE_PHP")" "yyyyy/yes"
check_eq "9 sync marker written" "$(wpcli eval 'echo strtok((string) get_option("lumia_mcp_agent_sync"), "|");')" admin
# LUMIA_MCP_AGENT_PRESET forces the preset, whatever is stored.
wpcli config set LUMIA_MCP_AGENT_PRESET editor >/dev/null
opcache_wait
check_eq "9 constant editor -> role resynced to Editor" "$(wpcli eval "$CODE_PHP")" "nnnnn/no"
check_re "9 constant -> preset select disabled" "$(curl -s -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools&tab=module_mcp_connect" | tr -d '\r\n\t')" 'id="lumia_mc_preset"[^>]*disabled'
wpcli config set LUMIA_MCP_AGENT_PRESET bogus >/dev/null
opcache_wait
check_eq "9 constant with an unknown value -> ignored (default Administrator)" "$(wpcli eval 'echo \Lumia\Tools\Modules\McpConnect\Agent::current_preset();')" admin
wpcli config delete LUMIA_MCP_AGENT_PRESET >/dev/null
opcache_wait
wpcli eval 'get_role("administrator")->add_cap("unfiltered_upload");' >/dev/null
# The rest of this section checks the Editor preset.
set_setting agent_preset "'editor'"
wpcli eval '\Lumia\Tools\Modules\McpConnect\Agent::sync_role("editor");' >/dev/null
r=$(wpcli eval '$u = get_user_by("login", "claude-mcp"); $r = apply_filters("wp_authenticate_user", $u, "x"); echo is_wp_error($r) ? $r->get_error_code() : "ALLOWED";')
check_eq "9 wp_authenticate_user refuses claude-mcp" "$r" lumia_mcp_agent_login
AGENT_PASS="e2e-agent-$RANDOM-$RANDOM"
wpcli user update claude-mcp --user_pass="$AGENT_PASS" --skip-email >/dev/null
if login claude-mcp "$AGENT_PASS" "$WORK/agent.jar"; then ko "9 claude-mcp cannot sign in with a valid password"; else ok "9 claude-mcp cannot sign in with a valid password"; fi
wpcli user update claude-mcp --user_pass="$(openssl rand -hex 32)" --skip-email >/dev/null
reset_limiter
r=$(curl -s -o /dev/null -w '%{http_code}' -H 'X-E2E-User: claude-mcp' "$BASE_URL/wp-admin/")
check_eq "9 claude-mcp on /wp-admin/ -> 403" "$r" 403
r=$(wpcli eval '$u = get_user_by("login", "claude-mcp"); echo user_can($u, "manage_options") ? "yes" : "no", "/", get_role("lumia_mcp_agent")->has_cap("manage_options") ? "yes" : "no", "/", user_can($u, "edit_users") ? "yes" : "no";')
check_eq "9 agent has no manage_options / edit_users" "$r" "no/no/no"

# Forbidden capabilities written on the role between two admin loads (role
# editor, another plugin, Bricks' role settings): denied at check time, then
# dropped from the role by the next administrator page load. The connection is
# approved first: the consent is an admin page load, which would resync.
flow "E2E forbidden caps"
F_AT=$AT
F_RT=$RT
CAPS_PHP='$u = get_user_by("login", "claude-mcp"); $r = get_role("lumia_mcp_agent"); echo $r->has_cap("manage_options") ? "yes" : "no", "/", $r->has_cap("bricks_execute_code") ? "yes" : "no", "/", user_can($u, "manage_options") ? "yes" : "no", "/", user_can($u, "bricks_execute_code") ? "yes" : "no";'
wpcli cap add lumia_mcp_agent manage_options bricks_execute_code >/dev/null
check_eq "9 manage_options + bricks_execute_code written on the role, denied to the agent" "$(wpcli eval "$CAPS_PHP")" "yes/yes/no/no"
mcp_session "$F_AT"
r=$(execute_ability "$F_AT" e2e/nested-rest '{"route":"/wp/v2/settings"}')
check_re "9 through the token: /wp/v2/settings (manage_options) still refused -> 403" "$r" '\\?"status\\?":403'
r=$(execute_ability "$F_AT" core/get-site-info)
check_no_re "9 through the token: core/get-site-info (manage_options) not served" "$r" '\\?"(admin_email|siteurl|url)\\?":'
check_re "9 through the token: core/get-site-info denied" "$r" '[Pp]ermission|not allowed|isError\\?":true|"error"'
curl -s -o /dev/null -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools"
check_eq "9 admin page load drops the forbidden caps from the role" "$(wpcli eval "$CAPS_PHP")" "no/no/no/no"
# The Editor preset follows the administrator role: a capability no role holds
# is outside it.
wpcli cap add lumia_mcp_agent e2e_drift_cap >/dev/null
curl -s -o /dev/null -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools"
check_eq "9 admin page load drops any capability outside the preset" "$(wpcli eval 'echo get_role("lumia_mcp_agent")->has_cap("e2e_drift_cap") ? "yes" : "no";')" no
check_eq "9 Editor preset: menus and comments, from the administrator role" "$(wpcli eval '$u = get_user_by("login", "claude-mcp"); echo user_can($u, "edit_theme_options") ? "yes" : "no", "/", user_can($u, "moderate_comments") ? "yes" : "no";')" "yes/yes"
check_eq "9 the connection still works after the resync" "$(mcp_status "$F_AT")" 200
# Drift on the account itself (second role, capability granted to the user):
# the role resync does not see it, reset_account() must.
wpcli user add-role claude-mcp editor >/dev/null
wpcli user add-cap claude-mcp delete_others_pages >/dev/null
check_eq "9 account drift present (second role + user cap)" "$(wpcli eval '$u = get_user_by("login", "claude-mcp"); echo count($u->roles), "/", isset($u->caps["delete_others_pages"]) ? "yes" : "no";')" "2/yes"
curl -s -o /dev/null -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools"
check_eq "9 admin page load resets the account to the agent role alone" "$(wpcli eval '$u = get_user_by("login", "claude-mcp"); echo implode(",", $u->roles), "/", isset($u->caps["delete_others_pages"]) ? "yes" : "no";')" "lumia_mcp_agent/no"
check_eq "9 Editor preset: role editing and URE capabilities forbidden" "$(wpcli eval 'echo \Lumia\Tools\Modules\McpConnect\Agent::is_forbidden("edit_roles", "editor") ? "yes" : "no", "/", \Lumia\Tools\Modules\McpConnect\Agent::is_forbidden("ure_edit_roles", "editor") ? "yes" : "no", "/", \Lumia\Tools\Modules\McpConnect\Agent::is_forbidden("edit_roles", "admin") ? "yes" : "no";')" "yes/yes/no"
check_eq "9 manage_network_options is a forbidden cap" "$(wpcli eval 'echo in_array("manage_network_options", \Lumia\Tools\Modules\McpConnect\Agent::FORBIDDEN_CAPS, true) ? "yes" : "no";')" yes
check_eq "9 the connection still works after the account reset" "$(mcp_status "$F_AT")" 200
# Administrator preset: every capability of an administrator, none denied at
# check time; back to Editor, the forbidden ones are gone again.
set_setting agent_preset "'admin'"
curl -s -o /dev/null -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools"
check_eq "9 Administrator preset: manage_options + edit_users granted" "$(wpcli eval '$u = get_user_by("login", "claude-mcp"); echo user_can($u, "manage_options") ? "yes" : "no", "/", user_can($u, "edit_users") ? "yes" : "no";')" "yes/yes"
mcp_session "$F_AT"
r=$(execute_ability "$F_AT" core/get-site-info)
check_re "9 Administrator preset: core/get-site-info served through the token" "$r" '\\?"(admin_email|siteurl|url)\\?":'
set_setting agent_preset "'content_bricks'"
curl -s -o /dev/null -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools"
check_eq "9 a 2.3.0 preset reads as the default, Administrator" "$(wpcli eval '$u = get_user_by("login", "claude-mcp"); echo user_can($u, "manage_options") ? "yes" : "no", "/", user_can($u, "edit_theme_options") ? "yes" : "no";')" "yes/yes"
set_setting agent_preset "'editor'"
curl -s -o /dev/null -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools"
curl -s -o /dev/null --data-urlencode "token=$F_RT" "$OAUTH/revoke"

# Role changed by hand: the account no longer counts as the agent, and the next
# administrator page load gives the role back (else every new agent
# connection would be refused by the Bearer check).
wpcli user set-role claude-mcp editor >/dev/null
check_eq "9 role changed by hand -> Agent::user_id() 0" "$(wpcli eval 'echo \Lumia\Tools\Modules\McpConnect\Agent::user_id();')" 0
curl -s -o /dev/null -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools"
check_eq "9 admin page load restores the agent role" "$(wpcli user get claude-mcp --field=roles)" lumia_mcp_agent
flow "E2E role restored"
check_eq "9 new agent connection works after the repair" "$(mcp_status "$AT")" 200
curl -s -o /dev/null --data-urlencode "token=$RT" "$OAUTH/revoke"

# Uninstall without an administrator role holder: the agent's content must
# survive. Run inside a transaction rolled back at the end (nothing persists).
UNINSTALL_PHP='global $wpdb; $wpdb->query("START TRANSACTION"); try { $a = \Lumia\Tools\Modules\McpConnect\Agent::ensure(); $p = wp_insert_post(["post_title" => "e2e agent post", "post_status" => "publish", "post_author" => $a]); foreach (get_users(["role" => "administrator"]) as $u) { $u->set_role("editor"); } \Lumia\Tools\Modules\McpConnect\Agent::remove(); clean_post_cache($p); $post = get_post($p); $user = get_userdata($a); $author = $post ? ((int) $post->post_author === $a ? "agent" : ((int) $post->post_author === get_current_user_id() ? "current" : "other")) : "none"; echo ($user ? "kept" : "deleted"), "/", ($user && ! $user->roles ? "norole" : "-"), "/", $author; } finally { $wpdb->query("ROLLBACK"); }'
check_eq "9 uninstall, no admin, no current user -> account kept without role, post kept" "$(wpcli eval "$UNINSTALL_PHP")" "kept/norole/agent"
check_eq "9 uninstall, no admin role, run by a user -> content reassigned to that user" "$(wpcli eval "$UNINSTALL_PHP" --user="$ADMIN_USER")" "deleted/-/current"
check_eq "9 rollback: agent and admin roles intact" "$(wpcli user get claude-mcp --field=roles)/$(wpcli user get "$ADMIN_USER" --field=roles)" "lumia_mcp_agent/administrator"

# ================================================================== 10. Activity Log

section "10. Activity Log"
check_eq "10 mcp_connection_authorized with the label" "$(sql "SELECT object_label FROM $T_LOG WHERE event = 'mcp_connection_authorized' AND object_id = $MAIN_CONN")" "$LABEL"
check_eq "10 mcp_connection_authorized actor is the approver" "$(sql "SELECT user_login FROM $T_LOG WHERE event = 'mcp_connection_authorized' AND object_id = $MAIN_CONN")" "$ADMIN_USER"
flow "$LABEL"
mcp_session "$AT"
TITLE="E2E MCP draft $RANDOM"
r=$(execute_ability "$AT" e2e/create-draft "{\"title\":\"$TITLE\"}")
POST_ID=$(printf '%s' "$r" | grep -oE '"structuredContent":\{"success":true,"data":\{"id":[0-9]+' | grep -oE '[0-9]+$')
if [ -n "$POST_ID" ]; then
	ok "10 content written through MCP (post $POST_ID)"
	row=$(sql "SELECT CONCAT(user_login, ' ', details) FROM $T_LOG WHERE event = 'post_created' AND object_id = $POST_ID")
	check_re "10 post_created by claude-mcp" "$row" '^claude-mcp '
	check_re "10 post_created via mcp" "$row" '"via":"mcp"'
	check_re "10 post_created carries the connection label" "$row" "\"connection\":\"$LABEL\""
	check_re "10 post_created carries the connection id" "$row" "\"connection_id\":$CONN"
	wpcli post delete "$POST_ID" --force >/dev/null
else
	ko "10 content written through MCP" "$(printf '%s' "$r" | head -c 300)"
fi
# Administrators untracked in the Activity Log: approvals and manual
# revocations are still written (security trail).
wpcli eval '$o = get_option("lumia_module_activity_log", []); $o = is_array($o) ? $o : []; $o["excluded_roles"] = ["administrator"]; update_option("lumia_module_activity_log", $o);' >/dev/null
flow "E2E untracked admin"
curl -s -o /dev/null -b "$ADMIN_JAR" -d action=lumia_mcp_connect_revoke -d "id=$CONN" -d "nonce=$AJAX_NONCE" "$BASE_URL/wp-admin/admin-ajax.php"
check_eq "10 administrators untracked: authorized still logged" "$(sql "SELECT COUNT(*) FROM $T_LOG WHERE event = 'mcp_connection_authorized' AND object_id = $CONN")" 1
check_eq "10 administrators untracked: manual revocation still logged" "$(sql "SELECT COUNT(*) FROM $T_LOG WHERE event = 'mcp_connection_revoked' AND object_id = $CONN")" 1
wpcli eval '$o = get_option("lumia_module_activity_log", []); $o = is_array($o) ? $o : []; $o["excluded_roles"] = []; update_option("lumia_module_activity_log", $o);' >/dev/null
reasons=$(sql "SELECT DISTINCT details FROM $T_LOG WHERE event = 'mcp_connection_revoked'")
for reason in reuse token manual all; do
	check_re "10 mcp_connection_revoked reason $reason" "$reasons" "\"reason\":\"$reason\""
done

# ================================================================== 11. Refusal

section "11. Refusal and denial"
pkce_new
RQ=$(header "$(auth_req)" location | grep -oE 'lmc_rq_[0-9a-f]{64}')
r=$(curl -s -i -H "X-E2E-User: $EDITOR_LOGIN" "$(consent_url "$RQ")")
check_eq "11 editor on the consent page -> 200" "$(status "$r")" 200
check_re "11 editor sees the refusal panel" "$r" 'Your account is not allowed to connect Claude to this site'
check_no_re "11 editor gets no form" "$r" 'name="lumia_nonce"|value="approve"'
EDITOR_JAR="$WORK/editor.jar"
if login "$EDITOR_LOGIN" "$EDITOR_PASS" "$EDITOR_JAR"; then
	n=$(nonce_of "$(curl -s -b "$ADMIN_JAR" "$(consent_url "$RQ")")")
	r=$(consent_post "$EDITOR_JAR" "$RQ" "$n" approve)
	check_no_re "11 editor POST with the admin's nonce -> no code" "$r" 'code=lmc_ac_'
else
	ko "11 editor signs in"
fi
CONSENT_PAGE=$(curl -s -b "$ADMIN_JAR" "$(consent_url "$RQ")")
denied_before=$(sql "SELECT COUNT(*) FROM $T_LOG WHERE event = 'mcp_connection_denied'")
r=$(consent_post "$ADMIN_JAR" "$RQ" "$(nonce_of "$CONSENT_PAGE")" deny)
check_re "11 deny -> callback with error=access_denied + state" "$r" "callback\\?error=access_denied&#038;error_description=[^&]+&#038;state=$STATE&#038;iss="
check_no_re "11 deny -> no code" "$r" 'code=lmc_ac_'
check_eq "11 Activity Log mcp_connection_denied" "$(sql "SELECT COUNT(*) FROM $T_LOG WHERE event = 'mcp_connection_denied'")" $((denied_before + 1))

# Editors allowed to approve, but the agent preset follows the administrator
# role and holds capabilities the editor lacks (edit_theme_options,
# bricks_full_access…): only "act as me" is offered and accepted.
set_setting approver_capability "'edit_others_pages'"
pkce_new
RQ=$(header "$(auth_req)" location | grep -oE 'lmc_rq_[0-9a-f]{64}')
CONSENT_PAGE=$(curl -s -b "$EDITOR_JAR" "$(consent_url "$RQ")")
check_re "11 editor approver: form shown" "$CONSENT_PAGE" 'name="lumia_nonce"'
check_no_re "11 editor approver: no agent choice" "$CONSENT_PAGE" 'name="acting" value="agent"'
check_re "11 editor approver: act as me preselected" "$CONSENT_PAGE" 'name="acting" value="me" +checked'
check_re "11 editor approver: explanation" "$CONSENT_PAGE" 'Claude can only act as you'
n=$(nonce_of "$CONSENT_PAGE")
r=$(consent_post "$EDITOR_JAR" "$RQ" "$n" approve "E2E editor" agent)
check_eq "11 editor approver: forged agent choice -> 403" "$(status "$r")" 403
check_no_re "11 editor approver: forged agent choice -> no code" "$r" 'code=lmc_ac_'
r=$(consent_post "$EDITOR_JAR" "$RQ" "$n" approve "E2E editor" me)
check_re "11 editor approver: act as me -> code (request not burnt by the refusal)" "$r" 'code=lmc_ac_'
CODE=$(printf '%s' "$r" | grep -oE 'code=lmc_ac_[0-9a-f]{64}' | head -1 | cut -d= -f2)
r=$(exchange "$CODE" "$VERIFIER")
E_AT=$(json_str "$(body "$r")" access_token)
mcp_session "$E_AT"
check_re "11 editor approver: acts as the editor" "$(execute_ability "$E_AT" core/get-user-info)" "\"user_login\":\"$EDITOR_LOGIN\""
curl -s -o /dev/null --data-urlencode "token=$(json_str "$(body "$r")" refresh_token)" "$OAUTH/revoke"
set_setting approver_capability "'manage_options'"

# ================================================================== Extras

section "Extras (beyond the issue list)"
# Token lifetimes.
flow "E2E expiry"
sql "UPDATE $T_TOKENS SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = '$(sha256 "$AT")'"
r=$(mcp_raw "$AT" "$MCP_INIT")
check_eq "x expired access token -> 401" "$(status "$r")" 401
check_re "x expired access token -> error=invalid_token" "$(header "$r" www-authenticate)" 'error="invalid_token"'
r=$(refresh "$RT")
check_eq "x refresh after access expiry -> 200" "$(status "$r")" 200
RT=$(json_str "$(body "$r")" refresh_token)
sql "UPDATE $T_TOKENS SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = '$(sha256 "$RT")'"
check_eq "x expired refresh token -> invalid_grant" "$(json_str "$(body "$(refresh "$RT")")" error)" invalid_grant
check_eq "x malformed Bearer -> 401" "$(mcp_status not-a-token)" 401

# /revoke with an access token deletes that token only.
flow "E2E revoke access"
check_eq "x /revoke access token -> 200" "$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "token=$AT" "$OAUTH/revoke")" 200
check_eq "x revoked access token -> 401" "$(mcp_status "$AT")" 401
check_eq "x connection still active after an access-token revocation" "$(conn_status "$CONN")" active
r=$(refresh "$RT")
check_eq "x refresh still works after an access-token revocation" "$(status "$r")" 200
curl -s -o /dev/null --data-urlencode "token=$(json_str "$(body "$r")" refresh_token)" "$OAUTH/revoke"

# /revoke naming another client does nothing.
flow "E2E revoke other client"
curl -s -o /dev/null --data-urlencode "token=$RT" -d client_id=https://claude.ai/oauth/other "$OAUTH/revoke"
check_eq "x /revoke with another client_id leaves the connection" "$(conn_status "$CONN")" active
curl -s -o /dev/null --data-urlencode "token=$RT" "$OAUTH/revoke"

# Expired / consumed authorization request.
pkce_new
RQ=$(header "$(auth_req)" location | grep -oE 'lmc_rq_[0-9a-f]{64}')
sql "UPDATE $T_TOKENS SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = '$(sha256 "$RQ")'"
r=$(curl -s -b "$ADMIN_JAR" "$(consent_url "$RQ")")
check_re "x expired request -> expired panel" "$r" 'This connection request has expired'
check_no_re "x expired request -> no form" "$r" 'name="lumia_nonce"'

# Act as me: the approver's own account.
flow "E2E as me" me
mcp_session "$AT"
check_re "x act as me -> acting user is the approver" "$(execute_ability "$AT" core/get-user-info)" "\"user_login\":\"$ADMIN_USER\""
check_eq "x act as me stored" "$(sql "SELECT act_as_approver FROM $T_CONN WHERE id = $CONN")" 1
curl -s -o /dev/null --data-urlencode "token=$RT" "$OAUTH/revoke"

# Deleting the acting user revokes its connections.
TMP_ADMIN=e2e-admin2
TMP_PASS="e2e-admin2-$RANDOM-$RANDOM"
[ -n "$(wpcli user get "$TMP_ADMIN" --field=ID)" ] && wpcli user delete "$TMP_ADMIN" --yes --reassign=1 >/dev/null
wpcli user create "$TMP_ADMIN" e2e-admin2@example.com --role=administrator --user_pass="$TMP_PASS" >/dev/null
if login "$TMP_ADMIN" "$TMP_PASS" "$WORK/admin2.jar"; then
	flow "E2E deleted user" me "$WORK/admin2.jar"
	check_eq "x connection of the second admin works" "$(mcp_status "$AT")" 200
	wpcli user delete "$TMP_ADMIN" --yes --reassign=1 >/dev/null
	check_eq "x deleting the acting user revokes its connection" "$(sql "SELECT CONCAT(status, '/', revoked_reason) FROM $T_CONN WHERE id = $CONN")" revoked/user_deleted
	check_eq "x its token -> 401" "$(mcp_status "$AT")" 401
else
	ko "x second admin signs in"
fi

# A user deleted while the module is off (no delete_user listener): the
# connection is refused and revoked at its next use.
TMP3=e2e-admin3
TMP3_PASS="e2e-admin3-$RANDOM-$RANDOM"
[ -n "$(wpcli user get "$TMP3" --field=ID)" ] && wpcli user delete "$TMP3" --yes --reassign=1 >/dev/null
wpcli user create "$TMP3" e2e-admin3@example.com --role=administrator --user_pass="$TMP3_PASS" >/dev/null
if login "$TMP3" "$TMP3_PASS" "$WORK/admin3.jar"; then
	flow "E2E approver deleted while off" agent "$WORK/admin3.jar"
	check_eq "x agent connection approved by a third admin works" "$(mcp_status "$AT")" 200
	set_module mcp_connect deactivate >/dev/null
	wpcli user delete "$TMP3" --yes --reassign=1 >/dev/null
	set_module mcp_connect activate >/dev/null
	check_eq "x approver deleted while the module was off: still active before use" "$(conn_status "$CONN")" active
	check_eq "x approver deleted while the module was off -> token 401" "$(mcp_status "$AT")" 401
	check_eq "x ... and the connection is revoked (user_deleted)" "$(sql "SELECT CONCAT(status, '/', revoked_reason) FROM $T_CONN WHERE id = $CONN")" revoked/user_deleted
else
	ko "x third admin signs in"
fi

# Daily purge: an active connection whose refresh token expired is revoked
# (reason expired, logged); expired authorization requests go at once.
flow "E2E refresh expired"
sql "UPDATE $T_CONN SET refresh_expires_at = '2000-01-01 00:00:00' WHERE id = $CONN"
pkce_new
PURGE_RQ=$(header "$(auth_req)" location | grep -oE 'lmc_rq_[0-9a-f]{64}')
sql "UPDATE $T_TOKENS SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_hash = '$(sha256 "$PURGE_RQ")'"
wpcli eval "\Lumia\Tools\Modules\McpConnect\Store::purge_expired();" >/dev/null
check_eq "x purge revokes a connection whose refresh expired" "$(sql "SELECT CONCAT(status, '/', revoked_reason) FROM $T_CONN WHERE id = $CONN")" revoked/expired
check_re "x purge logs the expiry" "$(sql "SELECT details FROM $T_LOG WHERE event = 'mcp_connection_revoked' AND object_id = $CONN")" '"reason":"expired"'
check_eq "x purge deletes an expired request at once" "$(sql "SELECT COUNT(*) FROM $T_TOKENS WHERE token_hash = '$(sha256 "$PURGE_RQ")'")" 0

# Site Health looks for the authorization server document where a client
# does (RFC 8414 path insertion at the origin root).
check_eq "x AS metadata URL, root install" "$(wpcli eval 'echo \Lumia\Tools\Modules\McpConnect\Discovery::authorization_server_metadata_url();')" "$AS_URL"
check_eq "x AS metadata URL, home URL with a path" "$(wpcli eval 'add_filter("home_url", function () { return "https://example.com/site2"; }); echo \Lumia\Tools\Modules\McpConnect\Discovery::authorization_server_metadata_url();')" "https://example.com/.well-known/oauth-authorization-server/site2"

# Application passwords of the agent are refused.
# Probe: listing drafts needs a signed-in user with edit_posts (the agent has it).
DRAFTS="$BASE_URL/wp-json/wp/v2/posts?status=draft&context=edit"
CTL_PW=$(wpcli user application-password create "$ADMIN_USER" e2e-check --porcelain)
APP_PW=$(wpcli user application-password create claude-mcp e2e-check --porcelain)
if [ -n "$APP_PW" ] && [ -n "$CTL_PW" ]; then
	check_eq "x control: admin application password works" "$(curl -s -o /dev/null -w '%{http_code}' -u "$ADMIN_USER:$CTL_PW" "$DRAFTS")" 200
	check_eq "x agent application password refused" "$(curl -s -o /dev/null -w '%{http_code}' -u "claude-mcp:$APP_PW" "$DRAFTS")" 400
	for u in claude-mcp "$ADMIN_USER"; do
		for id in $(wpcli user application-password list "$u" --name=e2e-check --field=uuid); do wpcli user application-password delete "$u" "$id" >/dev/null; done
	done
else
	echo "INFO x application passwords could not be created (skipped)"
fi

# Site Health probe endpoint (the Site Health loopback itself cannot reach the
# host port from inside the container).
check_re "x /ping sees the Authorization header" "$(curl -s -X POST -H 'Authorization: Bearer lmc_probe' "$OAUTH/ping")" '"authorization":true'
check_re "x /ping without header -> false" "$(curl -s -X POST "$OAUTH/ping")" '"authorization":false'

# DCR end to end, trusted_only off: unverified badge, then a working token.
set_setting dcr_enabled true
set_setting trusted_only false
r=$(curl -s -X POST -H "$H_JSON" -d "{\"client_name\":\"E2E DCR client\",\"redirect_uris\":[\"$REDIRECT\"]}" "$OAUTH/register")
DCR_ID=$(json_str "$r" client_id)
AUTH_EXTRA=("client_id=$DCR_ID")
flow_code "E2E DCR"
AUTH_EXTRA=()
check_re "x DCR consent shows the unverified warning" "$CONSENT_PAGE" 'Unverified client'
check_re "x DCR consent shows the registered name" "$CONSENT_PAGE" 'E2E DCR client'
r=$(token_req grant_type=authorization_code "code=$CODE" "redirect_uri=$REDIRECT" "client_id=$DCR_ID" "code_verifier=$VERIFIER" "resource=$MCP")
DCR_AT=$(json_str "$(body "$r")" access_token)
check_eq "x DCR client gets a working token" "$(mcp_status "$DCR_AT")" 200
set_setting trusted_only true
echo "INFO x existing DCR connection once trusted_only is back on: MCP answers $(mcp_status "$DCR_AT") (the setting gates new connections)"
set_setting dcr_enabled false
check_eq "x DCR off -> /register 404" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "$H_JSON" -d '{}' "$OAUTH/register")" 404
curl -s -o /dev/null --data-urlencode "token=$(json_str "$(body "$r")" refresh_token)" "$OAUTH/revoke"
reset_limiter

# ================================================================== 12. Settings screen

section "12. Settings screen and debug.log"
r=$(curl -s -i -b "$ADMIN_JAR" "$BASE_URL/wp-admin/admin.php?page=lumia-tools&tab=module_mcp_connect")
check_eq "12 module admin page -> 200" "$(status "$r")" 200
check_re "12 module admin page renders the module" "$r" 'mcp-connect'
check_re "12 Help tab shows the MCP URL" "$r" "value=\"${MCP//\//\\/}\""
r=$(curl -s -i -H "X-E2E-User: $ADMIN_USER" "$BASE_URL/wp-admin/site-health.php")
check_eq "12 Site Health page -> 200" "$(status "$r")" 200
# The direct tests run while the page renders; their result is in the page data.
# Discovery and the probe loop back to BASE_URL from inside the container,
# which fails on the bench (localhost:8089 is the host port): status shown only.
for t in lumia_mcp_discovery lumia_mcp_authorization_header lumia_mcp_environment; do
	st=$(printf '%s' "$r" | sed -E 's/("test":"[^"]*"\})/\1\n/g' | grep "\"test\":\"$t\"}" | grep -oE '"status":"[a-z]+"' | tail -1 | cut -d'"' -f4)
	check_re "12 Site Health test $t ran" "$st" '^(good|recommended|critical)$'
	echo "      $t: $st"
done
NEW_LOG=$(dc exec -T wordpress bash -c "tail -n +$((LOG_START + 1)) /var/www/html/wp-content/debug.log 2>/dev/null" | tr -d '\r')
check_no_re "12 no PHP notice/warning from lumia-tools in debug.log" "$NEW_LOG" 'PHP (Warning|Notice|Deprecated|Fatal error|Parse error).*lumia-tools'
other=$(printf '%s' "$NEW_LOG" | grep -E 'PHP (Warning|Notice|Deprecated|Fatal error)' | grep -v 'lumia-tools' | sort -u | head -5)
[ -n "$other" ] && printf 'INFO other PHP messages in debug.log:\n%s\n' "$other" | sed '2,$s/^/      | /'

# ================================================================== 13. Uninstall

if [ "$DO_UNINSTALL" = 1 ]; then
	section "13. Uninstall"
	if [ "$fail" -ne 0 ]; then
		echo "SKIP 13 uninstall: earlier checks failed"
	else
		wpcli plugin deactivate lumia-tools >/dev/null
		wpcli plugin uninstall lumia-tools >/dev/null
		check_eq "13 MCP tables dropped" "$(sql "SHOW TABLES LIKE '${DB_PREFIX}lumia\\_mcp\\_%'" | wc -l | tr -d ' ')" 0
		check_eq "13 MCP options deleted" "$(sql "SELECT COUNT(*) FROM ${DB_PREFIX}options WHERE option_name IN ('lumia_module_mcp_connect', 'lumia_mcp_schema', 'lumia_mcp_agent_user_id')")" 0
		check_eq "13 agent role removed" "$(wpcli eval 'echo null === get_role("lumia_mcp_agent") ? "gone" : "present";')" gone
		check_eq "13 claude-mcp user deleted" "$(wpcli user get claude-mcp --field=ID)" ""
		dc exec -T wordpress bash -c 'rm -rf /var/www/html/wp-content/plugins/lumia-tools && mkdir -p /var/www/html/wp-content/plugins/lumia-tools'
		(cd "$REPO_DIR" && tar -cf - --exclude=./vendor --exclude=./.git --exclude=./tools --exclude=./docs --exclude=./.claude .) | dc exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/lumia-tools
		dc exec -T wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins/lumia-tools
		wpcli plugin activate lumia-tools >/dev/null
		for m in security activity_log mcp_connect; do set_module "$m" activate >/dev/null; done
		check_eq "13 reinstalled: agent user recreated" "$(wpcli user get claude-mcp --field=roles)" lumia_mcp_agent
		check_eq "13 reinstalled: discovery answers" "$(curl -s -o /dev/null -w '%{http_code}' "$AS_URL")" 200
	fi
fi

# ================================================================== teardown

dc exec -T wordpress rm -f /var/www/html/wp-content/e2e-snippets/e2e-mcp-ability.php
reset_limiter

echo
echo "RESULT pass=$pass fail=$fail"
if [ "$fail" -ne 0 ]; then
	printf '  failed: %s\n' "${FAILED[@]}"
	exit 1
fi
exit 0
