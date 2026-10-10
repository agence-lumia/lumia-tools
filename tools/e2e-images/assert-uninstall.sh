#!/usr/bin/env bash
#
# Plugin deactivation and uninstall of the Image Optimizer (spec 6, 9.4, 9.11), on a running
# stack with the plugin installed. Bench only, never shipped.
#
#   tools/e2e-images/assert-uninstall.sh [stack]      # default: nginx
#
# 1. seed (assert-uninstall.php seed): siblings, legacy file, excluded item, former metas and
#    options, kept original, uploads/.htaccess block, events with and without arguments;
# 2. `wp plugin deactivate`, then the "deactivated" checks (siblings purged, legacy file kept,
#    no block, no event) and a second seed without the plugin loaded;
# 3. `wp plugin uninstall` (uninstall.php, files deleted), then the "uninstalled" checks;
# 4. the working tree is installed again (`run.sh install-lumia`): the module is then inactive
#    (the uninstall deleted lumia_settings).
#
# Exit code: 0 success, 1 failure.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN="${E2E_DIR}/run.sh"
STACK="${1:-nginx}"
SCRIPT="${E2E_DIR}/assert-uninstall.php"
FAILURES=0

wpx() { "${RUN}" wp "${STACK}" "$@"; }

phase() {
	echo "== $1"
	"${RUN}" assert "${STACK}" "${SCRIPT}" "$1" || FAILURES=$((FAILURES + 1))
}

wpx plugin is-active lumia-tools >/dev/null 2>&1 || "${RUN}" install-lumia "${STACK}"
# The module on (outside the admin, Modules only registers the active modules: the state is
# set directly; the next request boots it, which is all the seed needs).
wpx eval '$s = get_option( "lumia_settings" ); $s["modules"]["image_optimizer"] = true; update_option( "lumia_settings", $s );'

phase seed
[ "${FAILURES}" -eq 0 ] || { echo "assert-uninstall: the seed failed"; exit 1; }

wpx plugin deactivate lumia-tools
phase deactivated

wpx plugin uninstall lumia-tools
phase uninstalled

"${RUN}" install-lumia "${STACK}"

[ "${FAILURES}" -eq 0 ] || { echo "assert-uninstall: ${FAILURES} phase(s) failed"; exit 1; }
echo "assert-uninstall: all checks passed."
