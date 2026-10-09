# Design system — reusable components

All the reusable components are defined in `assets/admin/css/components.css` and `assets/admin/js/admin.js`. **Always use them; never hand-code a home-made equivalent.**

## CSS class conventions

The admin interface uses BEM classes prefixed `lumia-`. Key patterns: `lumia-section`, `lumia-section__header`, `lumia-option`, `lumia-option__control`, `lumia-toggle`, `lumia-form__group`, `lumia-badge` (modifiers: `--success`, `--warning`, `--danger`, `--info`, `--inactive`), `lumia-btn` (modifiers: `--primary`, `--secondary`, `--sm`).

## Design tokens (CSS custom properties)

Defined in `assets/admin/css/reset.css`:
- Colors: `--lumia-accent`, `--lumia-success`, `--lumia-danger`, `--lumia-warning`
- Neutrals: `--lumia-n50` … `--lumia-n950`, `--lumia-surface`, `--lumia-border`, `--lumia-text`, `--lumia-text-secondary`
- Radii: `--lumia-radius`, `--lumia-radius-sm`, `--lumia-radius-xs`
- Shadows: `--lumia-shadow`, `--lumia-shadow-md`, `--lumia-shadow-lg`

## Modals

Two complementary systems, both defined in `components.css` + `admin.js`.

### 1. Programmatic confirmation modal

For simple confirm/cancel flows, with no input field:

```javascript
window.lumiaModal.open({
  title:        "Title",
  message:      "Explanatory message.",
  confirmLabel: "Confirm",
  cancelLabel:  "Cancel",
  danger:       true,           // red button instead of blue
  onConfirm:    function() {},  // callback if the user confirms
});
window.lumiaModal.close(); // programmatic close
```

