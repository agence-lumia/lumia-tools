# Module Motion : animations GSAP réglables par élément dans Bricks

Date : 11/10/2026 · Module : `Motion` (nouveau) · Dépôts : `agence-lumia/lumia-tools` (plugin), `sk-motion` (librairie remplacée, à archiver)

**Itération 2** : les décisions de la section « Itération 2 » (fin du document) remplacent celles des sections 1 à 5 quand elles diffèrent (noms des effets, courbes, rythme, panneau, compteur, chargement).

Sous-projet 1 sur 3 : **le socle**. Les effets avancés (pin, parallaxe, défilement horizontal…) et les transitions de page (View Transitions natives) auront chacun leur spec ; ils s'appuieront sur le moteur et le panneau décrits ici.

## Objectif

Remplacer le snippet FluentSnippets « SK Motion » (librairie `@studiokyne/sk-motion` chargée depuis jsDelivr en `@latest`) par un module de Lümia Tools qui garde le geste actuel (cliquer une classe dans Bricks) et ajoute ce qui manque : régler une animation **pour un élément** sans toucher au reste du site.

Critères de réussite :

1. Une animation se pose en un clic (classe globale), comme aujourd'hui.
2. Début, fin, rythme, courbe, délai, décalage et paramètres propres à l'effet se règlent sur **un élément**, sur **une classe** (préréglage valable partout) ou **globalement** (valeurs de référence), avec des valeurs par breakpoint.
3. Le réglage se voit en direct dans le builder.
4. Aucun script d'animation n'est chargé sur une page sans animation ; aucune ressource tierce (CDN) n'est appelée.
5. Rafraîchir au milieu d'une page, revenir en arrière ou suivre un lien `#ancre` laisse le visiteur où il doit être, sans contenu invisible.
6. Les sites existants continuent de fonctionner avec leurs classes `sk-*` jusqu'à leur migration, puis migrent en une commande.

## Constats (11/10/2026)

**Code actuel** (`sk-motion` 1.3.1, audit du 11/10/2026) :

- 159 Ko bruts / 58 Ko gzip chargés sur **toutes** les pages (GSAP + ScrollTrigger + SplitText + CustomEase + Lenis), boucle rAF de Lenis permanente, même sans aucune classe `sk-*`.
- Toute la config est globale (`window.SKMotionConfig`) ; seul le compteur lit des `data-*`. `ScrollTrigger.batch` copie les mêmes `vars` sur chaque élément d'un lot.
- `forceTopOnBoot` (défaut vrai) force le haut de page quatre fois, ignore `location.hash` et casse la restauration du bouton retour.
- SplitText en `autoSplit` sans `onSplit` : au redécoupage (polices, redimensionnement), l'animation vise des nœuds détachés et est perdue sans erreur. `mask: "lines"` n'est jamais retiré (jambages coupés).
- `aria-label` posé par SplitText sur des `<p>`/`<div>` (interdit en ARIA 1.2 pour ces rôles).
- Compteur : `toLocaleString` sans `useGrouping: false`, « 2024 » devient « 2 024 ».
- `will-change` posé sur tous les éléments dès l'init et jamais retiré sur les mots/caractères des surlignages.
- Lenis `autoToggle` sans la règle CSS officielle : `stop()`/`start()` sans effet réel.

**Cause réelle du retour en haut forcé** (mesuré sur une copie locale du lab) : le réglage « Smooth scroll » de Bricks écrit `html { scroll-behavior: smooth }`. Au rafraîchissement, `ScrollTrigger.refresh()` remonte la page pour mesurer puis la replace ; avec le défilement doux CSS, le replacement échoue et la page reste en haut (1440 px : 5000 → 2). Avec `scroll-behavior: auto`, la position est conservée à toutes les largeurs, au rafraîchissement comme au retour arrière. Le réglage est désactivé dans le boilerplate depuis le 11/10/2026 ; les sites existants l'ont encore.

**Prototype jetable sur Bricks 2.4.2** (copie locale du lab dans Docker, 11/10/2026) :

| Vérification | Résultat |
|---|---|
| Contrôle à clé `css` réglé avec une classe active | enregistré **sur la classe** ; CSS `.classe.brxe-heading { --lm-start: top 65% }` dans le canvas et sur le front |
| Valeur par breakpoint | `@media (max-width: 478px) { .classe.brxe-heading { --lm-end: top 60% } }` |
| Surcharge élément | `#brxe-id { --lm-end: top 10% }` l'emporte sur la classe (spécificité) |
| Aperçu live | la variable change dans le canvas 66 ms après la saisie, le moteur reconstruit l'animation (observateur sur les `<style>`) |
| HTML dans les options d'un `select` | rendu dans la liste native (vignette animée possible) |
| Contrôle `info` | HTML rendu tel quel |
| Classe verrouillée | réglages visibles mais grisés dans le panneau |
| Renommage d'une classe en base | `sk-fade` → `lm-fade` : 14 éléments de l'accueil migrés instantanément, animations intactes |
| Rafraîchissement, retour, ancre (1440 et 390 px) | position conservée, ancre exacte, aucun élément invisible |

Constats associés, vérifiés dans le code de Bricks 2.4.2 :

