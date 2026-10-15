# Module Motion — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** remplacer le snippet « SK Motion » (CDN) par un module `Motion` de Lümia Tools : effets GSAP posés par classe globale Bricks, réglables par élément / classe / globalement via des variables CSS `--lm-*`, avec rythme et courbes intégrés, chargés seulement là où ils servent.

**Architecture :** côté PHP, des classes à responsabilité unique sous `includes/Modules/Motion/` : catalogue des effets (`Effects`), noms hérités (`Legacy`), validation des réglages (`SettingsSanitizer`), panneau Bricks (`Controls`), marquage `data-lm` + style inline des composants / boucles (`Marker`), chargement des fichiers (`Assets`), classes globales et migration (`ClassManager`, `Command`), orchestrés par `Module`. Côté navigateur : `motion-core.js` (fonctions pures : courbes, ressorts, résolution des réglages, compteur, signature), `motion.js` (moteur DOM), `motion-builder.js` (canvas Bricks), `motion-scroll.js` (Lenis), `motion.css`. Les fonctions pures sont testées en unitaire (PHP sans WordPress, `node --test`) ; le reste par des scénarios Playwright contre un site Bricks local.

**Tech Stack :** PHP 8.0+ / WordPress 6.9+ (local : WP 7, PHP 8.5), Bricks 2.4.2, GSAP 3.15.0 (core, ScrollTrigger, SplitText), Lenis 1.3.26, Node 22+ (`node:test`, `playwright-core` 1.64 dans un cache ignoré par git), Google Chrome, Docker.

