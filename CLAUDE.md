# CLAUDE.md

Guide de travail pour Claude Code sur ce dépôt. Les règles ci-dessous sont absolues ; le détail (architecture complète, pièges par module, design system) vit dans [docs/](docs/README.md) et se lit **à la demande**, quand la tâche touche le sujet.

## Le projet

**Lümia Tools** est une extension WordPress modulaire (PHP 8.0+, WP 6.9+). Pas de build, pas de Composer à l'exécution, pas de npm : du PHP pur avec un autoloader PSR-4 maison (`Lumia\Tools\` → `includes/`). Aucun test automatisé. Le JS tiers est embarqué tel quel sous `assets/admin/js/vendor/`, jamais bundlé.

Dix modules sous `includes/Modules/` : Security, WhiteLabel, ImageOptimizer, MenuCreator, Login, Files, Media, Database, ActivityLog, Smtp. Chacun étend `AbstractModule`, enregistre ses hooks dans `init()`, assainit lui-même ce qu'il persiste (`save_settings()` — le cœur n'applique rien) et déclare ses clés de désinstallation. Réglages : `lumia_settings` (global + état des modules) et `lumia_module_{id}` (par module), fusionnés **récursivement** sur les défauts.

Cycle : `plugins_loaded` → `Plugin::instance()` → `init` → chargement du textdomain, définition des modules (filtre `lumia_module_definitions`), `Module::init()` sur chaque module actif. `Admin` n'existe que sous `is_admin()`. Tout formulaire poste vers `admin-post.php` avec nonce + `manage_options` ; tout endpoint AJAX (`wp_ajax_lumia_{module}_{action}`) vérifie `lumia_admin_nonce` puis la capacité du module.

## Règles absolues

- **Jamais de bump de version manuel.** La CI le fait (`* Version:` et `LUMIA_VERSION` dans `lumia-tools.php`, toujours en phase). Push sur `dev` → pré-release automatique ; stable → `workflow_dispatch` sur `main`, entrée `bump` = `patch` (défaut), `minor` ou `major` (`gh workflow run release-please.yml -f bump=minor`).
- **Garde `ABSPATH`** sur tout fichier PHP : `defined( 'ABSPATH' ) || exit;` après `namespace`, sinon après le docbloc.
- **Jamais de SVG dessiné ou approximé.** Toute icône vient de [Lucide](https://lucide.dev) (lucide-static v1.34.0), fichier officiel récupéré tel quel — en PHP (`Admin::get_icon_paths()`) comme dans le JS des modules.
- **Composants du design system uniquement** (`components.css` + `admin.js`) : modales, tooltips, toasts, boutons, formulaires, onglets. Ne pas recoder d'équivalent. Voir [docs/design-system.md](docs/design-system.md).
- **Ne jamais faire confiance au client** : tout `$_POST`/`$_GET`/`$_FILES` passe par l'assainisseur adapté après `wp_unslash()` ; chemins, identifiants SQL, IP et JSON importé sont validés côté serveur (voir les pages de module).
- **Pas de baseline PHPCS/PHPStan** : ne jamais en recréer une pour faire passer un constat. Corriger, ou poser un `phpcs:ignore` / `@phpstan-ignore` motivé.
- **Documenter les pièges dans `docs/`**, pas ici : quand une correction naît d'un comportement contre-intuitif de WordPress ou d'un bug qui a coûté, l'écrire dans la page du module (ce qui cassait, pourquoi cette solution).

## Convention de travail

Le suivi vit **dans les issues GitHub** (`gh issue list`) : toujours les consulter avant de proposer un plan.

1. **Une issue par sujet.** Modèles dans `.github/ISSUE_TEMPLATE/`.
2. **Une branche par issue**, créée depuis `dev` : `feat/<n>-<sujet>`, `fix/<n>-<sujet>`, `docs/<n>-<sujet>`, `chore/<n>-<sujet>`.
3. **Commits en Conventional Commits** : `type(scope): description` — types `feat`, `fix`, `docs`, `style`, `refactor`, `chore`, `ci` ; scope = module ou zone (`security`, `media`, `core`, `lint`…).
4. **PR vers `dev`**, titre en Conventional Commits, corps depuis `.github/PULL_REQUEST_TEMPLATE.md`, avec `Closes #n`. Lire la revue automatique de Copilot (≈ 2 min) avant de fusionner.
5. **Fusion locale groupée puis un seul push** de `dev`, pour ne déclencher qu'une pré-release par lot. `Closes #n` ne ferme l'issue qu'à l'arrivée sur `main` : après fusion dans `dev`, fermer à la main avec `gh issue close`.
6. **`composer check` avant la PR** (PHPCS + PHPStan). Pas de PHP sur le poste : `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 check`. Le workflow `lint.yml` rejoue le tout sur chaque PR.

## Où lire le détail

- [docs/core.md](docs/core.md) — démarrage, autoloader, stockage, contrat `AbstractModule` (capacité requise, `to_form_payload()`, `get_export_extras()`, `get_admin_js_deps()`, `get_admin_js_data()`), ajout d'un module, formulaires, import de réglages, téléchargements, AJAX, icônes, notices persistantes, updater, outillage.
- [docs/design-system.md](docs/design-system.md) — classes BEM `lumia-`, tokens, modales, formulaires, boutons, tooltips, toasts.
- [docs/modules/](docs/README.md#modules) — une page par module, à ouvrir avant de toucher au module concerné.
