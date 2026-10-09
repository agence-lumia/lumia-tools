# Lümia Tools

Plugin WordPress modulaire, léger et performant pour optimiser et améliorer votre site.

## Fonctionnalités principales

- Architecture modulaire, modules activables à la demande
- Interface admin custom moderne et rapide
- Mises à jour via GitHub (canal Stable et Dev)
- Modules : Image Optimizer, Security, White Label, Menu Creator, Login, Files, Database, Médias

## Installation

1. Téléchargez le ZIP depuis les releases GitHub.
2. Uploadez le ZIP dans Extensions > Ajouter > Téléverser.
3. Activez le plugin.
4. Ouvrez le menu LUMIA dans l'admin.

## Mise à jour

Dans Réglages > Mises à jour GitHub :

- Choisissez le canal Stable (main) ou Dev (pre-release).
- Cliquez sur Vérifier les mises à jour pour forcer un check.

## Modules

### Image Optimizer

- Conversion AVIF/WebP (auto)
- Qualité configurable
- Redimensionnement
- Suppression EXIF
- Alt text auto
- Optimisation en masse
- Upload SVG sécurisé (assainissement liste blanche, autorisé par rôle)

### Security

- Limitation des tentatives de connexion (rate limiting)
- Origine de l'IP configurable : connexion directe (défaut), Cloudflare ou reverse proxy.
  Les en-têtes de proxy sont envoyés par le client : ne les activer que si le site est
  réellement derrière ce proxy, sinon le blocage se contourne en changeant d'en-tête.
- URL de connexion personnalisée.
  En cas d'oubli du slug, poser `define( 'LUMIA_DISABLE_LOGIN_URL', true );` dans
  `wp-config.php` réactive `wp-login.php`.
- Durcissement (anti-énumération des utilisateurs, endpoint REST users)

### White Label

- Nettoyage de la barre d'admin et du footer
- Épuration de la page de profil (masquage des sections superflues)
- Avatars locaux (upload via médiathèque, prioritaire sur Gravatar)
- Profils de menu (stockage/résolution par utilisateur, rôle ou global)

### Menu Creator

- Réorganisation et masquage des entrées du menu wp-admin (drag & drop)
- Renommage, séparateurs, liens personnalisés, icônes (bibliothèque Lucide)
- Blocage optionnel de l'accès direct aux pages masquées, liens réservés à certains rôles
- Import / export des menus (globalement ou menu par menu)
- Raccourcis clavier : Ctrl/Cmd+S enregistre, Ctrl+Z / Ctrl+Y annulent et rétablissent
- Signalement des entrées obsolètes (slug absent du menu WordPress courant)
- Recherche d'icônes en français (« filtre », « panier », « facture »…), accents ignorés

### Login

- Personnalisation de la page de connexion (logo, couleurs, panneau latéral)
- Masquage optionnel : langue, mot de passe oublié, retour au site

### Files

- Gestionnaire de fichiers (liste, renommer, déplacer, supprimer)
- Upload, zip/dézip, édition et téléchargement, racine `ABSPATH`
- Éditeur de code avec coloration syntaxique et autocomplétion (CodeMirror livré par
  WordPress ; respecte le réglage de coloration du profil utilisateur)

### Database

- Explorateur/éditeur de tables sur `$wpdb` (données, structure)
- Édition, insertion et suppression de lignes typées (avec support NULL)
- Éditeur SQL libre sécurisé (mots-clés interdits, confirmation des écritures, plafond de lignes)
- Export `.sql` typé, historique des requêtes (local au navigateur)

### Médias

- Dossiers virtuels dans la médiathèque : aucun fichier n'est déplacé sur le disque
- Panneau de dossiers partout où WordPress affiche une médiathèque (page Médias en
  grille et en liste, éditeur de blocs, personnalisateur, constructeurs de page)
- Glisser-déposer des médias et des dossiers, appartenance à plusieurs dossiers
- Dossiers d'un média modifiables depuis sa fiche de détails

## Architecture

```
lumia-tools/
├── lumia-tools.php
├── includes/
│   ├── Core/
│   ├── Admin/
│   └── Modules/
├── templates/
│   ├── admin/
│   └── components/
└── assets/
    └── admin/
```

## Développement

### Ajouter un module

1. Créer un dossier dans includes/Modules/MonModule/
2. Implémenter ModuleInterface
3. Enregistrer le module via filtre `lumia_module_definitions`

```php
<?php
namespace Lumia\Tools\Modules\MonModule;

use Lumia\Tools\Core\AbstractModule;

class Module extends AbstractModule {
    public function init(): void {}
    public function get_settings(): array { return ['enabled' => true]; }
    public function save_settings( array $settings ): bool {
        return update_option( 'lumia_module_mon_module', $settings );
    }
    public function get_admin_css(): array {
        return [ LUMIA_ASSETS_URL . 'admin/css/modules/mon-module.css' ];
    }
}
```

### Enregistrement extensible des modules

Le core expose un registre extensible pour éviter de modifier `Core/Modules.php` à chaque nouveau module.

```php
add_filter( 'lumia_module_definitions', function( array $modules ) {
    $modules['mon_module'] = [
        'name'        => __( 'Mon module', 'lumia-tools' ),
        'description' => __( 'Description courte', 'lumia-tools' ),
        'menu_label'  => __( 'Mon module', 'lumia-tools' ),
        'menu_desc'   => __( 'Action principale', 'lumia-tools' ),
        'class'       => 'Lumia\\Tools\\Modules\\MonModule\\Module',
        'icon'        => 'package',
    ];
    return $modules;
} );
```

### Recommandation architecture module (simple -> complexe)

- Module simple :
  - `Module.php` (hooks + settings + vue)
- Module complexe :
  - `Module.php` (orchestration)
  - `Services/` (métier, API, cron, stockage)
  - `Admin/` (UI, handlers, rendering)
  - `Domain/` (DTO, règles, validation)

Chaque module doit sanitiser ses propres settings dans `save_settings()` (le core n'applique plus de sanitization générique).

Points de contrat utiles :

- `get_uninstall_keys()` distingue `options`, `meta` (post meta) et `user_meta`. Une méta
  d'utilisateur déclarée sous `meta` n'est jamais supprimée : ce sont deux tables.
- `get_admin_js_deps()` déclare des handles de scripts déjà enregistrés (bibliothèques
  tierces partagées) plutôt que d'en renvoyer l'URL depuis `get_admin_js()`.
- `to_form_payload()` convertit des réglages stockés vers la forme attendue par
  `save_settings()`. Utilisé par l'import de configuration, qui rejoue ainsi
  l'assainisseur du module au lieu d'écrire le JSON tel quel.
- Les défauts de `get_defaults()` sont fusionnés récursivement : une sous-clé ajoutée
  dans une version ultérieure arrive donc avec sa valeur par défaut chez les
  installations existantes.

## Releases et versioning

- Stable : releases depuis la branche main
- Dev : pre-releases auto depuis la branche dev
- Le ZIP est attaché aux releases (lumia-tools.zip)

## Licence

GPL-2.0+
