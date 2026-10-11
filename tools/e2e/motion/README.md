# Scénarios du module Motion

Scénarios Playwright du module Motion, joués contre un site Bricks **local** (Bricks est
sous licence : pas de site Bricks en CI). Rien ici n'est distribué (`tools/` est exclu du
zip). Ne jamais pointer ces scénarios sur un site de production : `setup` crée des pages
et modifie des classes globales Bricks.

Prérequis : Node 22+, npm, Google Chrome, un site WordPress + Bricks avec l'arbre de
travail monté en `wp-content/plugins/lumia-tools`, et une commande WP-CLI qui l'atteint.
Au premier lancement, `playwright-core` (sans navigateur) est installé dans `.cache/`
(ignoré par git).

## Variables

| Variable | Défaut | Rôle |
|---|---|---|
| `LM_E2E_URL` | `http://localhost:8088` | Site testé |
| `LM_E2E_USER` / `LM_E2E_PASS` | `local-admin` / `local-admin` | Compte administrateur |
| `LM_E2E_WP` | `wp` | Commande WP-CLI (ex. un `wp.sh` qui lance le conteneur `wordpress:cli`) |
| `LM_E2E_PLUGIN_DIR` | `/var/www/html/wp-content/plugins/lumia-tools` | Chemin du plugin vu par WP-CLI (pour `eval-file`) |
| `LM_E2E_CHROME` | canal `chrome` | Exécutable Chrome |

## Commandes

```bash
tools/e2e/motion/run.sh setup            # crée ou remplace les pages lm-e2e-* (idempotent)
tools/e2e/motion/run.sh smoke enter      # scénarios nommés
tools/e2e/motion/run.sh all              # tous les scénarios
```

Chaque scénario est `scenarios/<nom>.mjs` et exporte `default async function ()` ; il
lève une erreur au premier écart (`FAIL <nom>: <message>`, code de sortie 1). Les pages
et les classes globales dont ils ont besoin sont déclarées dans `fixtures.mjs`
(`PAGES`, `CLASSES`) ; `setup.php` les écrit (méta `_bricks_page_content_2`), puis
`wp bricks regenerate_assets` régénère les fichiers CSS.

Les captures, l'état de connexion et le JSON des pages vont dans `out/` (ignoré par git).
