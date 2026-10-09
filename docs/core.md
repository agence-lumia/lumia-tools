# Cœur du plugin

Architecture, contrats et pièges du cœur (`includes/Core/`, `Admin`, `AbstractModule`). Les modules ont chacun leur page sous [modules/](modules/).

## Séquence de démarrage

```
plugins_loaded → Plugin::instance() (singleton)
  └─ init hook → on_init()
       ├─ load_textdomain()
       ├─ Modules::register_default_modules()   ← déclenche le filtre lumia_module_definitions
       └─ Modules::init_active_modules()         ← appelle Module::init() sur chaque module actif
```

`Admin` est instancié dans le même constructeur de `Plugin`, uniquement quand `is_admin()`.

## Autoloader

`Lumia\Tools\` correspond directement à `includes/`. Exemple : `Lumia\Tools\Modules\Security\RateLimiter` → `includes/Modules/Security/RateLimiter.php`. Pas de Composer à l'exécution, pas de `vendor/` embarqué.

Les modules peuvent dépendre de classes d'un autre module (`MenuCreator\Module` utilise `WhiteLabel\MenuProfileManager`) : l'autoloader n'isole rien. Le module dépendant ne fonctionne plus correctement si l'autre est désactivé ou désinstallé.

## Stockage des réglages

| Portée | Clé d'option | Accès |
|---|---|---|
| Plugin global | `lumia_settings` | `Settings::get('global.update_channel')` (notation pointée) |
| État des modules | `lumia_settings` → `modules.{id}` | `Settings::get('modules.security')` |
| Réglages d'un module | `lumia_module_{id}` | `AbstractModule::get_module_settings()` |

Les entrées du rate limiter sont des **transients**, clé `_lumia_rl_{md5(ip)}`, TTL 24 h : elles expirent seules et sont volontairement absentes de `get_uninstall_keys()`. Le cache de profil de menu par utilisateur est aussi un transient (`lumia_wl_menu_user_{gen}_{id}`, 1 h).

`AbstractModule::get_module_settings()` fusionne les valeurs stockées par-dessus les défauts **récursivement** : il descend dans les tableaux associatifs mais remplace les listes (rôles, listes d'IP) en bloc. `wp_parse_args()` seul ne fusionne que le premier niveau : toute sous-clé ajoutée dans une version ultérieure manquerait sur les installations existantes tant que l'utilisateur n'a pas ré-enregistré l'écran, et un réglage dont le défaut est `true` arriverait silencieusement à `false` partout.

## Système de modules

Chaque module est une classe qui étend `AbstractModule` (qui implémente `ModuleInterface`). Méthodes obligatoires :

- `init(): void` — enregistrer tous les hooks WordPress ici
- `get_settings(): array` — réglages courants
- `save_settings(array $settings): bool` — assainir et persister ; le cœur n'applique aucun assainissement
- `static get_defaults(): array` — tableau imbriqué de défauts, fusionné récursivement par `get_module_settings()`
- `static get_uninstall_keys(): array` — déclare `options`, `meta` (métas de **post**), `user_meta`, `post_type`, `taxonomy`, `tables` (tables propres, **sans préfixe**, supprimées par `DROP TABLE`) et `cron` (hooks de tâches planifiées) pour le nettoyage à la désinstallation ; chaque clé est optionnelle. Les deux canaux méta vivent dans des tables différentes : une méta utilisateur déclarée sous `meta` n'est jamais supprimée. Les hooks `cron` sont aussi désinscrits par `Deactivator` à la désactivation de l'extension : sans ça, WordPress relançait chaque jour un hook que plus personne n'écoute.

Surcharges optionnelles : `get_admin_css()`, `get_admin_js()`, `get_admin_js_deps()`, `get_admin_js_data()`, `to_form_payload()`, `get_export_extras()` / `import_extras()`, `get_required_capability()`, `on_activate()`, `on_deactivate()`.

### Capacité requise

`static get_required_capability(): string` — capacité exigée pour ouvrir l'écran du module **et** appeler ses endpoints ; `manage_options` par défaut. À surcharger par tout module dont le pouvoir dépasse le site courant : sous **multisite**, `manage_options` est une capacité **par site**, si bien que l'administrateur d'un simple sous-site l'obtient — un gestionnaire de fichiers ou un éditeur SQL lui livrent alors le réseau entier, fichiers et base étant communs. Fichiers et Base de données renvoient donc `is_multisite() ? 'manage_network_options' : 'manage_options'`. `Admin` s'en sert pour la capacité de `add_submenu_page()` (WordPress masque l'entrée) **et** re-teste dans `render_page()` : l'URL de l'onglet reste devinable, c'est là que le refus compte.

### Garde `ABSPATH`

Tout fichier PHP de l'extension porte `defined( 'ABSPATH' ) || exit;` — après la ligne `namespace` s'il y en a une, sinon après le docbloc de tête. Sans elle, une requête directe sur le fichier l'exécute hors de WordPress : `templates/components/sidebar.php` répondait 200 avec une erreur fatale divulguant le chemin absolu du serveur. C'est aussi bloquant pour toute soumission au dépôt WordPress.org.

### Export d'état hors option module

`get_export_extras()` / `import_extras()` déclarent l'état du module rangé **hors** de `lumia_module_{id}` (MenuCreator : les profils sous `lumia_wl_menu_profiles`). Sans ça, l'export de configuration se croit complet alors qu'il laisse cette option de côté. Le bloc atterrit sous `extras.{module_id}` dans le JSON, et l'import le renvoie au module — qui le réassainit lui-même, comme `to_form_payload()` pour les réglages.

### Dépendances JS partagées

`get_admin_js_deps()` renvoie les handles de scripts déjà enregistrés dont le JS du module dépend. À utiliser pour les bibliothèques tierces partagées plutôt que de renvoyer leur URL depuis `get_admin_js()` : deux modules qui font ça produisent deux handles pour le même fichier, que WordPress ne peut pas dédoublonner. `lumia-sortable-js` est enregistré par `Admin::enqueue_assets()` et consommé ainsi par MenuCreator ; le module Média enfile le même handle.

### `to_form_payload()`

`to_form_payload(array $stored): array` convertit les réglages **stockés** vers la forme attendue par `save_settings()`. Il existe pour l'import de configuration, qui doit réutiliser l'assainisseur du module plutôt qu'écrire le JSON directement dans l'option. Le défaut est l'identité — ne le surcharger que si les deux formes diffèrent (Security stocke sous `authentication`/`hardening` ce que le formulaire poste à plat).

### Gabarit de réglages

Le gabarit d'un module vit dans `includes/Modules/{ModuleName}/settings-template.php` (chargé par `templates/admin/module-settings.php`). Variables disponibles : `$module_id`, `$module`, `$instance`, `$module_settings`, `$tab`.

Les champs doivent utiliser `name="lumia_module_settings[field_name]"` et le champ caché `lumia_tab=module_{id}` pour que `Admin::handle_save_settings()` route le POST.

### Données passées au JS

Les valeurs renvoyées par `get_admin_js_data()` sont injectées dans `window.lumiaAdmin` par `Admin::enqueue_module_assets()` : la clé `i18n` est fusionnée dans `window.lumiaAdmin.i18n`, toute autre clé est posée directement en `window.lumiaAdmin[key]` (encodée JSON). Sert à passer n'importe quelle donnée de module au JS (`mcProfiles`, `wpMenu`), pas seulement des traductions.

## Ajouter un module

1. Créer `includes/Modules/MyModule/Module.php` étendant `AbstractModule`
2. Ajouter un `settings-template.php` dans le même dossier
3. Enregistrer via le filtre (aucun fichier du cœur à toucher) :

```php
add_filter( 'lumia_module_definitions', function( array $modules ) {
    $modules['my_module'] = [
        'name'    => __( 'My Module', 'lumia-tools' ),
        'class'   => 'Lumia\\Tools\\Modules\\MyModule\\Module',
        'icon'    => 'package',
        // name, description, menu_label, menu_desc, icon
    ];
    return $modules;
} );
```

4. Ajouter la classe à `Activator::MODULE_CLASSES` (`includes/Core/Activator.php`) — la liste unique partagée par les défauts d'activation et `uninstall.php`

## Formulaires d'administration

Tous les formulaires de réglages postent vers `admin-post.php`. Le nom de l'action détermine le handler :

- `lumia_save_settings` → `Admin::handle_save_settings()`
- `lumia_toggle_module` → `Admin::handle_toggle_module()`
- `lumia_update_modules` → `Admin::handle_update_modules()`
- `lumia_check_updates` → `Admin::handle_check_updates()`
- `lumia_reset_settings` → `Admin::handle_reset_settings()`

Tous vérifient un nonce et la capacité `manage_options`, puis redirigent avec `?lumia_notice=...`.

### Import de réglages

`handle_import_settings()` ne doit jamais écrire le JSON téléversé directement dans les options — ça contourne chaque assainisseur de module (HTML non filtré dans le pied de page white-label, rôles SVG arbitraires, slug de connexion libre). Chaque bloc de module est rejoué via `to_form_payload()` puis `save_settings()`, si bien que le fichier suit exactement le chemin du formulaire. Deux détails comptent : les valeurs `false` sont retirées avant l'appel, parce qu'un formulaire HTML omet ses cases décochées et que certains modules testent `isset()` plutôt que la valeur ; et le bloc `lumia_settings` est fusionné par-dessus l'option existante plutôt que de la remplacer, pour qu'un fichier partiel n'efface pas l'état d'activation des modules qu'il ne mentionne pas. Avant toute lecture : `is_uploaded_file()` sur `$_FILES[…]['tmp_name']` — cette valeur vient du client, et c'est la seule chose qui atteste qu'elle désigne bien un fichier déposé par *cette* requête et non un chemin arbitraire du serveur — puis un plafond `IMPORT_MAX_BYTES` (2 Mio), le fichier étant lu en entier **puis** décodé en JSON, soit deux copies en mémoire.

### Téléchargements

Tout en-tête `Content-Disposition` passe par `Admin::content_disposition( $filename )`. Le nom était injecté tel quel entre guillemets : sous Linux un nom de fichier peut contenir un guillemet — qui referme la valeur et laisse ajouter des paramètres — voire un retour à la ligne, qui termine l'en-tête. L'aide rend les deux paramètres de la RFC 6266 : `filename=` en ASCII assaini pour les clients anciens, et `filename*=UTF-8''…` percent-encodé, qui porte le nom réel sans guillemets à refermer.

## Endpoints AJAX

Les actions AJAX des modules suivent le nommage `wp_ajax_lumia_{module}_{action}` et un motif de garde identique en tête de chaque handler :

```php
check_ajax_referer( 'lumia_admin_nonce', 'nonce' ); // ou wp_verify_nonce() + wp_send_json_error() manuel
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
}
```

Toute donnée de requête passe par `sanitize_text_field( wp_unslash( $_POST[...] ) )` (ou l'assainisseur adapté au type) avant usage ; les réponses passent par `wp_send_json_success()` / `wp_send_json_error()`. Le nonce est créé une fois via `wp_create_nonce( 'lumia_admin_nonce' )` et partagé entre modules (`window.lumiaNotifData.nonce` / `window.lumiaAdmin`).

## Icônes

Les icônes sont des SVG inline rendus via `Admin::render_icon(string $icon, string $size, string $extra_class)`. Les icônes disponibles sont définies dans `Admin::get_icon_paths()` : `layout-dashboard`, `package`, `settings`, `image`, `check-circle`, `info`, `shield`, `bell`, `x`, `log-in`, `folder`, `chevron-down`, `palette`, `menu`, `database`, `eye-off`, `folder-tree`, `history`, `mail`.

**Ne jamais inventer ni dessiner un SVG à la main.** Toutes les icônes viennent de [Lucide](https://lucide.dev) (lucide-static v1.34.0). Pour une icône absente de `get_icon_paths()` ou du JS d'un module, récupérer le fichier officiel tel quel. Ne pas approximer en éditant le path d'une autre icône, ne pas fabriquer de coordonnées : le résultat paraît cassé et s'écarte du reste de l'interface. Ça vaut aussi pour les SVG inline des modules JS (icônes de dossier dans `assets/admin/js/modules/media.js`).

## Notices persistantes

Distinctes des toasts éphémères (`lumiaShowToast`), `Admin::add_persistent_notice(string $id, string $message, string $type, int $user_id = 0)` / `Admin::dismiss_persistent_notice(string $id)` stockent des notices dans la méta utilisateur `lumia_notices` pour qu'elles survivent aux rechargements. `$user_id` vaut l'utilisateur courant par défaut mais peut cibler un utilisateur précis depuis un contexte sans utilisateur (cron qui termine un travail de fond). Rendues dans `window.lumiaPersistentNotices` dans le tiroir de notifications ; fermées côté client via l'endpoint `wp_ajax_lumia_dismiss_notice` (`Admin::handle_dismiss_notice()`).

## Updater

`Core/Updater.php` met la réponse GitHub en cache 12 h **et met les échecs en cache 15 min**. Sans ce cache négatif, un GitHub injoignable ou un quota anonyme épuisé (60 req/h) déclenche un nouvel appel HTTP de 10 s à chaque vérification, c'est-à-dire à presque chaque chargement d'une page d'administration. Le bouton « vérifier les mises à jour » supprime d'abord les deux transients, il contourne donc toujours le cache.

- **Journal des modifications.** L'API est appelée avec `Accept: application/vnd.github.html+json` : GitHub renvoie les notes déjà rendues (`body_html`), pas de parseur Markdown à embarquer. Le HTML passe par `wp_kses_post()` avant d'aller dans l'onglet `changelog` de `plugins_api`. Sur le canal dev, les notes des 10 dernières versions sont gardées en cache et la modale affiche toutes celles postérieures à la version installée (une mise à jour saute souvent plusieurs pré-versions).
- **Numérotation des pré-versions (#73).** `release-dev.yml` vise le **patch suivant** la dernière stable : après `v1.1.0` viennent `1.1.1-dev.1`, `1.1.1-dev.2`… Auparavant il repartait de la stable (`1.1.0-dev.N`), or pour SemVer comme pour `version_compare()`, `1.1.0-dev.N` est **plus ancien** que `1.1.0` : l'updater compensait avec des cas particuliers par canal. Désormais `compare_versions()` n'est qu'un `version_compare()`, sans exception.
- **Le canal dev suit aussi les stables.** Il prend la plus haute version entre pré-versions et stables (hors brouillons). En ne regardant que les pré-versions, un site en `1.0.13-dev.19` ne voyait pas la `1.1.0` avant le push suivant sur `dev`.
- **Mise à jour automatique.** La bascule des Réglages écrit directement dans l'option WordPress `auto_update_plugins`, la même que la colonne « Mises à jour auto » de la liste des extensions : une seule source de vérité, rien dans `lumia_settings`, donc ni export ni réinitialisation. Elle est désactivée si `wp_is_auto_update_enabled_for_type( 'plugin' )` est faux ou sans `update_plugins` (multisite : réservé au super admin).
- **Tableau de bord.** `Updater::get_status()` lit **uniquement** le transient : afficher le canal et la version distante ne doit jamais déclencher un appel HTTP de 10 s. Cache vide → pas de badge.
- **Pas d'ETag, pas de token (décision du 2026-09-19, #18).** Une requête conditionnelle qui reçoit un `304` n'épargne le quota GitHub **que si elle est authentifiée** ; en anonyme elle est décomptée comme une autre. Le dépôt restant public, pas de token, donc l'ETag n'apporterait qu'un gain de bande passante : écarté. Le quota reste protégé par le cache 12 h et le cache négatif.

## Outillage de développement

Composer sert **uniquement** au développement : aucune dépendance d'exécution, `vendor/` est ignoré par Git et exclu des ZIP de release (comme `composer.*`, `phpcs.*`, `phpstan*`, `tools/`, `CLAUDE.md`, `docs/`).

```bash
composer install          # PHPCS (WPCS + PHPCompatibilityWP), PHPStan (+ stubs WordPress)
composer lint             # phpcs — composer lint:fix pour phpcbf
composer analyse          # phpstan, niveau 8
composer check            # les deux
```

Pas de PHP sur le poste Windows : passer par l'image Docker, avec `MSYS_NO_PATHCONV=1` pour que Git Bash ne convertisse pas les chemins :

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 check
```