`confirmLabel` and `cancelLabel` default to the translated `lumiaAdmin.i18n.confirm` / `.cancel`; pass module strings from `lumiaAdmin.i18n` (see [core.md](core.md#translatable-strings-in-js)), never a literal.

Uses the `#lumia-modal-overlay` singleton of `templates/admin/layout.php`.

### 2. Named modal (persistent HTML)

For modals with form fields (inputs, selects, etc.):

```javascript
window.lumiaModalOpen('my-modal-id');   // adds .is-open
window.lumiaModalClose('my-modal-id');  // removes .is-open
```

Required HTML structure (copy this template):

```html
<div class="lumia-modal-overlay" id="my-modal-id" role="dialog" aria-modal="true" aria-labelledby="my-modal-title">
  <div class="lumia-modal">
    <div class="lumia-modal__header">
      <h3 id="my-modal-title" class="lumia-modal__title">Title</h3>
    </div>
    <div class="lumia-modal__body">
      <!-- content, inputs, etc. -->
    </div>
    <div class="lumia-modal__footer">
      <!-- .lumia-modal-close on the Cancel button → automatic closing -->
      <button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close">Cancel</button>
      <button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="my-confirm-btn">Apply</button>
    </div>
  </div>
</div>
```

The `.lumia-modal-close` class and the click outside the box are handled automatically by `admin.js`. Same for the Escape key.

## Forms

```html
<!-- Label + input group -->
<div class="lumia-form__group">
  <label class="lumia-form__label" for="my-input">Label</label>
  <input type="text" class="lumia-input" id="my-input">
  <p class="lumia-form__help">Optional help text.</p>
</div>

<!-- Standard select -->
<select class="lumia-select">...</select>
<select class="lumia-select lumia-select--sm">...</select>  <!-- small -->

<!-- Toggle -->
<label class="lumia-toggle">
  <input type="checkbox" name="...">
  <span class="lumia-toggle__slider"></span>
</label>
```

### `input` types and WordPress's `forms.css`

wp-admin's `forms.css` targets fields by type (`input[type="date"]`, `input[type="url"]`…), a specificity of (0,1,1) that beats `.lumia-input` (0,1,0): the field then keeps WordPress's height, border and radius. `components.css` therefore redeclares the component on `input[type="…"].lumia-input` for each type in use (`text`, `number`, `email`, `password`, `date`). **New `input` type → add it to both selector lists** (normal and `:focus` states).

For `date`, Chromium adds internal sub-fields that push the height to ≈ 50px despite the `min-height`: the height is pinned to 42px, and the native indicator is replaced by the Lucide `calendar` icon (through `::-webkit-calendar-picker-indicator`, like the chevron of `lumia-select`). Firefox keeps its icon, which cannot be styled.

### Fields that are not saved

Search, list filters, a test email field: these controls often live inside the settings form, but must **not have a `name` attribute**. They are not sent on save, and the "unsaved changes" warning of `admin.js` ignores them (it only counts named fields). Another consequence: do not give them a `type` the browser validates (`email`, `url`, `required`), because native validation also applies to fields without a `name` and would block "Save".

## Tabs

**When to use them**: a screen mixing different natures (settings, tool, list or log), or more than three or four long sections. One tab per nature of content, not one per section: a few short sections stay on a single page.


Client-side sub-tabs of a screen (`components.css` + `initTabs()` in `admin.js`, no initialization to write):

```html
<div class="lumia-tabs" role="tablist" data-lumia-tabs="smtp">
  <button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="settings">Settings</button>
  <button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="log">Log</button>
</div>
<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="settings">…</div>
<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="log" hidden>…</div>
```

- The panels stay in the DOM: in a settings form, **all** the fields are sent on save, whatever the open tab.
- The last tab is remembered through `sessionStorage` (`lumia-tab:{group}`): the redirect after saving comes back to the screen, not to the tab.
- An invalid field in a hidden panel opens its tab: without that, the browser blocks the submission without being able to show the faulty field.
- Every change emits `lumia:tab` (`detail.group`, `detail.name`) on the bar, which bubbles up to the `document`: a module can wait for a tab to open before loading its data. `window.lumiaTabs.activate(group, name)` opens a tab programmatically.
- In a module screen, the bar goes **inside** the form, before `.lumia-module-form__scroll`, to stay fixed while scrolling.

## Buttons

```html
<button class="lumia-btn lumia-btn--primary">Primary</button>
<button class="lumia-btn lumia-btn--secondary">Secondary</button>
<button class="lumia-btn lumia-btn--danger">Danger</button>
<!-- Sizes: add --sm for small -->
```

A button can be an `<a>` ("Open the media library …"). `buttons.css` therefore redeclares the color on `a.lumia-btn:hover/:focus/:active` per variant: without that, wp-admin's `a:hover { color:#135e96 }` wins (the state adds a pseudo-class to the specificity of `.lumia-btn--primary`) and the label turns blue on hover.

## Tooltips

```html
<button data-lumia-tip="Export this menu as .json">…</button>
<button data-lumia-tip="…" data-lumia-tip-placement="right">…</button>   <!-- top by default -->
<?php echo $this->render_help_tip( __( 'Clarification', 'lumia-tools' ) ); ?>  <!-- (i) marker, inside the <label> -->
```

Home-made system (no tippy.js: it pulls in Popper, and the plugin has neither a build nor a bundler), defined in `components.css` + `admin.js`. **No initialization**: everything goes through delegation on the `document`, so markup rendered in JS afterwards (menu creator tree, AJAX lists) is covered without a second thought. API: `window.lumiaTooltip.hide()`, `.refresh()`, `.set(el, text)`.

Three structural points:
- The singleton is `position:fixed` + `translate3d`, appended to `<body>`: a child tooltip would be clipped by the first `overflow:hidden` column (they all are), and as `absolute` it would have to know the offsets of each of its parents. In return, scrolling must be tracked, hence the recalculation on `scroll`/`resize` throttled with `requestAnimationFrame`, and the automatic hiding when the reference leaves the screen or the DOM.
- The flip (`top` → `bottom`…) only happens if the opposite side offers **more** room, otherwise the box oscillates between two equally too small positions. After clamping within the window, the arrow is repositioned on the reference: without that it points next to it in the corners.
- A `title` on the same element is removed on the first hover (saved in `data-lumia-tip-title`), otherwise the native bubble doubles ours. The modules that run **outside** the Lümia pages — `media.js`, loaded by `wp_enqueue_media` where `admin.js` is absent — therefore keep both attributes: `title` serves as a fallback, `data-lumia-tip` takes over when our JS is there.

For a **secondary clarification** — the caveat that matters but would lengthen the line — `Admin::render_help_tip( $text )` puts a marker in the `<label>`: the Lucide `info` icon (`.lumia-tip-info`), not a dot drawn in CSS nor a dotted underline under the label — the former fakes an icon, the latter dirties the line and does not read as a control. `menu-creator.js` has the JS equivalent (`helpTip()`, icon served by `window.lumiaLucide.info`). What **describes** the option stays in the visible help text; only the detail goes under the marker.

Hover and focus both trigger the bubble (which `title` does not do); a tooltip already open chains without delay onto the next. On the plugin pages, prefer `data-lumia-tip` to `title` for any icon-only control.

## Toasts / notifications

```javascript
window.lumiaShowToast("Message", "success"); // success | error | info | warning
```

Defined in `assets/admin/js/notifications.js`, loaded globally. For notices that must survive a reload, see the persistent notices in [core.md](core.md#persistent-notices).
