# Design system — composants réutilisables

Tous les composants réutilisables sont définis dans `assets/admin/css/components.css` et `assets/admin/js/admin.js`. **Toujours les utiliser ; ne jamais recoder un équivalent maison.**

## Conventions de classes CSS

L'interface d'administration utilise des classes BEM préfixées `lumia-`. Motifs clés : `lumia-section`, `lumia-section__header`, `lumia-option`, `lumia-option__control`, `lumia-toggle`, `lumia-form__group`, `lumia-badge` (modificateurs : `--success`, `--warning`, `--danger`, `--info`, `--inactive`), `lumia-btn` (modificateurs : `--primary`, `--secondary`, `--sm`).

## Design tokens (custom properties CSS)

Définis dans `assets/admin/css/reset.css` :
- Couleurs : `--lumia-accent`, `--lumia-success`, `--lumia-danger`, `--lumia-warning`
- Neutres : `--lumia-n50` … `--lumia-n950`, `--lumia-surface`, `--lumia-border`, `--lumia-text`, `--lumia-text-secondary`
- Rayons : `--lumia-radius`, `--lumia-radius-sm`, `--lumia-radius-xs`
- Ombres : `--lumia-shadow`, `--lumia-shadow-md`, `--lumia-shadow-lg`

## Modales

Deux systèmes complémentaires, tous deux définis dans `components.css` + `admin.js`.

### 1. Modale de confirmation programmatique

Pour les flux confirmer/annuler simples, sans champ de saisie :

```javascript
window.lumiaModal.open({
  title:        "Titre",
  message:      "Message explicatif.",
  confirmLabel: "Confirmer",
  cancelLabel:  "Annuler",
  danger:       true,           // bouton rouge au lieu de bleu
  onConfirm:    function() {},  // callback si l'utilisateur confirme
});
window.lumiaModal.close(); // fermeture programmatique
```

Utilise le singleton `#lumia-modal-overlay` de `templates/admin/layout.php`.

### 2. Modale nommée (HTML persistant)

Pour les modales avec champs de formulaire (inputs, selects, etc.) :

```javascript
window.lumiaModalOpen('my-modal-id');   // ajoute .is-open
window.lumiaModalClose('my-modal-id');  // retire .is-open
```

Structure HTML requise (copier ce gabarit) :

```html
<div class="lumia-modal-overlay" id="my-modal-id" role="dialog" aria-modal="true" aria-labelledby="my-modal-title">
  <div class="lumia-modal">
    <div class="lumia-modal__header">
      <h3 id="my-modal-title" class="lumia-modal__title">Titre</h3>
    </div>
    <div class="lumia-modal__body">
      <!-- contenu, inputs, etc. -->
    </div>
    <div class="lumia-modal__footer">
      <!-- .lumia-modal-close sur le bouton Annuler → fermeture automatique -->
      <button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close">Annuler</button>
      <button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="my-confirm-btn">Valider</button>
    </div>
  </div>
</div>
```

La classe `.lumia-modal-close` et le clic hors-boîte sont gérés automatiquement par `admin.js`. Idem pour la touche Échap.

## Formulaires

```html
<!-- Groupe label + input -->
<div class="lumia-form__group">
  <label class="lumia-form__label" for="my-input">Label</label>
  <input type="text" class="lumia-input" id="my-input">
  <p class="lumia-form__help">Texte d'aide optionnel.</p>
</div>

<!-- Select standard -->
<select class="lumia-select">...</select>
<select class="lumia-select lumia-select--sm">...</select>  <!-- petit -->

<!-- Toggle -->
<label class="lumia-toggle">
  <input type="checkbox" name="...">
  <span class="lumia-toggle__slider"></span>
</label>
```

### Types d'`input` et `forms.css` de WordPress

`forms.css` de wp-admin cible les champs par type (`input[type="date"]`, `input[type="url"]`…), une spécificité (0,1,1) qui bat `.lumia-input` (0,1,0) : le champ garde alors la hauteur, la bordure et le rayon de WordPress. `components.css` redéclare donc le composant sur `input[type="…"].lumia-input` pour chaque type utilisé (`text`, `number`, `email`, `password`, `date`). **Nouveau type d'`input` → l'ajouter aux deux listes de sélecteurs** (état normal et `:focus`).

Pour `date`, Chromium ajoute des sous-champs internes qui portent la hauteur à ≈ 50px malgré le `min-height` : la hauteur est figée à 42px, et l'indicateur natif est remplacé par l'icône Lucide `calendar` (via `::-webkit-calendar-picker-indicator`, comme le chevron des `lumia-select`). Firefox garde son icône, non stylable.

### Champs hors enregistrement

Recherche, filtres de liste, champ d'un mail de test : ces contrôles vivent souvent dans le formulaire de réglages, mais ne doivent **pas avoir d'attribut `name`**. Ils ne partent pas à l'enregistrement, et l'avertissement « modifications non sauvegardées » d'`admin.js` les ignore (il ne compte que les champs nommés). Autre conséquence : ne pas leur donner un `type` que le navigateur valide (`email`, `url`, `required`), parce que la validation native s'applique aussi aux champs sans `name` et bloquerait « Enregistrer ».

## Onglets

