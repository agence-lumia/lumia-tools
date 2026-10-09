# Banc de test Docker (renommage Lümia)

Banc jetable pour vérifier le renommage « Studio Kyne Mini Tools » → « Lümia Tools » :
il installe SKMT à son état d'avant renommage (commit `8d4cd85`) avec des données
réalistes, capture le texte visible de l'administration, puis installe l'extension
renommée pour contrôler la migration et les textes.

Rien ici n'est distribué : les workflows de release excluent `tools/` du zip. Le
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
tools/e2e/run.sh down                  # arrête et supprime les données
```

Codes de sortie : 0 succès, 1 échec, 2 usage. Une commande inconnue affiche l'aide et
sort en 2 : les tâches suivantes y ajoutent leurs commandes dans le `case` final de `run.sh`.

`seed-skmt` refuse de s'exécuter sur un banc où SKMT est déjà installé : faire `down` puis `up`.

- `seed-skmt --minimal` : SKMT actif, Sécurité seul actif, **aucune** option `skmt_module_*`
  (site jamais configuré). Pas de contenu ; la capture `login-url` échoue (404), c'est normal.
- `seed-skmt --encryption-key` : définit `SKMT_ENCRYPTION_KEY` (`e2e-fixed-key`) dans
  `wp-config.php` **avant** le chiffrement des secrets SMTP.
- `capture <dossier> [slug]` : le slug de la page d'administration vaut
  `studio-kyne-mini-tools` par défaut, `lumia-tools` après le renommage.
- `install-lumia` : lit le nom du dossier du plugin dans l'arbre de travail (le fichier
  `*.php` à la racine portant un en-tête `Plugin Name:`), construit le zip avec les exclusions
  de `release-please.yml` et l'installe par `wp plugin install --force --activate`.

## Données semées (`seed-skmt.php`)

Trois processus wp-cli successifs, car SKMT lit ses réglages au démarrage : `modules`
(activation par `Modules::activate()`), `settings` (chaque `save_settings()`), `content`.

| Zone | Contenu |
|---|---|
| `skmt_settings` | `modules` : les 10 modules à `true` ; `global.update_channel = stable` |
| Image Optimizer | `format_mode=webp`, `quality=82`, `max_width/height=1920`, `keep_original=true`, `svg_roles=[administrator,editor]` ; 3 JPEG importés (`Photo E2E A`, `Photo E2E B`, `Avatar E2E`) → métas `_skmt_*` et dossier `uploads/skmt-originals-<jeton>/` ; option `skmt_module_image_optimizer_bulk_state` en cours (`running=true`, `total=12`, `processed=4`, `remaining=8`, `user_id=1`) et événement cron unique `skmt_image_optimizer_cron` d'argument `[5]`, programmé **un an** plus tard pour que ni WP-Cron ni `run_cron_batch()` ne le consomment avant la vérification d'une migration |
| Security | `enable_custom_login_url=true`, `custom_login_url=/connexion-e2e`, `rate_limiting=true`, `rate_limit_attempts=3`, whitelist `192.0.2.10` et `198.51.100.7` |
| Login | `panel_bg_color=#112233`, `logo_width=200`, `btn_bg_color=#ff5500`, `hide_lost_password=true` |
| White Label | `hide_wp_logo=false`, `footer.left_text`, `profile.hide_language=true` ; user meta `skmt_local_avatar` (admin) ; option `skmt_wl_menu_profiles` : un profil actif (rôle `editor`) dont l'item référence `studio-kyne-mini-tools` |
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

## Journaux et bruit connu

- `wp-content/debug.log` (du conteneur `wordpress`) reçoit les erreurs PHP des pages
  capturées (`WP_DEBUG`, `WP_DEBUG_LOG`, sans affichage). Absent = rien n'a été journalisé :
  `docker compose -f tools/e2e/docker-compose.yml exec wordpress cat /var/www/html/wp-content/debug.log`.
- wp-cli n'écrit pas dans `debug.log`. Pendant l'import des images, l'Imagick du conteneur
  `cli` n'a pas le décodeur JPEG : l'Image Optimizer journalise « optimize/imagick a échoué »
  sur la sortie du seed puis retombe sur GD. Les `_skmt_*` sont bien posés.
- Commandes ponctuelles :
  `docker compose -f tools/e2e/docker-compose.yml run --rm cli wp <commande>`.