- La destination d'une valeur (classe ou élément) ne dépend que d'une chose : le contrôle a une clé `css` (et n'est pas de type `icon`). Un contrôle **sans** `css` (interrupteur, `select` sans `css`) reste toujours sur l'élément.
- Il n'existe pas de filtre global pour ajouter des contrôles : un filtre `bricks/elements/{name}/controls` par élément. Le CSS d'une classe n'est généré que pour les éléments qui déclarent le contrôle.
- Le CSS des classes globales est toujours inline (aucun fichier), celui des éléments est en fichier quand `cssLoading = file` : une modification faite par code impose `wp bricks regenerate_assets` (le builder le fait seul à l'enregistrement).
- Pour un **composant** ou un **élément global**, le sélecteur de l'élément est `.brxe-{id}` (0,1,0) : la classe (0,2,0) l'emporte sur l'élément.
- `bricks/element/render_attributes` passe dans le canvas pour les éléments présents au chargement, mais n'est **pas** rafraîchi sur section / container / block / div quand un réglage change.
- Bricks appelle les fonctions déclarées par `bricks/elements/{name}/scripts` à chaque montage / re-rendu : environ 180 appels au chargement du builder sur l'accueil d'agence-lumia.com.
- Aucun événement « Bricks prêt ». Événements utiles : `bricks/ajax/query_result/displayed`, `bricks/ajax/load_page/completed`, `bricks/ajax/pagination/completed`, `bricks/ajax/popup/loaded`, `bricks/popup/open`, `bricks/popup/close`.

**Conventions des sites** : `--cubic-bezier` (`cubic-bezier(0.625, 0.05, 0, 1)` ou `0.63`, la courbe « smoothKyne » de SK Motion), `--cubic-bezier-slow` et `--navbar-height` (61 à 102 px) existent sur les 8 sites vérifiés (agence-lumia.com par le lab, boilerplate, facpa, orphee, resetyou, maison-aline, neo-nat ; coyac : seulement `--navbar-height`). `--navbar-height` existe partout (il sert aussi au calcul des héros) mais sa valeur change d'un site à l'autre : le module la lit, il ne la suppose jamais. Les courbes, elles, ne sont **pas** lues sur le site : le module embarque ses propres profils (voir « Profils de mouvement »), pour fonctionner à l'identique sur un site sans ces variables.

**Usage réel** (agence-lumia.com, contenu des pages) : `sk-entry-blur` ×205, `sk-fade` ×164, `sk-hover-underline` ×59, `sk-text-highlight-chars` ×11 ; les 10 classes `sk-*` existent comme classes globales **verrouillées et vides** (simples marqueurs).

**Licence GSAP** : « Standard No-Charge License ». Le cas « préréglages + paramètres réglés par les contrôles d'un builder, GSAP fourni dans le plugin » a été qualifié d'usage autorisé par l'équipe GSAP (forum, sujet 45477). Usage interne à l'agence, pas de publication sur WordPress.org (licence non GPL).

## Décisions

| Sujet | Décision |
|---|---|
| Modèle | **La classe donne le préréglage, le panneau ajuste l'élément.** Tous les réglages, y compris l'effet, sont des variables CSS `--lm-*` : ils vivent sur l'élément, sur une classe ou dans les valeurs globales, par breakpoint, avec la cascade CSS comme arbitre. |
| Nommage | Préfixe `lm-`. Les classes `sk-*` restent reconnues (réglage « Compatibilité SK Motion », actif par défaut) jusqu'à la migration. |
| Panneau | Groupe « Motion » dans l'onglet **Style** de tous les éléments (y compris ceux des add-ons, enregistrés après coup). |
| Librairies | GSAP 3.15.0 (core, ScrollTrigger, SplitText), Lenis 1.3.26. Fichiers officiels non modifiés, versions épinglées. Lenis 2 (en préversion) réévalué à sa sortie stable. |
| Chargement | Seulement sur les pages qui contiennent un élément animé ; SplitText seulement s'il y a un effet de texte ; Lenis seulement si activé. |
| Retour en haut | Supprimé. Le module neutralise `html { scroll-behavior: smooth }` quand il est actif. |
| Mouvement | Deux réglages indépendants de l'effet : le **rythme** (rapide, normal, lent ou durée exacte) et la **courbe** (profils intégrés au module, inspirés des ressorts d'Apple, plus la courbe Lümia actuelle). Aucune dépendance aux variables `--cubic-bezier` des sites. |
| Ancres | Décalage = `--navbar-height` du site (repli 0). |
| Aperçu builder | État final affiché par défaut ; l'animation d'un élément se rejoue quand un de ses réglages Motion change. |
| Démo dans la liste | Vignette CSS animée dans les options de l'effet si le rendu reste propre ; bonus d'interface, pas un critère de la v1. |
| Transitions de page | Hors périmètre (sous-projet 3, View Transitions cross-document). |

## 1. Effets de la v1

| Classe | Ancienne classe (alias) | Effet | Réglages propres |
|---|---|---|---|
| `lm-fade` | `sk-fade` | apparition en opacité | — |
| `lm-reveal` | `sk-reveal` | translation verticale + opacité | distance (px) |
| `lm-blur-in` | `sk-entry-blur` | translation + flou + opacité | distance, flou (px) |
| `lm-scale-in` | `sk-scale-in` | échelle + opacité | échelle de départ |
| `lm-scale-blur-in` | `sk-scale-in-blur` | échelle + flou + opacité | échelle, flou |
| `lm-text-lines` | `sk-text-lines` | lignes révélées sous un masque | distance (%) |
| `lm-text-words` | `sk-text-highlight-words` | mots surlignés au défilement (scrub) | opacité de départ |
| `lm-text-chars` | `sk-text-highlight-chars` | caractères surlignés au défilement (scrub) | opacité de départ |
| `lm-counter` | `sk-counter` | compteur jusqu'à la valeur écrite dans le HTML | valeur de départ |
| `lm-underline` | `sk-hover-underline` | soulignement au survol (CSS seul) | épaisseur, écart, couleur |

Réglages communs aux effets d'entrée : début, rythme, courbe, délai, décalage entre éléments d'un même lot (stagger). Effets en scrub (texte surligné) : début et fin. Les valeurs par défaut reprennent celles du snippet d'agence-lumia.com (`reveal` 48 px / 0,65 s, `entryBlur` 20 px / 12 px / 0,8 s, etc.), à ajuster sur le lab.

Un effet par élément. Si un élément porte deux classes d'effet, la première dans l'ordre de `class` l'emporte ; le moteur l'écrit en console en mode debug, et l'aide du panneau le rappelle (ligne fixe : les conditions `required` de Bricks ne voient pas les classes, un avertissement conditionnel est impossible).

## 2. Intégration Bricks

### Contrôles

Groupe `lm_motion` (« Motion ») dans l'onglet `style`, ajouté par `bricks/elements/{name}/control_groups` et `bricks/elements/{name}/controls` sur **chaque** nom de `\Bricks\Elements::$elements`, à `init` priorité 100. Bricks enregistre ses éléments (et les add-ons les leurs) pendant `init`, mais ne charge leurs contrôles qu'au hook `wp` (`Elements::load_elements()`, précédé de l'action `bricks/load_elements/before`) : à `init` 100, tous les noms sont connus et aucun contrôle n'est encore chargé. Vérifié dans `includes/elements.php` de Bricks 2.4.2.

Chaque contrôle porte `'css' => [ [ 'property' => '--lm-…', 'selector' => '' ] ]` : c'est la condition pour qu'il s'enregistre sur une classe active, ait des valeurs par breakpoint et s'affiche en direct sans re-rendu.

| Contrôle | Type | Variable | Valeur produite |
|---|---|---|---|
| Effet | `select` (libellés HTML : vignette + nom) | `--lm-effect` | `fade`, `reveal`, … ou `none` (désactive, utile par breakpoint) |
| Début : repère de l'élément | `select` haut / centre / bas | `--lm-start-edge` | `top` / `center` / `bottom` |
| Début : position dans l'écran | `number`, `%` | `--lm-start-at` | `90%` |
| Fin (effets en scrub) | idem, deux contrôles | `--lm-end-edge`, `--lm-end-at` | |
| Position avancée | `text` (syntaxe ScrollTrigger) | `--lm-start`, `--lm-end` | prioritaire sur les deux précédents si renseigné |
| Rythme | `select` : Rapide, Normal, Lent, Durée exacte | `--lm-tempo` | `fast`, `normal`, `slow`, `exact` |
| Durée exacte | `number`, s, visible si « Durée exacte » | `--lm-duration` | |
| Délai | `number`, s | `--lm-delay` | |
| Décalage | `number`, s | `--lm-stagger` | |
| Courbe | `select` : Lümia, Douce, Vive, Rebond, Linéaire, Personnalisée | `--lm-ease` | `lumia`, `smooth`, `snappy`, `bouncy`, `linear`, `custom` |
| Courbe personnalisée | `text`, visible si « Personnalisée » | `--lm-ease-custom` | `cubic-bezier(…)` |
| Distance, flou, échelle, opacité de départ | `number`, visibles selon l'effet (`required`) | `--lm-distance`, `--lm-blur`, `--lm-scale`, `--lm-from-opacity` | |
| Aide | `info` | — | rappel : « une valeur posée ici pour l'ordinateur vaut aussi pour mobile, sauf si vous réglez mobile » |

Les contrôles dépendants de l'effet utilisent `required` sur `_lmEffect`. Limite assumée : quand l'effet vient d'une classe et non de l'élément, ces conditions ne voient pas l'effet de la classe ; les contrôles propres aux effets sont alors tous visibles (regroupés sous un séparateur « Paramètres de l'effet »).

### Variables non héritées

Les propriétés personnalisées s'héritent : une section qui porte `--lm-end` le transmettrait à tous ses descendants animés. Chaque variable est donc déclarée une fois, dans la feuille du module :

```css
@property --lm-effect { syntax: '*'; inherits: false; }
/* … une règle par variable --lm-* */
```

Exception : les variables du soulignement (`--lm-underline-*`) restent héritables, car le pseudo-élément `::after` qui les lit les hérite de son élément.

`syntax: '*'` ne demande pas de valeur initiale. Support : Chrome 85, Safari 16.4, Firefox 128 ; un navigateur plus ancien hérite (dégradation acceptée). À vérifier en premier pendant l'implémentation, dans le canvas comme sur le front.

### Valeurs globales

Les valeurs de référence de l'admin **ne passent pas** par `:root` (elles ne s'hériteraient pas, justement) : elles sont transmises au moteur en JSON (`wp_add_inline_script`), avec une table par effet. Ordre de résolution d'un réglage, pour un élément : variable calculée sur l'élément (élément > classe, par la cascade de Bricks) → valeur globale de l'effet → valeur par défaut du code.

