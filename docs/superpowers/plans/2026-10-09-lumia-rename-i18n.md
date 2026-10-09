# Lümia Tools — renommage et passage en anglais : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** transformer Studio Kyne Mini Tools en Lümia Tools (code en anglais, interface traduite en `fr_FR` à l'identique) avec une migration sans perte des sites en production.

**Architecture :** trois passes — renommage mécanique scripté, traduction module par module (chaînes anglaises + paires vers le français d'origine), puis compatibilité, migration et fichiers de langue. Le banc de test est un WordPress en Docker piloté par wp-cli : il installe SKMT au commit de départ, capture le texte de l'admin, installe Lümia Tools et vérifie migration et texte.

**Tech Stack :** PHP 8.0+ (WordPress 6.9+), JS vanilla, Composer pour l'outillage (PHPCS/WPCS, PHPStan niveau 8), Docker (`wordpress`, `wordpress:cli`, `mariadb`), GitHub Actions.

**Spec :** [docs/superpowers/specs/2026-10-09-lumia-rename-i18n-design.md](../specs/2026-10-09-lumia-rename-i18n-design.md)

## Global Constraints

- Branche `chore/15-rename-lumia`. Commit de départ de référence : `8d4cd85`. Rien n'est poussé sans accord explicite.
- Noms : `Lumia\Tools`, `LUMIA_`, `lumia_`, `_lumia_`, `lumia-`, `lumiaAdmin`, text domain et slug `lumia-tools`, fichier principal `lumia-tools.php`, « Lümia Tools », auteur « Agence Lümia » (`https://agence-lumia.com`), dépôt `agence-lumia/lumia-tools`, asset `lumia-tools-<version>.zip`.
- `Requires at least: 6.9`, `Requires PHP: 8.0` ; PHPCS `testVersion` `8.0-`, `minimum_wp_version` `6.9` ; `composer.json` `"php": ">=8.0"`.
- Aucun bump de version manuel (`* Version:` et `LUMIA_VERSION` restent à `1.1.0`, la CI fera 2.0.0).
- Garde `defined( 'ABSPATH' ) || exit;` sur tout nouveau fichier PHP de `includes/`.
- `composer check` sans constat, sans baseline. Commande (macOS) : `docker run --rm -v "$PWD:/app" -w /app composer:2 check`.
- Commits Conventional Commits **en anglais**, terminés par `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Les chaînes françaises d'origine sont reprises **à l'identique** (apostrophes typographiques, espaces insécables, ponctuation) comme traductions `fr_FR`.
- `docs/superpowers/` n'est jamais renommé ni traduit (archive de conception).

## Review Focus

1. **Site dont SKMT est actif mais jamais configuré** (pas de `skmt_module_*` en base, cas réel de 5 sites sur 8) : la migration copie ce qui existe, n'invente rien, et les défauts de Lümia s'appliquent (dont `rate_limiting = true`). → test dans la tâche 16 (`seed --minimal`).
2. **Réactivation de Lümia après migration**, ou désactivation/réactivation : la migration ne se rejoue pas et n'écrase pas des réglages modifiés depuis. → tâche 16.
3. **Clé SMTP fixée par `SKMT_ENCRYPTION_KEY` dans `wp-config`** : le rechiffrement lit l'ancienne clé avec ce matériau, et la nouvelle clé utilise aussi `SKMT_ENCRYPTION_KEY` en repli de `LUMIA_ENCRYPTION_KEY` — sinon le secret devient illisible au premier redémarrage. → tâche 16 (scénario `--encryption-key`).
4. **Chaîne anglaise identique pour deux textes français différents** : le `.po` ne peut en garder qu'un ; collision détectée par l'outil, résolue par `_x()`. → tâches 3 et 17.
5. **Chaînes JS affichées seulement à l'interaction** (modales, toasts) : invisibles dans la capture de texte des pages. Chaque littéral français retiré d'un JS doit se retrouver comme valeur de paire. → étape dédiée des tâches 4 à 14.

---

## Structure des fichiers

| Fichier | Rôle |
|---|---|
| `tools/e2e/docker-compose.yml` | WordPress + MariaDB + wp-cli, port 8089, locale `fr_FR` |
| `tools/e2e/mu-plugins/e2e-auth.php` | Authentifie la requête via l'en-tête `X-E2E-User` (banc uniquement, jamais distribué) |
| `tools/e2e/run.sh` | Point d'entrée : `up`, `down`, `seed-skmt [--minimal] [--encryption-key]`, `capture <dir>`, `install-lumia`, `assert-migration`, `uninstall-skmt`, `assert-after-uninstall`, `reactivate-lumia` |
| `tools/e2e/capture.php` | Texte visible normalisé de chaque page d'admin + écran de connexion |
| `tools/i18n/build-po.php` | `.pot` + `languages/pairs/*.json` → `.po` ; `--check` pour la CI |
| `includes/Core/Compat.php` | Constantes `wp-config` et hooks dépréciés |
| `includes/Core/Migration/FromSkmt.php` | Migration complète, idempotente |
| `languages/lumia-tools.pot`, `-fr_FR.po`, `-fr_FR.mo` | Traductions |
| `docs/migration-from-skmt.md` | Procédure par site |

---

### Task 1 : banc de test Docker et capture de référence

**Files :**
- Create : `tools/e2e/docker-compose.yml`, `tools/e2e/mu-plugins/e2e-auth.php`, `tools/e2e/run.sh`, `tools/e2e/capture.php`, `tools/e2e/README.md`
- Modify : `.gitignore` (ajouter `tools/e2e/out/`), `phpcs.xml.dist` (`<exclude-pattern>*/tools/e2e/*</exclude-pattern>`)

**Interfaces :**
- Produces : `tools/e2e/run.sh <commande>` (codes de sortie 0/1), répertoire `tools/e2e/out/baseline/` (un `.txt` par page), `seed-skmt` qui construit le zip SKMT par `git archive 8d4cd85 --prefix=studio-kyne-mini-tools/`.

- [ ] **Step 1 : `docker-compose.yml`** — services `db` (`mariadb:11`), `wordpress` (`wordpress:php8.3-apache`, port `8089:80`, `WORDPRESS_CONFIG_EXTRA` avec `define('WP_DEBUG', true); define('WP_DEBUG_LOG', true);`), `cli` (`wordpress:cli-php8.3`, mêmes volumes, `user: "33:33"`). Volume nommé pour `/var/www/html` ; `mu-plugins/` monté en lecture seule.
- [ ] **Step 2 : `e2e-auth.php`** — filtre `determine_current_user` : si `$_SERVER['HTTP_X_E2E_USER']` vaut un login existant, renvoie son ID. Commentaire en tête : banc de test uniquement.
- [ ] **Step 3 : `run.sh up`** — `docker compose up -d`, attente de la base, `wp core install` (admin `admin`/`admin`, URL `http://localhost:8089`), `wp language core install fr_FR --activate`, échec si `wp core version` < 6.9.
- [ ] **Step 4 : `run.sh seed-skmt`** — installe et active le zip SKMT de `8d4cd85`, active les 10 modules (`skmt_settings['modules']`), puis crée par `wp eval` / `wp media import` :
  - réglages modifiés sur chaque module (au moins une valeur différente du défaut par module) ;
  - Security : `enable_custom_login_url = true`, `custom_login_url = 'connexion-e2e'`, `rate_limiting = true` ;
  - ImageOptimizer : `optimize_on_upload`, `keep_original` ; import de 2 JPEG (fichiers générés par `php -r` + GD) → meta `_skmt_*` et dossier `skmt-originals-*` ;
  - Media : 2 termes `skmt_media_folder`, dont un avec la term meta `skmt_folder_color`, une image rangée dedans ;
  - SMTP : `Crypto::encrypt()` du mot de passe `e2e-secret` → `skmt_smtp_password`, clé Brevo `e2e-brevo` → `skmt_smtp_brevo_key`, journal activé, un `wp_mail()` (échec attendu → ligne dans `skmt_mail_log`) ;
  - ActivityLog : un enregistrement des réglages (ligne `event = 'skmt_settings'`, `object_type = 'skmt'`) ;
  - WhiteLabel : avatar local (`skmt_local_avatar`), profil de menu qui référence le slug `studio-kyne-mini-tools` (structure lue dans `MenuProfileManager`) ;
  - notice utilisateur (`skmt_notices`).
  - `--minimal` : SKMT activé, Security seul actif, **aucune** option `skmt_module_*`.
  - `--encryption-key` : ajoute `define('SKMT_ENCRYPTION_KEY','e2e-fixed-key');` au `wp-config.php` avant le chiffrement.
- [ ] **Step 5 : `capture.php`** (exécuté dans `cli`) — pour chaque URL (`admin.php?page=<slug>` + `&tab=dashboard|modules|settings`, `&tab=<module_id>` pour les 10 modules, puis `/connexion-e2e/`), requête HTTP avec `X-E2E-User: admin`, suppression de `<script>`, `<style>`, `#adminmenumain`, `#wpadminbar`, `#wpfooter`, extraction du texte (DOMDocument), espaces normalisés, une ligne par bloc. Slug lu en argument (`studio-kyne-mini-tools` ou `lumia-tools`). Écrit `out/<dir>/<page>.txt`.
- [ ] **Step 6 : vérifier** — `tools/e2e/run.sh up && tools/e2e/run.sh seed-skmt && tools/e2e/run.sh capture baseline`. Attendu : 14 fichiers non vides dans `out/baseline/`, aucun « Fatal error » / « Warning » dans `debug.log`. Relire deux fichiers : texte français lisible, sans menu WP.
- [ ] **Step 7 : commit** — `test(e2e): docker bench with SKMT seed and admin text capture`.

### Task 2 : renommage mécanique et versions minimales

**Files :** tout le dépôt sauf `vendor/`, `composer.lock`, `docs/superpowers/`, `tools/e2e/` (le banc garde ses références à SKMT). `git mv studio-kyne-mini-tools.php lumia-tools.php`.

**Interfaces :**
- Produces : tous les noms de la section 1 de la spec ; `Lumia\Tools\…` ; constantes `LUMIA_VERSION`, `LUMIA_PLUGIN_FILE`, `LUMIA_PLUGIN_DIR`, `LUMIA_PLUGIN_URL`, `LUMIA_INCLUDES_DIR`, `LUMIA_TEMPLATES_DIR`, `LUMIA_ASSETS_URL`.

- [ ] **Step 1 : script de renommage** (scratchpad, non committé). Remplacements **dans cet ordre** (perl, sensible à la casse) :
  1. `StudioKyne\\MiniTools` → `Lumia\\Tools` et `StudioKyne\MiniTools` → `Lumia\Tools`
  2. `studiokyne/studio-kyne-mini-tools` → `agence-lumia/lumia-tools` ; `'studiokyne'` (Updater `$github_user`) → `'agence-lumia'`
  3. `https://studiokyne.com` → `https://agence-lumia.com`
  4. `studio-kyne-mini-tools` → `lumia-tools`
  5. `Studio Kyne Mini Tools` → `Lümia Tools` ; `Mini Tools` → `Lümia Tools` ; `Studio Kyne` → `Agence Lümia`
  6. `SKMT` → `LUMIA` ; `Skmt` → `Lumia` ; `skmt` → `lumia`
  7. `StudioKyne` (prefixes PHPCS) → `Lumia`
- [ ] **Step 2 : en-tête et outillage** — `lumia-tools.php` : `Requires at least: 6.9`, `Requires PHP: 8.0`, description inchangée (traduite en tâche 4). `phpcs.xml.dist` : `testVersion` `8.0-`, `minimum_wp_version` `6.9`. `composer.json` : nom `agence-lumia/lumia-tools`, `"php": ">=8.0"`, homepage. `phpstan.neon.dist` : chemin `lumia-tools.php`. Workflows : fichier `lumia-tools.php`, zip et dossier `lumia-tools`, groupes `lumia-*`.
- [ ] **Step 3 : vérifier les résidus** — `git grep -nIiE "skmt|studio.?kyne|studiokyne|mini.?tools" -- . ':!docs/superpowers' ':!tools/e2e' ':!composer.lock'` → aucune ligne.
- [ ] **Step 4 : `composer check`** → 0 erreur. Un constat PHPCompatibility nouveau (passage en 8.0) se corrige, jamais par baseline.
- [ ] **Step 5 : fumée en Docker** — `run.sh down && run.sh up`, installer le zip du répertoire de travail (`run.sh install-lumia`, même `rsync --exclude` que `release-please.yml`), activer les 10 modules, ouvrir chaque page par `capture smoke`. Attendu : aucun fatal dans `debug.log`, 14 fichiers non vides.
- [ ] **Step 6 : commit** — `refactor(core): rename plugin to Lümia Tools (namespace, prefixes, text domain, slug)`.

### Task 3 : outil de fichiers de langue

**Files :**
- Create : `tools/i18n/build-po.php`, `tools/i18n/fixtures/` (un `.pot` de 3 entrées, deux fichiers de paires dont une collision), `tools/i18n/README.md`
- Modify : `composer.json` (scripts `i18n:pot`, `i18n:po`, `i18n:mo`, `i18n:check`), `.github/workflows/lint.yml`

**Interfaces :**
- Produces : `php tools/i18n/build-po.php build <pot> <pairs-dir> <po>` (code 1 + liste si `msgid` sans paire ou collision) ; `php tools/i18n/build-po.php check <pot> <po> <mo>` (code 1 si `msgid` absent/vide dans le `.po`, ou `.mo` désynchronisé). Format des paires : `{ "<msgctxt>\u0004<msgid>" | "<msgid>": "<texte français>" }` ; pluriels : `{ "<msgid>|<msgid_plural>": ["<fr singulier>", "<fr pluriel>"] }`.
- Commandes Docker : `docker run --rm -v "$PWD:/app" -w /app wordpress:cli-php8.3 wp i18n make-pot . languages/lumia-tools.pot --slug=lumia-tools --domain=lumia-tools --exclude=vendor,tools,docs,dist` ; `wp i18n make-mo languages/`.

- [ ] **Step 1 : fixtures et test** — `tools/i18n/test.sh` : `build` sur les fixtures doit échouer en nommant la collision ; sans le fichier en collision, réussir et produire un `.po` dont les 3 `msgstr` sont les textes français attendus (comparaison exacte) ; `check` doit échouer sur un `.po` où un `msgstr` est vide.
- [ ] **Step 2 : lancer `test.sh`** → échec (outil absent).
- [ ] **Step 3 : implémenter `build-po.php`** (PHP CLI pur, lecteur/écrivain PO minimal : `msgctxt`, `msgid`, `msgid_plural`, `msgstr[n]`, commentaires `#.` et `#:` repris du `.pot`, en-tête `Language: fr_FR`, `Plural-Forms: nplurals=2; plural=(n > 1);`). `check` compare via `msgunfmt` si disponible, sinon relit le `.mo` (format binaire GNU) pour comparer les `msgstr`.
- [ ] **Step 4 : `test.sh`** → PASS.
- [ ] **Step 5 : porte CI** — job `i18n` dans `lint.yml` : make-pot dans `wordpress:cli-php8.3`, puis `build-po.php check`. Tant que `languages/` n'existe pas (tâches 4–16), le job est sauté si `languages/lumia-tools-fr_FR.po` est absent ; la tâche 17 retire cette condition.
- [ ] **Step 6 : commit** — `build(i18n): pot/po tooling with pairs, collision check and CI gate`.

### Tasks 4 à 14 : traduction en anglais, une tâche par périmètre

Même procédure pour chaque périmètre ; un sous-agent par tâche, exécutables **en parallèle** (fichiers disjoints, un fichier de paires chacun).

| Tâche | Périmètre (fichiers) | Paires |
|---|---|---|
| 4 | `lumia-tools.php`, `uninstall.php`, `includes/Core/*`, `includes/Admin/*`, `templates/**`, `assets/admin/js/admin.js`, `assets/admin/js/notifications.js`, `assets/admin/css/*.css` (hors `modules/`), `assets/login/css/login.css`, `docs/core.md`, `docs/design-system.md`, `docs/README.md` | `core.json` |
| 5 | Security (`includes/Modules/Security/*`, `assets/admin/js/modules/security-admin.js`, `assets/admin/css/modules/security-admin.css`, `docs/modules/security.md`) | `security.json` |
| 6 | WhiteLabel (+ `white-label.css`, `docs/modules/white-label.md`) | `white-label.json` |
| 7 | ImageOptimizer (+ `image-optimizer.js/.css`, doc) | `image-optimizer.json` |
| 8 | MenuCreator (+ `menu-creator.js/.css`, doc) | `menu-creator.json` |
| 9 | Login (+ `login.js/.css`, doc) | `login.json` |
| 10 | Files (+ `files.js/.css`, doc) | `files.json` |
| 11 | Media (+ `media.js/.css`, doc) | `media.json` |
| 12 | Database (+ `database.js/.css`, doc) | `database.json` |
| 13 | ActivityLog (+ `activity-log.js/.css`, doc) | `activity-log.json` |
| 14 | Smtp (+ `smtp.js/.css`, doc) | `smtp.json` |

**Interfaces :**
- Consumes : format des paires (tâche 3). Mécanisme JS existant : cœur → tableau `i18n` passé à `wp_localize_script( 'lumia-admin', 'lumiaAdmin', … )` dans `Admin.php` ; module → clé `i18n` du tableau renvoyé par `get_admin_js_data()` (créer la méthode si le module n'en a pas, contrat dans `docs/core.md`).
- Produces : `languages/pairs/<périmètre>.json` ; tâche 4 produit en plus les clés `lumiaAdmin.i18n.*` dont les modules peuvent se servir (lire `Admin.php` avant d'en ajouter).

- [ ] **Step 1 : relever les littéraux JS français** du périmètre avant toute modification : `grep -noE "(['\"\`])[^'\"\`]*[À-ÿ][^'\"\`]*\1" <js>` → `tools/e2e/out/js-literals-<périmètre>.txt` (+ littéraux sans accent visibles à l'écran, relevés à la lecture).
- [ ] **Step 2 : traduire** :
  - chaque appel i18n PHP : source anglaise naturelle (style WordPress, casse de phrase), domaine `lumia-tools` ; paire `"English": "Texte français d'origine exact"` ; `/* translators: */` sur toute chaîne à placeholder ; placeholders numérotés (`%1$s`) dès qu'il y en a deux ;
  - chaque littéral JS visible : retiré du JS, ajouté dans le tableau `i18n` côté PHP (`__()`), lu en JS par `lumiaAdmin.i18n.<clé>` ou par les données du module ; clé en camelCase anglais ;
  - commentaires, docblocks, noms de variables/méthodes/propriétés français → anglais (ex. `$bloque` → `$blocked`) ; ne pas changer les clés persistées (noms d'options, clés de tableaux de réglages, valeurs de `<option>` enregistrées) ;
  - la page `docs/` du périmètre en anglais ; les pièges documentés gardent leur contenu.
- [ ] **Step 3 : contrôle des JS** — chaque ligne de `js-literals-<périmètre>.txt` est une valeur du fichier de paires (script `jq` ou PHP ad hoc). Attendu : 0 manquant.
- [ ] **Step 4 : contrôle des paires** — make-pot restreint au périmètre (`--include=<chemins>`) puis `build-po.php build` sur ce `.pot` et ce seul fichier de paires → code 0 (aucun `msgid` sans paire, aucune collision interne).
- [ ] **Step 5 : contrôle des accents** — `grep -nP "[À-ÿ]" <fichiers du périmètre> | grep -v "Lümia"` → aucune ligne.
- [ ] **Step 6 : `composer check`** → 0 erreur.
- [ ] **Step 7 : commit** — `refactor(<scope>): English codebase and translatable strings` (`scope` = `core`, `security`, `white-label`, …).

### Task 15 : couche de compatibilité

**Files :**
- Create : `includes/Core/Compat.php`
- Modify : tous les appels aux constantes `LUMIA_DISABLE_LOGIN_URL`, `LUMIA_SMTP_USER`, `LUMIA_SMTP_PASSWORD`, `LUMIA_BREVO_API_KEY`, `LUMIA_ENCRYPTION_KEY` et à `apply_filters`/`do_action` dont le nom commence par `lumia_`
- Test : `tools/e2e/run.sh assert-compat`

**Interfaces :**
- Produces :
  - `Compat::constant( string $name ): mixed` — valeur de `LUMIA_{$name}`, sinon `SKMT_{$name}`, sinon `null` ;
  - `Compat::has_constant( string $name ): bool` ;
  - `Compat::apply_filters( string $suffix, mixed $value, mixed ...$args ): mixed` — si `has_filter( 'skmt_' . $suffix )`, passe d'abord par `apply_filters_deprecated( 'skmt_' . $suffix, [ $value, ...$args ], '2.0.0', 'lumia_' . $suffix )`, puis `apply_filters( 'lumia_' . $suffix, … )` ;
  - `Compat::do_action( string $suffix, mixed ...$args ): void` — même schéma avec `do_action_deprecated`.
  - Hooks concernés (liste fermée, relevée à `8d4cd85`) : `module_definitions`, `register_modules`, `custom_login_redirect`, `db_table_owner_aliases`, `activity_log_post_types`, `activity_log_content_meta_keys`, `activity_log_tracked_options`, `activity_log_record`, `mc_editor_excluded_slugs`.

- [ ] **Step 1 : test** — `assert-compat` installe un mu-plugin temporaire qui `define('SKMT_DISABLE_LOGIN_URL', true)` et `add_filter('skmt_custom_login_redirect', …)`, puis vérifie : `wp-login.php` répond 200 malgré l'URL personnalisée ; le filtre ancien est appliqué ; `debug.log` contient la notice de dépréciation.
- [ ] **Step 2 : lancer** → FAIL.
- [ ] **Step 3 : implémenter `Compat`** et remplacer les appels directs. Les textes d'aide n'affichent que les noms `LUMIA_*`.
- [ ] **Step 4 : Database / Cleanup** — lire comment `Cleanup` attribue une table à un plugin (jeton avant le premier `_`, `OWNER_ALIASES`) ; garantir que `{prefix}lumia_*` est attribuée à Lümia Tools et qu'une `{prefix}skmt_*` résiduelle apparaît comme orpheline. Ajouter ce contrôle à `assert-compat` (créer une table `skmt_e2e_leftover` vide, lire la liste du module par `wp eval`).
- [ ] **Step 5 : lancer** → PASS ; `composer check` → 0.
- [ ] **Step 6 : commit** — `feat(core): compatibility layer for SKMT constants and hooks`.

### Task 16 : migration depuis SKMT

**Files :**
- Create : `includes/Core/Migration/FromSkmt.php`
- Modify : `includes/Core/Activator.php` (appel en tête de `activate()`), `includes/Core/Plugin.php` (garde « SKMT actif » + notices), `includes/Modules/Smtp/Crypto.php`, `includes/Modules/ImageOptimizer/Module.php` (`get_backup_dir()`)
- Test : `tools/e2e/run.sh assert-migration`, `assert-after-uninstall`, `reactivate-lumia`

**Interfaces :**
- Consumes : `Compat::constant( 'ENCRYPTION_KEY' )` (tâche 15).
- Produces :
  - `FromSkmt::LEGACY_PLUGIN = 'studio-kyne-mini-tools/studio-kyne-mini-tools.php'`, `FromSkmt::MARKER = 'lumia_migrated_from_skmt'` (valeur : timestamp), `FromSkmt::ERROR_OPTION = 'lumia_migration_error'` (nom de l'étape en échec) ;
  - `FromSkmt::needed(): bool` — `false !== get_option( 'skmt_settings' )` et marqueur absent ;
  - `FromSkmt::run(): bool` — étapes dans l'ordre de la section 2 de la spec, chacune idempotente ; `true` = marqueur posé et SKMT désactivé ;
  - `Crypto::reencrypt_from_legacy( string $stored ): string` — déchiffre avec le contexte `'skmt-smtp|'`, rechiffre avec `'lumia-smtp|'` ; si le déchiffrement échoue, renvoie `$stored` inchangé. `Crypto::key()` prend le contexte en paramètre ; le matériau des deux clés = `Compat::constant( 'ENCRYPTION_KEY' )`, sinon `LOGGED_IN_KEY . LOGGED_IN_SALT`, sinon `wp_salt( 'logged_in' )` ;
  - `ImageOptimizer\Module::LEGACY_BACKUP_DIR = 'skmt-originals'` : `get_backup_dir()` renvoie le dossier `skmt-originals-{jeton}` s'il existe et que `lumia-originals-{jeton}` n'existe pas.

Listes fermées utilisées par `run()` :
- options copiées : `skmt_settings`, `skmt_module_{image_optimizer,security,login,files,white_label,menu_creator,database,media,activity_log,smtp}`, `skmt_module_image_optimizer_stats`, `_bulk_state`, `_backup_token`, `skmt_wl_menu_profiles`, `skmt_wl_menu_cache_gen`, `skmt_activity_log_schema`, `skmt_mail_log_schema` ; `skmt_smtp_password` et `skmt_smtp_brevo_key` passent par `reencrypt_from_legacy()` ;
- post meta : `_skmt_optimized`, `_skmt_original_bytes`, `_skmt_optimized_bytes`, `_skmt_bytes_saved`, `_skmt_main_original_bytes`, `_skmt_main_optimized_bytes`, `_skmt_main_bytes_saved`, `_skmt_optimized_format`, `_skmt_optimized_mime`, `_skmt_backup_file`, `_skmt_fallback_files` ;
- user meta : `skmt_notices`, `skmt_local_avatar` ; term meta : `skmt_folder_color` ; taxonomie : `skmt_media_folder` ;
- tables : `skmt_activity_log`, `skmt_mail_log` ; lignes : `event 'skmt_settings' → 'lumia_settings'`, `object_type 'skmt' → 'lumia'` ;
- crons effacés : `skmt_smtp_log_purge`, `skmt_activity_log_purge`, `skmt_image_optimizer_cron` (si ce dernier était programmé, reprogrammer `lumia_image_optimizer_cron` avec les mêmes arguments et le même horaire) ;
- slug : dans `lumia_wl_menu_profiles` et le réglage `menu_creator`, toute chaîne égale à `studio-kyne-mini-tools` ou commençant par `studio-kyne-mini-tools&` est réécrite avec `lumia-tools` (parcours récursif).

- [ ] **Step 1 : test `assert-migration`** — après `seed-skmt` puis `install-lumia` (activation), en SQL via `wp db query` et `wp eval` :
  - chaque option de la liste existe en `lumia_*` et vaut l'originale (sauf secrets et slug réécrits) ; les originales existent toujours ;
  - `Crypto::decrypt( get_option( 'lumia_smtp_password' ) ) === 'e2e-secret'`, clé Brevo `'e2e-brevo'` ;
  - 0 post meta `_skmt_%`, autant de `_lumia_%` qu'il y avait de `_skmt_%` ; idem user meta, term meta, `term_taxonomy` ;
  - tables `lumia_*` présentes avec le même nombre de lignes, tables `skmt_*` absentes ; plus aucune ligne `object_type = 'skmt'` ;
  - `uploads/lumia-originals-<jeton>` existe, `skmt-originals-*` non ; « Restaurer l'original » (méthode du module) sur une image → succès ;
  - crons `lumia_*` programmés, aucun `skmt_*` ;
  - profil de menu : plus de `studio-kyne-mini-tools` ;
  - `wp plugin is-active studio-kyne-mini-tools` → non ; marqueur posé ; `lumia_migration_error` absent ;
  - `curl -o /dev/null -w '%{http_code}' /wp-login.php` → 404 (ou la réponse de blocage du module) et `/connexion-e2e/` → 200 ;
  - `rate_limiting` vrai.
  `assert-after-uninstall` : `wp plugin delete studio-kyne-mini-tools` puis mêmes contrôles sur les données `lumia_*`. `reactivate-lumia` : modifier un réglage `lumia_module_security`, désactiver/réactiver, vérifier que la valeur modifiée est conservée et que le marqueur n'a pas changé.
  Scénarios : défaut ; `--minimal` (aucune option module créée par la migration, défauts appliqués, `rate_limiting` vrai) ; `--encryption-key` (le secret reste déchiffrable après migration **et** après `wp eval 'wp_cache_flush();'` + nouvelle requête).
- [ ] **Step 2 : lancer** → FAIL.
- [ ] **Step 3 : implémenter `FromSkmt`**, le branchement en tête d'`Activator::activate()` (si `needed()`, `run()` avant la création des défauts), `Crypto`, `get_backup_dir()`. Requêtes SQL par `$wpdb->prepare` ; noms de tables bâtis depuis `$wpdb->prefix` et des constantes uniquement. Échec d'une étape (`$wpdb->last_error` non vide, `rename()` d'un fichier excepté) : `update_option( ERROR_OPTION, '<étape>' )`, arrêt, SKMT laissé actif.
- [ ] **Step 4 : garde dans `Plugin`** — si `defined( 'SKMT_VERSION' )` : aucun `Module::init()`, notice admin « Studio Kyne Mini Tools is still active… » (traduite) ; si `ERROR_OPTION` existe : notice d'erreur nommant l'étape ; si le marqueur vient d'être posé : notice persistante de succès (mécanisme de notices existant, `docs/core.md`).
- [ ] **Step 5 : lancer les trois scénarios** → PASS. `composer check` → 0.
- [ ] **Step 6 : piège documenté** — ajouter à `docs/core.md` (anglais) une section « Migration from SKMT » : ordre migration → défauts, pourquoi renommer tables/meta plutôt que copier (uninstall de SKMT), rechiffrement.
- [ ] **Step 7 : commit** — `feat(core): migrate SKMT data on activation`.

### Task 17 : fichiers de langue et vérification du texte

**Files :** Create `languages/lumia-tools.pot`, `languages/lumia-tools-fr_FR.po`, `languages/lumia-tools-fr_FR.mo` ; delete `languages/pairs/` ; modify `.github/workflows/lint.yml` (condition retirée).

- [ ] **Step 1 : générer** — `composer i18n:pot`, puis `build-po.php build languages/lumia-tools.pot languages/pairs languages/lumia-tools-fr_FR.po`. Collision entre deux fichiers de paires : ajouter `_x()` avec un contexte anglais court aux deux appels en cause, mettre à jour les paires, régénérer. Jamais modifier le texte français.
- [ ] **Step 2 : `composer i18n:mo`** puis `composer i18n:check` → code 0.
- [ ] **Step 3 : comparaison de texte** — banc neuf : `up`, `seed-skmt`, `install-lumia`, `capture after` (slug `lumia-tools`), puis `diff -r out/baseline out/after`. Différences admises : le nom du plugin (« Studio Kyne Mini Tools » / « Mini Tools » → « Lümia Tools »), les horodatages relatifs. Toute autre ligne est une régression de traduction : corriger la paire ou la source, régénérer, recapturer.
- [ ] **Step 4 : supprimer `languages/pairs/`**, `composer check`, `composer i18n:check`.
- [ ] **Step 5 : commit** — `feat(i18n): French translation files`.

### Task 18 : documentation et modèles GitHub en anglais

**Files :** `CLAUDE.md`, `README.md`, `.github/ISSUE_TEMPLATE/*`, `.github/PULL_REQUEST_TEMPLATE.md`, `docs/README.md` (lien vers la nouvelle page), create `docs/migration-from-skmt.md`.

- [ ] **Step 1 : `CLAUDE.md`** — même contenu, en anglais, avec : noms Lümia, PHP 8.0 / WP 6.9, commande `composer check` pour macOS **et** Windows, procédure i18n (ajout d'une chaîne : `__()` anglais → `composer i18n:pot` → édition du `.po` → `composer i18n:mo` → `composer i18n:check`), règle « commits en anglais », dépôt `agence-lumia/lumia-tools`.
- [ ] **Step 2 : `README.md`** — anglais, liste des **10** modules (ActivityLog et Smtp manquaient).
- [ ] **Step 3 : `docs/migration-from-skmt.md`** — procédure par site : `wp db export`, installer le zip Lümia, activer, contrôles (pages du plugin, connexion par l'URL personnalisée, envoi de test SMTP, une image restaurable), suppression de SKMT ; en cas d'échec : lire la notice, `wp option get lumia_migration_error`, restaurer l'export.
- [ ] **Step 4 : vérifier** — `git grep -nP "[À-ÿ]" -- . ':!docs/superpowers' ':!languages' ':!tools/e2e' ':!vendor' ':!composer.lock' | grep -v Lümia` → aucune ligne.
- [ ] **Step 5 : commit** — `docs: English documentation and SKMT migration guide`.

### Task 19 : vérification finale

- [ ] **Step 1** — `composer check`, `composer i18n:check`, `tools/i18n/test.sh`.
- [ ] **Step 2** — banc neuf, les trois scénarios de la tâche 16 + `assert-compat` + comparaison de texte : tout PASS.
- [ ] **Step 3** — installation **vierge** de Lümia (sans SKMT) : activation, 10 modules, aucune notice de migration, aucun fatal, `wp option get lumia_migrated_from_skmt` → absent.
- [ ] **Step 4** — résidus : `git grep -nIiE "skmt|studio.?kyne|studiokyne" -- . ':!docs/superpowers' ':!tools/e2e' ':!composer.lock'` → uniquement `Compat.php`, `Migration/FromSkmt.php`, `Crypto.php` (contexte legacy), `ImageOptimizer/Module.php` (`LEGACY_BACKUP_DIR`), `docs/core.md`, `docs/migration-from-skmt.md`.
- [ ] **Step 5** — revue de toute la branche (superpowers:requesting-code-review).

### Task 20 : publication GitHub — **sur confirmation explicite de l'utilisateur**

- [ ] Créer `agence-lumia/lumia-tools` (public), pousser `main`, `dev`, tags, `chore/15-rename-lumia`.
- [ ] Transférer #14, #15, #21, #22, #23 ; fermer #77 et la PR #78 dans l'ancien dépôt avec renvoi.
- [ ] PR `chore/15-rename-lumia` → `dev` dans le nouveau dépôt (modèle de PR, `Closes #<n>` avec les **nouveaux** numéros d'issues), lecture de la revue Copilot, fusion locale puis un seul push de `dev` (pré-release automatique). La release stable (`bump=major` → 2.0.0) reste à l'initiative de l'utilisateur.
- [ ] Remettre la liste des sites à migrer et la procédure.
