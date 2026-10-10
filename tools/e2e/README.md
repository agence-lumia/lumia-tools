# Banc de test Docker (renommage Lümia)

Banc jetable pour vérifier le renommage « Studio Kyne Mini Tools » → « Lümia Tools » :
il installe SKMT à son état d'avant renommage (commit `8d4cd85`) avec des données
réalistes, capture le texte visible de l'administration, puis installe l'extension
renommée pour contrôler la migration et les textes.

Rien ici n'est distribué : `tools/build/zip-excludes.txt` exclut `tools/` du zip. Le
mu-plugin `mu-plugins/e2e-auth.php` connecte n'importe quelle requête portant
l'en-tête `X-E2E-User` : **il ne doit jamais sortir de ce banc**. `run.sh` refuse de
construire un zip qui contiendrait `tools/` ou ce fichier.

Prérequis : bash, docker (avec compose), git, rsync, zip, unzip, curl. Pas de PHP sur
l'hôte : tout passe par Docker. Le banc écoute sur `http://localhost:8089`
(`admin` / `admin`, WordPress 6.9 minimum, interface en français).

## Commandes

```bash
tools/e2e/run.sh up                    # démarre le banc (idempotent)
tools/e2e/run.sh seed-skmt             # installe SKMT 8d4cd85 + données
tools/e2e/run.sh capture baseline      # texte de l'admin → tools/e2e/out/baseline/
tools/e2e/run.sh install-lumia         # zip de l'arbre de travail, installé et activé
tools/e2e/run.sh assert-compat         # couche de compatibilité SKMT_* / skmt_* (bench avec Lümia installé)
tools/e2e/run.sh assert-migration      # migration SKMT → Lümia (après seed-skmt puis install-lumia)
tools/e2e/run.sh reactivate-lumia      # désactivation/réactivation : pas de rejeu, réglage modifié conservé
tools/e2e/run.sh assert-after-uninstall  # désinstalle SKMT (son uninstall.php s'exécute), données Lümia intactes
tools/e2e/run.sh assert-partial        # migration interrompue par une erreur injectée, puis reprise
tools/e2e/run.sh assert-interrupted    # activation tuée au milieu d'une étape, trace puis reprise
tools/e2e/run.sh assert-reinstall      # SKMT supprimé sans son uninstall.php, Lümia désinstallé puis réinstallé
tools/e2e/run.sh down                  # arrête et supprime les données
```

Codes de sortie : 0 succès, 1 échec, 2 usage, 130 sur SIGINT (Ctrl+C), 143 sur SIGTERM.
Une commande inconnue affiche l'aide et sort en 2 : les tâches suivantes y ajoutent leurs commandes dans le `case` final de `run.sh`.

`seed-skmt` refuse de s'exécuter sur un banc où SKMT est déjà installé : faire `down` puis `up`.

- `seed-skmt --minimal` : SKMT actif, Sécurité seul actif, **aucune** option `skmt_module_*`
  (site jamais configuré). Pas de contenu ; la capture `login-url` échoue (404), c'est normal.
- `seed-skmt --encryption-key` : définit `SKMT_ENCRYPTION_KEY` (`e2e-fixed-key`) dans
  `wp-config.php` **avant** le chiffrement des secrets SMTP.
- `seed-skmt --folder=<nom>` : installe SKMT dans un autre dossier que
  `studio-kyne-mini-tools/` (par exemple `studio-kyne-mini-tools-main`, comme le laisse une
  archive GitHub). Les commandes retrouvent SKMT par son nom d'extension ; `assert-migration`
  exige qu'il soit réellement désactivé.
- `capture <dossier> [slug]` : le slug de la page d'administration vaut
  `studio-kyne-mini-tools` par défaut, `lumia-tools` après le renommage.
- `install-lumia` : lit le nom du dossier du plugin dans l'arbre de travail (le fichier
  `*.php` à la racine portant un en-tête `Plugin Name:`), construit le zip avec les exclusions
  de `tools/build/zip-excludes.txt` (celles du zip de release) et l'installe par
  `wp plugin install --force --activate`. `LUMIA_ZIP=<chemin> tools/e2e/run.sh install-lumia`
  installe à la place un zip déjà construit (sortie de `tools/build/build-zip.sh`, asset de release).

## Données semées (`seed-skmt.php`)

Trois processus wp-cli successifs, car SKMT lit ses réglages au démarrage : `modules`
(activation par `Modules::activate()`), `settings` (chaque `save_settings()`), `content`.

