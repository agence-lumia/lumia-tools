# Lümia Tools — renommage et passage en anglais (issues #14 et #15)

Date : 09/10/2026 · Branche : `chore/15-rename-lumia` (depuis `dev`, PR #78 déjà fusionnée en local)

## Objectif

Transformer Studio Kyne Mini Tools en **Lümia Tools** et passer la base de code en anglais, en un seul chantier (les deux touchent tous les fichiers). Critère de réussite : sur un site en français, après migration, **l'interface affiche exactement les mêmes textes** qu'avant (au nom du plugin près) et aucune donnée n'est perdue — réglages, images optimisées, dossiers de médias, journaux, mot de passe SMTP, URL de connexion personnalisée.

Hors périmètre : modules MCP et Motion, nouvelles fonctionnalités, release stable (décidée plus tard).

## Décisions

| Sujet | Décision |
|---|---|
| Nom | Lümia Tools |
| Passage des sites | Installation **manuelle** sur les 8 sites ; Lümia Tools migre seul à l'activation. Pas de version pont SKMT. |
| Dépôt | Nouveau dépôt public `agence-lumia/lumia-tools`, historique et tags poussés, issues ouvertes transférées. `studiokyne/studio-kyne-mini-tools` gelé, archivé quand les 8 sites sont migrés. |
| Pourquoi pas renommer le dépôt actuel | Les SKMT installés interrogent `releases/latest`, prennent `studio-kyne-mini-tools-*.zip` ou, à défaut, le zipball. Une release Lümia dans le même dépôt leur serait proposée comme mise à jour de SKMT et casserait l'installation. |
| Version | 2.0.0 (premier `bump=major` dans le nouveau dépôt, à partir du tag v1.1.0) |
| Minimums | WordPress 6.9, PHP 8.0 |
| Méthode | Approche A : renommage mécanique scripté → traduction module par module → migration, i18n, CI |
| PR #78 | Livrée avec Lümia 2.0.0, pas de dernière release SKMT |

## 1. Correspondance des noms

| Avant | Après |
|---|---|
| `studio-kyne-mini-tools/studio-kyne-mini-tools.php` | `lumia-tools/lumia-tools.php` |
| Plugin Name « Studio Kyne Mini Tools », Author « Studio Kyne » | « Lümia Tools », « Agence Lümia » |
| Namespace `StudioKyne\MiniTools` | `Lumia\Tools` |
| Constantes `SKMT_*` | `LUMIA_*` |
| Préfixes `skmt_`, `_skmt_` (options, meta, hooks, AJAX, nonces, crons, tables, transients) | `lumia_`, `_lumia_` |
| Classes CSS / ids / handles `skmt-` | `lumia-` |
| Objet JS `skmtAdmin` (et autres globales `skmt*`) | `lumiaAdmin` (`lumia*`) |
| Text domain `studio-kyne-mini-tools` | `lumia-tools` |
| Slug de la page d'admin `studio-kyne-mini-tools` | `lumia-tools` |
| Updater `studiokyne/studio-kyne-mini-tools`, asset `studio-kyne-mini-tools-*.zip` | `agence-lumia/lumia-tools`, `lumia-tools-*.zip` |
| Zip de release (`release-please.yml`, `release-dev.yml`) | `lumia-tools-<version>.zip`, dossier `lumia-tools/` |
| Groupes de concurrence CI `skmt-*` | `lumia-*` |

**Exceptions — ne pas renommer :**

- `'skmt-smtp|'` dans `Smtp\Crypto` : contexte de dérivation de la clé de chiffrement. Le changer rend illisibles les mots de passe SMTP et clés Brevo enregistrés.
- `skmt-originals` (`ImageOptimizer\Module::BACKUP_DIR`) : dossier des originaux sur disque, référencé par la meta `_backup_file` de chaque image.
- La couche de compatibilité (section 3), qui lit volontairement les anciens noms.

## 2. Migration des données (`Core\Migration\FromSkmt`)

**Déclenchement** : à l'activation de Lümia Tools (`Activator::activate`) **et** au premier `plugins_loaded` en admin (cas d'un upload qui n'appelle pas le hook d'activation dans le même processus), si `skmt_settings` existe et que l'option `lumia_migrated_from_skmt` est absente. Idempotente : chaque étape vérifie son état avant d'agir ; le marqueur n'est posé qu'en fin de migration réussie.