Le workflow `lint.yml` rejoue `php -l`, PHPCS et PHPStan sur chaque PR vers `dev` et `main`.

**Sans baseline.** PHPCS comme PHPStan tournent sans baseline : tout constat bloque la CI. PHPStan a perdu la sienne à la PR #52 et tourne **au niveau 8** depuis la PR #54 (unions `string|false` et nullables traités explicitement). **On s'arrête au niveau 8.** Mesure faite le 2026-09-19 : les niveaux 9 et 10 doublent puis quadruplent le total en sanctionnant `mixed`, or WordPress renvoie `mixed` partout (`get_option()`, `get_post_meta()`, `$_POST`) — ce serait des centaines de casts défensifs sans gain. La baseline PHPCS a été soldée famille par famille (PR #55 à #58, suivi dans l'issue #50) puis supprimée avec le paquet `php-codesniffer-baseline`. Ne jamais en recréer une pour faire passer un constat : corriger, ou poser un `phpcs:ignore` / `@phpstan-ignore` motivé (`-- raison` après le code du sniff). Quand une règle est fausse pour tout un pan du code, l'exclure dans `phpcs.xml.dist` avec un commentaire plutôt que d'annoter chaque ligne (gabarits inclus depuis une méthode d'`Admin`, `FileManager`).