**Spec :** `docs/superpowers/specs/2026-10-11-motion-module-design.md` (issue #25, branche `feat/25-motion`). La lire avant toute tâche.

## Global Constraints

- Règles absolues de `CLAUDE.md` : garde `ABSPATH` dans chaque fichier PHP ; tout en anglais (identifiants, commentaires, libellés, `docs/`) ; français seulement dans `languages/lumia-tools-fr_FR.po` ; aucune lettre accentuée française dans `includes/`, `assets/`, `docs/` sauf « Lümia » ; composants du design system uniquement ; icônes Lucide officielles (lucide-static v1.34.0) ; jamais de baseline PHPCS/PHPStan ; ne jamais toucher à la version.
- Commits Conventional Commits en anglais, scope `motion` (ou `e2e`, `docs`, `i18n`, `ci`), terminés par `Refs #25` puis `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- `docker run --rm -v "$PWD:/app" -w /app composer:2 check` sans constat avant chaque commit de code PHP ; `composer motion:test` et `node --test tools/motion/js/` verts avant chaque commit qui les concerne.
- Aucun appel à un CDN ; aucune dépendance npm dans le plugin ; pas de bundler. Fichiers tiers copiés tels quels depuis les archives npm (`registry.npmjs.org/gsap/-/gsap-3.15.0.tgz`, `registry.npmjs.org/lenis/-/lenis-1.3.26.tgz`).
- Noms hérités (`sk-*`, `--sk-hover-underline-*`, `StudioKyneMotion`, « SK Motion ») **uniquement** dans `includes/Modules/Motion/Legacy.php`, ajouté à la liste blanche de `CLAUDE.md`.
- Variables moteur (non héritées, `@property … inherits: false`) : `--lm-effect`, `--lm-start-edge`, `--lm-start-at`, `--lm-end-edge`, `--lm-end-at`, `--lm-start`, `--lm-end`, `--lm-tempo`, `--lm-duration`, `--lm-delay`, `--lm-stagger`, `--lm-ease`, `--lm-ease-custom`, `--lm-distance`, `--lm-blur`, `--lm-scale`, `--lm-from-opacity`, `--lm-start-value`. Variables du soulignement (héritables, jamais en `@property`) : `--lm-underline-thickness`, `--lm-underline-gap`, `--lm-underline-color`.
- Mots-clés : effets `fade`, `reveal`, `blur-in`, `scale-in`, `scale-blur-in`, `text-lines`, `text-words`, `text-chars`, `counter`, `underline`, plus `none` ; repères `top|center|bottom` ; rythme `fast` ×0,7, `normal` ×1, `slow` ×1,4, `exact` ; courbes `lumia` = `cubic-bezier(0.625, 0.05, 0, 1)`, `smooth` (rebond 0), `snappy` (0,15), `bouncy` (0,3), `linear`, `custom`.
- Valeurs de référence (snippet d'agence-lumia.com) — début `top 98%` ; fin des scrubs `top -120%` ; délai 0 ; `fade` 0,45 s / décalage 0,06 ; `reveal` 0,65 s / 0,1 / distance 48 ; `blur-in` 0,8 s / 0,08 / distance 20 / flou 12 ; `scale-in` 0,55 s / 0,08 / échelle 0,9 ; `scale-blur-in` 0,7 s / 0,08 / échelle 0,86 / flou 10 ; `text-lines` 0,95 s / 0,08 / distance 120 (%) ; `text-words` décalage 0,25 / opacité de départ 0,08 ; `text-chars` 0,1 / 0,08 ; `counter` 1,35 s / départ 0. Soulignement (CSS) : épaisseur 2px, écart 0.12em, durée 0.4s.
- Délais : sécurité `lm-ready` 3000 ms ; attente des polices plafonnée à 1500 ms ; regroupement builder 120 ms ; rejeu d'un scrub dans le builder 1,2 s.
- Rien n'est écrit en production par ce plan. Tous les essais tournent sur la copie locale du lab (`http://localhost:8088`, projet Docker `motion-lab`). Déploiement : hors plan, avec l'utilisateur.

## Review Focus

1. **Élément dans une boucle de requête, un composant ou un élément global, avec une surcharge Motion sur l'élément** : la surcharge l'emporte sur la classe, comme ailleurs (sélecteur `.brxe-{id}` plus faible que la classe) — test « loop override » de la tâche 5.
2. **Élément sans effet à l'intérieur d'une section animée** (section `lm-fade`, titre sans classe) : le titre n'hérite d'aucun réglage, n'est pas animé à part et reste visible — test « nested » de la tâche 7.
3. **Valeurs invalides venues du contenu** (`--lm-duration: abc`, `--lm-start: foo;bar`, courbe `cubic-bezier(2,0,0,1)`) : valeurs de référence utilisées, aucune exception, élément visible — tests unitaires de la tâche 3 et test « invalid » de la tâche 7.
4. **Texte de compteur non numérique ou ambigu** (« 24/7 », « +150 clients », « 1 200,50 € », « 2024 ») : « 24/7 » n'est jamais touché, les autres retrouvent exactement leur texte d'origine — tests unitaires de la tâche 3, test « counter » de la tâche 8.
5. **Contenu inséré après le chargement** (pagination AJAX, popup Bricks) : les nouveaux éléments s'animent ou restent visibles, jamais bloqués à `opacity: 0` — test « ajax » de la tâche 7, test « popup » de la tâche 9.

## Structure des fichiers

| Fichier | Rôle |
|---|---|
| `includes/Modules/Motion/Module.php` | Module : réglages, hooks, activation, gabarit admin |
| `includes/Modules/Motion/Effects.php` | Catalogue des effets, familles, valeurs de référence, mots-clés (pur PHP) |
| `includes/Modules/Motion/Legacy.php` | Alias `sk-*`, variables de soulignement héritées, nom du global hérité (pur PHP) |
| `includes/Modules/Motion/SettingsSanitizer.php` | Validation des réglages de l'admin (pur PHP) |
| `includes/Modules/Motion/Controls.php` | Groupe et contrôles Bricks, filtre `scripts` du builder |
| `includes/Modules/Motion/Marker.php` | `data-lm`, style inline (composants, globaux, boucles), familles de la page |
| `includes/Modules/Motion/Assets.php` | CSS / script inline du `<head>`, scripts conditionnels, JSON de configuration |
| `includes/Modules/Motion/ClassManager.php` | Création des classes `lm-*`, plan et application de la migration, usage hérité |
| `includes/Modules/Motion/Command.php` | `wp lumia motion classes|migrate` |
| `includes/Modules/Motion/settings-template.php` | Écran du module |
| `assets/front/js/motion-core.js` | Fonctions pures (UMD : `window.lumiaMotionCore` / `module.exports`) |
| `assets/front/js/motion.js` | Moteur DOM (`window.lumiaMotion`) |
| `assets/front/js/motion-builder.js` | Canvas : détection, rejeu (`window.lumiaMotionBuilder`) |
| `assets/front/js/motion-scroll.js` | Lenis (`window.lumiaLenis`) |
| `assets/front/css/motion.css`, `assets/front/css/motion-builder.css` | `@property`, pré-masquage, soulignement ; vignettes de la liste d'effets |
| `assets/front/js/vendor/gsap/`, `assets/front/js/vendor/lenis/` | Fichiers officiels + licences |
| `tools/motion/php/run.php`, `tools/motion/php/*Test.php` | Tests unitaires PHP (sans WordPress) |
| `tools/motion/js/core.test.mjs` | Tests unitaires `node --test` |
| `tools/e2e/motion/` | Lanceur, bibliothèque, pages de test, scénarios Playwright, assertions WP-CLI |
| `docs/modules/motion.md` | Doc du module |

---

### Task 0: Banc local (hors dépôt) et lanceur de scénarios

**Files:**
- Modify (hors dépôt) : `<scratchpad>/local-lab/compose.yml`
- Create : `tools/e2e/motion/run.sh`, `tools/e2e/motion/lib.mjs`, `tools/e2e/motion/fixtures.mjs`, `tools/e2e/motion/README.md`
- Modify : `.gitignore`

**Interfaces:**
- Produces : `tools/e2e/motion/run.sh setup|<scenario>|all` ; variables `LM_E2E_URL` (défaut `http://localhost:8088`), `LM_E2E_USER`, `LM_E2E_PASS`, `LM_E2E_WP` (commande wp-cli, défaut `wp`). `lib.mjs` exporte `browser()`, `newPage(ctx, {width, height, reducedMotion})`, `login(page)`, `wp(...args): string` (lance `LM_E2E_WP` avec `--user=$LM_E2E_USER`), `pageUrl(slug)`, `items(page)` (renvoie `lumiaMotion._debug.items()`), `assert(cond, msg)`, `injectSmoothScroll(page)` (route qui ajoute `<style>html{scroll-behavior:smooth}</style>` au HTML). `fixtures.mjs` exporte `setup()` qui crée ou remplace, idempotent par slug, les pages `lm-e2e-*` décrites dans chaque tâche puis lance `wp bricks regenerate_assets`.

- [ ] **Step 1 : monter l'arbre de travail dans le site local.** Déplacer `site/wp-content/plugins/lumia-tools` (copie 2.1.0) hors de `site/`, ajouter au service `wordpress` (et `cli`) le volume `/Users/alban/Documents/DEV/studio-kyne-mini-tools:/var/www/html/wp-content/plugins/lumia-tools`, supprimer `site/wp-content/mu-plugins/lm-spike.php` et `lm-spike/`, désactiver le snippet « SK Motion » dans FluentSnippets (il ne sera réactivé que par le test de double chargement de la tâche 6). `docker compose -p motion-lab up -d`, puis `wp plugin list` montre `lumia-tools` actif.
- [ ] **Step 2 : écrire `run.sh`.** Même principe que `tools/i18n/wp.sh` : au premier lancement, `npm install --prefix tools/e2e/motion/.cache playwright-core@1.64.0` ; `NODE_PATH` pointe sur ce cache ; Chrome via `channel: 'chrome'` (ou `LM_E2E_CHROME`). Ajouter `tools/e2e/motion/.cache/` et `tools/e2e/motion/out/` à `.gitignore`. Chaque scénario est `tools/e2e/motion/scenarios/<nom>.mjs` exportant `default async function ()`, et sort en 1 au premier échec avec le message.
- [ ] **Step 3 : vérifier.** `tools/e2e/motion/run.sh setup` crée une page `lm-e2e-smoke` (un titre) ; un scénario `smoke.mjs` l'ouvre et vérifie le statut 200 et l'absence de `pageerror`. Attendu : `ok smoke`.
- [ ] **Step 4 : commit** `chore(e2e): local Bricks runner for the Motion scenarios`.

### Task 1: Catalogue des effets, noms hérités, tests unitaires PHP

**Files:**
- Create : `includes/Modules/Motion/Effects.php`, `includes/Modules/Motion/Legacy.php`, `tools/motion/php/run.php`, `tools/motion/php/EffectsTest.php`
- Modify : `composer.json` (script `motion:test`), `CLAUDE.md` (liste blanche), `.github/workflows/lint.yml` (job `motion`)

**Interfaces:**
- Produces :
  - `final class Effects` : constantes `FAMILY_ENTER = 'enter'`, `FAMILY_TEXT = 'text'`, `FAMILY_COUNTER = 'counter'`, `FAMILY_UNDERLINE = 'underline'` ; `TEMPOS = ['fast' => 0.7, 'normal' => 1.0, 'slow' => 1.4]` ; `TEMPO_KEYWORDS = ['fast','normal','slow','exact']` ; `EASES = ['lumia','smooth','snappy','bouncy','linear','custom']` ; `EDGES = ['top','center','bottom']` ; `ENGINE_VARIABLES` (liste des 18 variables moteur, sans `--`).
  - `Effects::catalog(): array<string, array{class: string, family: string, scrub: bool, params: string[]}>` — clés = les 10 slugs ; `class` = `lm-<slug>` ; `params` parmi `distance`, `blur`, `scale`, `from_opacity`, `start_value`.
  - `Effects::reference_values(): array<string, array<string, float|string>>` — par slug, clés `start_edge`, `start_at`, `delay`, plus `duration`, `stagger`, ses `params`, et `end_edge`, `end_at` pour les scrubs ; valeurs des Global Constraints (`underline` : tableau vide).
  - `Effects::slug_for_class( string $class_name, bool $legacy ): ?string` ; `Effects::family( string $slug ): ?string`.
  - `final class Legacy` : `CLASS_ALIASES` (`sk-fade` → `fade`, `sk-reveal` → `reveal`, `sk-entry-blur` → `blur-in`, `sk-scale-in` → `scale-in`, `sk-scale-in-blur` → `scale-blur-in`, `sk-text-lines` → `text-lines`, `sk-text-highlight-words` → `text-words`, `sk-text-highlight-chars` → `text-chars`, `sk-counter` → `counter`, `sk-hover-underline` → `underline`) ; `UNDERLINE_VARIABLES` (`thickness`, `gap`, `color`, `duration` : `--sk-hover-underline-*` → `--lm-underline-*`) ; `ENGINE_GLOBAL = 'StudioKyneMotion'` ; `SNIPPET_NAME = 'SK Motion'` ; `Legacy::new_class_name( string $legacy ): ?string` ; `Legacy::underline_css(): string` (règle `.sk-hover-underline { --lm-underline-*: var(--sk-hover-underline-*, <défaut>) }`).
  - `tools/motion/php/run.php` : définit `ABSPATH`, charge `includes/Core/Autoloader.php`, exécute chaque `tools/motion/php/*Test.php` (chacun retourne `array<string, callable>`), affiche `ok <nom>` / `FAIL <nom>: <message>`, sort en 1 si un échec. Assertion : `lm_assert( bool $cond, string $msg )` qui lève une exception.

- [ ] **Step 1 : écrire `EffectsTest.php`** — `catalog has the ten effects` (`array_keys` = la liste des Global Constraints) ; `every alias targets a catalog slug` ; `slug_for_class('lm-fade', false) === 'fade'` ; `slug_for_class('sk-fade', false) === null` ; `slug_for_class('sk-fade', true) === 'fade'` ; `family('text-words') === 'text'` ; `catalog()['text-chars']['scrub'] === true` ; `new_class_name('sk-entry-blur') === 'lm-blur-in'` ; `reference_values()['reveal']['distance'] === 48.0` ; `reference_values()['text-words']['end_at'] === '-120%'` ; `ENGINE_VARIABLES` compte 18 entrées, aucune ne commence par `underline`.
- [ ] **Step 2 : lancer** `docker run --rm -v "$PWD:/app" -w /app composer:2 motion:test` (script `"motion:test": "@php tools/motion/php/run.php"`). Attendu : FAIL, classes absentes.
- [ ] **Step 3 : implémenter `Effects` et `Legacy`** (constantes et tableaux littéraux, aucune fonction WordPress : ces classes sont chargées sans WordPress par les tests).
- [ ] **Step 4 : relancer** `motion:test`. Attendu : tous `ok`.
- [ ] **Step 5 : CI et liste blanche.** Job `motion` dans `lint.yml` : setup PHP 8.3, `composer motion:test`, setup Node 22 (`actions/setup-node`), `node --test tools/motion/js/` (tolère l'absence du dossier jusqu'à la tâche 3 : `[ -d tools/motion/js ] && node --test tools/motion/js/ || true` remplacé par la commande nue à la tâche 3). Ajouter `includes/Modules/Motion/Legacy.php` à la liste blanche de `CLAUDE.md`.
- [ ] **Step 6 : `composer check`**, puis commit `feat(motion): effect catalog and legacy aliases`.

### Task 2: Module, réglages et écran d'administration

**Files:**
- Create : `includes/Modules/Motion/Module.php`, `includes/Modules/Motion/SettingsSanitizer.php`, `includes/Modules/Motion/settings-template.php`, `tools/motion/php/SettingsSanitizerTest.php`
- Modify : `includes/Core/Modules.php` (définition `motion`), `includes/Core/Activator.php` (`MODULE_CLASSES`), `includes/Admin/*` (`get_icon_paths()` : icône `sparkles` officielle si absente), `languages/*`

**Interfaces:**
- Consumes : `Effects::reference_values()`, `Effects::TEMPOS`, `Effects::EASES`, `Effects::EDGES`.
- Produces :
  - `Module::get_defaults(): array` = `['effects' => Effects::reference_values(), 'tempo' => 'normal', 'ease' => 'lumia', 'ease_custom' => '', 'lenis' => ['enabled' => true, 'lerp' => 0.1], 'legacy_classes' => true, 'builder_preview' => 'replay']`.
  - `SettingsSanitizer::sanitize( array $input, array $defaults ): array` (pur PHP) ; `SettingsSanitizer::is_position( string $v ): bool` (`/^[a-z0-9%+\-. ]{1,40}$/i`) ; `SettingsSanitizer::is_percent( string $v ): bool` (`/^-?\d{1,4}(\.\d{1,2})?%$/`) ; `SettingsSanitizer::parse_bezier( string $v ): ?array` (quatre nombres, `x1` et `x2` dans [0, 1], sinon `null`).
  - `Module::is_bricks_active(): bool` (`'bricks' === get_template()`).
  - Uninstall : `['options' => ['lumia_module_motion']]`.

- [ ] **Step 1 : écrire `SettingsSanitizerTest.php`** — bornes : `duration` [0, 10], `delay` [0, 10], `stagger` [0, 2], `distance` [-500, 500], `blur` [0, 100], `scale` [0, 3], `from_opacity` [0, 1], `start_value` [-1e9, 1e9], `lerp` [0.01, 1] (valeur hors bornes → bornée ; non numérique → valeur par défaut) ; `start_edge: 'middle'` → `'top'` ; `start_at: '90'` → `'98%'` (défaut) et `'90%'` gardé ; `tempo: 'exact'` refusé au niveau global → `'normal'` ; `ease: 'custom'` avec `ease_custom: 'cubic-bezier(2,0,0,1)'` → `ease: 'lumia'`, `ease_custom: ''` ; `ease_custom: 'cubic-bezier(.2, .8, .2, 1)'` gardé tel quel ; case à cocher absente → `false` ; `builder_preview: 'x'` → `'replay'` ; effet inconnu dans `effects` → ignoré ; clé de paramètre absente du catalogue pour cet effet → ignorée.
- [ ] **Step 2 : lancer `motion:test`.** Attendu : FAIL.
- [ ] **Step 3 : implémenter `SettingsSanitizer`** (aucune fonction WordPress) **et `Module`** : `init()` lit les réglages, ne pose aucun hook si Bricks n'est pas le thème actif (l'écran l'explique), sinon instancie `Controls`, `Marker`, `Assets` (tâches suivantes : laisser des appels `->hook()` ajoutés au fur et à mesure) ; `save_settings()` délègue à `SettingsSanitizer::sanitize()`.
- [ ] **Step 4 : gabarit** sur le modèle de `Login/settings-template.php` : onglets « Animations » (rythme et courbe par défaut, courbe personnalisée, tableau des valeurs de référence : une ligne par effet, seulement ses champs), « Scrolling » (Lenis actif, `lerp`), « Builder » (aperçu `replay|off`), « Compatibility » (classes `sk-*` ; bloc migration rempli à la tâche 11). Bricks absent : section unique « Bricks is not the active theme ».
- [ ] **Step 5 : enregistrer** `motion` dans `Modules.php` (`name` « Motion », `description` « Entrance and scroll animations for Bricks, adjustable per element. », `menu_label` « Motion », `menu_desc` « Animate Bricks elements », `icon` `sparkles`) et `Activator::MODULE_CLASSES`.
- [ ] **Step 6 : vérifier.** `motion:test` vert ; sur le site local, activer le module, enregistrer l'écran avec `duration` de `reveal` à `99` → relu à `10` (`wp option get lumia_module_motion --format=json`).
- [ ] **Step 7 : i18n** (`i18n:pot`, `.po` français, `i18n:mo`, `i18n:check`), `composer check`, commit `feat(motion): module settings and admin screen`.

### Task 3: Fonctions pures du moteur (`motion-core.js`)

**Files:**
- Create : `assets/front/js/motion-core.js`, `tools/motion/js/core.test.mjs`
- Modify : `.github/workflows/lint.yml` (commande `node --test tools/motion/js/` sans garde)

**Interfaces:**
- Consumes : forme du JSON de configuration (tâche 6) : `{ effects: {<slug>: {...}}, tempo, ease, easeCustom }` (clés des valeurs de référence en `snake_case`, comme en PHP).
- Produces (`window.lumiaMotionCore` dans le navigateur, `module.exports` sous Node) :
  - `bezier(x1, y1, x2, y2): (t: number) => number` — mis en cache par clé.
  - `spring(bounce: number): { ease: (p: number) => number, settle: number }` — mis en cache par rebond.
  - `resolveEase(keyword: string, custom: string, family: string, scrub: boolean): { key: string, ease: Function, safeEase: Function, settle: number, fallback: boolean }` — `safeEase` = variante sans dépassement (opacité, flou).
  - `tempoFactor(keyword: string): number | null` (`exact` → `null`, inconnu → 1).
  - `resolve(slug: string, raw: Record<string, string>, config: object): Options` — `raw` : valeurs calculées des variables moteur, clés sans `--lm-` (`'start-edge'`, `'from-opacity'`…). `Options` = `{ effect, family, scrub, start, end, duration, tweenDuration, delay, stagger, easeKey, ease, safeEase, distance, blur, scale, fromOpacity, startValue, warnings: string[] }`.
  - `signature(o: Options): string`.
  - `parseCounter(text: string): { prefix, value, decimals, group, decimal, suffix } | null` ; `formatCounter(value: number, fmt): string`.

- [ ] **Step 1 : écrire `core.test.mjs`** (`node:test` + `node:assert/strict`, chargement par `createRequire`) :
  - `bezier(0,0,1,1)(t) ≈ t` (1e-6) ; `bezier(.25,.1,.25,1)(0.5) ≈ 0.8024` (1e-3) ; `bezier(.625,.05,0,1)` vaut 0 en 0, 1 en 1, croissante sur 100 points.
  - `spring(0)` : `ease(1) === 1`, maximum ≤ 1.0005, `settle` dans [1.4, 1.55] ; `spring(0.15)` : maximum dans [1.003, 1.01] ; `spring(0.3)` : maximum dans [1.04, 1.05].
  - `resolveEase('bouncy','', 'counter', false).key === 'smooth'` ; `resolveEase('snappy','', 'enter', true).key === 'linear'` (scrub) ; `resolveEase('custom','cubic-bezier(2,0,0,1)','enter',false)` → `key === 'lumia'`, `fallback === true` ; `resolveEase('nope', …).key === config.ease` par défaut ; pour `bouncy`, `safeEase` ne dépasse jamais 1.
  - `resolve('reveal', {tempo:'fast'}, CFG)` : `duration ≈ 0.455`, `stagger ≈ 0.07`, `delay === 0` ; `{tempo:'exact', duration:'1.2'}` → `1.2` ; `{tempo:'exact', duration:''}` → `0.65` ; `{duration:'abc', tempo:'exact'}` → `0.65` ; `{tempo:'slow', delay:'0.3'}` → `delay === 0.3`.
  - Positions : `{start:'top 50%'}` → `'top 50%'` ; `{'start-edge':'center','start-at':'80%'}` → `'center 80%'` ; `{'start-at':'80'}` → `'top 80%'` ; `{start:'top 50%; alert(1)'}` → `'top 98%'` et un avertissement ; `resolve('text-words', {}, CFG).end === 'top -120%'`, `.scrub === true`.
  - `tweenDuration === duration * settle` pour `smooth`, `=== duration` pour `lumia`.
  - `signature` identique pour deux `raw` identiques, différente si `tempo` diffère.
  - Compteur : `'2024'` → `{value:2024, group:'', decimals:0}` et `formatCounter(2024, fmt) === '2024'` ; `'1 200'` → groupe espace ; `'1 200,50 €'` → `{value:1200.5, group:' ', decimal:',', decimals:2, suffix:' €'}` ; `'1,500'` → groupe `,` ; `'98,5 %'` → décimale `,` ; `'1.500,75'` → groupe `.`, décimale `,` ; `'+150 clients'` → `prefix:'+'`, `suffix:' clients'` ; `'24/7'` → `null` ; `'abc'` → `null` ; pour chaque cas non nul, `prefix + formatCounter(value, fmt) + suffix === texte d'origine`.
- [ ] **Step 2 : lancer** `node --test tools/motion/js/`. Attendu : FAIL (module absent).
- [ ] **Step 3 : implémenter `motion-core.js`.** Enveloppe UMD sans dépendance. Bézier : algorithme UnitBezier (Newton 8 itérations, repli par dichotomie, epsilon 1e-6). Ressort, paramétrage SwiftUI (durée perçue = 1) :

  ```
  ω0 = 2π ; ζ = 1 − bounce
  ζ < 1 : ωd = ω0·√(1−ζ²) ; x(τ) = 1 − e^(−ζω0τ)·(cos ωdτ + (ζω0/ωd)·sin ωdτ)
  ζ = 1 : x(τ) = 1 − e^(−ω0τ)·(1 + ω0τ)
  settle = plus petit τ où l'enveloppe passe sous 0,001 :
           ζ < 1 : e^(−ζω0τ)·√(1 + (ζω0/ωd)²) ; ζ = 1 : e^(−ω0τ)·(1 + ω0τ)
  ease(p) = x(p·settle), 240 échantillons + interpolation linéaire, ease(1) = 1
  ```

  Résolution d'une valeur : `raw` (si valide) → `config.effects[slug]` → repli interne (`duration` 0.6, `stagger` 0.08, `delay` 0, `distance` 40, `blur` 10, `scale` 0.9, `from_opacity` 0.1, `start_value` 0). Rythme : multiplie `duration` et `stagger`, jamais `delay` ; `raw.tempo` vide → `config.tempo`. Courbe : `raw.ease` vide → `config.ease` (et `config.easeCustom`). Compteur : une seule suite numérique (`/\d[\d.,\s  ']*\d|\d/`), sinon `null` ; si le texte restant contient un chiffre → `null` ; séparateurs : deux sortes → la dernière est décimale ; une seule sorte, une seule occurrence suivie d'exactement 3 chiffres → groupe ; espaces → groupe ; sinon décimale.
- [ ] **Step 4 : relancer** `node --test tools/motion/js/`. Attendu : tout passe. Retirer la garde du job `motion` dans `lint.yml`.
- [ ] **Step 5 : commit** `feat(motion): pure easing, spring, settings and counter helpers`.

### Task 4: Panneau Bricks (`Controls`)

**Files:**
- Create : `includes/Modules/Motion/Controls.php`, `tools/e2e/motion/assert-controls.php`, `tools/e2e/motion/scenarios/builder-panel.mjs`
- Modify : `includes/Modules/Motion/Module.php`, `tools/phpstan-bootstrap.php` (stubs Bricks), `languages/*`

**Interfaces:**
- Consumes : `Effects::catalog()`, `Effects::TEMPO_KEYWORDS`, `Effects::EASES`, `Effects::EDGES`.
- Produces :
  - `Controls::hook(): void` (`add_action( 'init', [ $this, 'register' ], 100 )`) ; `Controls::register(): void` (pour chaque `array_keys( \Bricks\Elements::$elements )` : filtres `control_groups`, `controls`, `scripts`).
  - `Controls::definitions(): array<string, array<string, mixed>>` (statique) — clés `_lmEffect`, `_lmStartEdge`, `_lmStartAt`, `_lmEndEdge`, `_lmEndAt`, `_lmAdvancedSep`, `_lmStart`, `_lmEnd`, `_lmTempo`, `_lmDuration`, `_lmDelay`, `_lmStagger`, `_lmEase`, `_lmEaseCustom`, `_lmParamsSep`, `_lmDistance`, `_lmBlur`, `_lmScale`, `_lmFromOpacity`, `_lmStartValue`, `_lmUnderlineThickness`, `_lmUnderlineGap`, `_lmUnderlineColor`, `_lmHelp`.
  - `Controls::variable_map(): array<string, string>` (clé de contrôle → variable CSS), utilisé par `Marker`.
  - Script de builder déclaré : `'lumiaMotionBuilder'`.

- [ ] **Step 1 : écrire `assert-controls.php`** (lancé par `wp eval-file`) : après `init`, `apply_filters( 'bricks/elements/heading/controls', [] )` contient les 24 clés ; `_lmEffect['css'][0]['property'] === '--lm-effect'`, `'tab' === 'style'`, `'group' === 'lm_motion'` ; `_lmHelp` n'a pas de clé `css` ; même chose pour `section` et pour un élément enregistré par un code tiers dans le test (`\Bricks\Elements::register_element()` d'une classe minimale déclarée par le test avant `init` 100, ou à défaut le dernier nom de `Elements::$elements`) ; `apply_filters( 'bricks/elements/heading/scripts', [] )` contient `lumiaMotionBuilder`.
- [ ] **Step 2 : lancer** `$LM_E2E_WP eval-file tools/e2e/motion/assert-controls.php`. Attendu : FAIL.
- [ ] **Step 3 : implémenter `Controls`.** Chaque contrôle porteur d'une variable : `'tab' => 'style'`, `'group' => 'lm_motion'`, `'css' => [ [ 'property' => '--lm-…', 'selector' => '' ] ]`. Types : `_lmEffect` `select` (options : vignette `<span class="lm-thumb lm-thumb--<slug>"></span>` + libellé, plus `none` « Off ») ; repères `select` ; `_lmStartAt` / `_lmEndAt` `number` avec `'unit' => '%'` ; `_lmStart` / `_lmEnd` / `_lmEaseCustom` `text` (placeholder `top 90%`, `cubic-bezier(0.2, 0.8, 0.2, 1)`) ; durée, délai, décalage, échelle, opacité, départ `number` `'unitless' => true` ; `_lmDuration` `required` `['_lmTempo', '=', 'exact']` ; `_lmEaseCustom` `required` `['_lmEase', '=', 'custom']` ; `_lmUnderlineGap` `number` avec unités ; `_lmUnderlineColor` `color` ; `_lmHelp` `info` : « One effect per element: with two Motion classes, the first one wins. A desktop value also applies to mobile unless mobile has its own. On the main heading above the fold, prefer a text effect (LCP). » Paramètres d'effet sans `required` sur l'effet (la condition ne voit pas l'effet d'une classe, cf. spec), sous le séparateur « Effect settings ». Stubs PHPStan : `\Bricks\Elements::$elements`, `\Bricks\Elements::register_element()`, `\Bricks\Helpers::save_global_classes_in_db()`, `\Bricks\Helpers::get_global_element()`, `\Bricks\Query::is_looping()`, `\Bricks\Database::$global_data`, `bricks_is_builder()`, `bricks_is_builder_main()`, `bricks_is_builder_iframe()`, `\WP_CLI::runcommand()`.
- [ ] **Step 4 : relancer l'assertion.** Attendu : `ok`.
- [ ] **Step 5 : écrire et lancer `builder-panel.mjs`** (reprend `b1`–`b6` du prototype) : builder ouvert sur `lm-e2e-smoke`, titre sélectionné, onglet Style, groupe « Motion » visible ; saisir `40` dans `[data-controlkey="_lmStartAt"] input` → dans le canvas, `getComputedStyle(titre).getPropertyValue('--lm-start-at')` vaut `40%` en moins de 500 ms ; choisir `Exact duration` dans `_lmTempo` → le champ `_lmDuration` apparaît. Attendu : `ok builder-panel`.
- [ ] **Step 6 : i18n, `composer check`, commit** `feat(motion): Motion panel on every Bricks element`.

### Task 5: Marquage `data-lm` et style inline (`Marker`)

**Files:**
- Create : `includes/Modules/Motion/Marker.php`, `tools/motion/php/MarkerTest.php`, `tools/e2e/motion/scenarios/marker.mjs`
- Modify : `includes/Modules/Motion/Module.php`, `tools/e2e/motion/fixtures.mjs`

**Interfaces:**
- Consumes : `Effects::catalog()`, `Effects::slug_for_class()`, `Controls::variable_map()`, réglage `legacy_classes`.
- Produces :
  - `Marker::families( array $element_settings, array $classes, bool $legacy ): string[]` (pur ; `$classes` = liste de `['name' => string, 'settings' => array]` ; ordre fixe `enter`, `text`, `counter`, `underline`).
  - `Marker::inline_style( array $element_settings ): string` (pur ; valeurs de base seulement, toute clé contenant `:` ignorée ; valeur refusée si elle contient autre chose que `[A-Za-z0-9%#(),.\- ]`).
  - `Marker::uses_class_selector( array $element ): bool` — `cid` ou `parentComponent` non vides, `\Bricks\Helpers::get_global_element( $element, 'global' )`, ou `\Bricks\Query::is_looping()`.
  - `Marker::hook(): void` (filtre `bricks/element/render_attributes`, 10, 3) ; `Marker::page_families(): string[]` (familles vues pendant le rendu de la requête courante).

- [ ] **Step 1 : écrire `MarkerTest.php`** — `['_lmEffect' => 'reveal']` → `['enter']` ; `['_lmEffect:mobile_portrait' => 'text-words']` + classe `lm-fade` → `['enter','text']` ; `['_lmEffect' => 'none']` → `[]` ; classe `sk-counter`, legacy vrai → `['counter']`, legacy faux → `[]` ; classe `titre-hero` dont les réglages portent `_lmEffect: scale-in` → `['enter']` ; `inline_style(['_lmEndAt' => '10%', '_lmTempo' => 'fast', '_lmEndAt:mobile_portrait' => '5%'])` === `'--lm-end-at:10%;--lm-tempo:fast;'` ; valeur `'top 10%;} body{display:none'` → ignorée.
- [ ] **Step 2 : lancer `motion:test`.** Attendu : FAIL.
- [ ] **Step 3 : implémenter `Marker`.** Filtre : clé `_root` seulement ; classes lues une fois par requête dans `\Bricks\Database::$global_data['globalClasses']` (repli `get_option( 'bricks_global_classes', [] )`), indexées par ID ; `data-lm` = familles jointes par une espace, si non vide ; si `uses_class_selector()` et `inline_style()` non vide, ajout à `$attributes['_root']['style']` (chaîne ou tableau existants conservés). Enregistrer les familles vues pour `page_families()`.
- [ ] **Step 4 : relancer `motion:test`.** Attendu : vert.
- [ ] **Step 5 : pages de test et `marker.mjs`.** `fixtures.mjs` ajoute `lm-e2e-marker` : un titre `lm-fade`, un titre `_lmEffect: text-words`, un texte `sk-counter` (classe `sk-counter` créée par le setup si absente), une boucle de requête (3 articles) dont le titre porte la classe `lm-e2e-preset` (réglages de classe `_lmStartAt: 60%`) et `_lmStartAt: 30%` sur l'élément. Assertions : `data-lm` vaut `enter`, `text`, `counter` ; dans la boucle, `--lm-start-at` calculé vaut `30%` sur chaque titre (la surcharge gagne) ; sur un titre hors boucle avec la même classe et `_lmStartAt: 30%`, idem par spécificité.
- [ ] **Step 6 : ajuster la spec si un cas réel diffère** (déjà corrigée pour les boucles), `composer check`, commit `feat(motion): data-lm marker and inline overrides for components and loops`.

### Task 6: Chargement, CSS et fichiers tiers (`Assets`)

**Files:**
- Create : `includes/Modules/Motion/Assets.php`, `assets/front/css/motion.css`, `assets/front/js/vendor/gsap/{gsap.min.js,ScrollTrigger.min.js,SplitText.min.js,LICENSE…}`, `assets/front/js/vendor/lenis/{lenis.min.js,lenis.css,LICENSE}`, `assets/front/js/motion.js` (squelette : garde de double chargement, `lm-ready`, `window.lumiaMotion` vide), `tools/e2e/motion/scenarios/loading.mjs`
- Modify : `includes/Modules/Motion/Module.php`, `tools/e2e/motion/fixtures.mjs`

**Interfaces:**
- Consumes : `Marker::page_families()`, réglages du module, `Legacy::underline_css()`, `Legacy::CLASS_ALIASES`, `Legacy::ENGINE_GLOBAL`.
- Produces :
  - Handles : `lumia-gsap`, `lumia-scrolltrigger`, `lumia-splittext`, `lumia-lenis`, `lumia-motion-core`, `lumia-motion`, `lumia-motion-builder`, `lumia-motion-scroll` (scripts) ; `lumia-motion`, `lumia-lenis`, `lumia-motion-builder` (styles). Tous depuis `LUMIA_ASSETS_URL . 'front/…'`, version `LUMIA_VERSION`, scripts en pied de page avec `'strategy' => 'defer'`.
  - `window.lumiaMotionConfig` (inline `before` sur `lumia-motion`) : `{ effects, tempo, ease, easeCustom, classes: {<lm-class>: <slug>}, aliases: {<sk-class>: <slug>} | {}, legacyGlobal, builder: bool, preview: 'replay'|'off', variables: ENGINE_VARIABLES }`.
  - Classes sur `<html>` : `lm-js` (script inline du `<head>`), `lm-ready` (moteur prêt ou sécurité à 3000 ms).

- [ ] **Step 1 : écrire `loading.mjs`** (pages `lm-e2e-none` sans animation, `lm-e2e-basic` avec un titre `lm-fade`, `lm-e2e-text` avec `text-words`) : sur `lm-e2e-none`, aucune requête de script contenant `gsap`, `ScrollTrigger`, `SplitText` ou `motion.js`, et `window.gsap` indéfini ; sur `lm-e2e-basic`, `gsap` et `ScrollTrigger` chargés, pas `SplitText` ; sur `lm-e2e-text`, `SplitText` chargé ; sur toutes, aucune requête vers un autre hôte que celui de `LM_E2E_URL` ; `motion.js` bloqué par `page.route(… abort)` → après 3,2 s, `<html>` a `lm-ready` et chaque `[data-lm]` a une opacité calculée de 1 ; dans la fenêtre principale du builder, `lumia-motion` absent et `motion-builder.css` présent ; snippet « SK Motion » réactivé le temps du test → avertissement console du module, `lumiaMotion._debug` absent ou vide, `lm-ready` posé, puis snippet désactivé à nouveau.
- [ ] **Step 2 : lancer** `run.sh loading`. Attendu : FAIL.
- [ ] **Step 3 : fichiers tiers.** Télécharger les deux archives npm, copier les fichiers listés et le fichier de licence de chaque paquet tels quels, noter leurs SHA-256 (pour `docs/modules/motion.md`, tâche 12).
- [ ] **Step 4 : `motion.css`.** Une règle `@property --lm-<nom> { syntax: '*'; inherits: false; }` par variable moteur ; `html { scroll-behavior: auto !important; }` ; `@media (prefers-reduced-motion: no-preference) { .lm-js:not(.lm-ready) [data-lm~="enter"] { opacity: 0; } }` ; `html:not(.lenis) [id] { scroll-margin-top: var(--navbar-height, 0px); }` ; soulignement sur `.lm-underline, [data-lm~="underline"]` (même rendu que l'ancien, variables `--lm-underline-*` avec les défauts des Global Constraints, `transition` coupée en mouvement réduit) ; `[data-lm~="counter"] { font-variant-numeric: tabular-nums; }` ; `.lm-line { display: block; }` ; `.lm-sr` (texte masqué visuellement, accessible).
- [ ] **Step 5 : `Assets`.** `wp_enqueue_scripts` : rien si `is_admin()` ; fenêtre principale du builder → seulement `motion-builder.css` ; sinon `motion.css` (+ `Legacy::underline_css()` en style inline si la compatibilité est active) et, si Lenis est actif, `lenis.css` + `motion-scroll.js` (tâche 9). `wp_head` priorité 1 : `wp_print_inline_script_tag()` avec `lm-js` + `setTimeout` 3000 ms → `lm-ready`. `wp_footer` priorité 19 (après le rendu des popups Bricks, priorité 10, avant les scripts, priorité 20) : si `page_families()` est non vide ou si on est dans le canvas du builder → core + GSAP + ScrollTrigger + moteur, SplitText si `text` est présent (toujours dans le canvas), builder JS dans le canvas ; configuration JSON par `wp_add_inline_script()`.
- [ ] **Step 6 : squelette `motion.js`** : sortie immédiate si `window.lumiaMotion` existe ; si `window[config.legacyGlobal]` existe, `console.warn` (le snippet doit être retiré), ajout de `lm-ready`, sortie ; sinon ajoute `lm-ready` au démarrage. Le reste arrive à la tâche 7.
- [ ] **Step 7 : relancer `run.sh loading`.** Attendu : `ok loading`.
- [ ] **Step 8 : `composer check`, commit** `feat(motion): conditional loading, front stylesheet and bundled GSAP/Lenis`.

### Task 7: Moteur — effets d'entrée et cycle de vie

**Files:**
- Modify : `assets/front/js/motion.js`, `tools/e2e/motion/fixtures.mjs`
- Create : `tools/e2e/motion/scenarios/enter.mjs`, `tools/e2e/motion/scenarios/scroll-position.mjs`

**Interfaces:**
- Consumes : `lumiaMotionCore.resolve()`, `.signature()`, `window.lumiaMotionConfig`.
- Produces : `window.lumiaMotion = { refresh(): void, scan(root?: Element|Document): void, destroy(): void, _debug: { items(): Array<{ id: string, effect: string, signature: string, options: Options, state: 'pending'|'playing'|'done'|'final' }> } }`. `_debug` existe toujours (lecture seule, utilisé par les scénarios).

- [ ] **Step 1 : pages de test.** `lm-e2e-basic` (hauteur ≥ 6000 px, `--navbar-height` défini par le thème) : 4 titres `lm-e2e-preset` consécutifs (classe : `_lmEffect: reveal`, `_lmStartAt: 60%`) dont un avec `_lmStartAt: 30%`, un avec `_lmStartAt:mobile_portrait: 80%`, un avec `_lmTempo: fast` + `_lmEase: bouncy` ; une section `lm-fade` contenant un titre sans réglage ; un élément `_lmEffect: blur-in` + `_lmEffect:mobile_portrait: none` ; un élément avec les classes `lm-fade` puis `lm-reveal` ; un élément `_lmEffect: blur-in` dont la méta porte aussi directement `_lmTempo: exact`, `_lmDuration: abc`, `_lmStart: foo;bar`, `_lmEase: custom`, `_lmEaseCustom: cubic-bezier(2,0,0,1)` ; un élément `sk-fade`. `lm-e2e-anchor` : cible `#target` à 3000 px, et un lien vers `lm-e2e-anchor#target` sur `lm-e2e-basic`.
- [ ] **Step 2 : écrire `enter.mjs`** (1440 et 390 px) : options du titre à `30%` → `start === 'top 30%'`, des autres → `'top 60%'`, à 390 px celui en `mobile_portrait` → `'top 80%'` ; le titre `fast`/`bouncy` a `duration ≈ 0.455`, `easeKey === 'bouncy'` et une signature distincte de ses voisins ; pendant son animation (échantillon à chaque `requestAnimationFrame`), `style.opacity ≤ 1` et aucun `blur(-` dans `style.filter` ; *nested* : le titre de la section n'a pas d'item et son `--lm-effect` calculé est vide ; à 390 px, l'élément `none` n'a pas d'item et son opacité est 1 ; double classe → effet `fade` et, avec `?lmDebug`, un message console qui le dit ; *invalid* : item avec `duration === 0.8`, `start === 'top 98%'`, `easeKey === 'lumia'`, aucune `pageerror`, opacité 1 après défilement ; `sk-fade` animé, puis compatibilité coupée (`wp option patch update lumia_module_motion legacy_classes 0`) → pas d'item ; *ajax* : insertion d'un `<div data-lm="enter" style="--lm-effect: fade">` puis `document.dispatchEvent(new CustomEvent('bricks/ajax/query_result/displayed'))` → item créé, opacité 1 une fois défilé dans l'écran ; retrait d'un élément animé du DOM puis `lumiaMotion.refresh()` → plus d'item pour lui ; *reduced* : contexte `reducedMotion: 'reduce'` → aucun item en `pending`, aucun `transform`/`opacity` inline, tout visible ; 2 s après chargement, aucun `[data-lm]` dans l'écran sous une opacité de 0.5.
- [ ] **Step 3 : écrire `scroll-position.mjs`** (1440 et 390 px, avec et sans `injectSmoothScroll`) : défilement à 3000, rechargement → `scrollY` à ±5 px, chaque item au-dessus de l'écran en état `final` et opacité 1 ; navigation vers une autre page puis retour arrière → même position ; lien vers `lm-e2e-anchor#target` depuis `lm-e2e-basic` → `#target` à `--navbar-height` ±4 px du haut.
- [ ] **Step 4 : lancer** `run.sh enter scroll-position`. Attendu : FAIL.
- [ ] **Step 5 : implémenter le moteur.** Démarrage : `DOMContentLoaded`, puis `Promise.race([document.fonts.ready, 1500 ms])`. `gsap.matchMedia()` : branche `(prefers-reduced-motion: no-preference)` = construction normale (retourne le nettoyage), branche `reduce` = rien d'animé. Candidats : `[data-lm]` ; effet = `--lm-effect` calculé, sinon première classe de `classList` présente dans `config.classes` ou `config.aliases` ; `none` ou vide → ignoré (et rendu visible). Chaque construction dans un `try/catch` : en cas d'erreur, état final et `console.error` en debug. État de départ par `gsap.set` (`fade` : `opacity 0` ; `reveal` : + `y: distance` ; `blur-in` : + `filter: blur(Npx)` ; `scale-in` : `scale` ; `scale-blur-in` : `scale` + flou). Élément dont `getBoundingClientRect().bottom < 0` au démarrage → état final, sans animation. Les autres : regroupés par signature, un `ScrollTrigger.batch( groupe, { start, once: true, onEnter } )` par groupe ; `onEnter` lance deux tweens : transformations avec `ease` et `tweenDuration`, opacité et flou avec `safeEase`, même `delay` et même `stagger` ; `will-change` posé au départ, `clearProps` des propriétés animées à la fin. Puis `lm-ready` et `ScrollTrigger.refresh()`. `refresh()` : relit les variables, reconstruit les items dont la signature a changé (redimensionnement regroupé à 200 ms, changement de breakpoint), détruit ceux dont l'élément n'est plus dans le DOM. Événements Bricks (`bricks/ajax/query_result/displayed`, `bricks/ajax/load_page/completed`, `bricks/ajax/pagination/completed`, `bricks/ajax/popup/loaded`) → `scan(document)` ; `bricks/popup/open` → `ScrollTrigger.refresh()`. `?lmDebug` : `markers: true` et journal des éléments ignorés.
- [ ] **Step 6 : relancer** `run.sh enter scroll-position`. Attendu : `ok` partout.
- [ ] **Step 7 : commit** `feat(motion): entrance effects engine with per-element settings`.

### Task 8: Moteur — effets de texte et compteur

**Files:**
- Modify : `assets/front/js/motion.js`, `tools/e2e/motion/fixtures.mjs`
- Create : `tools/e2e/motion/scenarios/text.mjs`, `tools/e2e/motion/scenarios/counter.mjs`

**Interfaces:**
- Consumes : `Options` de la tâche 3 (`scrub`, `end`, `fromOpacity`, `startValue`), `parseCounter()` / `formatCounter()`, items de la tâche 7.

- [ ] **Step 1 : pages de test.** `lm-e2e-text` : un `h2` et un `p` `lm-text-lines`, un `p` long `lm-text-words`, un `h3` `lm-text-chars`, chacun séparé par 1200 px. `lm-e2e-counter` : textes `lm-counter` « 2024 », « 1 200 », « 98,5 % », « +150 clients », « 24/7 », « <strong>350</strong> projets », un « 100 » avec `_lmEase: bouncy`.
- [ ] **Step 2 : écrire `text.mjs`** : `text-lines` → pendant l'animation des `.lm-line` existent, à la fin `innerHTML` est identique au HTML d'origine (masque retiré) ; sur le `p`, un nom accessible égal au texte d'origine (`page.accessibility.snapshot()` ou `.lm-sr`), sur le `h2` le texte lu reste le titre ; `text-words` → opacité du premier mot ≈ 0.08 avant le début, 1 après la fin ; `page.setViewportSize` (390 puis 1440) puis défilement → les nouveaux mots suivent toujours le défilement (l'animation est recréée dans `onSplit`) ; `will-change` absent des mots hors de la zone active.
- [ ] **Step 3 : écrire `counter.mjs`** : à la fin, chaque texte est **exactement** l'original ; « 24/7 » ne change à aucun moment (échantillons à chaque frame) ; « 100 » en `bouncy` ne dépasse jamais 100 ; `<strong>` conservé ; mouvement réduit → valeurs finales dès le chargement.
- [ ] **Step 4 : lancer** `run.sh text counter`. Attendu : FAIL.
- [ ] **Step 5 : implémenter.** Texte : `SplitText.create( el, { type, autoSplit: true, … , onSplit( self ) { return <tween ou timeline>; } } )`. `text-lines` : `type: 'lines'`, `mask: 'lines'`, `linesClass: 'lm-line'`, `from` `yPercent: distance`, `once`, `self.revert()` à la fin. Scrubs : `type: 'words'` (ou `'words, chars'`), opacité de départ par `gsap.set`, `to { opacity: 1, ease: 'none', stagger, scrollTrigger: { trigger: el, start, end, scrub: true, invalidateOnRefresh: true, onToggle: will-change on/off } }`. ARIA : `aria: 'auto'` sur `h1`–`h6` ; ailleurs, lire dans `SplitText.js` 3.15 ce que fait `aria: 'hidden'` ; s'il masque le texte aux lecteurs d'écran, ajouter avant le découpage une copie `<span class="lm-sr">` du texte d'origine et poser `aria-hidden` sur le contenu découpé. Élément déjà dépassé : aucun découpage. Compteur : parcours des nœuds texte ; un seul nœud contenant un chiffre → `parseCounter` sur ce nœud, sinon ignoré (journal debug) ; tween d'un objet de `startValue` à `value`, `duration`/`ease` résolus (le cœur remplace déjà `snappy`/`bouncy` par `smooth`), `onUpdate` écrit `prefix + formatCounter + suffix`, `onComplete` remet le texte d'origine.
- [ ] **Step 6 : relancer** `run.sh text counter enter`. Attendu : `ok` partout.
- [ ] **Step 7 : commit** `feat(motion): text and counter effects`.

### Task 9: Défilement doux (`motion-scroll.js`)

**Files:**
- Create : `assets/front/js/motion-scroll.js`, `tools/e2e/motion/scenarios/lenis.mjs`
- Modify : `includes/Modules/Motion/Assets.php`

**Interfaces:**
- Consumes : réglages `lenis.enabled`, `lenis.lerp` (inline `before` : `window.lumiaMotionScroll = { lerp }`), handles de la tâche 6.
- Produces : `window.lumiaLenis` (instance Lenis ou absent). `Assets` charge `motion-scroll.js` sur **toutes** les pages publiques si Lenis est actif, avec `lumia-lenis` en dépendance, plus `lumia-gsap` et `lumia-scrolltrigger` quand la page charge aussi le moteur.

- [ ] **Step 1 : écrire `lenis.mjs`** (1440 px, Lenis actif) : sur `lm-e2e-none` (sans animation), `window.lumiaLenis` existe et `window.gsap` est indéfini ; sur `lm-e2e-basic`, la molette fait défiler et `ScrollTrigger` reçoit les mises à jour (un item `pending` passe en `done`) ; clic sur un lien `#target` de la même page → `#target` à `--navbar-height` ±4 px ; rechargement au milieu → position gardée ; `document.dispatchEvent(new CustomEvent('bricks/popup/open'))` → `lumiaLenis.isStopped === true`, puis `bricks/popup/close` → `false` ; *popup* : une popup Bricks réelle (modèle de type popup créé par le setup, ouverte par un bouton) contenant un titre `lm-fade` → titre visible (opacité 1) après ouverture. Lenis coupé dans les réglages → `window.lumiaLenis` absent, ancre depuis une autre page respecte `scroll-margin-top`.
- [ ] **Step 2 : lancer** `run.sh lenis`. Attendu : FAIL.
- [ ] **Step 3 : implémenter.** `new Lenis({ lerp, autoRaf: !window.gsap, syncTouch: false, allowNestedScroll: true, stopInertiaOnNavigate: true, anchors: { offset: -navbarHeight() } })` ; avec GSAP : `lenis.on('scroll', ScrollTrigger.update)`, `gsap.ticker.add(t => lenis.raf(t * 1000))`, `gsap.ticker.lagSmoothing(0)`. `navbarHeight()` : hauteur mesurée d'un élément sonde `height: var(--navbar-height, 0px)` (la variable peut être un `clamp()` ou des `rem`). `data-lenis-prevent` sur `.brx-popup` ; `bricks/popup/open` → `stop()`, `close` → `start()`.
- [ ] **Step 4 : relancer** `run.sh lenis scroll-position`. Attendu : `ok`.
- [ ] **Step 5 : `composer check`, commit** `feat(motion): Lenis smooth scrolling on every page when enabled`.

### Task 10: Builder (`motion-builder.js`, vignettes)

**Files:**
- Create : `assets/front/js/motion-builder.js`, `assets/front/css/motion-builder.css`, `tools/e2e/motion/scenarios/builder-preview.mjs`
- Modify : `assets/front/js/motion.js` (mode builder), `includes/Modules/Motion/Assets.php`

**Interfaces:**
- Consumes : `lumiaMotion.scan()`, `_debug.items()`, `config.builder`, `config.preview`.
- Produces : `window.lumiaMotionBuilder` (fonction appelée par Bricks via le filtre `scripts`) ; `lumiaMotion._debug.stats(): { scans: number, replays: number }`.

- [ ] **Step 1 : écrire `builder-preview.mjs`** : builder ouvert sur `lm-e2e-basic` → au chargement, `stats().scans ≤ 3` malgré ~180 appels de `lumiaMotionBuilder` ; tous les éléments animés à l'état final (opacité 1) ; changement de `_lmStartAt` d'un titre dans le panneau → `replays` +1 en moins de 500 ms, puis opacité revenue à 1 ; ajout d'un nouveau titre puis choix de `Reveal` dans `_lmEffect` → item créé sans `data-lm` (détection par `.brxe-*`) et rejoué ; réglage `builder_preview: off` → `replays` reste à 0 ; la liste de `_lmEffect` affiche les vignettes animées (capture `tools/e2e/motion/out/effects-list.png` pour contrôle visuel).
- [ ] **Step 2 : lancer** `run.sh builder-preview`. Attendu : FAIL.
- [ ] **Step 3 : implémenter.** Mode builder du moteur : aucun pré-masquage, aucun `ScrollTrigger`, état final par défaut. `motion-builder.js` : `lumiaMotionBuilder` regroupé à 120 ms → `scan()` ; candidats en plus de `[data-lm]` : `.brxe-*` dont `--lm-effect` calculé est non vide ; `MutationObserver` sur `document.head` (`childList`, `characterData`, `subtree`) et `document.body` (`childList`, `subtree`), regroupé à 120 ms → relit les signatures ; signature changée d'un item déjà connu et `preview === 'replay'` → rejeu unique (effets d'entrée depuis l'état de départ ; scrubs de 0 à 1 en 1,2 s ; compteur de `startValue` à la valeur), puis état final. `motion-builder.css` : `.lm-thumb` 14×14 px, une animation `@keyframes` par effet, coupée en mouvement réduit.
- [ ] **Step 4 : relancer** `run.sh builder-preview builder-panel`. Attendu : `ok`.
- [ ] **Step 5 : commit** `feat(motion): live preview and effect thumbnails in the builder`.

### Task 11: Classes `lm-*`, migration et commande WP-CLI

**Files:**
- Create : `includes/Modules/Motion/ClassManager.php`, `includes/Modules/Motion/Command.php`, `tools/motion/php/ClassManagerTest.php`, `tools/e2e/motion/assert-migration.sh`
- Modify : `includes/Modules/Motion/Module.php` (`on_activate()`, commande, bloc migration de l'écran), `languages/*`

**Interfaces:**
- Consumes : `Effects::catalog()`, `Legacy::CLASS_ALIASES`, `Legacy::new_class_name()`, `Legacy::SNIPPET_NAME`, options Bricks `bricks_global_classes`, `bricks_global_classes_categories`, `bricks_global_classes_locked`.
- Produces :
  - `ClassManager::plan_creation( array $classes ): string[]` (pur) — noms `lm-*` absents **et** dont l'équivalent `sk-*` est absent.
  - `ClassManager::plan_migration( array $classes ): array{ rename: array<string, array{from: string, to: string}>, conflicts: string[] }` (pur ; clé = ID de classe).
  - `ClassManager::rewrite_class_css( array $settings, string $from, string $to ): array` (pur ; remplace `.from` par `.to` en mot entier dans toute clé commençant par `_cssCustom`).
  - `ClassManager::create_missing(): string[]` ; `ClassManager::migrate( bool $apply ): array{ renamed: string[], conflicts: string[], elements: int, posts: int, css_hits: string[] }` ; `ClassManager::legacy_usage(): array{ classes: array<string, string>, elements: int, posts: int, css_hits: string[] }`.
  - `wp lumia motion classes` ; `wp lumia motion migrate [--apply]` (`--dry-run` par défaut).

- [ ] **Step 1 : écrire `ClassManagerTest.php`** — `plan_creation` sur `[sk-fade, lm-reveal]` → contient `lm-blur-in`, ne contient ni `lm-fade` ni `lm-reveal` ; `plan_migration` sur `[ {id: a, name: sk-fade}, {id: b, name: sk-reveal}, {id: c, name: lm-reveal} ]` → `rename = { a: {from: sk-fade, to: lm-fade} }`, `conflicts = [sk-reveal]` ; `rewrite_class_css` → `.sk-fade:hover{}` devient `.lm-fade:hover{}`, `.sk-fade-extra` inchangé, clés `_cssCustom:mobile_portrait` traitées.
- [ ] **Step 2 : lancer `motion:test`.** Attendu : FAIL.
- [ ] **Step 3 : implémenter.** `create_missing()` : rien si Bricks n'est pas actif ; catégorie « Motion » trouvée ou créée dans `bricks_global_classes_categories` ; nouvelles classes `['id' => <6 lettres a-z uniques>, 'name' => …, 'settings' => [], 'category' => <id>]`, enregistrées par `\Bricks\Helpers::save_global_classes_in_db()`, IDs ajoutés à `bricks_global_classes_locked`. `migrate()` : `legacy_usage()` parcourt les métas `_bricks_page_content_2`, `_bricks_page_header_2`, `_bricks_page_footer_2` (éléments dont `_cssGlobalClasses` contient un ID `sk-*`, occurrences de `.sk-` ou `--sk-hover-underline` dans les `_cssCustom*`), les réglages de classes, le CSS global de Bricks et les fichiers `wp-content/fluent-snippet-storage/*.php` (signalés seulement) ; `--apply` : renommage, catégorie « Motion », `rewrite_class_css()`, enregistrement, puis `\WP_CLI::runcommand( 'bricks regenerate_assets' )`, et rappel : désactiver le snippet `Legacy::SNIPPET_NAME`, vérifier le site, couper la compatibilité. `Command` : sorties `\WP_CLI::log()` en tableau lisible, conflit → `\WP_CLI::warning()`. `on_activate()` → `create_missing()`. Écran (onglet Compatibility) : nombre de classes `sk-*`, d'éléments et de pages concernés, et la commande à lancer.
- [ ] **Step 4 : relancer `motion:test`.** Attendu : vert.
- [ ] **Step 5 : écrire et lancer `assert-migration.sh`** sur le site local : `wp db export` d'abord ; `migrate` → liste les 10 classes `sk-*` et un nombre d'éléments > 0, rien n'est modifié ; création manuelle d'une classe `lm-counter` → `--apply` la signale en conflit et ne touche pas `sk-counter` ; `--apply` → les autres classes portent leur nom `lm-*` avec le **même ID**, dans la catégorie « Motion » ; `run.sh enter` repasse ; `wp lumia motion classes` ne crée rien de plus qu'attendu ; `wp db import` pour revenir à l'état initial. Attendu : `ok migration`.
- [ ] **Step 6 : i18n, `composer check`, commit** `feat(motion): lm-* classes and wp lumia motion migrate`.

### Task 12: Documentation, relecture et PR

**Files:**
- Create : `docs/modules/motion.md`
- Modify : `docs/README.md`, `README.md` (liste des modules si présente), `CLAUDE.md` (« Ten modules » → onze, liste), `languages/*`

- [ ] **Step 1 : `docs/modules/motion.md`** (anglais, sans accent français) : utilisation dans Bricks (classe, panneau, cascade élément > classe > global, breakpoints), table des variables, rythme et courbes, limites (composants, éléments globaux et boucles sans valeurs par breakpoint ; effet d'une classe invisible pour les conditions du panneau ; contenu AJAX non détecté au rendu), conseil LCP, pièges (`scroll-behavior: smooth` et `ScrollTrigger.refresh`, contrôles ajoutés à `init` 100, `cssLoading = file` et `regenerate_assets`, variables de soulignement héritables), versions et SHA-256 des fichiers tiers, procédure de migration par site, licence GSAP (usage interne, pas de WordPress.org).
- [ ] **Step 2 : passe complète.** `composer check`, `motion:test`, `node --test tools/motion/js/`, `i18n:check`, `run.sh all` à 1440 et 390 px, `tools/build/build-zip.sh` puis `node --check` des JS minifiés du zip (fait par le script). Attendu : tout vert.
- [ ] **Step 3 : issue #25** — remplacer dans le corps la ligne « default easing read from the site's `--cubic-bezier` » par « built-in tempo and easing profiles (Lümia curve by default, Apple-style springs) » (`gh issue edit 25 --body-file`).
- [ ] **Step 4 : commit** `docs(motion): module documentation`, push de la branche, PR vers `dev` (modèle `.github/PULL_REQUEST_TEMPLATE.md`, `Closes #25`), relecture par un agent indépendant en lecture seule avec `CLAUDE.md` et la spec ; corriger ses constats bloquants avant fusion.

## Après la fusion (hors plan, avec l'utilisateur)

1. Lab `lab.dev.agence-lumia.com` : installation, snippet désactivé, `migrate`, page de comparaison des courbes (même effet en Lümia, Douce, Vive, Rebond), réglage des valeurs de référence et des rebonds.
2. agence-lumia.com, boilerplate, puis les sites clients ; archivage de `sk-motion` quand plus aucun site ne charge le CDN.
3. Supprimer la copie locale du lab (`docker compose -p motion-lab down -v` et le dossier `local-lab` du scratchpad, qui contient des empreintes de mots de passe).