| Donnée | Traitement |
|---|---|
| `skmt_settings`, `skmt_module_*`, `skmt_wl_menu_profiles`, `skmt_wl_menu_cache_gen`, `skmt_smtp_password`, `skmt_smtp_brevo_key`, options d'état de l'optimiseur (stats, bulk, jeton de sauvegarde) | **Copiées** vers `lumia_*` (sans écraser une clé `lumia_*` existante). Les originaux restent : filet tant que SKMT n'est pas supprimé. |
| Options de schéma `skmt_activity_log_schema`, `skmt_mail_log_schema` | Copiées. |
| Tables `{prefix}skmt_activity_log`, `{prefix}skmt_mail_log` | **`RENAME TABLE`** vers `{prefix}lumia_*` (si la cible n'existe pas). |
| Post meta `_skmt_*` (optimiseur d'images, repli) | **Renommées en place** : `UPDATE postmeta SET meta_key = '_lumia_…' WHERE meta_key = '_skmt_…'`, clé par clé (liste fermée). |
| User meta `skmt_notices`, `skmt_local_avatar` | Renommées en place (`usermeta`). |
| Taxonomie `skmt_media_folder` | Renommée en place (`UPDATE term_taxonomy SET taxonomy = 'lumia_media_folder'`), cache des termes vidé. |
| Crons `skmt_smtp_log_purge`, `skmt_activity_log_purge`, `skmt_image_optimizer_cron` | `wp_clear_scheduled_hook` sur l'ancien ; le module reprogramme le nouveau à son `init()` (bulk de l'optimiseur : reprogrammé avec ses arguments s'il était en cours). |
| Slug de page `studio-kyne-mini-tools` stocké dans les profils de menu (WhiteLabel, MenuCreator) | Remplacé par `lumia-tools` dans les options copiées (parcours récursif des tableaux, remplacement exact de la valeur et du préfixe `studio-kyne-mini-tools&`). |
| Transients `_skmt_rl_*`, `skmt_wl_menu_user_*`, `skmt_github_update*`, `skmt_image_caps_*` | Ignorés (reconstruits). Le compteur de rate limiting repart à zéro : accepté. |

En fin de migration : **désactivation de SKMT** (`deactivate_plugins`), sinon les deux plugins accrochent les mêmes hooks (URL de connexion, SMTP, white label). Puis notice persistante : « Migration depuis Studio Kyne Mini Tools terminée. Vous pouvez supprimer l'ancien plugin. »

Ordre de sûreté : tables et meta renommées **avant** la désactivation. Supprimer ensuite SKMT exécute son `uninstall.php`, qui ne trouve plus que les options d'origine (copiées) : rien de Lümia n'est touché. **Ce scénario est testé** (section 5).

Échec d'une étape (`$wpdb->last_error`) : migration interrompue, marqueur non posé, SKMT laissé actif, notice d'erreur avec l'étape en cause. Lümia Tools ne charge alors **aucun module** tant que SKMT est actif (contrôle au démarrage), pour éviter le double accrochage.

## 3. Compatibilité

- **Constantes `wp-config`** : `LUMIA_DISABLE_LOGIN_URL`, `LUMIA_SMTP_USER`, `LUMIA_SMTP_PASSWORD`, `LUMIA_BREVO_API_KEY`, `LUMIA_ENCRYPTION_KEY` ; la `SKMT_*` équivalente est lue en repli si la `LUMIA_*` est absente. Un helper unique (`Core\Compat::constant( 'SMTP_USER' )`) centralise la lecture. Textes d'aide de l'admin : nouveau nom uniquement.
- **Hooks publics** : `lumia_module_definitions`, `lumia_register_modules`, `lumia_custom_login_redirect`, `lumia_db_table_owner_aliases`, `lumia_activity_log_post_types`. L'ancien nom `skmt_*` est encore appliqué (`apply_filters_deprecated` / `do_action_deprecated`, version 2.0.0) : un snippet existant continue de fonctionner et signale la dépréciation en `WP_DEBUG`.
- **Database / Cleanup** : les tables `lumia_*` sont attribuées à Lümia Tools ; les anciennes `skmt_*` éventuelles (migration partielle) apparaissent comme orphelines, donc nettoyables.
- Pas de redirection des anciennes URL `admin.php?page=studio-kyne-mini-tools` (YAGNI).

## 4. Anglais et traductions

**Code** : identifiants, commentaires, docblocks, chaînes d'interface en anglais. `docs/`, `CLAUDE.md`, `README.md`, `.github/ISSUE_TEMPLATE/*`, `.github/PULL_REQUEST_TEMPLATE.md` en anglais. Messages de commit : Conventional Commits, en anglais à partir de ce chantier.

**Chaînes PHP** (~1 040 appels) : texte source anglais, text domain `lumia-tools`. Les `sprintf` gardent leurs placeholders ; un commentaire `/* translators: */` accompagne chaque chaîne à placeholder (règle PHPCS `WordPress.WP.I18n` déjà active).

**Chaînes JS** (~170 littéraux français dans `assets/admin/js/`) : sorties du JS, passées par PHP comme aujourd'hui (`lumiaAdmin.i18n` pour le cœur, `get_admin_js_data()` pour les modules), donc traduites par le même `.mo`. Pas de `wp.i18n` ni de JSON de traduction : un seul pipeline.

**Fichiers de langue** :

1. Pendant la traduction, chaque module produit `languages/pairs/<module>.json` : `{ "English source": "Texte français d'origine" }`, la valeur étant la chaîne française **exacte** d'avant (ponctuation, espaces insécables et typographie compris).
2. `wp i18n make-pot` (image `wordpress:cli`) → `languages/lumia-tools.pot`.
3. Script `tools/i18n-build-po.php` (lancé en Docker) : `.pot` + paires → `languages/lumia-tools-fr_FR.po` ; échoue si un `msgid` n'a pas de paire, **ou si une même source anglaise a deux traductions françaises différentes** (« Save » → « Enregistrer » ici, « Sauvegarder » là). Une collision se règle par un contexte `_x()`, jamais en changeant le texte français. Puis `wp i18n make-mo`.
4. `.pot`, `.po` et `.mo` committés ; `languages/pairs/` supprimé une fois le `.po` produit — le `.po` devient la seule source.
5. **Porte `lint.yml`** : régénère le `.pot` et échoue si un `msgid` manque dans le `.po` ou y est vide, ou si le `.mo` est plus ancien que le `.po` (comparaison par `msgunfmt`, pas par date).

Documenter dans `docs/core.md` la procédure pour ajouter une chaîne (make-pot, édition du `.po`, make-mo).

## 5. Vérification

1. `composer check` sans constat, sans baseline.
2. **Docker** (`wordpress:6.9+` / 7.1.2, PHP 8.x, MariaDB, wp-cli) :
   - installer SKMT au commit de départ (`8d4cd85`), locale `fr_FR`, tous les modules actifs, et créer des données réelles : réglages modifiés sur chaque module, images optimisées (meta + `skmt-originals`), dossiers de médias avec images, entrées des deux journaux, mot de passe SMTP enregistré (chiffré), URL de connexion personnalisée, profil de menu WhiteLabel/MenuCreator, avatar local, notice utilisateur ;
   - **capture de référence** : texte visible (DOM sans balises, normalisé) de chaque page d'admin du plugin et de chaque onglet de module, plus l'écran de connexion ;
   - installer le zip Lümia Tools, l'activer → contrôler chaque ligne du tableau de la section 2 (requêtes SQL), mot de passe SMTP déchiffrable, URL de connexion fonctionnelle et `wp-login.php` toujours bloqué, rate limiting actif, crons programmés sous `lumia_*`, SKMT désactivé ;
   - **recapture** : diff vide avec la référence, au nom du plugin près ;
   - supprimer SKMT par l'admin (exécute son `uninstall.php`) → données Lümia intactes ;
   - réactiver Lümia Tools : migration non rejouée.
3. Recherche de résidus : aucun `skmt`/`SKMT`/`studio-kyne`/`StudioKyne` hors liste d'exceptions et couche de compatibilité ; aucun caractère accentué français dans `includes/`, `templates/`, `assets/` hors `languages/` (seule exception : « Lümia »).

## 6. Déroulé Git et GitHub

1. Commits sur `chore/15-rename-lumia`, dans cet ordre : spec ; renommage mécanique ; traduction par module (un commit chacun : core/admin/templates, puis chaque module) ; migration + compatibilité ; fichiers de langue + outil ; CI (workflows, porte i18n) ; docs/CLAUDE.md/README.
2. **Avec confirmation au moment venu** (actions publiques) : création de `agence-lumia/lumia-tools`, push de `main`, `dev`, des tags et de la branche ; transfert des issues ouvertes (#14, #15, #21, #22, #23) ; PR vers `dev` dans le nouveau dépôt. Dans l'ancien dépôt, #77 et la PR #78 sont fermées avec un renvoi (fusionnée dans `dev` en local, livrée avec Lümia Tools 2.0.0).
3. Ancien dépôt : README mis à jour (« déplacé vers agence-lumia/lumia-tools ») au moment de l'archivage, pas avant.
4. Après fusion : liste des sites à migrer et procédure par site (`wp db export`, installation du zip, activation, contrôle, suppression de SKMT).

## Risques

| Risque | Parade |
|---|---|
| Une chaîne française change de forme à la traduction aller-retour | Paires stockant la chaîne **d'origine** + diff de texte visible avant/après |
| Un sous-agent renomme une exception (`skmt-smtp|`, `skmt-originals`) | Renommage mécanique fait **avant** et une seule fois par script, avec liste d'exceptions ; vérification par recherche en fin de chantier |
| Migration partielle sur un site | Étapes idempotentes, marqueur en fin, modules non chargés tant que SKMT est actif, `wp db export` avant chaque site |
| Snippet FluentSnippets ou thème qui utilise un hook `skmt_*` | Hooks dépréciés encore appliqués |
| Diff énorme, revue difficile | Un commit par étape et par module |