Pièges rencontrés en soldant la baseline : PHPCS ne voit pas un nonce vérifié dans une méthode d'aide (`guard()`, `check_nonce()`), d'où les `phpcs:ignore` / `phpcs:disable` motivés en tête des handlers AJAX ; il ne reconnaît pas le transtypage `(int) ( $_POST['x'] ?? 0 )`, qu'il faut écrire `isset( $_POST['x'] ) ? (int) $_POST['x'] : 0` — et ne pas remplacer ce `(int)` par `absint()` : `absint(-5)` vaut 5, alors que le code traite les identifiants négatifs comme « aucun dossier ». Un `phpcs:ignore` ne couvre que la ligne où il est posé (ou la suivante s'il est seul sur sa ligne) : sur un appel multiligne, le mettre au-dessus de la ligne qui porte la fonction signalée. Le code du sniff dans un `phpcs:ignore` doit être exact : un code erroné (`file_system_read_readfile` au lieu de `file_system_operations_readfile`) n'ignore rien, sans aucun avertissement. Pour retirer un paramètre de hook inutilisé, abaisser `accepted_args` dans l'`add_filter()` correspondant. Les messages d'exception de `FileManager` ne sont pas échappés à la source : ils partent en JSON et le toast les échappe à l'affichage.

Exclusions assumées dans `phpcs.xml.dist` : docblocs (`Squiz.Commenting`), noms de fichiers PSR-4 (`WordPress.Files.FileName`), fins de ligne (Git les normalise). Les gabarits (`templates/`, `settings-template.php`) sont inclus depuis une méthode d'`Admin` : PHPStan ne les analyse pas et PHPCS n'y exige pas de préfixe sur les variables. `tools/phpstan-bootstrap.php` déclare les constantes absentes des stubs (`WPINC`).
