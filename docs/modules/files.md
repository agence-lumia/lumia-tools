# Module Files

`includes/Modules/Files/` — `FileManager.php` est un service de système de fichiers autonome enraciné à `ABSPATH` ; `Module.php` est une fine couche AJAX/admin-post (`ajax_list`, `ajax_delete`, `ajax_rename`, `ajax_move`, `ajax_mkdir`, `ajax_zip`, `ajax_extract`, `ajax_get_content`, `ajax_save_content`, `ajax_upload`, plus un handler de téléchargement en flux `admin_post_lumia_files_download`). Capacité requise : `manage_network_options` sous multisite (voir [core.md](../core.md#capacité-requise)).

Toute entrée de chemin passe par `get_post_path()` (`sanitize_text_field` + `rawurldecode`) avant d'atteindre `FileManager` — ne jamais faire confiance à un chemin fourni par le client. `FileManager::save_content()` lève une exception sur une cible en lecture seule ou non inscriptible : ne jamais avaler cette valeur de retour, sinon l'éditeur annonce un enregistrement réussi alors que rien n'a atteint le disque.

`FileManager` appelle PHP directement (`unlink`, `rename`, `rmdir`, `file_put_contents`, `is_writable`) et non `WP_Filesystem` : ce dernier peut passer par FTP/SSH sous un autre utilisateur que PHP, et les droits affichés dans la liste ne correspondraient plus à ce que les opérations peuvent réellement faire. La règle PHPCS `WordPress.WP.AlternativeFunctions` y est donc désactivée pour tout le fichier.

## Éditeur de code

L'éditeur est CodeMirror **livré avec WordPress** (`wp-codemirror`), pas une copie embarquée. `Module::enqueue_code_editor()` tourne sur `admin_enqueue_scripts` à la **priorité 5** (Admin lit `get_admin_js_data()` à 10) et appelle `wp_enqueue_code_editor()` une fois par entrée de `EDITABLE_EXTENSIONS`, en collectant les réglages par extension dans le payload JS `codeEditor`. Le `wp-admin/js/code-editor.js` du cœur câble déjà l'autocomplétion à la frappe pour HTML/CSS/JS/PHP — ne pas la réimplémenter. `wp_enqueue_code_editor()` renvoie `false` quand l'utilisateur a désactivé la coloration syntaxique dans son profil ; le payload est alors vidé et `files.js` retombe sur le textarea nu. Garder `EDITABLE_EXTENSIONS` synchronisé avec `isEditable()` dans `files.js`.

Seul le thème CodeMirror par défaut (clair) est livré avec le cœur : la palette sombre vit dans `files.css` sous `.lumia-files-editor`. Le menu d'autocomplétion est ajouté au `<body>` : il lui faut `ul.CodeMirror-hints` (élément + classe) pour l'emporter sur `ul.CodeMirror-hints { z-index: 101 }` de `wp-admin/css/widgets.css`.

## `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`

`check_file_mods( bool $edition )` ouvre chaque endpoint mutant. Ces constantes ne sont pas un réglage cosmétique : elles disent « personne ne modifie de fichier depuis le navigateur sur ce site », et un gestionnaire de fichiers qui les ignore est plus permissif que l'éditeur du cœur qu'elles désactivent. Elles ne portent pas sur la même chose — `DISALLOW_FILE_EDIT` désigne l'**édition de code** (`ajax_save_content`, `ajax_upload`), `DISALLOW_FILE_MODS` toute modification de fichier (les précédents plus renommer, déplacer, supprimer, `mkdir`, zipper, extraire). La **lecture** (listing, aperçu, téléchargement) reste ouverte dans les deux cas : aucune des deux ne parle de lecture.