| Zone | Contenu |
|---|---|
| `skmt_settings` | `modules` : les 10 modules à `true` ; `global.update_channel = stable` |
| Image Optimizer | `format_mode=webp`, `quality=82`, `max_width/height=1920`, `keep_original=true`, `svg_roles=[administrator,editor]` ; 3 JPEG importés (`Photo E2E A`, `Photo E2E B`, `Avatar E2E`) → métas `_skmt_*` et dossier `uploads/skmt-originals-<jeton>/` ; option `skmt_module_image_optimizer_bulk_state` en cours (`running=true`, `total=12`, `processed=4`, `remaining=8`, `user_id=1`) et événement cron unique `skmt_image_optimizer_cron` d'argument `[5]`, programmé **un an** plus tard pour que ni WP-Cron ni `run_cron_batch()` ne le consomment avant la vérification d'une migration |
| Security | `enable_custom_login_url=true`, `custom_login_url=/connexion-e2e`, `rate_limiting=true`, `rate_limit_attempts=3`, whitelist `192.0.2.10` et `198.51.100.7` |
| Login | `panel_bg_color=#112233`, `logo_width=200`, `btn_bg_color=#ff5500`, `hide_lost_password=true` |
| White Label | `hide_wp_logo=false`, `footer.left_text`, `profile.hide_language=true` ; user meta `skmt_local_avatar` (admin) ; option `skmt_wl_menu_profiles` : un profil actif (rôle `editor`) dont l'item référence `studio-kyne-mini-tools`, avec deux enfants masqués : `studio-kyne-mini-tools&tab=module_smtp` et le séparateur de sous-menu `skmt-separator` |
| Media | 2 termes `skmt_media_folder` (`Dossier E2E rouge` avec term meta `skmt_folder_color=#ef4444`, `Dossier E2E sans couleur`), un média rangé dans le premier |
| SMTP | hôte `127.0.0.1:2525` (rien n'y écoute), journal activé ; `skmt_smtp_password` = `Crypto::encrypt('e2e-secret')`, `skmt_smtp_brevo_key` = `Crypto::encrypt('e2e-brevo')` ; un `wp_mail()` en échec → une ligne `failed` dans `skmt_mail_log` |
| Activity Log | `retention_days=60`, `max_rows=5000`, `anonymize_ip=true` ; lignes `event='skmt_settings'`, `object_type='skmt'` produites par les enregistrements ci-dessus |
| Notice | user meta `skmt_notices` (admin), clé `e2e_notice` |

Files, Database, Media et MenuCreator n'ont aucun réglage propre.

## Capture (`capture.php`)

14 fichiers dans `out/<dossier>/` : `dashboard`, `modules`, `settings`, `module_<id>` pour
les 10 modules (l'onglet est `module_<id>`), puis `login-url` (la page `/connexion-e2e/`,
vue d'un visiteur déconnecté). Pour chaque page : `<script>`, `<style>`, `#adminmenumain`,
`#wpadminbar` et `#wpfooter` sont retirés, le texte est extrait une ligne par bloc, les
suites d'espaces ASCII sont réduites à une. **Les espaces insécables (U+00A0, U+202F) sont
conservés** : ils font partie de la typographie française à reprendre à l'identique.
Les attributs lus par l'utilisateur (`placeholder`, `title`, `aria-label`, `alt`,
`data-skmt-tip`, `data-modal-*`) sortent en lignes `[attribut] texte`. Les chaînes que
seul du JavaScript affiche (modales, toasts) n'apparaissent pas.

`out/` est ignoré par git ; la capture de référence n'est pas commitée.

## Couche de compatibilité (`assert-compat`)

Sur un banc où Lümia est installé (`install-lumia`), vérifie `Core\Compat` : constantes
`SKMT_*` (lecture, priorité de `LUMIA_*`), filtres et actions `skmt_*` (ordre, arguments,
dépréciation 2.0.0), constantes SMTP et clé de chiffrement, attribution des tables
(`{prefix}lumia_*` à Lümia Tools, `{prefix}skmt_*` orpheline), puis en HTTP :
`wp-login.php` répond 200 avec `SKMT_DISABLE_LOGIN_URL`, `skmt_custom_login_redirect`
est appliqué et la dépréciation arrive dans `debug.log`. Les snippets de test sont
déposés dans `wp-content/e2e-snippets/` (chargé par le mu-plugin `e2e-snippets.php`,
banc seulement) puis supprimés. Active Sécurité (réglages par défaut) si besoin.

## Migration (`assert-migration` et suivantes)

`install-lumia`, lancé sur un banc où SKMT est installé et Lümia pas encore, écrit d'abord
`out/skmt-snapshot.json` (`assert-migration.php snapshot`) : valeurs brutes et autoload des
options, nombre de méta par clé, termes, lignes des tables (nombre, id max, empreinte),
fichiers d'originaux, événement cron du bulk. L'activation migre ; les commandes comparent
ensuite à cet instantané.

| Commande | Contrôles |
|---|---|
| `assert-migration` | chaque ligne du tableau de migration de la spec (options copiées et originales intactes, slug réécrit, `skmt-separator` devenu `lumia-separator`, secrets rechiffrés et déchiffrables, clé héritée indépendante de `LUMIA_ENCRYPTION_KEY` (phase `legacy-key` : un `LUMIA_ENCRYPTION_KEY` défini dans la requête n'empêche pas de rechiffrer les secrets de SKMT), SKMT réellement désactivé et non chargé, méta/taxonomie renommées, tables renommées sans table `skmt_*` recréée, dossier `lumia-originals-*`, cron du bulk à l'identique, SKMT désactivé, marqueur), puis HTTP (`wp-login.php` en 404, URL personnalisée en 200), déchiffrement dans une nouvelle requête après `wp_cache_flush()`, notice de succès au premier écran d'admin, « Restaurer l'original » par le module (puis ré-optimisation pour laisser le banc tel quel) ; écrit `out/lumia-snapshot.json` |
| `reactivate-lumia` | change `rate_limit_attempts`, désactive/réactive Lümia : valeur gardée, marqueur et données inchangés ; remet la valeur |
| `assert-after-uninstall` | `wp plugin uninstall` de SKMT (exécute `uninstall.php`, contrairement à `wp plugin delete`), puis données Lümia identiques à `lumia-snapshot.json` |
| `assert-partial` | banc fraîchement semé, sans Lümia : un snippet fait échouer l'`UPDATE` de `_skmt_optimized_mime`, puis `install-lumia` ; vérifie l'arrêt (`lumia_migration_error = post_meta` et l'erreur SQL, avertissement wp-cli, SKMT actif, aucun module Lümia, notice affichée en ligne et non avalée par le tiroir de SKMT, événement du bulk déjà déplacé) ; appelle en admin les huit actions AJAX de l'optimiseur de SKMT (snippet temporaire qui accepte tout nonce, pour que sans le gel les gestionnaires de SKMT s'exécutent vraiment) et exige exactement la réponse JSON du gel, aucun changement de méta ni de fichier et aucun `skmt_image_optimizer_cron` ; simule ensuite des écritures de SKMT pendant l'attente (`hold-writes` : URL de connexion, mot de passe SMTP, `_skmt_optimized` sur une image déjà migrée), désactive/réactive Lümia et enchaîne `assert-migration`, qui exige que ces valeurs l'emportent sans doublon. Les textes attendus (notice, réponse JSON, ligne de succès wp-cli) sont lus dans la langue du banc par la phase `l10n` (traduction du `.mo` de l'extension) : le banc tourne en `fr_FR`, un `grep` sur la source anglaise échouerait |
| `assert-interrupted` | banc fraîchement semé, sans Lümia : un snippet termine le processus (`exit`) sur l'`UPDATE` de `skmt_local_avatar`, au milieu de l'étape `user_meta`, comme une erreur fatale ou un délai PHP-FPM ; vérifie que `lumia_migration_error = user_meta` sans `lumia_migration_error_detail` (interrompue, pas en échec), Lümia inactif (WordPress ne l'ajoute à `active_plugins` qu'au retour du hook), SKMT actif, `post_meta` déjà faite ; simule des écritures de SKMT (`hold-writes`), réactive Lümia et enchaîne `assert-migration` (reprise en mode rafraîchissement) |
| `assert-reinstall` | après `assert-migration` : `wp plugin delete` de SKMT (fichiers seuls, ses options `skmt_*` restent), `wp plugin uninstall` de Lümia, puis réinstallation : le marqueur a survécu, `lumia_settings` et `lumia_module_security` sont les valeurs par défaut de Lümia, aucun secret réimporté |

Scénarios : `seed-skmt`, `seed-skmt --minimal`, `seed-skmt --encryption-key` et
`seed-skmt --folder=studio-kyne-mini-tools-main`, chacun depuis `down` + `up`. `assert-compat` se lance sur un banc **non migré** (`down`, `up`,
`install-lumia`) : il supprime les secrets SMTP et suppose l'URL de connexion par défaut.

## Journaux et bruit connu

- `wp-content/debug.log` (du conteneur `wordpress`) reçoit les erreurs PHP des pages
  capturées (`WP_DEBUG`, `WP_DEBUG_LOG`, sans affichage). Absent = rien n'a été journalisé :
  `docker compose -f tools/e2e/docker-compose.yml exec wordpress cat /var/www/html/wp-content/debug.log`.
- wp-cli n'écrit pas dans `debug.log`. Pendant l'import des images, l'Imagick du conteneur
  `cli` n'a pas le décodeur JPEG : l'Image Optimizer journalise « optimize/imagick a échoué »
  sur la sortie du seed puis retombe sur GD. Les `_skmt_*` sont bien posés.
- Commandes ponctuelles :
  `docker compose -f tools/e2e/docker-compose.yml run --rm cli wp <commande>`.
