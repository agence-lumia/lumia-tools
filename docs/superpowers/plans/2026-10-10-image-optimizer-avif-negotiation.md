# Optimiseur d'images : AVIF par négociation — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** remplacer la conversion « fichier remplacé + URL réécrites » par des AVIF frères (`fichier.ext.avif`) servis par négociation `Accept`, générés en arrière-plan, plus une commande de migration de l'existant.

**Architecture :** le module `ImageOptimizer` est découpé en classes à responsabilité unique (encodeur, état par média, file + verrou, cycle de vie des fichiers, livraison/auto-test, `.htaccess`, médiathèque, bulk, migration WP-CLI) orchestrées par `Module`. La livraison est faite par le serveur (règle nginx du template Dokploy, ou `.htaccess` écrit par le plugin) ; le plugin ne génère rien tant que l'auto-test n'a pas prouvé qu'elle fonctionne. Les tests sont des scripts d'assertion exécutés sur un banc Docker dédié (`tools/e2e-images/`), comme le banc de migration existant.

**Tech Stack :** PHP 8.0+ / WordPress 6.9+ (prod : WP 7.1, PHP 8.5, ImageMagick 7.1.2, libheif 1.23, aom 3.14), Imagick, nginx 1.31, Apache 2.4, OpenLiteSpeed, MariaDB, WP-CLI, Docker, bash + curl.