### Composants, éléments globaux et boucles de requête

Leur sélecteur (`.brxe-{id}`, 0,1,0) perd contre celui de la classe (0,2,0). Dans une boucle de requête, c'est différent (mesuré sur Bricks 2.4.2) : les enfants de la boucle ont une règle `.brxe-loop .brxe-{id}.brxe-{type}` (0,3,0) qui bat déjà la classe, breakpoints compris ; seul l'élément qui **porte** la boucle (`hasLoop`, 0,2,0) fait égalité et perd par l'ordre des feuilles. Pour les composants, les éléments globaux et l'élément porteur d'une boucle seulement, le filtre `bricks/element/render_attributes` recopie les réglages `_lm*` **de l'élément** dans un attribut `style` (`--lm-end: …`), qui l'emporte sur toute règle. Les valeurs par breakpoint de l'élément sont alors perdues pour ces trois cas (seule la valeur de base est recopiée) ; limite documentée dans `docs/modules/motion.md`. Les enfants d'une boucle ne reçoivent pas de style inline : il écraserait leurs valeurs par breakpoint.

### Classes

À l'activation du module (et par `wp lumia motion classes`), le module crée les classes `lm-*` manquantes dans une catégorie « Motion », **verrouillées**, sans style, via `\Bricks\Helpers::save_global_classes_in_db()` (qui met à jour l'horodatage de synchronisation). Rien n'est créé si Bricks n'est pas le thème actif. Une classe existante n'est jamais modifiée. Une classe `lm-*` n'est **pas** créée tant que son ancienne classe `sk-*` existe : la migration la produira par renommage (même ID, aucune référence à réécrire).

Une classe verrouillée affiche ses réglages grisés : c'est voulu, un préréglage partagé ne se modifie pas par accident. Pour en changer un, on déverrouille la classe dans Bricks. Pour un préréglage propre au site, on crée sa propre classe (par exemple `titre-hero`) et on y règle l'effet et ses paramètres.

### Marquage et détection des pages

`bricks/element/render_attributes` (clé `_root`) ajoute `data-lm` quand l'élément, ou une de ses classes globales, porte un réglage `_lmEffect*` non vide, ou une classe `lm-*` / `sk-*` connue. Sa valeur liste les familles d'effets trouvées, tous breakpoints confondus (`enter`, `text`, `counter`, `underline`, séparées par des espaces) : elle sert au CSS de pré-masquage (`[data-lm~="enter"]`) et au choix des scripts à charger. Les réglages des classes sont lus une fois par requête (`Database::$global_data['globalClasses']`, repli `get_option`).

