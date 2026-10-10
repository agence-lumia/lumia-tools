# Optimiseur d'images : AVIF par négociation de contenu

Date : 10/10/2026 · Module : `ImageOptimizer` · Dépôts : `agence-lumia/lumia-tools` (plugin) et `wp-dokploy-template` (règle nginx)

> **La section 9 (« Corrections après relecture ») prévaut sur les sections 1 à 8 en cas de contradiction.**

## Objectif

Rendre l'upload instantané et l'optimisation robuste, sans jamais casser une image, où qu'elle soit lue : page Bricks non enregistrée, e-mail WooCommerce, newsletter Brevo/Mailchimp, flux Google Merchant / Meta, aperçu LinkedIn, fichier envoyé à un prestataire, facture PDF.

Critères de réussite :

1. L'upload rend la main sans attendre la conversion.
2. Un client qui n'annonce pas `image/avif` reçoit toujours un JPEG/PNG valide, à la même URL.
3. Aucune URL d'image ne change après l'upload (hors migration ponctuelle de l'existant).
4. Qualité visuelle de l'AVIF ≥ celle du JPEG q82 de WordPress, pour un poids moindre.
5. Le pire cas, sur n'importe quel hébergement, est « images plus lourdes », jamais « images cassées ».

## Constats (mesurés le 10/10/2026)

- **Les 8 sites ne stockent que de l'AVIF** (828 images, aucun JPEG/PNG conservé, `keep_original` faux partout, dossier de sauvegarde vide). Le module remplace le fichier puis réécrit les URL en base.
- **Clients qui n'affichent pas l'AVIF** ([caniemail](https://www.caniemail.com/features/image-avif/)) : Outlook Windows, Orange iOS/Android, SFR iOS, LaPoste.net, GMX, Samsung Email. **LinkedIn** refuse l'AVIF en `og:image`. neo-nat.fr a WooCommerce, Google Listings & Ads (Merchant Center) et Brevo ; maison-aline.com a WooCommerce.
- **Bug** : `ImageProcessor` règle la qualité AVIF par `setImageCompressionQuality()`, ignoré par Imagick pour l'AVIF. Tout l'AVIF produit est en q50 / vitesse 6 quel que soit le réglage (fichiers identiques à l'octet de q30 à q95). SSIMULACRA2 moyen 65,4, soit 13 points sous le JPEG q82 de WordPress.
- **Banc d'essai** (20 originaux réels des sites × 6 tailles WordPress, même image Docker que la prod `wordpress:7-php8.5-fpm-alpine`, ImageMagick 7.1.2-30, libheif 1.23.4, aom 3.14.1, SSIMULACRA2 contre une référence 8 bits) :
  - Imagick AVIF **q70, `heic:speed` 8, `heic:chroma` 444** : SSIM2 80,75 (JPEG q82 : 78,85), **0,71 × le poids du JPEG q82**, aucun couple pire que le JPEG de plus de 5 points.
  - Vitesse 6 → 8 : +2 à 3 % d'octets, CPU ÷ 3. Vitesse 9 : +5 % (photos), **+37 % (PNG)**. Vitesses 6–7 : −1 à −2,5 % pour 2 à 3 × le CPU. `heic:speed=10` est refusé par libheif (exception).
  - 4:4:4 vs 4:2:0 sur les visuels PNG : 10e centile 81,4 contre 74,3, pour +3 % d'octets.
  - GD : 0,79 × le JPEG à qualité égale, pas de 4:4:4, **perd le profil ICC** (5 photos sur 14 en Display P3 / Apple : saturation −13 %).
  - WebP q85 n'atteint pas la qualité du JPEG q82 dans 56 % des cas ; à qualité égale 0,92 × le JPEG.
  - Temps par image (toutes tailles) estimé sur le serveur client (4 vCPU) : actuel ≈ 2,5 s mur, recommandé ≈ 1,3 s. aom prend tous les CPU visibles, rien ne le limite depuis PHP ; une conversion occupe ≈ 2 cœurs en prod.
  - Le profil « Apple Poppy » (60 Ko) est recopié dans chaque taille : une vignette 150 px AVIF pèse 65,6 Ko dont 60 Ko de profil.
  - Petits logos à aplats : tous les formats avec perte plafonnent à 60–75.
- **Infra** : aucun CDN devant les sites ; le conteneur WordPress se joint lui-même par son domaine public (auto-test possible) ; nginx 1.31 connaît `image/avif` ; `request_terminate_timeout` 300 s non appliqué après `fastcgi_finish_request` ; cron système toutes les 300 s (`DISABLE_WP_CRON`). Bricks écrit des URL `.avif` dans ses CSS externes (`uploads/bricks/css`) sur resetyou, coyac, orphee et neo-nat.

## Décisions

| Sujet | Décision |
|---|---|
| Principe | Le JPEG/PNG généré par WordPress reste **le** fichier, à URL fixe. Le plugin crée à côté `fichier.ext.avif` pour chaque taille. Le serveur sert l'AVIF si et seulement si `Accept` contient `image/avif`. |
| Encodeur | Imagick, `setCompressionQuality` **et** `setImageCompressionQuality`, `heic:speed`, `heic:chroma=444`. GD seulement si Imagick n'encode pas l'AVIF (qualité GD = qualité − 5, pas de 4:4:4, profil converti en sRGB avant). |
| Réglages par défaut | Qualité 70 ; vitesse « Équilibrée » (8). Deuxième préréglage « Rapide » (9). Pas de préréglage « max ». |
| WebP | Abandonné (ni généré ni servi). |
| JPEG de repli | Celui de WordPress (q82), jamais ré-encodé. Progressif activé (`image_save_progressive`). |
| PNG de repli | Optimisation **sans perte** (compression zlib maximale, métadonnées non colorimétriques retirées), appliquée seulement si le gain mesuré pendant l'implémentation dépasse 5 % ; sinon abandonnée. Jamais de réduction de palette. |
| Seuil AVIF | Un AVIF n'est gardé que s'il pèse ≤ 90 % de son repli (taille par taille). Sinon cette taille est servie en JPEG/PNG. |
| Gros profils ICC | Profil > 4 Ko : image convertie en sRGB et profil non embarqué dans l'AVIF. Le repli de WordPress n'est pas touché. |
| Dimension max | Un seul réglage « dimension maximale » (2560) appliqué par le filtre `big_image_size_threshold`. Plus de redimensionnement par le plugin. |
| Traitement | File d'attente en meta, vidée en arrière-plan juste après la réponse d'upload, par un seul processus à la fois par site, en tranches de 20 s. |
| Exclusion | Interrupteur « Servir le format d'origine » par média (= restaurer + exclure) ; règle par suffixe de nom (`-noopt` par défaut, liste éditable). Pas d'exclusion par dossier. |
| Échappatoire | `?original` sur une URL d'image force le JPEG/PNG. Boutons « Copier l'URL de l'original » et « Télécharger l'original » sur la fiche du média. |
| Même nom | Un réimport au nom d'un fichier supprimé il y a moins d'un an reçoit un nom unique (`image-2.png`). Un `.avif` orphelin sur le disque compte aussi comme nom occupé. |
| Livraison | nginx (template Dokploy) ; Apache/LiteSpeed : `.htaccess` écrit par le plugin dans `uploads/` ; autre nginx : JPEG seul + snippet affiché ; CDN qui ignore `Vary` : AVIF coupé + alerte. Pas de réécriture en `<picture>`. |
| Génération conditionnelle | Aucun AVIF n'est généré tant que l'auto-test n'a pas confirmé que la livraison fonctionne. |
| Migration de l'existant | Commande WP-CLI pilotée site par site, essai à blanc d'abord. Jamais automatique à la mise à jour. |
| Supprimé | `format_mode`, `keep_original` et le dossier `lumia-originals-*`, l'action « Convertir… », le `optimize()` des JPEG, la réécriture d'URL au fil de l'eau (`UrlRewriter` ne sert plus qu'à la migration). |

## 1. Livraison (négociation)

### nginx — template Dokploy

Dans `nginx.conf` (niveau http, en tête du mount, à côté des autres `map`) :

```nginx
map $args $lumia_force_original {
    default 0;
    "~(^|&)original(=|&|$)" 1;
}
map "$lumia_force_original:$http_accept" $lumia_avif_suffix {
    default "";
    "~^0:.*image/avif" ".avif";
}
```

Location dédiée aux médias JPEG/PNG, placée **avant** la location générique des images :

```nginx
location ~* ^/wp-content/uploads/.+\.(?:jpe?g|png)$ {
    add_header Vary Accept always;
    add_header Cache-Control "public, max-age=31536000" always;
    try_files $uri$lumia_avif_suffix $uri =404;
    access_log off;
}
```

- `Vary: Accept` est posé sur **toute** réponse JPEG/PNG des uploads, AVIF présent ou non : la réponse à cette URL peut changer quand l'AVIF apparaît.
- `immutable` est retiré pour ces fichiers (un F5 revalide par ETag) ; `max-age` d'un an conservé.
- Un `.avif` demandé directement (images héritées) reste servi par la location générique.
- Correspondance stricte : `*/*` ou `image/*` ne déclenchent jamais l'AVIF.
- Piège documenté du template : éditer le mount en place, jamais `sed -i` (inode). Validation `nginx -t` en CI (porte existante).

### Apache / LiteSpeed — `.htaccess` géré par le plugin

Le plugin maintient un bloc borné `# BEGIN Lumia Tools AVIF` / `# END Lumia Tools AVIF` dans `wp-content/uploads/.htaccess` (jamais le `.htaccess` racine ; le reste du fichier est préservé, écriture par temporaire + renommage) :

- `mod_rewrite` : si la query ne contient pas `original`, que `HTTP_ACCEPT` contient `image/avif` et que `%{REQUEST_FILENAME}.avif` existe, réécrire vers `$1.avif` avec le type `image/avif` ;
- `mod_headers` : `Vary: Accept` sur les `.jpe?g|png` et sur la réponse réécrite ;
- `AddType image/avif .avif`.

Écrit seulement si le serveur est détecté comme Apache ou LiteSpeed (`$_SERVER['SERVER_SOFTWARE']`, `apache_get_modules()` si disponible). Retiré à la désactivation et à la désinstallation, et retiré aussitôt si l'auto-test échoue après écriture.

### Auto-test (`DeliveryProbe`)

Fichier de test propre au plugin : `uploads/lumia-tools/probe.png` + `probe.png.avif` (quelques octets, créés à l'activation). Requêtes en boucle locale (`wp_remote_get`, `sslverify` selon l'environnement) :

1. `Accept: image/avif,image/*` → attendu `Content-Type: image/avif` et `Vary` contenant `Accept` ;
2. `Accept: image/png,image/*` → attendu `image/png` ;
3. `?original` avec `Accept: image/avif` → attendu `image/png`.

Détection CDN sur les en-têtes de réponse (`cf-ray`/`server: cloudflare`, `x-cache`, `via`, `x-served-by`, `x-hcdn-*`…). CDN connu pour ignorer `Vary` (Cloudflare hors règle explicite) → mode coupé.

Résultat persistant `{mode: nginx|htaccess|none, cdn: string, reason: string, checked_at}`. Lancé à l'activation, à l'enregistrement des réglages, depuis un bouton « Retester » et une fois par jour (cron). Un résultat `none` :

- l'AVIF n'est **pas** généré (file mise en pause) ;
- les AVIF déjà présents ne sont pas supprimés (le serveur ne les sert pas, ou le CDN les servirait mal : dans le second cas, ils sont supprimés pour que le JPEG soit garanti) ;
- l'admin affiche la raison et la marche à suivre (snippet nginx à transmettre à l'hébergeur, ou règle CDN).

Boucle locale impossible (hébergeur qui bloque) : statut « non vérifiable », traité comme `none`, avec la même aide.

## 2. Encodage (`AvifEncoder`)

Pour chaque fichier de l'attachement — principal (souvent `-scaled`) et chaque taille de `metadata['sizes']`, jamais `original_image` :

- source = le fichier JPEG/PNG produit par WordPress (pas de redimensionnement, pas de recompression) ;
- Imagick : lecture, si profil ICC > 4 Ko → `transformImageColorspace`/profil sRGB puis retrait du profil ; `setImageFormat('avif')`, `setCompressionQuality(q)`, `setImageCompressionQuality(q)`, `setOption('heic:speed', s)`, `setOption('heic:chroma', '444')`, métadonnées EXIF retirées ;
- écriture dans un temporaire du même dossier puis `rename()` vers `fichier.ext.avif` (jamais un AVIF à moitié écrit servi) ;
- garde : AVIF > 90 % du repli → supprimé, taille notée `skipped`.

Ignorés : GIF, images animées, SVG, ICO, et tout MIME hors `image/jpeg` / `image/png`. Un WebP ou AVIF téléversé tel quel est laissé tel quel (pas de repli à créer : l'utilisateur a choisi ce format).

Capacités : `get_capabilities()` existant (test d'encodage réel, cache par versions) étendu à `heic:speed` et `heic:chroma`. Aucune option refusée ne doit faire échouer l'encodage : repli sur l'encodage sans l'option, et l'admin l'indique.

## 3. File d'attente et traitement en arrière-plan

### État par média (meta `_lumia_avif`)

```
status   : pending | processing | done | partial | skipped | failed | excluded
sizes    : { "<fichier relatif>": { "bytes": int, "avif_bytes": int|null } }
error    : string (dernier échec)
attempts : int
updated  : timestamp
```

`excluded` est posé par l'interrupteur ou la règle de suffixe ; `skipped` quand aucune taille ne gagne assez (ou format non pris en charge).

### Mise en file

- `wp_generate_attachment_metadata` (fin de l'upload, priorité tardive) et `wp_update_attachment_metadata` : supprimer les `.avif` existants de l'attachement, puis `pending` (sauf `excluded`). Couvre l'upload, la régénération des miniatures (WP-CLI ou extension), le recadrage dans l'éditeur WordPress et le remplacement de fichier par une extension type Enable Media Replace.
- Le traitement ne réécrit jamais les métadonnées de l'attachement (seulement `_lumia_avif`) : pas de boucle.

### Vidage (`QueueRunner`)

- **Déclencheurs** :
  1. fin d'une requête qui a mis en file (`shutdown`) : `fastcgi_finish_request()` si disponible, puis vidage dans le même processus ;
  2. sinon (Apache mod_php, CGI) : requête en boucle locale non bloquante vers `admin-ajax.php?action=lumia_image_optimizer_drain`, authentifiée par un jeton HMAC à durée courte ;
  3. cron (événement récurrent, 5 min ici) ;
  4. l'écran d'administration du module, s'il voit des `pending` sans traitement en cours depuis plus de 60 s ;
  5. en WP-CLI : traitement immédiat dans le processus.
- **Verrou** : `GET_LOCK('<préfixe>lumia_avif', 0)` MySQL — libéré automatiquement si le processus meurt. Lock non obtenu → sortie immédiate (un autre vide déjà).
- **Budget** : 20 s par passage (borné à `max_execution_time − 5` quand celui-ci est non nul). Les images sont prises une à une dans l'ordre d'arrivée ; la tranche s'arrête au premier dépassement. Restant → nouveau déclenchement (boucle locale ou cron).
- **Robustesse** :
  - `processing` + `attempts++` est écrit **avant** l'encodage : un processus tué laisse une trace, l'image est reprise au passage suivant ;
  - échec ou 3e tentative → `failed` avec message, l'image sort de la file et ne bloque plus les suivantes ;
  - fichier source absent → `failed` (« fichier introuvable »).
- **Charge** : un seul encodage à la fois par site (le verrou), ≈ 2 cœurs. Le bulk utilise la vitesse configurée.

### Bulk

- « Analyser » compte les médias JPEG/PNG par statut.
- « Lancer » passe en `pending` tous les médias sans `_lumia_avif` ou en `failed` (relance), sauf `excluded`.
- La progression est la répartition des statuts (pas de compteur parallèle qui peut dériver). Bouton « Arrêter » : repasse les `pending` à leur état antérieur (sans statut).
- Notice persistante à la fin pour l'utilisateur qui a lancé le bulk (mécanisme existant).

### Suppression et noms

- Filtre `wp_delete_file` : toute suppression d'un fichier par WordPress supprime aussi `fichier.avif` s'il existe (couvre la suppression d'un média et le remplacement des anciennes tailles lors d'une régénération).
- Registre des noms supprimés (option `lumia_module_image_optimizer_tombstones`, chemin relatif → date, purgé au-delà d'un an, plafonné à 20 000 entrées en supprimant les plus anciennes).
- Filtre `wp_unique_filename` : si le nom retenu est dans le registre, ou si `nom.avif` existe sur le disque, chercher le premier suffixe numérique libre au même titre que WordPress.

## 4. Médiathèque et réglages

### Fiche du média et colonne

- Colonne « Format » remplacée par « AVIF » : `AVIF − 63 %`, `En attente`, `En cours`, `Échec` (infobulle avec l'erreur), `Format d'origine`, `Non concerné`.
- Panneau de la fiche :
  - poids repli / AVIF pour le fichier principal et au total ;
  - interrupteur **« Servir le format d'origine »** (on : `.avif` supprimés, `excluded` ; off : `pending`) ;
  - **« Copier l'URL de l'original »** (URL + `?original`) et **« Télécharger l'original »** (lien `download` vers la même URL) ;
  - **« Régénérer l'AVIF »** (`pending`) ;
  - en mode `none` : rappel que l'AVIF n'est pas servi sur ce serveur.
- Composants du design system uniquement ; icônes Lucide.

### Réglages du module

| Clé | Défaut | Note |
|---|---|---|
| `optimize_on_upload` | true | inchangé |
| `quality` | 70 | sens : qualité AVIF Imagick ; migration : toute valeur existante est remplacée par 70 une fois (l'ancienne était sans effet) |
| `speed` | `balanced` | `balanced` (8) / `fast` (9) |
| `max_dimension` | 2560 | remplace `max_width`/`max_height` (migration : max des deux) ; `0` = seuil WordPress désactivé |
| `exclude_suffixes` | `["-noopt"]` | suffixes avant l'extension, insensibles à la casse |
| `strip_exif`, `generate_alt`, `svg_upload`, `svg_roles` | inchangés | `strip_exif` s'applique à l'AVIF |

Retirés : `format_mode`, `keep_original`, `max_width`, `max_height`. Onglet « Livraison » : résultat de l'auto-test, mode, CDN détecté, bouton « Retester », snippet nginx copiable.

La doc `docs/modules/image-optimizer.md` est réécrite (livraison, file, pièges : qualité Imagick, `heic:speed` 10, profils ICC, `Vary`, `immutable`).

## 5. Migration de l'existant (`wp lumia images migrate`)

Concerne les médias portant les anciennes metas (`_lumia_optimized_format` ∈ {avif, webp}, fichier en `.avif`/`.webp`). Options : `--dry-run` (rapport sans écriture), `--ids=`, `--limit=`.

Pour chaque média, dans l'ordre :

1. **Original WordPress présent** (`metadata['original_image']` sur le disque, ex. les 80 JPEG de resetyou) : régénérer `-scaled` et toutes les tailles depuis l'original avec `wp_create_image_subsizes()` → vrais JPEG/PNG à pleine qualité. Les AVIF seront régénérés depuis eux par la file.
2. **Sinon** : décoder chaque fichier `.avif`/`.webp` (principal et tailles) et écrire le repli à côté — PNG si canal alpha utile, sinon JPEG q90 progressif ; nom = même base, extension d'origine si connue (meta héritée), sinon `.png`/`.jpg`. L'AVIF existant est **renommé** en `repli.ext.avif` (gardé tel quel, pas de second encodage avec perte) ; un WebP hérité est supprimé après écriture du repli (l'AVIF sera généré par la file).
3. Mettre à jour `_wp_attached_file`, `post_mime_type`, `guid`, `metadata` (fichier, tailles, MIME, poids), `_lumia_avif` (`done` si un AVIF a été conservé, sinon `pending`), retirer les anciennes metas `_lumia_optimized*`, `_lumia_*bytes*`, `_lumia_backup_file`, `_lumia_fallback_files`.
4. Réécrire les URL ancien → nouveau en base avec `UrlRewriter` (posts, postmeta — dont les données Bricks et Rank Math —, options), par lots.
5. En fin de site : réécrire les URL dans les CSS externes Bricks (`uploads/bricks/css/*.css`, remplacement exact, temporaire + renommage), vider Cache Enabler et le cache objet.

Collision de noms (`repli.ext` déjà présent) : nom unique, et la réécriture suit le nom réel. Écriture des fichiers **avant** toute modification en base ; un échec sur un média le laisse intact et passe au suivant. Rapport final : traités, ignorés, échecs, URL réécrites, octets avant/après.

Les AVIF hérités restent en q50 (originaux perdus) sauf en cas 1 : c'est assumé et documenté.

## 6. Désactivation, désinstallation

- Désactivation : bloc `.htaccess` retiré, événements cron effacés **avec leurs arguments** (corrige le cron jamais nettoyé), **`.avif` générés supprimés** (voir 9.4).
- Désinstallation : `.htaccess`, `uploads/lumia-tools/`, tous les `.avif` listés dans `_lumia_avif`, metas `_lumia_avif`, registre des noms, options du module.

## 7. Déploiement

Ordre sans risque, chaque étape pouvant attendre la suivante :

1. **Template nginx** (PR `wp-dokploy-template`), puis mise à jour du mount nginx des 8 sites (édition en place + `nginx -t` + reload, vérification par `curl` avec et sans `Accept: image/avif`). Sans fichier `.avif` à côté des JPEG, rien ne change pour les visiteurs. **Accord explicite de l'utilisateur avant de toucher la production.**
2. **Release** de Lümia Tools (stable, à l'initiative de l'utilisateur), mise à jour des sites.
3. **Migration** site par site, heure calme : `--dry-run`, migration, puis crawl de contrôle (toutes les URL du sitemap : chaque `img`, `srcset` et `url()` CSS répond 200 avec un type image cohérent ; `Accept` AVIF et non-AVIF). **Accord explicite avant chaque site client.**

## 8. Tests

Pas de tests automatisés du plugin (convention du dépôt) : `composer check`, porte i18n, et un **banc Docker** étendu sous `tools/e2e/` (non livré) :

- **Pile nginx du template** (même `nginx.conf` rendu par `ci/render-payload.py`) + `wordpress:7-php8.5-fpm-alpine` + MariaDB, avec une copie du boilerplate (Bricks inclus) :
  - upload par `async-upload.php` : réponse reçue avant la fin de l'encodage (mesure du temps de réponse avec une image 4000 px), AVIF présents quelques secondes après ;
  - matrice de clients (`Accept` et `User-Agent` réels) : Chrome, Safari, Firefox, Outlook Windows, Apple Mail, GoogleImageProxy, Googlebot-Image, facebookexternalhit, LinkedInBot, Mailchimp, Brevo, curl → type reçu attendu et `Vary: Accept` ;
  - `?original` → toujours le repli ;
  - bulk avec médias piégés (fichier absent, image animée, fichier corrompu) : se termine, les piégés en `failed`/`skipped` ;
  - deux vidages simultanés : un seul encode (verrou) ;
  - suppression d'un média puis réimport du même nom → nom unique, aucun `.avif` orphelin ;
  - régénération des miniatures et recadrage → AVIF régénérés, anciens supprimés ;
  - interrupteur « format d'origine » ;
  - page Bricks (image, galerie, fond CSS) rendue avec AVIF et sans.
- **Apache** (`wordpress:php8.x-apache`) : `.htaccess` écrit, même matrice ; module `headers` désactivé → auto-test en échec, bloc retiré, JPEG seul.
- **LiteSpeed** (OpenLiteSpeed) : même matrice ; si le comportement diffère de LiteSpeed Enterprise (Hostinger), le noter — l'auto-test reste le filet en production.
- **nginx sans règle** : mode `none`, aucune génération, snippet affiché.
- **Migration** : site seedé comme la prod (uploads AVIF seuls + URL en base, CSS Bricks, Rank Math, WooCommerce) et un média avec `original_image` : `--dry-run` n'écrit rien ; après migration, crawl 100 % en 200, contenus pointant vers les replis, AVIF conservés renommés.
- **Test manuel restant** (utilisateur) : « Enregistrer l'image sous » dans Chrome et Safari sur une URL `.jpg` servie en AVIF.

## Hors périmètre

- Réécriture `<picture>` (pourra devenir une option si un hébergement nginx tiers réel l'exige).
- WebP.
- Hébergement multisite, médias déportés (S3, offload).
- Réduction des profils ICC des JPEG générés par WordPress.
- Réencodage en meilleure qualité des 828 AVIF hérités sans original.

## 9. Corrections après relecture (10/10/2026)

Relecture indépendante, vérifiée sur WordPress 7.1.3, nginx 1.31.6 et Apache 2.4.68. Confirmé tel quel : la config nginx (`nginx -t`, `map` composée, `try_files` qui sert `image/avif` avec `Vary` et l'ETag du fichier servi, `?original`/`&original=1`, extensions en majuscules, ordre des locations, `.avif` direct toujours servi). Vérifié en plus : le décodeur ImageIO de macOS (celui de Safari et Mail) lit un AVIF 4:4:4. Le reste est corrigé ci-dessous.

### 9.1 Encodage uniquement dans PHP-FPM (bloquant B1)

L'image `wordpress:cli` (conteneur `cron`, WP-CLI) n'a pas d'Imagick capable d'AVIF/JPEG (0 format) : seul GD y encode, sans 4:4:4 et en perdant l'ICC.

- **Aucun encodage en CLI.** Cron et WP-CLI ne font que **déclencher** le vidage par la boucle locale HTTP (donc dans FPM). Le déclencheur « WP-CLI : traitement immédiat » du §3 est supprimé.
- Capacités : clé du cache = versions PHP, GD, `Imagick::getVersion()['versionString']` **et** `PHP_SAPI` (le cache objet Redis est partagé entre FPM et CLI).
- **Migration** : la commande refuse de tourner si Imagick ne sait pas décoder l'AVIF **et** encoder JPEG/PNG/AVIF dans le processus courant, avec un message qui indique comment la lancer dans le conteneur `wordpress` (phar WP-CLI exécuté par le PHP de l'image FPM). Le banc reproduit le conteneur `cron` pour prouver le refus et le déclenchement par boucle locale.
- `docs/modules/image-optimizer.md` (« AVIF delegate is missing ») est corrigé : vrai pour CLI, faux pour FPM.

### 9.2 `.htaccess` sans risque de 500 (bloquant B2)

- Tout le bloc est enveloppé : `<IfModule mod_rewrite.c>`, `<IfModule mod_headers.c>`, `<IfModule mod_mime.c>`. `RewriteOptions Inherit` après `RewriteEngine On` (sinon les règles WordPress cessent de s'appliquer dans `uploads/`, testé). `FilesMatch` : `\.(?i:jpe?g|png)(\.avif)?$`.
- Juste après l'écriture, **dans la même requête**, le plugin demande aussi une URL témoin sans AVIF (attendu 200 `image/png`). Un 500, une réponse incorrecte **ou** un test non vérifiable → bloc retiré immédiatement. Le bloc n'est jamais laissé en place sans test réussi.

### 9.3 Les anciennes URL restent valides après migration (bloquant B3)

- Cas 2 : l'AVIF hérité n'est **pas renommé** : `photo.jpg.avif` est un **lien physique** (`link()`, repli `copy()`) vers `photo.avif`. Les `.avif` et `.webp` hérités **restent en place** : newsletters déjà envoyées, Google Images, liens externes, CSS Bricks pendant la migration, pages en cache et tables non couvertes par `UrlRewriter` (`termmeta`, `usermeta`, tables tierces) continuent de fonctionner.
- Cas 1 : idem, les `.avif` hérités restent en place.
- Les chemins hérités conservés sont listés dans la meta `_lumia_avif_legacy` et supprimés avec le média (suppression définitive), jamais avant.

### 9.4 Jamais d'AVIF périmé (bloquant B4)

- **Empreinte par fichier** : pour chaque fichier source encodé, `_lumia_avif` mémorise `bytes` et `mtime`. Un AVIF n'est valable que si l'empreinte correspond au fichier source actuel. Toute divergence (régénération par `unlink()` de WP-CLI, remplacement, FTP, module Fichiers) → AVIF supprimé et fichier remis en file.
- **Désactivation : tous les `.avif` générés sont supprimés** (régénérables par le bulk) et `_lumia_avif` est remis à zéro. Le serveur continue de servir des `.avif` sinon, sans plus personne pour les tenir à jour. Une suppression du dossier du plugin sans passer par WordPress n'est pas gérable : documenté.
- **À l'activation**, et à chaque analyse du bulk : réconciliation des empreintes.
- **Famille de fichiers** : un nom est considéré comme occupé si un fichier de la famille `base(-\d+x\d+|-scaled|-rotated|-e\d+)?.ext.avif` existe, en plus du registre des noms. Implémentation par le filtre `pre_wp_unique_filename_file_list` (ajout de noms virtuels à la liste que WordPress compare), pas par une réimplémentation de la recherche de suffixe.
- **Module Fichiers de Lümia** : ses suppressions, renommages et déplacements d'un JPEG/PNG des uploads s'appliquent aussi au `.avif` frère (hook dans `FileManager`).
- Registre des noms alimenté par `delete_attachment` (liste des fichiers du média), écrit une seule fois par requête, `autoload` non.

### 9.5 File d'attente : metas scalaires, génération, empreintes

Remplace la meta sérialisée unique du §3 :

| Meta | Valeur |
|---|---|
| `_lumia_avif_status` | `pending`, `processing`, `done`, `partial`, `skipped`, `failed`, `excluded` |
| `_lumia_avif_queued_at` | timestamp de mise en file (ordre de traitement) |
| `_lumia_avif_origin` | `upload`, `bulk`, `manual`, `reconcile` |
| `_lumia_avif_gen` | compteur de génération, incrémenté à chaque mise en file |
| `_lumia_avif` | détail : par fichier `{bytes, mtime, avif_bytes|null}`, `error`, `attempts`, `updated` |
| `_lumia_avif_legacy` | chemins hérités conservés (migration) |

- Mise en file **seulement** à la fin de `wp_generate_attachment_metadata` et sur `wp_update_attachment_metadata` hors création des sous-tailles (drapeau posé au début de la génération et retiré à la fin), et toujours en comparant l'union ancienne + nouvelle liste de fichiers : les AVIF des fichiers disparus sont supprimés, seuls les fichiers nouveaux ou modifiés (empreinte) sont réencodés. Plus de « supprimer tous les `.avif` » à chaque appel.
- Le vidage relit `_lumia_avif_gen` **avant chaque `rename()`** et avant d'écrire le statut : si la génération a changé pendant l'encodage, le résultat est jeté.
- « Arrêter » le bulk ne retire que les `pending` d'origine `bulk`.
- Suffixe d'exclusion testé sur le nom avec le suffixe d'unicité : `-noopt(-\d+)?$`.

### 9.6 Verrou et durée d'exécution

- Nom du verrou : `lumia_avif_` + `md5( DB_NAME . $wpdb->prefix . home_url() )` (`GET_LOCK` est global au serveur MySQL et limité à 64 caractères ; en mutualisé, plusieurs sites partagent le serveur).
- Avant chaque image : `IS_USED_LOCK(nom) = CONNECTION_ID()`, sinon arrêt (une reconnexion de `$wpdb` perd le verrou).
- Avant `fastcgi_finish_request()` : `session_write_close()`, `ignore_user_abort( true )`. Hook `shutdown` en priorité `PHP_INT_MAX`.
- `set_time_limit()` remis avant chaque image (sur Linux, `max_execution_time` compte le CPU de tout le processus, threads aom compris). Le budget de 20 s reste en temps mur.
- **Charge** : le conteneur `wordpress` est limité à 2 CPU et une conversion en prend environ 2. Pendant un bulk (origine `bulk`), pause après chaque image égale au temps d'encodage de cette image (≈ 50 % du CPU disponible laissé aux pages). Le banc mesure la latence d'une page pendant un bulk.

### 9.7 Traitement côté navigateur de WordPress 7.1

WP 7.1 active par défaut, en HTTPS dans l'éditeur de blocs, le traitement des médias dans le navigateur : les tailles sont produites par le navigateur, le seuil `big_image_size_threshold` est forcé à `false` par le `create` REST, et `finalize` déclenche ensuite la génération. Décision : **désactivé** par `wp_client_side_media_processing_enabled` → `false` quand le module est actif, pour que les replis restent ceux de WordPress (q82, seuil appliqué). La mise en file attend dans tous les cas la fin de la génération.

### 9.8 Progressif, EXIF et profils ICC

- `image_save_progressive` → `true` **seulement pour `image/jpeg`** (sinon les PNG deviennent entrelacés et plus lourds).
- **Confidentialité** : WordPress garde l'EXIF (dont le GPS) dans les sous-tailles et dans un principal non redimensionné (≤ 2560 px, gardé brut). Avec `strip_exif` actif, le plugin retire **sans perte** les segments APP1 (EXIF, XMP) des JPEG servis (principal et tailles, jamais `original_image`), en gardant APP2 (ICC). Pas de retrait si l'orientation EXIF est différente de 1 (évite une image tournée). Fait dans la file, avant l'encodage AVIF. Comportement équivalent à l'ancien module, sans réencodage.
- AVIF : jamais `stripImage()` (retire aussi l'ICC — cause des couleurs P3 perdues dans l'existant). Retrait profil par profil (`exif`, `xmp`, `iptc`).
- Profil > 4 Ko : conversion par `profileImage( 'icc', <sRGB.icc embarqué dans le plugin> )` (lcms présent en prod), puis retrait du profil. `transformImageColorspace` seul ne convertit pas P3 → sRGB.
- `setImageDepth( 8 )` avant l'encodage (un PNG 16 bits donnerait un AVIF 12 bits).
- **GD** : utilisé seulement pour une image sans profil ou en sRGB ; sinon `skipped` (« GD cannot preserve the color profile »).
- JPEG CMYK : converti en sRGB pour l'AVIF (profil CMYK embarqué ou générique), sinon `skipped`.
- **HEIC** : WP 7.1 convertit en JPEG mais laisse `post_mime_type` à `image/heic` : la décision se fait sur le **MIME réel de chaque fichier** (`wp_get_image_mime()`), pas sur le MIME de l'attachement.
- Un principal ≤ 2560 px n'est ni recompressé ni rendu progressif par WordPress : documenté.

### 9.9 Migration : sûreté et reprise

- **Hooks suspendus** pendant la migration (mise en file, suppression des frères, registre des noms).
- **Journal par média** (meta `_lumia_migration` : étape courante + table ancien → nouveau chemin), écrit **avant** toute opération sur les fichiers. Reprise : chaque étape est rejouable à partir du journal ; un média dont le journal indique « fichiers écrits » reprend à l'étape base de données.
- **Cas 1** (original présent) : tailles produites par `WP_Image_Editor` (Imagick) **sans** `wp_create_image_subsizes()` (qui écrit en base au fil de l'eau) ; une seule écriture de la metadata à la fin. En cas d'échec, l'état d'avant (metadata, `_wp_attached_file`, MIME, `guid`) est restauré depuis le journal.
- **Cas 2, format du repli** : aucune meta ne conserve l'extension d'origine (l'ancien `convert()` remplaçait l'extension, `_lumia_optimized_mime` contient le MIME final, le `guid` a été réécrit). Règle : **PNG** si canal alpha utilisé **ou** ≤ 256 couleurs distinctes (logos à aplats) ; sinon **JPEG** q90 progressif. `_lumia_backup_file` est utilisé comme source s'il existe.
- **Collisions** résolues par famille (principal et toutes ses tailles sur la même base), avec la même fonction d'unicité que l'upload (9.4).
- `original_image` retiré de la metadata si le fichier n'existe plus.
- Cas 1, URL de tailles héritées absentes des nouvelles tailles : la réécriture les dirige vers la taille nouvelle la plus proche en largeur ; les fichiers hérités restent de toute façon en place (9.3).

### 9.10 Auto-test et CDN

- Chaque requête de test est faite **deux fois, ordre alterné** (AVIF puis non-AVIF, puis l'inverse) : un cache qui ignore `Vary` est détecté par la réponse, quel que soit l'en-tête. Lecture de `cf-cache-status`.
- CDN détecté mais inconnu → AVIF coupé par défaut.
- Distinction : **livraison incorrecte** (réponse de mauvais type) → couper tout de suite ; **injoignable** (erreur réseau, délai) → garder l'état précédent jusqu'à 3 échecs consécutifs (sauf juste après l'écriture d'un `.htaccess` : 9.2).
- Limite connue : une boucle locale qui contourne le CDN (résolution interne) ne le voit pas. Le test d'un CDN se fait donc aussi depuis le navigateur de l'admin (requêtes `fetch` avec les deux `Accept` sur la sonde, depuis l'onglet Livraison), résultat renvoyé au serveur.

### 9.11 Conventions du dépôt

- Le point d'entrée AJAX `nopriv` + jeton HMAC du vidage déroge à la règle nonce + capacité : exception documentée dans `docs/core.md` (il ne fait que vider la file, n'accepte aucun paramètre que le jeton).
- `BACKUP_DIR` et `LEGACY_BACKUP_DIR` restent (utilisés par `FromSkmt`) ; le banc `tools/e2e` `assert-migration` est relancé.
- `Deactivator` et `uninstall.php` : `wp_unschedule_hook()` au lieu de `wp_clear_scheduled_hook()` (qui n'efface que les événements sans arguments). Changement du cœur partagé, valable pour tous les modules.
- Les libellés d'interface de cette spec sont donnés en français pour la lecture : dans le code ils sont en anglais, la traduction va dans le `.po`.

### 9.12 Autres précisions

- `Cache-Control` : `max-age` d'un an conservé (audit Lighthouse du cache). Conséquence assumée et affichée à côté de l'interrupteur « format d'origine » : un visiteur qui a déjà l'AVIF en cache le garde jusqu'à un an.
- Matrice de test : ajouter `Storebot-Google` et `Google-Shopping` (UA), `Accept` réel ou `*/*`.
- PNG sans perte (si retenu) : temporaire + `rename`, et mise à jour de la seule clé `filesize` de la metadata, hooks suspendus.
- Réglages : marqueur `settings_version` (= 2) pour ne remettre `quality` à 70 qu'une fois.
- `Accept: image/avif;q=0` déclenche quand même l'AVIF : sans conséquence (aucun client réel).