**Spec :** `docs/superpowers/specs/2026-10-10-image-optimizer-avif-negotiation-design.md` (issue #21, branche `feat/21-avif-negotiation`). Le lire avant toute tâche.

## Global Constraints

- Règles absolues de `CLAUDE.md` : garde `ABSPATH` dans chaque fichier PHP ; tout en anglais (identifiants, commentaires, chaînes UI, `docs/`) ; français seulement dans `languages/lumia-tools-fr_FR.po` ; aucune lettre accentuée française dans `includes/`, `templates/`, `assets/`, `docs/` sauf « Lümia » ; composants du design system uniquement ; icônes Lucide officielles ; jamais de baseline PHPCS/PHPStan ; ne jamais toucher à la version.
- Commits Conventional Commits en anglais, scope `image-optimizer` (ou `e2e`, `docs`, `i18n`), terminés par `Refs #21` et `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- `docker run --rm -v "$PWD:/app" -w /app composer:2 check` sans constat avant chaque commit de code PHP.
- Encodage AVIF : Imagick, `setCompressionQuality(q)` **et** `setImageCompressionQuality(q)`, `heic:speed` = 8 (`balanced`) ou 9 (`fast`), `heic:chroma` = `444`. Jamais `heic:speed` 10.
- Défauts : `quality` 70, `speed` `balanced`, `max_dimension` 2560, `exclude_suffixes` `["-noopt"]`.
- Seuil : AVIF gardé seulement si `avif_bytes <= 0.9 * source_bytes`. Profil ICC > 4096 octets → sRGB, profil non embarqué.
- AVIF servi seulement si `Accept` contient littéralement `image/avif` et que la query ne contient pas `original`. `Vary: Accept` sur toute réponse JPEG/PNG des uploads.
- Budget de vidage : 20 s, borné à `max_execution_time - 5` si non nul ; 3 tentatives max ; verrou `GET_LOCK`.
- Rien n'est écrit en production par ce plan. Mise à jour nginx des sites, release et migration réelle : hors plan, avec accord de l'utilisateur.

## Review Focus

1. **Upload d'une grosse image (4000 px) suivi d'une insertion immédiate dans Bricks** : la page affiche l'image (JPEG puis AVIF), jamais un 404 — test « upload-then-fetch » de la tâche 5.
2. **Média piégé dans la file** (fichier absent, corrompu, animé) : le bulk se termine, le média est `failed`/`skipped`, les suivants sont traités — test de la tâche 5 et de la tâche 8.
3. **Hébergeur sans support** (`mod_headers` absent, nginx sans règle, CDN) : aucune génération, aucun AVIF servi, explication dans l'admin — tests de la tâche 6.
4. **Réimport d'un nom supprimé, régénération des miniatures, recadrage** : jamais d'ancien AVIF servi pour un nouveau contenu — tests de la tâche 4.
5. **Migration interrompue au milieu d'un média** puis relancée : pas de média à moitié migré visible (URL en base toujours valides), reprise propre — test de la tâche 9.

---

## Structure des fichiers

Plugin (`includes/Modules/ImageOptimizer/`) :

| Fichier | Responsabilité |
|---|---|
| `AvifEncoder.php` (créé) | Encoder un fichier JPEG/PNG en `fichier.ext.avif` (Imagick, repli GD), seuil, ICC, temporaire + renommage. Capacités. |
| `ImageProcessor.php` (réduit) | Ne garde que `get_capabilities()`, `is_animated()`, `get_mime_type()`, `filename_to_alt()` ; `optimize()`/`convert()` supprimés. |
| `AvifState.php` (créé) | Lecture/écriture de la meta `_lumia_avif`, transitions de statut, requêtes par statut. |
| `QueueRunner.php` (créé) | Verrou, budget, boucle de vidage, déclencheurs (shutdown + `fastcgi_finish_request`, boucle locale HMAC, cron, CLI). |
| `FileLifecycle.php` (créé) | Mise en file sur génération/mise à jour des métadonnées, suppression des `.avif` frères (`wp_delete_file`), registre des noms, `wp_unique_filename`, progressif, `big_image_size_threshold`. |
| `DeliveryProbe.php` (créé) | Fichiers sonde, auto-test HTTP, détection CDN, résultat persistant, `is_serving()`. |
| `HtaccessWriter.php` (créé) | Bloc borné dans `uploads/.htaccess` : écrire, retirer, détecter Apache/LiteSpeed. |
| `BulkProcessor.php` (réécrit) | Analyse par statut, lancement (mise en `pending`), arrêt, progression. |
| `MediaLibrary.php` (réécrit) | Colonne, panneau, interrupteur, URL/téléchargement de l'original, régénération. |
| `MigrationCommand.php` (créé) | `wp lumia images migrate`. |
| `UrlRewriter.php` (inchangé) | Utilisé par la migration seulement. |
| `Module.php` (modifié) | Réglages, orchestration, enregistrement des sous-objets, désactivation/désinstallation. |
| `settings-template.php` (modifié) | Réglages, onglet Livraison, onglet Bulk. |

Autres : `assets/admin/js/modules/image-optimizer.js`, `assets/admin/css/modules/image-optimizer.css`, `templates/admin/settings.php` (badges), `uninstall.php` (nettoyage fichiers), `docs/modules/image-optimizer.md`, `languages/*`.

Banc (`tools/e2e-images/`, non livré) : `docker-compose.yml`, `run.sh`, `fixtures/` (images générées par script, aucune image client), `lib.sh` (assertions curl), `assert-*.php`, `nginx/` (rendu du template + stubs).

Template (`wp-dokploy-template`) : `template.toml` (mount `nginx.conf`), `CLAUDE.md`.

## Ordre et parallélisme

- **Vague 1** : tâche 1 puis tâche 2 (même agent : le banc rend la configuration nginx modifiée par la tâche 1).
- **Vague 2 (parallèle)** : tâches 3 et 4 (fichiers distincts ; toutes deux testées sur le banc).
- **Vague 3** : tâche 6 (consomme l'encodeur de la tâche 3 pour la sonde).
- **Vague 4** : tâche 5 (consomme 3, 4 et 6).
- **Vague 5 (parallèle)** : tâches 7, 8, 9.
- **Vague 6** : tâche 10.

Chaque tâche d'une même vague touche des fichiers distincts ; les ajouts dans `Module::init()` passent par un appel unique `$this->xxx->register()` par sous-objet pour limiter les conflits de fusion.

---

### Task 1 : règle nginx dans le template Dokploy

**Files :**
- Modify : `/Users/alban/Documents/DEV/wp-dokploy-template/template.toml` (mount `nginx.conf` : `map` au niveau http, nouvelle `location` avant la location générique des images, ligne ≈ 502)
- Modify : `/Users/alban/Documents/DEV/wp-dokploy-template/CLAUDE.md` (section nginx : négociation AVIF, pourquoi `Vary`, pourquoi plus d'`immutable` pour JPEG/PNG des uploads)

**Interfaces :**
- Produces : la configuration exacte de la spec §1 (variables `$lumia_force_original`, `$lumia_avif_suffix`), reprise telle quelle par le banc (tâche 2) via `ci/render-payload.py`.

- [ ] **Step 1 :** dans une branche `feat/avif-negotiation` du dépôt template, ajouter les deux `map` et la `location ~* ^/wp-content/uploads/.+\.(?:jpe?g|png)$` de la spec §1 (antislashs doublés : le mount est une chaîne TOML littérale, cf. CLAUDE.md du template). Vérifier l'ordre face à `location ^~ /wp-content/uploads/bricks/` (préfixe `^~` : gagne toujours, inchangé) et à la location `.php` des uploads (regex plus haut : inchangée).
- [ ] **Step 2 :** rendre et valider : `python3 ci/render-payload.py /tmp/rendered && docker run --rm -v /tmp/rendered/files/nginx.conf:/etc/nginx/conf.d/default.conf:ro nginx:1-alpine nginx -t` (reproduire les stubs de snippets comme la CI `sync-dokploy-template.yml`). Attendu : `syntax is ok`.
- [ ] **Step 3 :** régénérer le payload (`python3 generate_base64.py`) si la convention du dépôt l'exige localement (sinon laisser la CI), documenter dans `CLAUDE.md`, commit `feat(nginx): serve AVIF siblings by Accept negotiation`. **Ne pas pousser** : la PR template sera ouverte avec celle du plugin.

---

### Task 2 : banc Docker `tools/e2e-images/`

**Compléments (spec §9) :** la pile `nginx` inclut aussi le conteneur `cron` du template (`wordpress:cli-2-php8.5`, même volume) pour prouver qu'aucun encodage n'a lieu en CLI (§9.1) ; `lib.sh` ajoute les UA `Storebot-Google` et `Google-Shopping` (§9.12) et une commande `latency <stack> <url> <secondes>` (temps de réponse médian d'une page pendant une durée, pour §9.6).

**Files :**
- Create : `tools/e2e-images/docker-compose.yml`, `tools/e2e-images/run.sh`, `tools/e2e-images/lib.sh`, `tools/e2e-images/fixtures/make-fixtures.php`, `tools/e2e-images/nginx/` (stubs), `tools/e2e-images/README.md`
- Modify : `tools/build/zip-excludes.txt` (vérifier que `tools/` entier est exclu ; sinon ajouter `tools/e2e-images/`)

**Interfaces :**
- Produces :
  - `tools/e2e-images/run.sh up <stack>` avec `<stack>` ∈ `nginx` (template rendu + `wordpress:7-php8.5-fpm-alpine`), `apache` (`wordpress:php8.5-apache` ; image la plus proche disponible), `ols` (OpenLiteSpeed + PHP 8.x) ; `down <stack>` ; `wp <stack> <args…>` ; `install-lumia <stack>` (copie de l'arbre de travail) ; `assert <stack> <script.php>` (exécuté par `wp eval-file`) ; `curl-matrix <stack> <url>`.
  - `lib.sh` : `assert_type <url> <accept> <user-agent> <expected-content-type>` et `assert_vary <url>` (code de sortie ≠ 0 et message en cas d'échec).
  - Fixtures générées (jamais d'image client) : `photo-4000.jpg` (4000×3000, bruit + dégradés), `photo-p3.jpg` (profil Display P3 embarqué), `photo-bigicc.jpg` (profil ICC > 4 Ko), `visual-alpha.png` (transparence), `logo-flat.png` (aplats 300 px), `anim.gif` (2 images), `corrupt.jpg` (en-tête JPEG tronqué).
  - Port HTTP de chaque pile : `nginx` 8091, `apache` 8092, `ols` 8093. Utilisateur admin/admin, mu-plugin d'auth `X-E2E-User` repris du banc existant (ne jamais le livrer).
  - Option `BRICKS_ZIP=<chemin>` : installe et active le thème Bricks s'il est fourni (non versionné, propriétaire) ; sans lui, les tests Bricks sont sautés avec un message.

- [ ] **Step 1 :** écrire `run.sh` et le compose (piles indépendantes, volumes nommés par pile), avec `set -euo pipefail` ; la pile `nginx` monte le `nginx.conf` rendu depuis `${TEMPLATE_DIR:-../../../wp-dokploy-template}` par `ci/render-payload.py`, plus les stubs des trois snippets écrits par `init.sh` (`nginx-servername.conf`, `nginx-security.conf`, `nginx-redirects.conf`).
- [ ] **Step 2 :** vérification de base sans le plugin : `run.sh up nginx && run.sh curl-matrix nginx /wp-content/uploads/<fixture importée>.jpg` → `image/jpeg` pour tous les clients, `Vary: Accept` présent ; puis déposer à la main `fixture.jpg.avif` à côté et vérifier `image/avif` pour Chrome (`Accept: image/avif,image/webp,*/*`), `image/jpeg` pour Outlook (`Accept: */*`), et `image/jpeg` avec `?original`. Idem pile `apache` avec le bloc `.htaccess` de la spec écrit à la main.
- [ ] **Step 3 :** `README.md` : commandes, piles, matrice des clients (en-têtes `Accept`/`User-Agent` exacts utilisés), limites (OLS ≠ LiteSpeed Enterprise). Commit `test(e2e): image delivery bench (nginx, apache, openlitespeed)`.

Matrice des clients (dans `lib.sh`, valeurs exactes) :

| Client | Accept | Attendu si AVIF présent |
|---|---|---|
| Chrome | `image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8` | avif |
| Safari 17 | `image/webp,image/avif,image/jxl,image/heic,image/heic-sequence,video/*;q=0.8,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5` | avif |
| Firefox | `image/avif,image/webp,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5` | avif |
| Outlook Windows | `*/*` | jpeg/png |
| GoogleImageProxy | `*/*` (UA `GoogleImageProxy`) | jpeg/png |
| Googlebot-Image | `image/*` (UA `Googlebot-Image/1.0`) | jpeg/png |
| facebookexternalhit | `*/*` | jpeg/png |
| LinkedInBot | `*/*` | jpeg/png |
| Mailchimp / Brevo | `*/*` | jpeg/png |
| curl | `*/*` | jpeg/png |
| Chrome + `?original` | Chrome | jpeg/png |

---

### Task 3 : `AvifEncoder` (encodage, seuil, ICC) et capacités

**Compléments (spec §9.1, §9.8) — prévalent sur le texte ci-dessous :**
- Signature : `encode( string $source_path, ?callable $can_commit = null ): EncodeResult` ; `$can_commit()` est appelé juste avant le `rename()` final ; s'il renvoie `false`, le temporaire est supprimé et le résultat est `SKIPPED` avec `error = 'stale'` (§9.5).
- Jamais `stripImage()` : `removeImageProfile()` pour `exif`, `xmp`, `iptc` (si `strip_exif`) ; profil ICC > 4096 octets → `profileImage( 'icc', file_get_contents( <plugin>/assets/icc/srgb.icc ) )` puis `removeImageProfile( 'icc' )`. Profil sRGB : `sRGB-v2-micro.icc` du dépôt saucecontrol/Compact-ICC-Profiles (licence CC0 à vérifier et citer dans un `assets/icc/README.md`). `setImageDepth( 8 )` avant l'encodage. CMYK → même conversion (profil embarqué de l'image, sinon `transformImageColorspace( COLORSPACE_SRGB )`), échec → `SKIPPED`.
- GD seulement si l'image n'a pas de profil ou un profil sRGB ; sinon `SKIPPED` (« GD cannot preserve the color profile »).
- MIME décidé par `wp_get_image_mime( $source_path )` (cas HEIC converti), jamais par le MIME de l'attachement.
- Capacités : clé du transient = PHP, GD, `Imagick::getVersion()['versionString']`, `PHP_SAPI` ; nouvelle clé `can_encode_here` (bool) = Imagick encode AVIF **et** décode JPEG/PNG/AVIF dans ce processus.
- Nouvelle classe `JpegMetadata` (`includes/Modules/ImageOptimizer/JpegMetadata.php`) : `public static function strip_app1( string $path ): int /* octets retirés */` — parcours des marqueurs JPEG, retrait des segments APP1 (`Exif\0\0` et XMP), conservation d'APP2 (ICC) et de tout le reste à l'octet près, aucun retrait si l'orientation EXIF ≠ 1, écriture temporaire + `rename`. Assertions : pixels identiques (`compareImages` = 0) ; plus de GPS (`exif_read_data`) ; profil ICC intact ; image orientée 6 inchangée.
- Assertions supplémentaires dans `assert-encoder.php` : PNG 16 bits → AVIF en 8 bits ; `photo-p3.jpg` encodé par GD forcé → `SKIPPED` ; `$can_commit` renvoyant `false` → aucun `.avif` final ni temporaire.

**Files :**
- Create : `includes/Modules/ImageOptimizer/AvifEncoder.php`
- Modify : `includes/Modules/ImageOptimizer/ImageProcessor.php` (supprimer `optimize*`, `convert*`, `get_target_format` ; étendre `get_capabilities()`)
- Test : `tools/e2e-images/assert-encoder.php`

**Interfaces :**
- Produces :
  - `final class AvifEncoder { public function __construct( int $quality, string $speed, bool $strip_exif, ImageProcessor $processor ); public function encode( string $source_path ): EncodeResult; }`
  - `final class EncodeResult { public const DONE = 'done'; public const SKIPPED = 'skipped'; public const FAILED = 'failed'; public function __construct( public string $status, public int $source_bytes, public ?int $avif_bytes, public string $error = '' ) }` (dans `AvifEncoder.php` ou fichier propre `EncodeResult.php`).
  - `encode()` écrit `$source_path . '.avif'` (temporaire `.<nom>.avif.tmp-XXXX` dans le même dossier puis `rename()`), supprime un `.avif` existant si le résultat est `skipped`, ne lève jamais d'exception (tout `\Throwable` → `FAILED` + message).
  - `ImageProcessor::get_capabilities()` renvoie en plus `avif_engine` (`imagick`|`gd`|`''`), `heic_speed` (bool : l'option est honorée), `heic_chroma` (bool). Clé de cache du transient inchangée dans son principe (versions), suffixe de version incrémenté pour invalider l'ancien cache.

- [ ] **Step 1 : test** `assert-encoder.php` (exécuté par `run.sh assert nginx assert-encoder.php`), assertions :
  - q30 et q90 sur `photo-4000.jpg` redimensionnée à 2560 → tailles différentes (`avif_bytes(q30) < avif_bytes(q90)`) — prouve le correctif qualité ;
  - `balanced` vs `fast` → `fast` plus rapide (temps mesuré) ;
  - en-tête `av1C` du fichier produit : `chroma_subsampling_x = 0` (4:4:4) ;
  - `photo-bigicc.jpg` → AVIF sans profil ICC de plus de 4096 octets (`Imagick::getImageProfiles('icc')`), `photo-p3.jpg` → profil P3 conservé ;
  - `logo-flat.png` à q70 → `SKIPPED` si l'AVIF dépasse 90 % du PNG, et aucun `.avif` laissé sur le disque ;
  - `corrupt.jpg` → `FAILED`, message non vide, aucun fichier temporaire restant dans le dossier ;
  - aucun fichier `.avif` partiel visible pendant l'écriture (le nom final n'apparaît qu'après `rename`).
- [ ] **Step 2 :** lancer, constater l'échec (classe absente).
- [ ] **Step 3 :** implémenter. Imagick : `readImage`, profil ICC > 4096 → `transformImageColorspace(\Imagick::COLORSPACE_SRGB)` après `profileImage('icc', <profil sRGB>)` puis `profileImage('icc', null)` ; `stripImage()` si `strip_exif` (en conservant un profil ≤ 4096) ; `setImageFormat('avif')`, les deux appels de qualité, `setOption('heic:speed', '8'|'9')`, `setOption('heic:chroma', '444')`. Si une option lève une exception, réessayer sans elle. Repli GD (`imageavif( $img, $tmp, max( 0, $quality - 5 ), 8|9 )`) seulement si `avif_engine === 'gd'`.
- [ ] **Step 4 :** relancer le test → toutes les assertions passent ; `composer check` propre.
- [ ] **Step 5 : PNG sans perte.** Mesurer sur `visual-alpha.png`, `logo-flat.png` et les tailles WordPress d'un PNG importé : réécriture Imagick `png:compression-level=9`, `png:compression-filter=5`, `png:compression-strategy=1`, chunks non colorimétriques retirés (`png:exclude-chunks=date,time,tEXt,zTXt,iTXt`). Pixels identiques exigés (`Imagick::compareImages( …, METRIC_ABSOLUTEERRORMETRIC )` = 0). Si le gain total dépasse 5 % : ajouter `public function optimize_png( string $path ): int /* octets gagnés, 0 si non réécrit */` à `AvifEncoder` (temporaire + `rename`, jamais réécrit si plus gros), appelé par la tâche 5 avant l'encodage AVIF de chaque PNG, avec son assertion dans `assert-encoder.php`. Sinon : ne rien ajouter, consigner la mesure dans `docs/modules/image-optimizer.md`.
- [ ] **Step 6 :** commit `feat(image-optimizer): AVIF encoder with working quality, speed and 4:4:4`.

---

### Task 4 : état, cycle de vie des fichiers, réglages

**Compléments (spec §9.4, §9.5, §9.7, §9.8) — prévalent sur le texte ci-dessous :**
- `AvifState` passe aux metas scalaires du §9.5 (`_lumia_avif_status`, `_lumia_avif_queued_at`, `_lumia_avif_origin`, `_lumia_avif_gen`, `_lumia_avif` pour le détail par fichier `{bytes, mtime, avif_bytes}`, `_lumia_avif_legacy`). API : `enqueue( int $id, string $origin ): int /* gen */`, `gen( int $id ): int`, `next_pending(): ?int` (plus ancien `queued_at`, requête sur metas scalaires), `fingerprint( string $path ): array{bytes:int,mtime:int}`, `is_fresh( int $id, string $path ): bool`, plus les méthodes déjà prévues.
- Mise en file : drapeau « génération des sous-tailles en cours » (posé sur `intermediate_image_sizes_advanced`, retiré à la fin de `wp_generate_attachment_metadata`) ; `wp_update_attachment_metadata` ignoré pendant ce drapeau ; diff de l'union ancienne/nouvelle liste de fichiers : `.avif` des fichiers disparus supprimés, seuls les fichiers nouveaux ou d'empreinte changée remis à encoder.
- Unicité : `pre_wp_unique_filename_file_list` ajoute des noms virtuels (familles `base(-\d+x\d+|-scaled|-rotated|-e\d+)?.ext` dont un `.avif` existe, et noms du registre) au lieu de filtrer `wp_unique_filename`. Registre alimenté par `delete_attachment` (fichiers du média), une écriture par requête.
- Suffixe : `-noopt(-\d+)?$` (insensible à la casse) sur le nom sans extension.
- `wp_client_side_media_processing_enabled` → `false` ; `image_save_progressive` → `true` seulement si le MIME est `image/jpeg`.
- Module Fichiers : dans `includes/Modules/Files/FileManager.php`, suppression / renommage / déplacement d'un `.jpe?g|png` situé sous `uploads/` appliqués aussi au `.avif` frère (fonction utilitaire statique `FileLifecycle::sibling()`).
- Désactivation : `FileLifecycle::purge_all(): int` supprime tous les `.avif` listés et remet les metas `_lumia_avif*` à zéro (sauf `_lumia_avif_legacy`) ; appelée par `Module::on_deactivate()`. Activation : `FileLifecycle::reconcile( int $limit ): int` (empreintes) appelée en tâche de fond.
- Assertions supplémentaires : `wp media regenerate` (qui supprime par `unlink`) → empreintes divergentes détectées, AVIF supprimés et remis en file ; plusieurs `wp_update_attachment_metadata` pendant un upload → une seule génération utile (`_lumia_avif_gen` final ≤ 2) ; `logo-noopt-1.png` exclu ; réimport de `photo.jpg` alors que seul `photo-300x200.jpg.avif` orphelin existe → autre nom ; suppression d'un JPEG par le module Fichiers → `.avif` frère supprimé ; désactivation → plus aucun `.avif` généré.

**Files :**
- Create : `includes/Modules/ImageOptimizer/AvifState.php`, `includes/Modules/ImageOptimizer/FileLifecycle.php`
- Modify : `includes/Modules/ImageOptimizer/Module.php` (réglages, `init()`), `includes/Modules/ImageOptimizer/settings-template.php` (onglet Réglages)
- Test : `tools/e2e-images/assert-lifecycle.php`

**Interfaces :**
- Consumes : rien des autres tâches (le traitement viendra de la tâche 5).
- Produces :
  - `final class AvifState { public const META = '_lumia_avif'; public const PENDING='pending'; PROCESSING='processing'; DONE='done'; PARTIAL='partial'; SKIPPED='skipped'; FAILED='failed'; EXCLUDED='excluded'; public static function get( int $id ): array; public static function set_status( int $id, string $status, string $error = '' ): void; public static function record_sizes( int $id, array $sizes ): void; public static function begin_attempt( int $id ): int /* attempts après incrément */; public static function next_pending( int $after_id = 0 ): ?int; public static function count_by_status(): array; public static function clear( int $id ): void; }` — `next_pending` renvoie le plus petit ID `pending` (ordre d'arrivée = ID croissant).
  - `final class FileLifecycle { public function __construct( Module $module ); public function register(): void; public static function sibling( string $path ): string /* $path . '.avif' */; public function delete_siblings( int $id ): void; public function is_excluded_by_name( string $file ): bool; public function source_files( int $id ): array /* chemins absolus : fichier principal + tailles, sans original_image */; }`
  - Action publique déclenchée après mise en file : `do_action( 'lumia_image_optimizer_enqueued', int $attachment_id )` (consommée par la tâche 5).
  - Réglages (`Module::get_settings()` / `save_settings()`), clés et défauts de la spec §4 ; migration unique des anciennes clés à la lecture (flag `settings_version` = 2 dans l'option du module) : `quality` → 70, `max_dimension` = max(`max_width`, `max_height`), suppression de `format_mode`, `keep_original`, `max_width`, `max_height`.
- Hooks enregistrés par `FileLifecycle::register()` :
  - `wp_generate_attachment_metadata` (priorité 99) et `wp_update_attachment_metadata` (priorité 99) : si MIME `image/jpeg`|`image/png`, non animé : `delete_siblings`, puis `pending` (ou `excluded` si `is_excluded_by_name` ou déjà `excluded`), puis `do_action( 'lumia_image_optimizer_enqueued', $id )`. Ignorer les appels où les métadonnées n'ont pas changé (comparer `file` + liste des tailles à l'état enregistré) pour ne pas remettre en file à chaque `wp_update_attachment_metadata` de WordPress pendant la création des sous-tailles.
  - `wp_delete_file` (filtre) : supprimer `$file . '.avif'` s'il existe ; enregistrer le chemin relatif du fichier dans le registre des noms **uniquement** si la suppression vient de `wp_delete_attachment` (drapeau posé sur `delete_attachment`, retiré sur `deleted_post`).
  - `wp_unique_filename` (filtre) : si `<dir>/<filename>` est dans le registre ou si `<dir>/<filename>.avif` existe, renvoyer le premier `<base>-<n>.<ext>` libre (n ≥ 2) selon les mêmes critères.
  - `big_image_size_threshold` → `max_dimension` (0 → `false`).
  - `image_save_progressive` → `true` pour `image/jpeg`. Vérifier d'abord que le filtre existe dans `wp-includes/class-wp-image-editor-imagick.php` de l'image du banc ; s'il n'existe pas, ne rien ajouter et consigner l'absence dans `docs/modules/image-optimizer.md`.
  - Registre : option `lumia_module_image_optimizer_tombstones`, autoload `false`, `[chemin relatif => timestamp]`, purge > 1 an, plafond 20 000 (plus anciennes supprimées).

- [ ] **Step 1 : test** `assert-lifecycle.php` :
  - upload de `photo-4000.jpg` par `media_handle_sideload` → `_lumia_avif.status === 'pending'`, `wp_get_attachment_metadata` contient `-scaled` à 2560 (seuil) ;
  - fichier `visual-alpha-noopt.png` → `excluded` ;
  - création manuelle de `x.jpg.avif` à côté d'une taille, puis `wp_delete_attachment( $id, true )` → plus aucun `.avif` de ce média sur le disque, nom enregistré dans le registre ;
  - réimport d'un fichier de même nom → nom final `<base>-2.jpg` (ou suivant) ; un `.avif` orphelin `orphan.jpg.avif` déposé à la main force aussi un autre nom pour `orphan.jpg` ;
  - `wp media regenerate <id> --yes` → `.avif` existants supprimés et statut `pending` ;
  - réglages : option ancienne `{quality: 75, format_mode: 'auto', keep_original: false, max_width: 1920, max_height: 2560}` → lue comme `{quality: 70, max_dimension: 2560}` sans clé retirée, `settings_version` 2.
- [ ] **Step 2 :** lancer, échec attendu.
- [ ] **Step 3 :** implémenter `AvifState`, `FileLifecycle`, les réglages et l'onglet Réglages (qualité 1–100 avec aide « 70 recommandé », vitesse Équilibrée/Rapide, dimension max, suffixes exclus, EXIF, alt, SVG inchangés). Retirer l'ancien hook `optimize_attachment_sizes` de `init()`.
- [ ] **Step 4 :** test vert, `composer check` propre. Commit `feat(image-optimizer): per-media AVIF state, file lifecycle and settings v2`.

---

### Task 5 : `QueueRunner` (vidage en arrière-plan)

**Compléments (spec §9.1, §9.5, §9.6) — prévalent sur le texte ci-dessous :**
- Aucun encodage en CLI : sous `WP_CLI` ou `wp_doing_cron()` en CLI (`PHP_SAPI === 'cli'`), `drain()` ne fait que `trigger()` (boucle locale HTTP). Si `! get_capabilities()['can_encode_here']`, même chose.
- Verrou `lumia_avif_` + `md5( DB_NAME . $wpdb->prefix . home_url() )` ; avant chaque image `SELECT IS_USED_LOCK(nom) = CONNECTION_ID()`, sinon arrêt.
- `shutdown` priorité `PHP_INT_MAX` ; avant `fastcgi_finish_request()` : `session_write_close()` si session active, `ignore_user_abort( true )` ; `set_time_limit( 120 )` avant chaque image.
- `process_one()` : n'encode que les fichiers non frais (`AvifState::is_fresh`) ; passe à l'encodeur `$can_commit = fn() => AvifState::gen( $id ) === $gen_lu_au_debut` ; relit la génération avant d'écrire le statut (génération changée → laisser `pending`).
- Origine `bulk` : pause après chaque image (`usleep`) égale au temps d'encodage mesuré de cette image.
- Assertions supplémentaires : `run.sh wp nginx eval 'do_action("lumia_image_optimizer_drain");'` dans le conteneur `cron` → aucun encodage dans ce processus (horodatage d'écriture du `.avif` postérieur et PID FPM consigné dans `_lumia_avif`), AVIF produit par FPM ; mise en file pendant un encodage (génération changée) → résultat jeté, image retraitée ; `latency nginx / 30` pendant un bulk de 20 images 4000 px → médiane consignée dans le README (pas de seuil bloquant, mais < 2 × la médiane au repos attendue).

**Files :**
- Create : `includes/Modules/ImageOptimizer/QueueRunner.php`
- Modify : `includes/Modules/ImageOptimizer/Module.php` (`init()`, cron, désactivation)
- Test : `tools/e2e-images/assert-queue.php`, `tools/e2e-images/upload-timing.sh`

**Interfaces :**
- Consumes : `AvifEncoder::encode()` (tâche 3), `AvifState`, `FileLifecycle::source_files()`, action `lumia_image_optimizer_enqueued` (tâche 4), `DeliveryProbe::is_serving(): bool` (tâche 6).
- Produces :
  - `final class QueueRunner { public const CRON_HOOK = 'lumia_image_optimizer_drain'; public const AJAX_ACTION = 'lumia_image_optimizer_drain'; public function register(): void; public function drain( int $budget_seconds = 20 ): int /* médias traités */; public function process_one( int $id ): string /* statut final */; public function trigger(): void /* déclenchement asynchrone selon l'environnement */; public function is_running(): bool; }`
  - `drain()` : `if ( ! DeliveryProbe::is_serving() ) return 0;` ; `GET_LOCK( $wpdb->prefix . 'lumia_avif', 0 )` (sortie si 0) ; boucle `next_pending` tant que le budget n'est pas dépassé ; `RELEASE_LOCK` dans un `finally` ; s'il reste des `pending`, `trigger()`.
  - `process_one()` : `begin_attempt` (statut `processing` écrit avant l'encodage) ; pour chaque PNG, `AvifEncoder::optimize_png()` avant l'AVIF si la tâche 3 l'a retenu ; > 3 tentatives → `failed` « too many attempts » ; source absente → `failed` « source file missing » ; encode chaque fichier de `source_files()` ; statut final `done` (toutes tailles AVIF), `partial` (au moins une `skipped`), `skipped` (aucune), `failed` (au moins un `FAILED`, message du premier).
  - `is_running()` : `IS_USED_LOCK(...)` non nul.
  - Déclencheurs : action `lumia_image_optimizer_enqueued` → drapeau ; `shutdown` : si drapeau et `function_exists( 'fastcgi_finish_request' )` → `fastcgi_finish_request(); $this->drain();` ; sinon `trigger()` = `wp_remote_post( admin_url( 'admin-ajax.php' ), [ 'blocking' => false, 'timeout' => 0.01, 'body' => [ 'action' => AJAX_ACTION, 'token' => <jeton> ] ] )` ; jeton = `hash_hmac( 'sha256', (string) floor( time() / 300 ), wp_salt( 'nonce' ) . 'lumia-avif' )`, accepté pour la fenêtre courante et la précédente ; handler `wp_ajax_nopriv_` et `wp_ajax_` qui vérifie le jeton (`hash_equals`) puis `drain()`. En WP-CLI : `drain()` directement à `shutdown`.
  - Cron récurrent `CRON_HOOK` (intervalle `lumia_five_minutes` = 300 s, déclaré par filtre `cron_schedules`), programmé à `init` s'il manque ; déclaré dans `get_uninstall_keys()['cron']` (avec l'ancien `lumia_image_optimizer_cron`, nettoyé aussi).
- [ ] **Step 1 : tests**
  - `assert-queue.php` : trois médias en file dont `corrupt.jpg` et un média dont le fichier a été supprimé → `drain(20)` : les deux piégés `failed` avec message, les autres `done`/`partial` et leurs `.avif` présents ; un média `processing` avec `attempts = 3` → `failed` au passage suivant ; `drain()` lancé pendant qu'un autre processus tient le verrou (`GET_LOCK` pris par une seconde connexion `mysqli`) → renvoie 0 sans rien encoder ; `DeliveryProbe::is_serving()` faux (option forcée) → aucun encodage.
  - `upload-timing.sh` (pile `nginx`, plugin installé, livraison active) : upload de `photo-4000.jpg` par `async-upload.php` (session admin) → temps de réponse < temps d'un upload sans plugin + 1 s ; puis interrogation de l'URL `-scaled.jpg` avec `Accept` Chrome toutes les 500 ms → `image/jpeg` d'abord (jamais 404), puis `image/avif` en moins de 30 s.
- [ ] **Step 2 :** lancer, échec attendu.
- [ ] **Step 3 :** implémenter, brancher dans `Module::init()` (`$this->queue->register()`), corriger `on_deactivate()` (`wp_clear_scheduled_hook` pour les deux hooks).
- [ ] **Step 4 :** tests verts sur `nginx` et `apache` (sur `apache`, `fastcgi_finish_request` absent → chemin boucle locale). `composer check`. Commit `feat(image-optimizer): background AVIF queue with lock and time budget`.

---

### Task 6 : livraison — `DeliveryProbe` et `HtaccessWriter`

**Compléments (spec §9.2, §9.10) — prévalent sur le texte ci-dessous :**
- Bloc `.htaccess` : tout est sous `<IfModule>` ; `RewriteEngine On` suivi de `RewriteOptions Inherit` ; `FilesMatch "\.(?i:jpe?g|png)(\.avif)?$"`. Après écriture, la même requête teste aussi une URL témoin sans AVIF (200 + `image/png`) ; 500, réponse incorrecte ou test non vérifiable → `remove()` immédiat.
- Chaque requête du test est faite deux fois en ordre alterné ; lecture de `cf-cache-status` ; CDN inconnu détecté → `none`.
- États : `incorrect` → `none` tout de suite ; `unreachable` → état précédent conservé jusqu'à 3 échecs consécutifs (compteur dans l'option), sauf juste après une écriture `.htaccess`.
- Test navigateur : l'onglet Delivery lance deux `fetch()` sur la sonde (`Accept: image/avif,*/*` puis `*/*`, `cache: 'no-store'` désactivé volontairement : `cache: 'default'` pour voir le cache intermédiaire) et envoie le résultat à `lumia_image_optimizer_delivery_browser` (nonce + capacité) ; un résultat incorrect force `none`.
- Assertions supplémentaires : `uploads/.htaccess` actif → requête d'un fichier absent sous `uploads/` toujours traitée par WordPress (404 de WordPress, pas d'Apache) grâce à `Inherit` ; `AllowOverride None` sans `FileInfo` simulé → bloc retiré, aucune réponse 500 laissée ; proxy de cache simulé démarré à froid avec une première requête Chrome puis Outlook → `none`.

**Files :**
- Create : `includes/Modules/ImageOptimizer/DeliveryProbe.php`, `includes/Modules/ImageOptimizer/HtaccessWriter.php`
- Modify : `includes/Modules/ImageOptimizer/settings-template.php` (onglet « Delivery »), `includes/Modules/ImageOptimizer/Module.php` (`register()` + activation + `save_settings()` → retest)
- Test : `tools/e2e-images/assert-delivery.sh`

**Interfaces :**
- Produces :
  - `final class DeliveryProbe { public const OPTION = 'lumia_module_image_optimizer_delivery'; public const MODE_NGINX='nginx'; MODE_HTACCESS='htaccess'; MODE_NONE='none'; public function register(): void; public function run(): array /* {mode, cdn, reason, checked_at} */; public static function is_serving(): bool; public static function result(): array; public function ensure_probe_files(): bool; }`
  - Fichiers sonde : `uploads/lumia-tools/probe.png` (PNG 8×8 généré par GD ou Imagick) et `probe.png.avif` (encodé par `AvifEncoder` sans seuil — paramètre interne `$force = true` à ajouter à `encode()` si nécessaire, sinon fichier AVIF minimal embarqué en base64 dans la classe).
  - `run()` : si serveur Apache/LiteSpeed (`HtaccessWriter::is_supported_server()`), `HtaccessWriter::write()` d'abord ; puis les 3 requêtes de la spec §1 (`wp_remote_get`, `timeout` 10, `sslverify` = `apply_filters( 'https_local_ssl_verify', false )`, `?nocache=<time>` pour contourner un cache) ; CDN détecté par en-têtes (`cf-ray`, `server: cloudflare`, `x-cache`, `via`, `x-served-by`, `x-hcdn-request-id`, `x-sucuri-id`, `x-fastly-request-id`) → si CDN **et** que la réponse sans AVIF diffère de l'attendu (`Vary` ignoré) → `none` + suppression de tous les `.avif` générés (via `AvifState`) ; échec de l'auto-test après écriture `.htaccess` → `HtaccessWriter::remove()` + `none`. Mode `nginx` si les 3 requêtes passent sans `.htaccess`.
  - Déclenchement : activation du module, `save_settings()`, bouton « Retest » (AJAX `lumia_image_optimizer_delivery_retest`, nonce + capacité), cron quotidien `lumia_image_optimizer_delivery_check`.
  - `is_serving()` : mode ∈ {nginx, htaccess}.
  - Passage de `none` à servi → `do_action( 'lumia_image_optimizer_enqueued', 0 )` pour relancer la file.
  - `final class HtaccessWriter { public const BEGIN = '# BEGIN Lumia Tools AVIF'; public const END = '# END Lumia Tools AVIF'; public static function is_supported_server(): bool; public function write(): bool; public function remove(): bool; public function block(): string; }` — fichier `uploads/.htaccess`, autres lignes préservées, écriture temporaire + `rename`.
  - Contenu exact du bloc (à valider sur `apache` et `ols`) :
    ```apache
    # BEGIN Lumia Tools AVIF
    <IfModule mod_mime.c>
    AddType image/avif .avif
    </IfModule>
    <IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{QUERY_STRING} !(^|&)original(=|&|$)
    RewriteCond %{HTTP_ACCEPT} image/avif
    RewriteCond %{REQUEST_FILENAME}.avif -f
    RewriteRule ^(.+\.(?:jpe?g|png))$ $1.avif [T=image/avif,E=LUMIA_AVIF:1,L]
    </IfModule>
    <IfModule mod_headers.c>
    <FilesMatch "\.(?i:jpe?g|png|avif)$">
    Header merge Vary Accept
    </FilesMatch>
    </IfModule>
    # END Lumia Tools AVIF
    ```
    Si le `Vary` n'apparaît pas sur la réponse réécrite, utiliser `Header merge Vary Accept env=REDIRECT_LUMIA_AVIF` en complément ; consigner le résultat dans `docs/`.
  - Onglet « Delivery » : mode, CDN, raison, date, bouton Retest, et en mode `none` le snippet nginx de la spec (bloc `<pre>` copiable).
- [ ] **Step 1 : test** `assert-delivery.sh` :
  - `nginx` (template) : après activation → mode `nginx` ; `curl-matrix` sur un média traité conforme au tableau de la tâche 2 ;
  - `nginx` avec un `nginx.conf` **sans** la règle (variante `up nginx-plain`) → mode `none`, aucun `.avif` créé après upload, snippet visible dans l'onglet (grep du HTML de l'écran) ;
  - `apache` → bloc présent dans `uploads/.htaccess` (lignes préexistantes conservées : écrire `# keep-me` avant), mode `htaccess`, matrice conforme ; `a2dismod headers` + reload → retest → mode `none`, bloc retiré ;
  - `ols` → matrice conforme ou, si OLS ne gère pas la règle, mode `none` (jamais d'AVIF servi à un client non-AVIF) — consigner le comportement ;
  - CDN simulé : proxy `nginx` devant la pile, qui met en cache sans `Vary` et ajoute `cf-ray` → mode `none` et `.avif` supprimés ;
  - désactivation du plugin → bloc `.htaccess` retiré.
- [ ] **Step 2 :** lancer, échec attendu.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** tests verts, `composer check`. Commit `feat(image-optimizer): delivery self-test, htaccess rules and CDN guard`.

---

### Task 7 : bulk (`BulkProcessor`) et écran du module

**Compléments (spec §9.4, §9.5) :** « Lancer » met en file avec l'origine `bulk` ; « Arrêter » ne retire que les `pending` d'origine `bulk` ; « Analyser » lance d'abord `FileLifecycle::reconcile()` sur les médias analysés.

**Files :**
- Modify : `includes/Modules/ImageOptimizer/BulkProcessor.php` (réécriture), `settings-template.php` (onglet Bulk), `assets/admin/js/modules/image-optimizer.js` (partie bulk), `Module.php` (`get_admin_js_data()`, AJAX)
- Test : `tools/e2e-images/assert-bulk.php`

**Interfaces :**
- Consumes : `AvifState::count_by_status()`, `QueueRunner::trigger()`, `QueueRunner::is_running()`.
- Produces : AJAX `lumia_image_optimizer_bulk_scan` (→ compteurs par statut + nombre de JPEG/PNG sans état), `lumia_image_optimizer_bulk` (→ met en `pending` les médias JPEG/PNG sans état ou `failed`, sauf `excluded`, par lots SQL de 500, puis `trigger()`), `lumia_image_optimizer_bulk_stop` (→ `pending` remis sans état), `lumia_image_optimizer_bulk_status` (→ compteurs + `is_running` ; relance `trigger()` si `pending` > 0 et non en cours). Option `bulk_state` réduite à `{ user_id, started_at }` ; notice de fin quand `pending` + `processing` = 0 (mécanisme `notify_bulk_complete` conservé). Capacité via `Module::get_required_capability()`.
- [ ] **Step 1 : test** `assert-bulk.php` : 6 médias sans état dont `anim.gif`, `corrupt.jpg`, un fichier supprimé → lancement → `drain` répété jusqu'à épuisement → `pending` = 0, `corrupt` et le supprimé `failed`, le GIF jamais mis en file ; arrêt au milieu → plus aucun `pending` ; relance → les `failed` reprennent (et échouent à nouveau, `attempts` remis à 0 à la relance manuelle).
- [ ] **Step 2 :** échec attendu.
- [ ] **Step 3 :** implémenter (UI : tuiles par statut, barre de progression = (done+partial+skipped+failed+excluded) / total, boutons Lancer/Arrêter, composants du design system).
- [ ] **Step 4 :** test vert, `composer check`. Commit `feat(image-optimizer): queue-based bulk with stop and per-status progress`.

---

### Task 8 : médiathèque (`MediaLibrary`)

**Files :**
- Modify : `includes/Modules/ImageOptimizer/MediaLibrary.php` (réécriture), `assets/admin/js/modules/image-optimizer.js` (partie média), `assets/admin/css/modules/image-optimizer.css`
- Test : `tools/e2e-images/assert-media.php`

**Interfaces :**
- Consumes : `AvifState`, `FileLifecycle::delete_siblings()`, `QueueRunner::trigger()`, `DeliveryProbe::is_serving()`.
- Produces : colonne `lumia_avif` (libellés de la spec §4, poids gagné = `1 - Σavif/Σsource` sur les tailles servies) ; panneau `attachment_fields_to_edit` ; AJAX `lumia_image_optimizer_media_toggle_original` (`excluded` ⇄ `pending`), `lumia_image_optimizer_media_regenerate` (`pending` + `trigger()`), chacun renvoyant le HTML du panneau. « Copier l'URL de l'original » = `wp_get_attachment_url( $id ) . '?original'` via le presse-papiers (composant existant) ; « Télécharger l'original » = lien `<a download>` vers la même URL. Anciennes actions AJAX (`optimize`, `reoptimize`, `convert`, `restore`) supprimées.
- [ ] **Step 1 : test** `assert-media.php` : rendu du panneau pour chaque statut (présence des libellés et des boutons attendus, absence de « Convert ») ; bascule « format d'origine » → `.avif` supprimés et statut `excluded`, re-bascule → `pending` ; `curl` de l'URL avec `?original` sur la pile `nginx` → `image/jpeg`.
- [ ] **Step 2 :** échec attendu.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** test vert, contrôle visuel rapide (capture de la médiathèque en mode grille et liste par le banc), `composer check`. Commit `feat(image-optimizer): media library status, original-format toggle and original URL`.

---

### Task 9 : migration de l'existant (`wp lumia images migrate`)

**Compléments (spec §9.1, §9.3, §9.9) — prévalent sur le texte ci-dessous :**
- Refus si `! get_capabilities()['can_encode_here']`, avec le message exact : `This command needs Imagick with AVIF, JPEG and PNG support in this PHP process. On the Dokploy template, run it in the wordpress container: docker exec -u www-data <project>-wordpress-1 php /tmp/wp-cli.phar lumia images migrate`. Assertion : lancé depuis le conteneur `cron` → code ≠ 0 et ce message.
- Hooks du module suspendus pendant la commande (`FileLifecycle::suspend()` / `resume()`).
- Journal `_lumia_migration` écrit avant toute opération sur les fichiers ; reprise étape par étape ; plus de variable `LUMIA_MIGRATE_DIE_AFTER_FILES` : le test d'interruption tue le processus (`kill -9`) après l'apparition du journal à l'étape « files written ».
- Cas 2 : `link()` (repli `copy()`) de `photo.avif` vers `repli.ext.avif` ; anciens `.avif`/`.webp` **jamais supprimés**, listés dans `_lumia_avif_legacy`. Format : PNG si alpha utilisé ou ≤ 256 couleurs distinctes (`Imagick::getImageColors()`), sinon JPEG q90 progressif. Collisions résolues par famille.
- Cas 1 : `WP_Image_Editor` (Imagick) pour chaque taille enregistrée de `wp_get_registered_image_subsizes()`, une seule écriture de metadata ; échec → état d'avant restauré depuis le journal. URL des tailles héritées sans équivalent → taille nouvelle la plus proche en largeur.
- `original_image` retiré de la metadata si absent du disque.
- Assertions supplémentaires : après migration, chaque **ancienne** URL `.avif`/`.webp` répond toujours 200 ; logo opaque à aplats → repli PNG ; suppression définitive d'un média migré → fichiers hérités supprimés aussi.

**Files :**
- Create : `includes/Modules/ImageOptimizer/MigrationCommand.php`, `tools/e2e-images/seed-legacy.php`, `tools/e2e-images/crawl-check.sh`, `tools/e2e-images/assert-migration.php`
- Modify : `Module.php` (enregistrement `WP_CLI::add_command( 'lumia images', MigrationCommand::class )` si `WP_CLI`)

**Interfaces :**
- Consumes : `UrlRewriter::rewrite( array $pairs ): int`, `AvifState`, `QueueRunner::trigger()`, `wp_create_image_subsizes()`.
- Produces : `wp lumia images migrate [--dry-run] [--ids=<liste>] [--limit=<n>]`, rapport final (traités, ignorés, échecs, URL réécrites, octets avant/après) et code de sortie ≠ 0 s'il y a des échecs. Étapes et ordre exacts : spec §5. Reprise : un média est « migré » quand ses anciennes metas sont absentes ; un média dont les fichiers de repli existent déjà mais dont les metas sont encore anciennes reprend à l'étape base de données (fichiers non réécrits).
- [ ] **Step 1 : seed + tests**
  - `seed-legacy.php` reproduit la prod : médias importés puis convertis comme l'ancien module (fichiers `.avif` seuls, `_wp_attached_file` et `sizes` en `.avif`, MIME `image/avif`, anciennes metas `_lumia_optimized*`), dont un PNG avec alpha, un média avec `original_image` présent sur le disque, un média WebP hérité, des URL dans `post_content`, dans une meta sérialisée façon Bricks (`_bricks_page_content_2`, tableau avec `url`), dans une meta Rank Math (`rank_math_facebook_image`), dans une option, et dans un fichier `uploads/bricks/css/post-1.min.css` (`background-image:url(...avif)`).
  - `assert-migration.php` : `--dry-run` → aucune écriture (somme de contrôle de la base et liste des fichiers inchangées) ; migration → pour chaque média : repli présent (`.png` si alpha), AVIF renommé `repli.ext.avif`, MIME et metas à jour, anciennes metas absentes ; média avec `original_image` → tailles régénérées en JPEG, `pending` ; WebP → repli + `pending` ; toutes les URL de la base et du CSS Bricks pointent vers les replis ; seconde exécution → « 0 traité ».
  - Interruption : `--limit=1` en tuant le processus après l'écriture des fichiers (variable d'environnement de test `LUMIA_MIGRATE_DIE_AFTER_FILES=1`, lue seulement si `WP_DEBUG`) → URL en base toujours valides (anciens `.avif` encore présents à leur ancien nom tant que la base n'est pas réécrite : ne supprimer/renommer les anciens fichiers qu'**après** la réécriture), relance → migration complète.
  - `crawl-check.sh <stack>` : parcourt le sitemap WordPress (`/wp-sitemap.xml`), extrait chaque `src`, `srcset` et `url()` des pages et des CSS liés, et vérifie 200 + type image cohérent avec `Accept` Chrome puis `Accept: */*`.
- [ ] **Step 2 :** échec attendu.
- [ ] **Step 3 :** implémenter. Ordre de sûreté : écrire replis (nouveaux fichiers) → copier (pas renommer) l'AVIF vers `repli.ext.avif` → base de données (metas + `UrlRewriter`) → CSS Bricks → suppression des anciens fichiers `.avif`/`.webp` → vider Cache Enabler (`do_action( 'cache_enabler_clear_complete_cache' )`) et `wp_cache_flush()`.
- [ ] **Step 4 :** tests verts, `crawl-check.sh nginx` vert, `composer check`. Commit `feat(image-optimizer): wp lumia images migrate for legacy AVIF-only media`.

---

### Task 10 : nettoyage, désinstallation, doc, traductions, revue finale

**Compléments (spec §9.11) :** `includes/Core/Deactivator.php` et `uninstall.php` passent à `wp_unschedule_hook()` ; exception `nopriv` + HMAC documentée dans `docs/core.md` (section AJAX) ; banc `tools/e2e` relancé (`up`, `seed-skmt`, `install-lumia`, `assert-migration`) car `FromSkmt` dépend de `BACKUP_DIR`/`LEGACY_BACKUP_DIR` ; doc du module : corriger « AVIF delegate is missing » (vrai en CLI seulement), documenter le principal ≤ 2560 px brut et le cache d'un an après bascule « format d'origine ».

**Files :**
- Modify : `Module.php` (retrait du code mort : `process_*`, sauvegardes, `restore_original`, `reprocess_attachment`, `with_format`, statistiques globales ; garder `BACKUP_DIR` et `LEGACY_BACKUP_DIR` utilisés par `FromSkmt`), `uninstall.php` (appel d'un nettoyage de fichiers propre au module), `templates/admin/settings.php` (badges : AVIF + mode de livraison, plus de WebP), `docs/modules/image-optimizer.md` (réécrit), `docs/README.md` si index, `languages/lumia-tools.pot`, `languages/lumia-tools-fr_FR.po`, `.mo`, `.l10n.php` selon l'outillage
- Test : `tools/e2e-images/assert-uninstall.php`, `composer check`, `composer i18n:check`, tous les tests du banc

**Interfaces :**
- Produces : `public static function Module::uninstall_files(): void` appelé par `uninstall.php` pour ce module (si la méthode existe) : retire le bloc `.htaccess`, `uploads/lumia-tools/`, chaque `.avif` listé dans `_lumia_avif` ; `get_uninstall_keys()` : options (module, `_delivery`, `_tombstones`, `_bulk_state`, anciennes `_stats` et `_backup_token`), meta (`_lumia_avif` + anciennes metas), cron (`lumia_image_optimizer_drain`, `lumia_image_optimizer_delivery_check`, `lumia_image_optimizer_cron`).
- [ ] **Step 1 : test** `assert-uninstall.php` : après désinstallation → aucun `.avif` des médias, pas de bloc `.htaccess`, pas d'option ni de meta du module, aucun événement cron du module.
- [ ] **Step 2 :** implémenter le nettoyage ; doc `docs/modules/image-optimizer.md` réécrite (livraison et auto-test, file et verrou, réglages, migration, pièges : qualité Imagick ignorée par `setImageCompressionQuality`, `heic:speed` 10 refusé, profils ICC, `Vary`, `immutable`, `wp_update_attachment_metadata` appelé plusieurs fois pendant l'upload, `.htaccess` LiteSpeed).
- [ ] **Step 3 :** traductions : `composer i18n:pot`, chaque nouvelle chaîne traduite dans le `.po` (registre de l'interface existante : vouvoiement, « média », « format d'origine »), `i18n:mo`, `i18n:check` vert. Chaînes devenues inutiles retirées du `.po`.
- [ ] **Step 4 :** passer **tous** les tests du banc sur les trois piles + `crawl-check.sh`, puis `tools/build/build-zip.sh` et vérifier que `tools/e2e-images/` n'est pas dans le zip.
- [ ] **Step 5 :** commit `docs(image-optimizer): delivery, queue and migration` (+ `chore(i18n): ...` séparé), puis revue de toute la branche par un agent relecteur indépendant (CLAUDE.md fourni), corrections des points bloquants, PR vers `dev` (plugin) et PR template, `Closes #21`.