Le moteur ne lit **pas** l'effet dans `data-lm` : l'effet réel vient toujours de `--lm-effect` calculé, ce qui permet de le changer ou de le couper par breakpoint. Le CSS ne peut pas lire la valeur d'une variable ; c'est pour lui seul que PHP résume les familles dans l'attribut.

## 3. Moteur côté visiteur

### Fichiers

- `assets/front/js/vendor/gsap/` : `gsap.min.js`, `ScrollTrigger.min.js`, `SplitText.min.js` + licence ; `assets/front/js/vendor/lenis/` : `lenis.min.js`, `lenis.css` + licence. Copiés tels quels depuis les paquets npm aux versions épinglées (SHA-256 notés dans `docs/modules/motion.md`). `build-zip.sh` ne minifie pas les `*.min.*`.
- `assets/front/js/motion.js` (moteur), `assets/front/css/motion.css` (`@property`, états initiaux, soulignement, neutralisation du défilement doux).
- Le moteur lit `window.gsap`, `ScrollTrigger`, `SplitText`, `Lenis` : pas d'import, pas de bundler, conformément aux règles du dépôt.

### Chargement

- `motion.css` dans le `<head>` dès que le module est actif (petit, et nécessaire à l'absence de flash). Il contient `html { scroll-behavior: auto !important; }` (voir Constats).
- Un script inline dans le `<head>` ajoute `lm-js` sur `<html>` et pose une **sécurité** : si le moteur n'a pas démarré après 3 s, il ajoute `lm-ready`, qui rend tout visible.
- Scripts en pied de page, `strategy => 'defer'`, mis en file pendant le rendu quand `data-lm` a été posé au moins une fois : GSAP + ScrollTrigger, SplitText si un effet de texte, Lenis si activé. Les popups Bricks sont rendues dans `wp_footer` : si la page en contient, le module charge GSAP + ScrollTrigger d'office (plus simple qu'une détection tardive). À valider pendant l'implémentation.
- Rien dans l'admin, rien dans `wp-login.php`.

### Absence de flash et de contenu bloqué

- Pré-masquage CSS : `.lm-js:not(.lm-ready) [data-lm~="enter"] { opacity: 0 }`, hors `prefers-reduced-motion: reduce`. Les effets de texte et le compteur ne sont jamais pré-masqués (le texte reste lisible). Un élément dont l'effet d'entrée est coupé sur un breakpoint (`none`) est brièvement masqué jusqu'au démarrage du moteur, qui le rend visible aussitôt.
- Au démarrage, le moteur pose l'état initial de chaque élément avec GSAP, ajoute `lm-ready`, puis lance les animations des éléments déjà dans l'écran.
- Élément déjà dépassé (au-dessus de l'écran, cas du rafraîchissement ou du retour) : état final immédiat, pas d'animation.
- Toute erreur dans l'initialisation d'un élément le laisse dans son état final (try/catch par élément), jamais à `opacity: 0`.
- **LCP** : un élément masqué (`opacity: 0`) n'est pas candidat LCP pour Chrome. Un effet d'entrée sur le titre principal retarde donc le LCP jusqu'à l'animation. Recommandation dans l'aide du panneau et la doc : sur le titre au-dessus de la ligne de flottaison, préférer un effet de texte ou un effet sans opacité 0.

### Animations

- Effets d'entrée : un `ScrollTrigger` par élément (`once`), regroupés en lots (`ScrollTrigger.batch`) **par signature identique** (même effet et mêmes réglages), pour garder le décalage entre éléments voisins.
- Effets de texte : SplitText créé avec `autoSplit: true` et l'animation **créée et retournée dans `onSplit()`**, pour survivre au redécoupage ; `mask: 'lines'` retiré (`revert`) à la fin des effets non scrubbés. `aria: 'auto'` gardé seulement sur les titres ; sur les autres balises, `aria: 'hidden'` + texte d'origine conservé pour les lecteurs d'écran (copie visuellement masquée), à confirmer pendant l'implémentation.
- Compteur : départ à la valeur réglée, arrivée à la valeur du HTML, **format d'origine respecté** (séparateurs tels qu'écrits, pas de regroupement ajouté). Balisage interne conservé si le nombre est dans un nœud texte unique.
- `will-change` posé au départ d'une animation et retiré à la fin.
- Rythme et courbe : voir « Profils de mouvement » ci-dessous.
- `prefers-reduced-motion: reduce` : aucun masquage, aucune animation, compteur à sa valeur finale ; géré par `gsap.matchMedia()` pour suivre un changement en cours de visite.

### Profils de mouvement

L'effet décrit **ce qui bouge** (opacité, translation, flou, échelle) ; le rythme et la courbe décrivent **comment**. Les deux se règlent séparément : on peut ralentir ou rendre plus vif un seul élément sans changer d'effet, et la même cascade s'applique (élément > classe > valeurs globales, par breakpoint).

**Rythme** (`--lm-tempo`) : multiplicateur de la durée de référence de l'effet et de son décalage (stagger) — Rapide ×0,7, Normal ×1, Lent ×1,4. « Durée exacte » utilise `--lm-duration` en secondes, sans multiplicateur. Le délai n'est jamais multiplié. Deux variables distinctes plutôt qu'un seul contrôle : une classe peut fixer une durée exacte et un élément repasser en « Rapide » sans conflit.

**Courbe** (`--lm-ease`), profils intégrés au moteur :

| Profil | Valeur | Nature | Rendu |
|---|---|---|---|
| Lümia (défaut) | `lumia` | `cubic-bezier(0.625, 0.05, 0, 1)` | la courbe « smoothKyne » de SK Motion : départ retenu, arrivée très douce. Défaut pour que les sites migrés gardent exactement leur rendu. |
| Douce | `smooth` | ressort, rebond 0 | arrivée naturelle sans dépassement (équivalent de `.smooth` de SwiftUI) |
| Vive | `snappy` | ressort, rebond 0,15 | démarrage franc, très léger dépassement (`.snappy`) |
| Rebond | `bouncy` | ressort, rebond 0,3 | dépassement visible (`.bouncy`), pour les échelles et les éléments ponctuels |
| Linéaire | `linear` | — | vitesse constante |
| Personnalisée | `custom` | `cubic-bezier(…)` lu dans `--lm-ease-custom` | courbe libre ; valeur invalide → profil par défaut + message en mode debug |