**Quand s'en servir** : un écran qui mélange des natures différentes (réglages, outil, liste ou journal), ou plus de trois ou quatre sections longues. Un onglet par nature de contenu, pas un par section : quelques sections courtes restent sur une seule page.


Sous-onglets d'un écran, côté client (`components.css` + `initTabs()` dans `admin.js`, aucune initialisation à écrire) :

```html
<div class="lumia-tabs" role="tablist" data-lumia-tabs="smtp">
  <button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="settings">Réglages</button>
  <button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="log">Journal</button>
</div>
<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="settings">…</div>
<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="log" hidden>…</div>
```

- Les panneaux restent dans le DOM : dans un formulaire de réglages, **tous** les champs partent à l'enregistrement, quel que soit l'onglet ouvert.
- Le dernier onglet est rappelé par `sessionStorage` (`lumia-tab:{groupe}`) : la redirection après enregistrement revient sur l'écran, pas sur l'onglet.
- Un champ invalide dans un panneau masqué ouvre son onglet : sans ça, le navigateur bloque la soumission sans pouvoir montrer le champ fautif.
- Chaque changement émet `lumia:tab` (`detail.group`, `detail.name`) sur la barre, qui remonte jusqu'au `document` : un module peut attendre l'ouverture d'un onglet pour charger ses données. `window.lumiaTabs.activate(groupe, nom)` ouvre un onglet par programme.
- Dans un écran de module, la barre se place **dans** le formulaire, avant `.lumia-module-form__scroll`, pour rester fixe pendant le défilement.

## Boutons

```html
<button class="lumia-btn lumia-btn--primary">Principal</button>
<button class="lumia-btn lumia-btn--secondary">Secondaire</button>
<button class="lumia-btn lumia-btn--danger">Danger</button>
<!-- Tailles : ajouter --sm pour petit -->
```

Un bouton peut être un `<a>` (« Ouvrir la médiathèque … »). `buttons.css` redéclare donc la couleur sur `a.lumia-btn:hover/:focus/:active` par variante : sans ça, `a:hover { color:#135e96 }` de wp-admin l'emporte (l'état ajoute une pseudo-classe à la spécificité de `.lumia-btn--primary`) et le libellé vire au bleu au survol.

## Tooltips

```html
<button data-lumia-tip="Exporter ce menu en .json">…</button>
<button data-lumia-tip="…" data-lumia-tip-placement="right">…</button>   <!-- top par défaut -->
<?php echo $this->render_help_tip( __( 'Précision', 'lumia-tools' ) ); ?>  <!-- marqueur (i), dans le <label> -->
```

Système maison (pas de tippy.js : il tire Popper, et le plugin n'a ni build ni bundler), défini dans `components.css` + `admin.js`. **Aucune initialisation** : tout passe par délégation sur le `document`, donc le markup rendu en JS après coup (arbre du créateur de menu, listes AJAX) est couvert sans y penser. API : `window.lumiaTooltip.hide()`, `.refresh()`, `.set(el, texte)`.

Trois points structurels :
- Le singleton est en `position:fixed` + `translate3d`, appendé au `<body>` : un tooltip enfant serait rogné par la première colonne en `overflow:hidden` (elles le sont toutes), et en `absolute` il devrait connaître les décalages de chacun de ses parents. Contrepartie : il faut suivre le défilement, d'où le recalcul sur `scroll`/`resize` throttlé en `requestAnimationFrame`, et le masquage automatique quand la référence sort de l'écran ou du DOM.
- La bascule (`top` → `bottom`…) n'a lieu que si le côté opposé offre **plus** de place, sinon la boîte oscille entre deux positions également trop petites. Après recadrage dans la fenêtre, la flèche est repositionnée sur la référence : sans ça elle pointe à côté dans les coins.
- Un `title` sur le même élément est retiré au premier survol (sauvegardé dans `data-lumia-tip-title`), sinon la bulle native double la nôtre. Les modules qui tournent **hors** des pages LUMIA — `media.js`, chargé par `wp_enqueue_media` là où `admin.js` est absent — gardent donc les deux attributs : `title` sert de repli, `data-lumia-tip` prend le relais quand notre JS est là.

Pour une **précision secondaire** — la réserve qui compte mais qui allongerait la ligne — `Admin::render_help_tip( $texte )` pose un marqueur dans le `<label>` : l'icône Lucide `info` (`.lumia-tip-info`), pas une pastille dessinée en CSS ni un soulignement pointillé sous le libellé — la première fabrique une fausse icône, le second salit la ligne et ne se lit pas comme un contrôle. `menu-creator.js` en a l'équivalent JS (`helpTip()`, icône servie par `window.lumiaLucide.info`). Ce qui **décrit** l'option reste dans le texte d'aide visible ; seul le détail passe sous le marqueur.

Le survol comme le focus déclenchent la bulle (ce que `title` ne fait pas) ; un tooltip déjà ouvert enchaîne sans délai sur le suivant. Sur les pages du plugin, préférer `data-lumia-tip` à `title` pour tout contrôle en icône seule.

## Toasts / notifications

```javascript
window.lumiaShowToast("Message", "success"); // success | error | info | warning
```

Défini dans `assets/admin/js/notifications.js`, chargé globalement. Pour les notices qui doivent survivre à un rechargement, voir les notices persistantes dans [core.md](core.md#notices-persistantes).
