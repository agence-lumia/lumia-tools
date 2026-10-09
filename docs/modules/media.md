# Module Media

`includes/Modules/Media/Module.php` + `assets/admin/js/modules/media.js`. Dossiers virtuels de médias sous forme de taxonomie `lumia_media_folder` sur `attachment` — aucun fichier n'est jamais déplacé sur le disque.

## Filtrage côté serveur

Le filtrage est **entièrement côté serveur** : la taxonomie est enregistrée avec `'query_var' => 'lumia_folder'`, que `wp_ajax_query_attachments()` autorise pour toute taxonomie d'attachement, si bien que la vue ne fait que `collection.props.set('lumia_folder', id)` et aucune liste d'identifiants ne transite. Deux arguments d'enregistrement sont obligatoires : ce `query_var`, et `'update_count_callback' => '_update_generic_term_count'` (le callback par défaut ne compte que les posts `publish`, et les médias sont `inherit`). Toujours effacer la query var brute avant que `WP_Query::parse_tax_query()` ne la voie, sinon WordPress combine en AND sa propre clause résolue par slug avec la nôtre et le résultat est toujours vide.

## Chargement des assets

Les assets se chargent sur l'action `wp_enqueue_media` — la seule ancre qui couvre tous les contextes (upload.php, éditeur de blocs, Customizer, widgets, éditeur de site, constructeurs frontaux comme Bricks). Un second hook `admin_enqueue_scripts` @100 couvre `upload.php?mode=list`, le seul écran sans `wp.media`.

`media.js` monte le même `FolderPanel` vanilla de deux façons : comme extension de `wp.media.view.AttachmentsBrowser` (grille + toutes les modales), et de façon autonome dans la vue liste. Il patche aussi `wp.media.view.Attachment.Details` **et** `.TwoColumn` pour ajouter les cases de dossier par média — au DOM ready, pas au parsing, parce que `media-grid.js` définit `TwoColumn` et peut être imprimé après nous. Les identifiants de dossier atteignent le modèle Backbone via un filtre `wp_prepare_attachment_for_js` (`lumiaFolders`), si bien qu'ouvrir les détails d'un média ne coûte aucune requête supplémentaire.

## Glisser-déposer

Le glisser-déposer utilise SortableJS **uniquement pour porter le geste** (`forceFallback: true`, `sort: false`) ; les cibles de dépôt sont résolues par hit-testing `document.elementFromPoint`. Ne pas transformer la liste des dossiers en zones de dépôt Sortable — son `emptyInsertThreshold` fait atterrir les items dans le dossier voisin.

## Deux capacités, pas une

`upload_files` (`CAP_USE`) est la capacité de **téléverser**, pas celle d'**organiser la bibliothèque du site** : s'en contenter partout laissait un simple auteur renommer les dossiers d'autrui et en supprimer un avec toute sa descendance, d'un seul appel. Les dossiers sont une taxonomie, la capacité qui décrit ce pouvoir est donc `manage_categories` (`CAP_MANAGE`, éditeur et au-dessus) : `guard()` pour la lecture et le classement, `guard_manage()` pour toute mutation de l'arborescence (créer, renommer, supprimer, déplacer, colorer). `ajax_move_items()` ajoute un contrôle **par pièce jointe** (`current_user_can( 'edit_post', $att_id )`) — la garde d'entrée dit que l'appelant peut utiliser la médiathèque, pas qu'il peut toucher *ce* média-là ; les refus sont comptés et remontés en `refused`, qu'un toast affiche, sinon un déplacement silencieusement partiel se lit comme un bug.

Le drapeau `canManage` (payload `lumiaMedia`) masque côté JS le bouton « + », les menus « … » et le glisser-déposer de dossiers. **Piège** : `wp_localize_script()` convertit toutes les valeurs en **chaînes** — un `false` PHP arrive en `""` et un `true` en `"1"`, si bien qu'un test du genre `cfg.canManage !== false` est toujours vrai et ne masque jamais rien. Comparer aux deux formes (`=== true || === "1"`). Ce n'est de toute façon que cosmétique : la décision appartient à `guard_manage()`, qui ne lit rien de ce que le client envoie.

## Tooltips hors pages LUMIA

`media.js` tourne là où `admin.js` est absent : garder `title` **et** `data-lumia-tip` sur les contrôles en icône seule (voir [design-system.md](../design-system.md#tooltips)).