Les rebonds 0 / 0,15 / 0,3 sont des valeurs de départ, à comparer sur le lab côte à côte avec Lümia avant de figer.

Ressorts : même paramétrage que SwiftUI, une **durée perçue** et un **rebond** (amortissement = 1 − rebond). Le moteur calcule la réponse d'un ressort amorti, en échantillonne la courbe (une fois par profil, mise en cache) et la passe à GSAP comme fonction de courbe : pas de `CustomEase`, pas de dépendance. La durée réglée (rythme compris) est la durée perçue ; la stabilisation du ressort peut prolonger légèrement le tween, sans décaler l'élément suivant du lot. Les courbes de Bézier passent par une petite fonction de Bézier propre au moteur, elle aussi mise en cache par valeur.

Garde-fous : les dépassements ne s'appliquent qu'aux propriétés où ils ont un sens (translation, échelle) ; l'opacité est bornée à 1 et le flou à 0. Le **compteur** n'utilise jamais de ressort avec rebond (afficher 101 puis 100 serait faux) : `snappy` et `bouncy` y deviennent `smooth`. Les **effets en scrub** suivent le défilement : rythme et courbe y sont ignorés (contrôles masqués quand l'effet est un scrub).

### Défilement doux (Lenis)

Optionnel (réglage du module, défaut : actif comme aujourd'hui), ordinateur seulement (`syncTouch: false`). Quand il est actif, il est chargé sur **toutes** les pages publiques, avec ou sans animation (petit script `motion-scroll.js`, horloge propre de Lenis quand GSAP est absent) : sinon le défilement changerait de sensation d'une page à l'autre. Branché sur le ticker GSAP selon la doc Lenis (`lenis.on('scroll', ScrollTrigger.update)`, `lagSmoothing(0)`), `lenis.css` chargé, `anchors: { offset: -navbarHeight }` lu sur `--navbar-height`, `stopInertiaOnNavigate: true`. Popups et menus hors-canevas Bricks : `lenis.stop()` / `start()` sur `bricks/popup/open` / `close`, et `data-lenis-prevent` posé sur les conteneurs de popup. Sans Lenis, les ancres utilisent `scroll-margin-top: var(--navbar-height)` (règle de `motion.css` sur `[id]`).

### Cycle de vie

- Démarrage après `document.fonts.ready` (plafonné à 1,5 s), puis `ScrollTrigger.refresh()`.
- Réinitialisation des nouveaux nœuds sur les événements AJAX de Bricks listés dans Constats, et `refresh` à l'ouverture d'une popup.
- Les éléments retirés du DOM sont détruits (triggers, SplitText, tweens). API publique : `window.lumiaMotion = { refresh(), scan(root), destroy() }`.
- Double chargement : si `window.lumiaMotion` existe déjà, le second chargement s'arrête. Si l'ancien script `StudioKyneMotion` est présent (snippet encore actif pendant une migration), le module ne démarre pas et l'écrit en console : le snippet doit être retiré.
- Debug : `?lmDebug` affiche les marqueurs ScrollTrigger et journalise les éléments ignorés.

## 4. Dans le builder

- Le moteur est chargé dans le canvas (`bricks_is_builder_iframe()`). Dans la fenêtre principale (`bricks_is_builder_main()`), seulement la feuille des vignettes de la liste d'effets.
- Par défaut, les éléments sont affichés dans leur **état final** (on construit la page sans contenu masqué).
- Un `MutationObserver` sur les `<style>` du canvas (regroupé toutes les 120 ms) relit les variables des éléments `data-lm` / classes connues ; quand la signature d'un élément change, son animation est **rejouée une fois** (les effets en scrub sont joués de 0 à 1 en 1,2 s), puis l'élément revient à l'état final.
- Les appels de Bricks via `bricks/elements/{name}/scripts` servent seulement à détecter les éléments nouvellement montés ; ils sont regroupés (un passage par tranche de 120 ms) pour ne pas refaire 180 initialisations au chargement.
- Les éléments ajoutés pendant la session n'ont pas encore `data-lm` (attribut non rafraîchi par Bricks) : dans le canvas, le moteur inspecte aussi tout élément `.brxe-*` dont `--lm-effect` calculé est non vide (coût acceptable dans l'éditeur, jamais sur le front).

## 5. Admin du module

Page de réglages standard (design system du plugin, `AbstractModule`) :

- Valeurs de référence par effet (durée, délai, décalage, distance, flou, échelle, opacité de départ, début, fin).
- Rythme et courbe par défaut du site (défaut : Normal, Lümia).
- Défilement doux Lenis : actif / inactif, intensité (`lerp`).
- Compatibilité SK Motion : active / inactive.
- Aperçu dans le builder : rejouer à la modification (défaut) / ne jamais animer.
- Bloc « Migration » : nombre d'éléments utilisant encore une classe `sk-*`, lien vers la commande.

`save_settings()` valide chaque valeur (bornes numériques, positions ScrollTrigger limitées à `[a-z0-9%+\-. ]`, rythme et courbe limités à leurs mots-clés, courbe personnalisée limitée à `cubic-bezier(…)` à quatre nombres).

## 6. Migration des sites (`wp lumia motion migrate`)

Par site, après installation de la version du plugin qui contient le module :

1. `--dry-run` par défaut : liste les classes `sk-*` à renommer, le nombre d'éléments concernés, et toute occurrence de `.sk-` ou `--sk-hover-underline` dans le CSS personnalisé (éléments, classes, CSS global de Bricks, snippets FluentSnippets) — signalée, jamais réécrite automatiquement.
2. `--apply` : renomme chaque classe `sk-*` connue en son nom `lm-*` (même ID, donc tous les éléments suivent), la range dans la catégorie « Motion », remplace `.sk-…` par `.lm-…` dans les `_cssCustom*` **de la classe** (comme le fait le builder), enregistre par `save_global_classes_in_db()`, puis `wp bricks regenerate_assets`. Cas d'une classe `lm-*` déjà présente sous le même nom (créée à la main) : la commande s'arrête pour cette classe et la signale, sans rien fusionner.
3. Le snippet « SK Motion » est désactivé à la main dans FluentSnippets (la commande rappelle son nom ; elle ne touche pas aux snippets).
4. Vérifier le site, puis couper la compatibilité `sk-*` dans les réglages du module.

Variables de soulignement `--sk-hover-underline-*` : lues en repli de `--lm-underline-*` tant que la compatibilité est active.

### Conventions du dépôt

Les noms hérités (`sk-*`, `--sk-hover-underline-*`, `StudioKyneMotion`) vivent dans un seul fichier, `includes/Modules/Motion/Legacy.php`, ajouté à la liste blanche des noms hérités du `CLAUDE.md`. Il fournit la table des alias au moteur par le JSON de configuration : `motion.js` ne contient aucun nom hérité. Code, commentaires et libellés en anglais, traduction française dans le `.po`, comme le reste du plugin.

## 7. Désactivation, désinstallation

- Désactivation du module : plus de scripts ni de contrôles ; les classes `lm-*` restent (elles sont vides) et les réglages `_lm*` restent dans le contenu Bricks, sans effet. Réactivation : tout revient.
- Désinstallation du plugin : option `lumia_module_motion` supprimée ; les classes et réglages Bricks ne sont pas touchés (contenu du site).

## 8. Tests

Le dépôt n'a pas de tests automatisés du plugin ; s'y ajoutent :

- `composer check` (PHPCS + PHPStan), la porte des fichiers de langue.
- **Scénarios Playwright** dans `tools/e2e/motion/` (non livrés dans le zip), exécutés contre un site Bricks local fourni par le développeur (Bricks est sous licence, il n'est pas dans le dépôt) : URL et identifiants en variables d'environnement. Reprennent les vérifications du prototype :
  - préréglage sur une classe, surcharge élément, valeur mobile, composant ;
  - aperçu live et rejeu dans le canvas, nombre d'initialisations au chargement du builder ;
  - rafraîchissement au milieu de la page, retour arrière, ancre depuis une autre page, à 1440 et 390 px, avec et sans Lenis, avec et sans `scroll-behavior: smooth` du thème ;
  - aucun élément `data-lm` sous `opacity: 0.5` dans l'écran 2 s après le chargement ; moteur bloqué volontairement → tout visible après 3 s ;
  - `prefers-reduced-motion` ;
  - rythme et courbe : un élément en « Rapide » / « Rebond » dans un lot réglé par sa classe garde sa propre durée et sa courbe ; compteur en « Rebond » sans dépassement ; opacité jamais au-dessus de 1 ;
  - page sans animation : aucun script du module chargé ;
  - migration : `--dry-run` puis `--apply` sur une copie d'agence-lumia.com, mêmes animations avant et après.
- Contrôle manuel sur iPhone (Safari) et sur le lab avant agence-lumia.com.

## 9. Déploiement

1. Lab (`lab.dev.agence-lumia.com`) : module activé, snippet désactivé, migration, réglage des valeurs de référence avec l'utilisateur, dont une page de comparaison des courbes (même effet en Lümia, Douce, Vive, Rebond, côte à côte) pour figer les rebonds et le profil par défaut.
2. agence-lumia.com, puis le boilerplate (classes `lm-*` créées, aucune classe `sk-*`), puis les sites clients un par un.
3. Dépôt `sk-motion` et paquet npm `@studiokyne/sk-motion` : archivés et dépréciés quand plus aucun site ne charge le CDN.

## Hors périmètre

- Effets avancés (pin, parallaxe, défilement horizontal, révélation d'image, marquee, curseur, boutons magnétiques) : sous-projet 2.
- Transitions de page : sous-projet 3.
- Éditeur libre de timelines (zone grise de la licence GSAP).
- Un sélecteur d'effets en cartes dessiné dans la fenêtre du builder (API non documentée) ; la vignette dans la liste native suffit.

---

## Itération 2 : retours des essais en local (11/10/2026)

Décisions prises après les essais sur la copie locale du lab (PR #26 ouverte, non fusionnée, module jamais déployé). **Elles remplacent** les passages correspondants des sections 1 à 5 ; en cas de contradiction, cette section fait foi. Comme aucun site n'utilise encore les noms `lm-*` de la v1, ils sont renommés **sans alias** : seuls les alias `sk-*` existent, et ils pointent vers les nouveaux noms.

### A. Mesures de performance

Lighthouse mobile, copie locale, gzip actif :

| Page | Ancien snippet | Module v1 |
|---|---|---|
| Accueil | 95 / LCP 2,58 s | 89 / 3,45 s |
| Contact | 99 / 1,97 s | 94 / 2,96 s |
| Mentions légales (soulignement seul) | 97 | 95 |

Ordinateur : 100 partout. Poids : environ 63 Ko gzip sur une page animée (ancien : 58 Ko sur toutes les pages, depuis un CDN), environ 7 Ko (Lenis) sur une page sans animation.

Cause de l'écart : le snippet ne pré-masquait rien (le contenu apparaissait, puis le script le masquait et l'animait : un flash), alors que le module pré-masque en CSS. Le LCP attend donc les scripts. L'attente des polices (1,5 s) n'y est pour rien (vérifié en la retirant).

**Critère de réussite ajouté** : LCP mobile de l'accueil et de la page contact au plus 0,2 s au-dessus de l'ancien snippet, mesuré dans les mêmes conditions.

### B. Animations au chargement (LCP)

Les éléments à effet d'entrée visibles dès l'arrivée sur la page sont animés **en CSS pur, dès la première image**, sans attendre le moteur : ils ne sont jamais à `opacity: 0` au moment où Chrome choisit le LCP, et il n'y a pas de flash.

- **Détection automatique** par `Marker` au rendu : éléments à effet d'entrée situés dans le gabarit d'en-tête ou dans la **première section** du contenu. Ils reçoivent `data-lm-load` au lieu d'être pré-masqués.
- **Réglage du panneau** « Au chargement » (`--lm-load`, avec clé `css` pour vivre sur une classe) : `auto` (défaut, la détection ci-dessus), `always` (animé au chargement où qu'il soit), `never` (toujours le chemin normal, au défilement). PHP lit la valeur de base de l'élément puis de ses classes ; les valeurs par breakpoint de ce réglage sont ignorées.
- **Paramètres résolus côté serveur** : pour ces éléments, PHP calcule les réglages du breakpoint de base (élément > classes > valeurs globales > défauts) et les écrit en `style` inline : `--lm-load-duration` (rythme appliqué), `--lm-load-delay` (délai + rang dans le lot × décalage, le rang comptant les éléments de même signature dans la zone), `--lm-load-ease`, et les paramètres de l'effet (distance, flou, échelle). Limite assumée : les valeurs par breakpoint ne s'appliquent pas à l'animation de chargement.
- **Courbes en CSS** : `motion.css` déclare `--lm-curve-smooth`, `--lm-curve-snappy`, `--lm-curve-bouncy` sous forme de `linear()`, **générées** depuis `motion-core.js` par un script de `tools/motion/` ; un test vérifie que les valeurs du fichier correspondent au calcul du moteur. Courbe personnalisée : le `cubic-bezier()` tel quel. Navigateur sans `linear()` : repli sur une courbe de Bézier proche (déclaration précédente).
- Une `@keyframes` par effet d'entrée, `animation-fill-mode: both`. Mêmes garde-fous que le moteur : l'opacité et le flou ne dépassent jamais (le rebond ne s'applique qu'à la translation et à l'échelle, qui ont leur propre courbe).
- Le moteur ignore les éléments `data-lm-load` (aucun ScrollTrigger). `prefers-reduced-motion: reduce` : aucune animation. Dans le builder : état final, le bouton « Rejouer » passe par le moteur.
- Limite documentée : dans une première section très haute, les éléments du bas s'animent hors de l'écran. Solution : « Au chargement : jamais » sur ces éléments.
- Aucune étape manuelle, aucune requête supplémentaire, aucun fichier généré par site.

### C. Effets : noms et classes

| Classe | Valeur `--lm-effect` | Anglais | Français | Ancienne classe (alias) | Réglages propres |
|---|---|---|---|---|---|
| `lm-fade` | `fade` | Fade | Fondu | `sk-fade` | — |
| `lm-slide-up` | `slide-up` | Slide up | Montée | `sk-reveal` | distance |
| `lm-blur` | `blur` | Blur | Flou | — (nouveau) | flou |
| `lm-blur-up` | `blur-up` | Blur up | Flou montant | `sk-entry-blur` | distance, flou |
| `lm-zoom` | `zoom` | Zoom | Zoom | `sk-scale-in` | échelle |
| `lm-zoom-blur` | `zoom-blur` | Zoom blur | Zoom flou | `sk-scale-in-blur` | échelle, flou |
| `lm-lines` | `lines` | Lines | Lignes | `sk-text-lines` | distance (%) |
| `lm-words` | `words` | Words on scroll | Mots au défilement | `sk-text-highlight-words` | opacité de départ |
| `lm-letters` | `letters` | Letters on scroll | Lettres au défilement | `sk-text-highlight-chars` | opacité de départ |
| `lm-counter` | `counter` | Counter | Compteur | `sk-counter` | valeur de départ, largeur |
| `lm-underline` | `underline` | Hover underline | Soulignement au survol | `sk-hover-underline` | épaisseur, écart, couleur |

**Flou** (nouveau) : opacité + flou, sans translation ni échelle. Valeurs de départ : flou 12 px, 0,8 s, décalage 0,08 s (comme Flou montant), à ajuster sur le lab.

Copie locale du lab : réinitialisée depuis `db.sql` avant les essais, ses classes `lm-*` de la v1 n'ont pas à être migrées.

### D. Courbes

La courbe Lümia (`cubic-bezier(0.625, 0.05, 0, 1)`) **quitte le module** : elle convient aux interactions d'interface (boutons, popups, menus déroulants), pas aux apparitions au défilement. Elle pourra revenir dans un sous-projet « interactions ». Un site qui la veut sur ses animations la saisit en courbe personnalisée.

| Clé | Anglais | Français | Nature |
|---|---|---|---|
| `smooth` (défaut) | Smooth | Douce | ressort, rebond 0 |
| `snappy` | Snappy | Vive | ressort, rebond 0,25 |
| `bouncy` | Bouncy | Rebond | ressort, rebond 0,45 |
| `linear` | Linear | Linéaire | vitesse constante |
| `custom` | Custom | Personnalisée | `cubic-bezier(…)` lu dans `--lm-ease-custom` |

Les rebonds de la v1 (0,15 / 0,3) étaient trop proches de Douce pour se distinguer à l'œil. 0,25 et 0,45 sont les valeurs de départ, à figer sur le lab. Valeur `lumia` rencontrée (réglage admin enregistré pendant les essais) : traitée comme `smooth`.

### E. Rythme

| Clé | Anglais | Français | Multiplicateur |
|---|---|---|---|
| `very-fast` | Very fast | Très rapide | ×0,5 |
| `fast` | Fast | Rapide | ×0,75 |
| `normal` (défaut) | Normal | Normal | ×1 |
| `slow` | Slow | Lent | ×1,5 |
| `very-slow` | Very slow | Très lent | ×2 |
| `custom` | Custom | Personnalisé | durée exacte en secondes (`--lm-duration`) |

La clé `exact` de la v1 devient `custom` (même règle : durée non multipliée, décalage toujours multiplié). Valeur `exact` rencontrée : traitée comme `custom`.

### F. Panneau Bricks

- **Sous-sections** (séparateurs) : Effet, Déclenchement, Durée, Sensation, Paramètres de l'effet, Soulignement (visible seulement pour cet effet, quand l'effet est posé sur l'élément ; même limite `required` qu'en v1 quand l'effet vient d'une classe).
- **Une phrase et l'unité sous chaque champ** (`description` des contrôles), par exemple :
  - Décalage : « temps entre deux éléments qui entrent ensemble, en s » ;
  - Échelle : « taille de départ, de 0 à 1 : 0,9 = démarre à 90 % » ;
  - Distance : « déplacement de départ, en px » ;
  - Début : « l'animation part quand ce repère de l'élément atteint cette hauteur de l'écran (0 % = haut, 100 % = bas) » ;
  - Au chargement : « auto = animé dès l'ouverture s'il est dans l'en-tête ou la première section ».
- **Valeur héritée en grisé** dans les champs vides : `motion-builder.js` lit dans le canvas la valeur effective (classe, sinon valeur globale de l'effet) et la pose en `placeholder` à l'ouverture du panneau ; à défaut, le placeholder statique est la valeur globale de l'effet par défaut.
- **Bouton « Rejouer »** (dans le contrôle `info` en tête du groupe) : rejoue l'animation de l'élément sélectionné dans le canvas, via `lumiaMotion.replay(id)` ajouté à l'API publique.
- **Interrupteur « Afficher les repères »** au même endroit : affiche les marqueurs ScrollTrigger dans le canvas. Préférence du navigateur (`localStorage`), jamais enregistrée dans le contenu ni visible sur le front. Sur le front, `?lmDebug` reste.
- **Icône du groupe** dans le rail de gauche : icône `sparkles` de Lucide posée en CSS (`motion-builder.css`) par-dessus l'icône déclarée ; icône Bricks `star` déclarée en repli si la surcharge ne tient pas.

### G. Soulignement

Épaisseur par défaut : `round(max(1px, 0.06em), 1px)`, soit 1 px de 14 à 18 px de texte et environ 2 px sur un titre de 32 px, toujours un nombre entier de pixels (pas de trait flou). Navigateur sans `round()` (Safari < 15.4, Chrome < 125, Firefox < 118) : 1px (déclaration précédente). Le réglage d'épaisseur reste disponible par élément et par classe. Les sites qui posent `--sk-hover-underline-thickness` gardent leur valeur.

### H. Compteur : NumberFlow embarqué

Une seule variante, le défilement de chiffres de NumberFlow, **embarqué tel quel** plutôt que recodé (un prototype maison laissait des résidus de chiffres voisins et un décalage en fin d'animation).

- **Fichiers** : `number-flow` 0.6.2 (MIT, `lite.mjs`, `index.mjs`, `plugins.mjs`, `csp.mjs`, `group.mjs`, `ssr-*.mjs`) et `esm-env` 1.2.2 (MIT), copiés sans modification dans `assets/front/js/vendor/number-flow/` et `vendor/esm-env/`, avec licences et SHA-256 dans `docs/modules/motion.md`. Environ 7 Ko gzip.
- **Chargement** : modules natifs de WordPress (Script Modules, carte d'import), **seulement** sur les pages qui ont un compteur :
  - `esm-env/browser` → `true.js`, `esm-env/development` → `false.js`, `esm-env/node` → `false.js`, `esm-env` → `index.js` ;
  - `number-flow/lite` → `lite.mjs` ;
  - `motion-counter.mjs` (adaptateur du module) en dépend et s'enregistre auprès du moteur.
- **Adaptateur** : n'utilise que l'API publique de la version lite (`define('lm-number-flow', NumberFlowLite)`, `formatToData`, `canAnimate`, propriétés `data` et `animated`).
  - Format lu par `parseCounter` (préfixe, suffixe, séparateurs, décimales) et rendu **exactement** : formateur maison passé à `formatToData`, séparateurs tels qu'écrits, aucun regroupement ajouté.
  - Le compteur remplace le texte par `<lm-number-flow>` et **le garde** après l'animation (revenir au texte décale le nombre de plusieurs pixels).
  - Durée : durée de référence de l'effet × rythme, courbe du module passée en `linear()` (Vive et Rebond deviennent Douce : un compteur ne dépasse pas sa valeur).
- **Largeur** (réglage `--lm-counter-width`) :
  - `auto` (défaut) : le nombre s'élargit à mesure que les chiffres apparaissent ;
  - `fixed` : tous les chiffres du nombre final existent dès le départ (« 0 000 » → « 1 250 ») et les chiffres ont tous la même largeur (`tabular-nums`). La largeur ne change à aucune image (mesuré : constante sur 90 images pour trois compteurs). Le rendu final est un peu plus large qu'en chiffres proportionnels.
- **Aucun décalage de mise en page** : NumberFlow ajoute des marges pour son masque en fondu, qui agrandissaient la ligne (61,6 → 84 px). Règle de `motion.css`, vérifiée stable sur toute l'animation :

  ```css
  lm-number-flow {
  	line-height: inherit;
  	vertical-align: top;
  	margin-block: calc(-2 * round(nearest, calc(var(--number-flow-mask-height, .25em) / 2), 1px));
  }
  ```

- **Accessibilité** : NumberFlow expose la valeur par `ElementInternals` (`ariaLabel`). **Référencement** : le HTML servi contient le nombre en texte ; à vérifier pendant l'implémentation que le rendu de Google lit la valeur dans le shadow DOM, sinon garder une copie du texte masquée visuellement.
- **Replis** : `canAnimate` faux, `prefers-reduced-motion`, ou module non chargé → le texte d'origine reste, à sa valeur finale.

### I. Tests ajoutés

- Courbes CSS générées identiques au calcul de `motion-core.js` (test unitaire).
- Chargement : un élément à effet d'entrée dans la première section n'est jamais sous `opacity: 0.5` sur la première image capturée ; `never` le fait passer au défilement ; `always` l'anime hors de la première section.
- Lighthouse mobile accueil et contact (critère A), avant fusion.
- Compteur : largeur visuelle constante en `fixed`, hauteur de ligne constante dans les deux modes, valeur finale et séparateurs identiques au HTML, aucun script de compteur sur une page sans compteur.
- Alias : chaque classe `sk-*` produit l'effet de sa nouvelle classe ; `migrate --apply` renomme vers les nouveaux noms.

## Itération 3 : premiers essais sur le lab (14/10/2026)

- **Champs vides « Animation » et « Tempo »** : le texte d'aide affiche la valeur du site (« Smooth (défaut du site) ») au lieu de « Par défaut ». Smooth reste la valeur par défaut du module.
- **Plus aucune classe créée à l'activation.** Les nouveaux sites (boilerplate compris) choisissent l'effet dans l'onglet Motion de chaque élément. Les sites existants gardent la migration par renommage (`sk-*` → `lm-*`, même ID, aucune page réécrite). `wp lumia motion classes` crée encore les classes `lm-*` à la demande, et elles restent reconnues partout. Le § 2 (« À l'activation du module… ») ne vaut plus que pour cette commande.
- **Boilerplate** : 5 éléments seulement (Accueil : 2, modèle Maintenance : 3) utilisent les anciennes classes ; ils seront refaits à la main dans l'onglet Motion, puis les classes `sk-*` supprimées. Pas d'option de conversion automatique.
