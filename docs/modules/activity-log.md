# Module ActivityLog

`includes/Modules/ActivityLog/` + `assets/admin/js/modules/activity-log.js`. Qui a modifié quoi, et quand (issue #19). Quatre classes :

- `Events` — catalogue : familles (`auth`, `content`, `media`, `users`, `plugins`, `themes`, `options`, `settings`) et libellés d'événements. La table ne stocke que la **clé** d'événement ; le libellé est traduit à l'affichage, donc un journal écrit en français se relit en anglais.
- `Store` — la table `{prefix}lumia_activity_log` : schéma (`dbDelta`), insertion, lecture filtrée, purge.
- `Recorder` — les hooks WordPress et `record()`, qui applique exclusions, IP, auteur et dédoublonnage.
- `Module` — réglages, cron, liste AJAX (`lumia_activity_log_list`), export CSV (`admin_post_lumia_activity_log_export`).

## Stockage

- **Table dédiée**, pas d'option ni de postmeta : un journal grossit sans cesse, se filtre par date et par utilisateur, et se purge par lots. Dates en **UTC** (`created_at`) ; les filtres « du / au » arrivent en date du site et passent par `get_gmt_from_date()`.
- **Nom de table interpolé** dans le SQL : `%i` n'existe dans `wpdb::prepare()` que depuis WP 6.2, l'extension supporte 6.0. Le nom ne vient que de `$wpdb->prefix`.
- **Installation à chaque chargement** (`Store::maybe_install()`, une lecture d'option autochargée `lumia_activity_log_schema`) et pas seulement dans `on_activate()` : un module activé par import de configuration n'appelle pas `on_activate()`. Toute modification du `CREATE TABLE` incrémente `Store::SCHEMA_VERSION`.
- **Table supprimée à la main** (onglet Base de données) : l'option de schéma dit toujours « installée », donc `maybe_install()` ne voit rien et chaque `INSERT` échouait en silence — journal muet jusqu'à une réactivation. `Store::insert()` teste l'existence de la table **seulement quand l'écriture échoue**, la recrée et réessaie une fois : un `SHOW TABLES` à chaque requête coûterait une requête SQL par page pour un cas rarissime.
- **Valeurs tronquées** à la largeur des colonnes avant l'`INSERT` : en mode SQL strict, un titre de 300 caractères faisait échouer l'insertion entière et l'événement était perdu.
- Désactiver le module **garde la table** (l'historique ne doit pas disparaître sur un clic) ; seule la désinstallation la supprime, via les clés `tables` et `cron` de `get_uninstall_keys()` (voir [core.md](../core.md#système-de-modules)).
- Le user_login et le rôle sont **copiés** dans la ligne : un compte supprimé reste lisible et filtrable, et c'est souvent lui qu'on cherche. Le filtre « utilisateur » lit donc la table, pas `wp_users`.

## Pièges de journalisation

- **Auto-draft** : WordPress crée un brouillon automatique à l'ouverture de l'éditeur, puis le premier enregistrement passe par `wp_update_post()`. La création se lit sur `transition_post_status` (`new`/`auto-draft` → autre chose), et `post_updated` ignore tout ce qui part d'un auto-draft. Sans ça, chaque création apparaissait en double (« créé » + « modifié »), et chaque ouverture d'éditeur abandonnée en « créé ».
- **Gutenberg enregistre deux fois** (article, puis boîtes méta) : `post_updated` compare les champs et n'écrit rien si aucun n'a changé.
- **Corbeille** : `wp_trash_post()` et `wp_untrash_post()` passent eux aussi par `wp_update_post()`. `post_updated` écarte donc toute transition depuis/vers `trash`, qui a ses propres événements.
- **Constructeurs de page** : Bricks et Elementor rangent le contenu en postmeta et n'appellent pas `wp_update_post()`. Une page refaite dans Bricks n'apparaissait nulle part ; `added/updated_post_meta` surveille `_bricks_page_content_2`, `_bricks_page_header_2`, `_bricks_page_footer_2` et `_elementor_data` (filtre `lumia_activity_log_content_meta_keys`).
- **Dédoublonnage par requête** (`Recorder::$seen`, clé événement + objet) : Bricks écrit plusieurs métas dans la même requête. Deux exceptions : `login_failed` (chaque tentative compte) et `option_updated` (le hook ne part que si la valeur change, deux lignes sont deux changements réels).
- **Rôle posé avant `user_register`** : `wp_insert_user()` appelle `set_role()` avant de déclencher `user_register`. `set_user_role` ignore donc une liste d'anciens rôles vide, sinon chaque création produisait aussi un « rôle modifié ».
- **Texte alternatif** : l'Image Optimizer le génère au téléversement ; il est ignoré si un `media_added` du même média a déjà été écrit dans la requête.
- **Auteur explicite** pour `wp_login` (l'utilisateur courant n'est pas encore positionné), `wp_logout` (il est déjà remis à zéro — l'identifiant arrive en argument depuis WP 5.5) et `after_password_reset` (la réinitialisation se fait déconnecté).
- **Suppression d'extension ou de thème** : l'en-tête du fichier n'existe plus après coup. Le nom est capturé sur `delete_plugin` / `delete_theme`, relu sur `deleted_*` si la suppression a réussi.
- **Réglages Lümia Tools** (`lumia_settings`, `lumia_module_*`) : on liste les **chemins** modifiés, jamais les valeurs — un module peut stocker un secret (SMTP à venir). Seuls les interrupteurs d'activation des modules, booléens, sont montrés. Écritures sans utilisateur connecté (updater, cron) ignorées. Le premier enregistrement d'un écran passe par `added_option`, pas `updated_option`.
- **Canal** (`details.via`) : web, AJAX, REST, cron, WP-CLI, XML-RPC. Une mise à jour automatique apparaît sans auteur, en « Tâche planifiée » : c'est ce qu'elle est.

## IP et force brute

- IP résolue par `ClientIp` avec la source déclarée dans le module Sécurité (`lumia_module_security` → `authentication.ip_source`), **lue même si Sécurité est inactif** : c'est là que vit la configuration du proxy, et se fier aux en-têtes sans elle laisserait n'importe qui écrire l'IP de son choix dans le journal. Vide sous WP-CLI, qui pose lui-même un `REMOTE_ADDR` factice à `127.0.0.1`.
- Anonymisation optionnelle par `wp_privacy_anonymize_ip()` (celle des outils de confidentialité du cœur), appliquée à l'écriture.
- **Plafond d'échecs de connexion** : 10 par IP et par heure (transient `_lumia_al_fail_{md5(ip)}`), la dixième ligne portant `capped`. Sans lui, une attaque de dix mille essais remplissait le plafond de lignes en une nuit et la purge par volume effaçait tout l'historique utile.
- L'exclusion par rôle ne s'applique pas aux échecs de connexion : exclure les administrateurs ne doit pas masquer les attaques contre eux.

## Réglages

Le formulaire poste les familles et rôles **suivis** (cases cochées) ; le stockage garde les **exclusions** (`excluded_groups`, `excluded_roles`). Une famille ajoutée par une version ultérieure est ainsi suivie d'office au lieu d'arriver exclue. `to_form_payload()` fait la conversion inverse pour l'import de configuration.

## Purge

Cron quotidien `lumia_activity_log_purge`, programmé dans `init()` s'il manque (même raison que la table), désinscrit par `on_deactivate()` et par la désactivation de l'extension. Par âge (`retention_days`, 90 par défaut) puis par volume (`max_rows`, 10 000). Suppression par lots de 5 000 (`DELETE … LIMIT`) pour ne pas verrouiller la table ; le seuil de volume se lit d'abord (`ORDER BY id DESC LIMIT 1 OFFSET n`) parce que MySQL refuse `LIMIT` dans une sous-requête sur la table modifiée.

## Export CSV

Manuel, depuis la liste, avec les filtres appliqués — rien ne s'archive automatiquement sur le serveur (décision de l'issue #19 : pas d'IP qui s'accumulent sur disque).

- Pagination **par clé** (`before_id`) et non par `OFFSET` : des lignes qui arrivent pendant l'export décaleraient les pages.
- **Injection de formule** : toute cellule commençant par `=`, `+`, `-`, `@`, tabulation ou retour chariot est préfixée d'une apostrophe. Titres, identifiants de connexion tentés et e-mails sont saisis par n'importe qui, et Excel exécute `=…` à l'ouverture.
- BOM UTF-8 (sinon Excel lit en Windows-1252) ; séparateur `;` ; échappement vide passé explicitement à `fputcsv()` — le défaut `\` est déprécié depuis PHP 8.4 et l'avertissement partait dans le fichier.
- Téléchargement par un formulaire POST éphémère plutôt que `fetch()`, qui obligerait à garder le fichier entier en mémoire dans un Blob.

## Interface

Liste et réglages partagent le formulaire du module : les filtres n'ont **pas d'attribut `name`** (ils ne partent pas avec l'enregistrement), et Entrée est interceptée dans **tous** les champs de filtre (recherche et dates), sinon elle soumettait les réglages : page rechargée, filtres perdus, et un faux « Réglages modifiés » au journal. Seule la dernière requête de liste s'affiche (compteur `state.request`) : une frappe rapide en lance plusieurs, qui peuvent répondre dans le désordre. Le détail d'un événement est mis en forme côté serveur (`Module::detail_lines()`, paires libellé/valeur) et affiché tel quel dans une modale nommée.
