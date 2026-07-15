# TYPO3 Core Backend Design Adoption Guide (v12 · v13 · v14)

Project-agnostic reference for building **any** TYPO3 backend module so it looks and behaves like native TYPO3. Drop this file into any extension/project. When implementing a new extension or feature, follow this guide to adopt TYPO3 core design **automatically** — use core markup and `--typo3-*` tokens first; add extension CSS only when core cannot express the layout.

**Scope:** Backend module UI (Fluid templates, module CSS, JS toggles).
**Supported TYPO3:** `^12.4 || ^13.4 || ^14.3`.
**Source of truth:** TYPO3 core `backend.css` (always) + **styleguide** when installed (optional live reference). Styleguide is **not** required in production — see fallbacks below.

---

## How to use this guide (for agents & developers)

When asked to build or restyle any backend UI:

1. **Find the core pattern first.** Open **System → Styleguide → Components** on the target TYPO3 version and copy the matching markup (Cards, Buttons, Tables, Forms, Modals, Badges).
2. **Use core classes, not custom skins.** `.card`, `.btn`, `.badge`, `.callout`, `.table`, `.card-container` already match the backend.
3. **Use `--typo3-*` tokens for any CSS** (with sensible fallbacks for v12). Never hardcode colours.
4. **Extension CSS = layout only** (margin, grid, flex, gap). Never re-theme core components or typography.
5. **Test light + dark theme on each supported version** before declaring done.

If a pattern exists in core, you do not need sign-off — adopt it. Only escalate when no core pattern fits and a genuinely custom component is required.

---

## Goals

1. **Look native** — users should not notice a "custom skin" inside the TYPO3 backend.
2. **Survive upgrades** — prefer core classes and `--typo3-*` tokens over hard-coded colours (`#f8fafc`, `#fff`).
3. **Support v12–v14** — one template set with progressive enhancement on v14; avoid v14-only markup without a fallback.
4. **Use TYPO3 core typography** — inherit backend font stack, sizes, and line-height from `backend.css`. Never ship a parallel type scale.

---

## Cross-version strategy

Target **v14 core patterns** as the design baseline, with graceful degradation on v12 and v13.

```
Design (v14 styleguide + SubmoduleOverview)
    ↓
Markup (core classes, one Fluid template set)
    ↓
CSS (layout + --typo3-* tokens with fallbacks)
    ↓
Test v12 + v13 + v14 (light + dark backend theme)
```

| Principle | Rationale |
|-----------|-----------|
| **Target v14 markup** | Newest patterns (`card-container`, structured `card-header`, `callout`) |
| **Token + fallback CSS** | `--typo3-*` on v13+; v12 gets sensible defaults |
| **One template set** | No version-specific Fluid files unless unavoidable |
| **Extension CSS = layout only** | Do not re-skin `.card`, `.btn`, `.badge` |
| **Toggle `active` + `is-active`** | Segmented controls work with core JS and custom scripts |

### Version compatibility matrix

| UI element | v12 | v13 | v14 | Recommended approach |
|------------|-----|-----|-----|----------------------|
| **Cards grid** | Bootstrap `row` / `col-*` | `.card-container` | `.card-container` + `card-size-*` | Prefer `.card-container`; Bootstrap grid OK as fallback |
| **Card anatomy** | Basic `.card` | + `card-icon`, `card-header-body` | Full header structure | Use full v14 anatomy — v12 degrades gracefully |
| **Banners** | `f:be.infobox`, `.alert` | `.callout` | `.callout callout-info\|notice\|…` | Prefer callout; infobox valid on v12-only |
| **Buttons / tabs** | `.btn-group` + `.btn-default` | Same | Same + `.active` / `.is-active` | Always toggle both classes in JS |
| **Badges** | `badge badge-*` | Same | Soft tints | Use core badge variants |
| **Typography** | ~13px Bootstrap body | `--typo3-font-size` (12px) | Open Sans headings | Inherit core — see **Typography** |
| **Dark mode** | `[data-bs-theme=dark]` | `[data-color-scheme=dark]` | Both | Cover both selectors |
| **Icons** | `<core:icon … />` | Same | + `alternativeMarkupIdentifier="inline"` in headers | Safe on all versions |
| **Tables in cards** | `.table.table-striped` | + `.table-fit` | Nested `.card` + `.table-fit` | Nested card wrapper when inside parent card |

---

## TYPO3 Styleguide (official core reference)

The **styleguide** extension ships with TYPO3 core. It is the live, browsable reference for backend UI — maintained by core developers alongside `backend.css`.

| | |
|---|---|
| **Extension key** | `styleguide` |
| **Typical path** | `typo3conf/ext/styleguide/` or `vendor/typo3/cms-styleguide/` |
| **Backend module** | **System → Styleguide** |
| **Access** | Admin only |

### Component templates (styleguide source files)

Under `styleguide/Resources/Private/Templates/Backend/Components/`:

| Component | File |
|-----------|------|
| Cards | `Cards.html` |
| Buttons | `Buttons.html` |
| Form / Input / Select / Textarea | `Form.html`, `Input.html`, `Select.html`, `Textarea.html` |
| Tables | `Tables.html` |
| Modal | `Modal.html` |
| Tab / Navs | `Tab.html`, `Navs.html` |
| Badges | `Badges.html` |
| Notifications / Flash | `Notifications.html`, `FlashMessages.html` |
| Dropdown | `Dropdown.html` |
| Panels / Infobox | `Panels.html`, `Infobox.html` |

**Workflow:** Install/enable styleguide in local DDEV when designing new UI. After core upgrades, re-check styleguide Components for markup changes before refactoring templates.

| Need | Open |
|------|------|
| Copy-paste markup for a core component | Styleguide → Components (live preview + code) |
| Cross-version rules, token fallbacks, migration | This document |
| Core source of truth (cards grid) | `backend/Resources/Private/Templates/SubmoduleOverview/Cards.fluid.html` |

### When Styleguide is **not** installed

Styleguide (`typo3/cms-styleguide`) is an **optional** dev extension. It is often missing on staging/production and may be disabled in local DDEV. That is fine — runtime UI still comes from core `backend.css`, which is always loaded for backend modules.

**Use this priority order:**

| Priority | Reference | Path / how to use |
|----------|-----------|-------------------|
| **1** | **Core `backend.css`** | `typo3/sysext/backend/Resources/Public/Css/backend.css` (or `vendor/typo3/cms-backend/…`) — tokens (`:root`), components, dark mode |
| **2** | **Production Fluid in core sysext** | Real module markup shipped with TYPO3 (see table below) |
| **3** | **Live backend modules** | Open a native module in the browser (Web → Modules, List, Dashboard, Site Management) → inspect HTML / copy class names |
| **4** | **This document** | Markup snippets + token rules in `Typo3CoreBackendDesign.generic.md` |
| **5** | **Install styleguide (dev only)** | `composer require typo3/cms-styleguide --dev` then enable in Extension Manager — for local design work only |

**Component → core template fallback** (no styleguide needed):

| UI pattern | Core reference file |
|------------|---------------------|
| Cards grid | `backend/Resources/Private/Templates/SubmoduleOverview/Cards.fluid.html` |
| Module layout shell | `backend/Resources/Private/Layouts/Module.html` |
| Docheader / buttons | Any list module using `ModuleTemplate` + `ButtonBar` (e.g. `beuser`, `scheduler`) |
| Tables + row actions | `backend/Resources/Private/Templates/RecordList.fluid.html`, `beuser/Resources/Private/Partials/BackendUser/PaginatedList.fluid.html` |
| Form tabs | `backend/Resources/Private/Templates/Form/Tabs.fluid.html` |
| Infobox / callout | `f:be.infobox` in core templates; HTML: `install/Resources/Private/Templates/Login/*.fluid.html`, `dashboard/Resources/Private/Templates/Widget/*.fluid.html` |
| Badges, dashboard cards | `dashboard/Resources/Private/Templates/Dashboard/Main.fluid.html` |
| New record wizard | `backend/Resources/Private/Templates/NewContentElement/Wizard.fluid.html` |

**Rule:** Never block development on styleguide. If you cannot open **System → Styleguide**, read `backend.css` for tokens and copy markup from the matching **production** core Fluid template above — that is what TYPO3 actually renders.

**Local DDEV tip:** Add styleguide only when you want a browsable component gallery. It does not change frontend/backend styling for other extensions.

---

## Typography — TYPO3 core only (mandatory)

The backend already defines font family, size, weight, and line-height via `backend.css`. Extension code must not replace or duplicate that system.

### Forbidden in new code

| Forbidden | Use instead |
|-----------|-------------|
| `font-family: Inter, …` on module wrappers | Inherit backend stack via `--typo3-font-family` |
| `font-family: "Fira Code", …` | `var(--typo3-font-family-code)` |
| Pixel sizes: `10px`, `11px`, `12px`, `13px`, `24px` on text | Default body, `small`, or semantic headings (`h1`–`h6`, `card-title`) |
| Custom title classes with `font-size` / `font-weight` | `<h1>` / `<h2 class="card-title">` — core sets scale |
| Bootstrap `text-muted` | `text-variant` (TYPO3 v13+) |
| `<h3 class="card-title h6">` (Bootstrap size fights core) | `<h2 class="card-title">` only |

### Markup patterns

```html
<!-- Page intro -->
<h1 class="mb-2">Dashboard</h1>
<p class="text-variant mb-3">Short description.</p>

<!-- Card header — core sets title scale -->
<h2 class="card-title">Title</h2>
<span class="card-subtitle">Subtitle</span>

<!-- Dense meta -->
<span class="small text-variant">Last used 2 hours ago</span>

<!-- CLI / key / id — backend.css styles <code> -->
<code>vendor/bin/typo3 cache:flush</code>
```

### Core typography tokens (v12 · v13 · v14)

Source: `backend/Resources/Public/Css/backend.css` (`:root`). Live preview: **Styleguide → Styles**.

| Token | Computed (≈) | Use |
|-------|--------------|-----|
| `--typo3-font-size` | 12px | Body, tables, most UI |
| `--typo3-font-size-small` | 11px | Dense meta (via `.small` / `btn-sm`) |
| `--typo3-line-height` | 1.5 | Default text |
| `--typo3-font-family` | Verdana/Arial sans | UI font — **do not replace** |
| `--typo3-header-font-family` | Open Sans | `h1`–`h6`, titles |
| `--typo3-font-family-code` | SFMono/Menlo/Consolas | `<code>`, CLI tags |
| `--typo3-input-font-size` | 12px | `.form-control`, `.btn` |
| `--typo3-input-sm-font-size` | 11px | `.btn-sm`, compact controls |
| `--typo3-text-color-base` | — | Primary text |
| `--typo3-text-color-variant` | — | Muted text → class **`text-variant`** |

**v12 note:** these tokens may be partial/absent. Still use the same **class names** (`card-title`, `btn-sm`, `small`, `text-variant`) — they map to `backend.css` in all supported versions. Do not add a v12-specific font stack.

### Let core CSS win (do not re-declare)

| Element / class | Extension action |
|-----------------|------------------|
| `body`, module content | No `font-size` / `font-family` override |
| `h1`–`h6` | Use semantic headings; no custom title CSS |
| `.card-title` / `.card-subtitle` / `.card-text` | No `font-size` override |
| `.callout-title` / `.callout-body` | No custom banner title size |
| `.btn` / `.btn-default` / `.btn-sm` | No font-size override |
| `.badge` | No pixel font sizes |
| `.form-label` | No override |
| `.table` | No font overrides |
| `code` | Plain `<code>`; token only in CSS |

### Module typography baseline (recommended)

Scope core typography to your module wrapper once (replace the wrapper selector with your own), then **never** duplicate these rules in per-tab CSS:

```css
.your-module-page {
    font-size: var(--typo3-font-size, inherit);
    line-height: var(--typo3-line-height, 1.5);
    color: var(--typo3-text-color-base, var(--typo3-component-color, inherit));
    font-family: var(--typo3-font-family, inherit);
}

.your-module-page h1,
.your-module-page h2,
.your-module-page h3,
.your-module-page h4,
.your-module-page h5,
.your-module-page h6 {
    font-family: var(--typo3-header-font-family, inherit);
    font-weight: 400;
}

.your-module-page code,
.your-module-page pre,
.your-module-page .form-control.font-monospace {
    font-family: var(--typo3-font-family-code, ui-monospace, monospace);
}
```

**Never** set `font-family` on `*` descendants inside module wrappers.

### Typography checklist

- [ ] Module content inherits the baseline wrapper (no per-wrapper font overrides)
- [ ] No `font-family: Inter`, `Fira Code`, or other non-core stacks
- [ ] Page title is `h1` (or `h2` inside cards) — no custom title font-size
- [ ] Card text uses `card-title` / `card-subtitle` / `card-text` without extra `font-size`
- [ ] Muted copy uses `text-variant` (+ `small` if denser)
- [ ] Buttons use `btn` / `btn-sm` without font-size overrides
- [ ] CLI/command strings use `var(--typo3-font-family-code)` only
- [ ] No pixel font sizes in new CSS
- [ ] Line-height not forced to `1.1` / `1.15` on body text (use core `1.5`)

---

## Layout shell

Every module tab renders inside the TYPO3 backend module frame. **Do not** add Bootstrap `container-fluid` or `py-2` on the extension wrapper — core already handles module chrome and spacing.

```html
<div class="module-body t3js-module-body">
    <div class="module-body-container t3js-module-body-container">
        <f:flashMessages />

        <div class="your-module-page">
            <div class="your-module-content">
                <!-- tab content -->
            </div>
        </div>
    </div>
</div>
```

| Wrapper | Role |
|---------|------|
| `your-module-page` | Extension scope hook for CSS (typography baseline, tokens) |
| `your-module-content` | Tab content; horizontal padding via your own spacing var |

The docheader may keep its own `container-fluid` on the inner row so tabs align with core module navigation; that is separate from the main content area.

**Do not** override global `.card` / `.btn` outside your module scope unless bridging tokens.

### Sidebar nav shell (list + detail tabs)

When a tab needs a **left sidebar list** and a **main panel** (e.g. MCP Tools → Tools & Resources, AI Permissions → Groups), copy the existing module pattern — do not use Bootstrap `list-group` or a custom one-off layout.

| Piece | Markup / class |
|-------|----------------|
| Outer grid | `div.aiu-mcp-tools-shell` |
| Sidebar column | `aside.aiu-mcp-tools-shell__sidebar` |
| Content column | `div.aiu-mcp-tools-shell__content` |
| Sidebar card | `card card-size-small mb-0 w-100` with `card-header` + `h3.card-title` |
| Sidebar CTA | `btn btn-default w-100 mb-3` + `actions-plus` icon |
| Nav list | `nav.aiu-mcp-tools-nav` with `button.aiu-mcp-tools-nav__item` rows |
| Row content | `typo3-backend-icon` / `core:icon` + `span.aiu-mcp-tools-nav__title` → `h4.aiu-mcp-tools-nav__label` + `span.aiu-mcp-tools-nav__meta` |
| Active row | `active is-active` on the current `__item` |

**References**

- Fluid: `Resources/Private/Partials/McpTools/ToolsResourcesShell.html`
- CSS: `Resources/Public/Css/module/mcp-tools.css`
- JS (dynamic list): `Resources/Public/JavaScript/access-roles.js` → `renderGroupsLayout()`, `renderGroupList()`
- Feature note: `context/features/ai-access-roles.md` → **UI**

Tab-specific CSS may only adjust list max-height, sticky sidebar, or CTA flex — **not** re-style nav items.

---

## Header design

A backend module has **two** distinct headers. Keep them separate — do not merge module tabs into the content area or push the page title into the docheader.

| Header | Where | Purpose |
|--------|-------|---------|
| **Module docheader** | Fixed bar above content (`module-docheader`) | Module-level navigation tabs, root-page/path info, global actions, status badges |
| **Page header** | First block inside content | Page/tab title, description, primary action button |

### 1. Module docheader (top navigation bar)

Render module-level tabs and actions inside the core docheader so they align with native TYPO3 module chrome. Use core `module-docheader` classes + Bootstrap `nav nav-pills` for tabs.

```html
<div class="module-docheader t3js-module-docheader module-docheader-navigation pb-0">
    <div class="container-fluid">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2">

            <!-- Left: module icon + primary tabs -->
            <div class="d-flex align-items-end gap-2 flex-grow-1 min-w-0">
                <core:icon identifier="your_module_icon" alternativeMarkupIdentifier="inline" size="default" />
                <ul class="nav nav-pills mb-0 gap-2" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link active is-active" href="…" role="tab" aria-selected="true">Tab A</a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" href="…" role="tab" aria-selected="false">Tab B</a>
                    </li>
                </ul>
            </div>

            <!-- Right: actions / status / path info -->
            <div class="d-flex flex-wrap align-items-end gap-2 mb-0">
                <div class="btn-toolbar" role="toolbar" aria-label="Actions">
                    <div class="btn-group" role="group">
                        <a href="…" class="btn btn-default btn-sm">
                            <core:icon identifier="actions-list-alternative" size="small" /> Logs
                        </a>
                    </div>
                </div>
                <span class="text-variant small mb-1">
                    Path: / <strong><core:icon identifier="apps-pagetree-page-domain" alternativeMarkupIdentifier="inline" size="small" /> {pageTitle} [{pageUid}]</strong>
                </span>
            </div>

        </div>
    </div>
</div>
```

| Rule | Detail |
|------|--------|
| Container | `module-docheader t3js-module-docheader module-docheader-navigation` (core hooks) |
| Inner | `container-fluid` is allowed **here** so tabs align with core nav (not in the content area) |
| Row | `d-flex flex-wrap align-items-end justify-content-between gap-2` — tabs left, actions right |
| Tabs | `nav nav-pills` + `nav-item` + `nav-link`; active tab gets `active is-active` + `aria-selected="true"` |
| Module icon | `<core:icon … alternativeMarkupIdentifier="inline" size="default" />` left of tabs |
| Actions | `btn-toolbar` / `btn-group` with `btn btn-default btn-sm`; status as `badge` or `text-variant small` |
| JS tabs | If switching client-side, toggle `active` + `is-active` and `aria-selected` |

**Active/hover styling:** rely on core `nav-pills`. If you add custom emphasis, scope it and use tokens only:

```css
/* layout + token colours only — no hardcoded brand hex */
.your-module-nav-tabs .nav-link.active {
    color: var(--typo3-badge-primary-color, #fff);
}
.your-module-nav-tabs .nav-link:not(.active):hover {
    background: var(--typo3-badge-primary-bg, #f2f2f2);
    color: var(--typo3-badge-primary-color, #000);
}
[data-color-scheme=dark] .your-module-nav-tabs .nav-link:not(.active):hover,
[data-bs-theme=dark] .your-module-nav-tabs .nav-link:not(.active):hover {
    background: var(--typo3-badge-primary-bg, #181818);
    color: var(--typo3-badge-primary-color, #fff);
}
```

**Native alternative:** TYPO3 `ModuleTemplate` provides a real docheader API (`getDocHeaderComponent()` → menus, buttons) from the controller. Prefer it for menus/buttons when you control the `ModuleTemplate`; use the Fluid markup above when you need custom tab layout or are embedding in a legacy module body.

### 2. Page header (title + description + primary action)

The first block inside the content area. Title left, primary action right. Use semantic headings + core button — no custom title font sizes.

```html
<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div>
        <h1 class="page-title mb-1">Page title</h1>
        <p class="page-description text-variant mb-0">Short description of this screen.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary">
            <core:icon identifier="actions-save" alternativeMarkupIdentifier="inline" /> Save
        </button>
    </div>
</div>
```

| Rule | Detail |
|------|--------|
| Wrapper | `d-flex justify-content-between align-items-start flex-wrap gap-3` (title wraps above action on narrow) |
| Title | `<h1>` (page) or `<h2 class="card-title">` (section) — **no** custom `font-size`/`font-weight` |
| Description | `<p class="text-variant">` — not `text-muted`, not custom grey |
| Primary action | `btn btn-primary` with leading `core:icon` (`alternativeMarkupIdentifier="inline"`) |
| Secondary actions | `btn btn-default` in the same `page-actions` group |
| Spacing | Layout-only margin classes (`mb-1`, `mb-0`, `gap-3`); no fixed pixel typography |

Wrapper class names (`page-header`, `page-title`, `page-description`, `page-actions`) are layout hooks — style only margin/flex on them, never font size or colour that core already provides.

### Header checklist

- [ ] Module tabs live in the **docheader**, not in the content area
- [ ] Page title/description live in a **page-header** inside content
- [ ] Tabs use `nav nav-pills` (or `nav-tabs`) + `active is-active` + `aria-selected`
- [ ] Module/action icons use `core:icon` with `alternativeMarkupIdentifier="inline"`
- [ ] Title is `<h1>`/`<h2 class="card-title">` with no custom font CSS
- [ ] Description uses `text-variant`
- [ ] Primary action is `btn btn-primary`; others `btn btn-default`
- [ ] Any custom tab emphasis uses `--typo3-*` tokens + both dark selectors
- [ ] Header verified in light + dark on v12 · v13 · v14

---

## Body design

The body is the scrollable content region between docheader and footer. It already gets correct padding and background from core (`module-body` → `module-body-container`). Keep your content inside a scoped wrapper and let core own the chrome.

```html
<div class="module-body t3js-module-body">
    <div class="module-body-container t3js-module-body-container">
        <f:flashMessages />

        <div class="your-module-page">
            <div class="your-module-content">
                <!-- page-header, then tab content / cards / tables -->
            </div>
        </div>
    </div>
</div>
```

| Rule | Detail |
|------|--------|
| Padding | Core `module-body` provides it — **do not** add `container-fluid` / `py-2` / custom padding on your wrapper |
| Background | Inherit core; never paint a custom body background |
| Width | Full width of the module body; no fixed `max-width` unless the design needs a reading column |
| Scope hook | `your-module-page` is the only place to apply typography baseline + tokens |
| Content order | `flashMessages` first, then page-header, then content blocks |
| Spacing between blocks | Bootstrap margin utilities (`mb-3`, `mb-4`), not custom pixel gaps |

### Full-height shell (when the footer must sit at the bottom)

If the module has a sticky-looking footer, make the module a flex column so the body grows and the footer stays at the bottom even on short pages. Layout-only — colours/padding still come from core tokens.

```css
/* the body container holds a single full-height module wrapper */
.t3js-module-body-container:has(> .your-module.module) {
    display: flex;
    flex-direction: column;
    min-height: 100%;
}

.your-module.module {
    display: flex;
    flex-direction: column;
    width: 100%;
}

.your-module.module .module-body { flex: 1 1 auto; min-height: 0; order: 2; }
.your-module.module .module-docheader-navigation { flex: 0 0 auto; order: 1; }
.your-module.module .module-footer { flex: 0 0 auto; margin-top: auto; order: 3; }
```

`:has()` is supported on the browsers TYPO3 v12–v14 target. If you must support older engines, apply the flex container via a controller-added class instead.

---

## Footer design

A module footer holds secondary links (about, docs, support, rate-us) — never primary actions (those belong in the page-header or card footers). Use the core `module-footer` classes so it matches native modules.

```html
<div class="module-footer border-top t3js-module-footer d-flex justify-content-end align-items-center py-3">
    <ul class="footer-links list-unstyled mb-0 d-flex flex-wrap justify-content-end align-items-center">
        <li><a class="btn btn-link p-0" href="…" target="_blank">About</a></li>
        <li><a class="btn btn-link p-0" href="…" target="_blank">Documentation</a></li>
        <li><a class="btn btn-link p-0" href="…" target="_blank">Support</a></li>
    </ul>
</div>
```

| Rule | Detail |
|------|--------|
| Container | `module-footer border-top t3js-module-footer` (core hooks) + `d-flex justify-content-end align-items-center py-3` |
| Links list | `ul.footer-links.list-unstyled.mb-0` with `li > a.btn.btn-link.p-0` |
| Link colour | `--typo3-text-color-link` (fallback `--typo3-component-color`); underline on hover only |
| Separators | Optional `1px` divider via `::before` on `li:not(:first-child)` using `--typo3-component-border-color` |
| Background/border | `--typo3-component-bg` + `border-top` via `--typo3-component-border-color` (token, not hardcoded) |
| Content | Secondary/utility links only — **no** `btn-primary`, no form submits |
| External links | `target="_blank"` for off-backend docs/support |

Optional separator + colour styling (layout + tokens only):

```css
.your-module .footer-links { gap: 0; }
.your-module .footer-links li { padding-left: 20px; position: relative; }
.your-module .footer-links li:not(:first-child)::before {
    content: "";
    position: absolute;
    left: 10px; top: 50%;
    transform: translateY(-50%);
    width: 1px; height: 80%;
    background-color: var(--typo3-component-border-color, #cdcdcd);
}
.your-module .footer-links .btn-link {
    color: var(--typo3-text-color-link, var(--typo3-component-color, inherit));
    text-decoration: none;
}
.your-module .footer-links .btn-link:hover,
.your-module .footer-links .btn-link:focus { text-decoration: underline; }
```

### Body & footer checklist

- [ ] Body content sits in `module-body` → `module-body-container`; no `container-fluid`/custom padding on the wrapper
- [ ] No custom body background or fixed `max-width` (unless intentional reading column)
- [ ] `flashMessages` rendered first, then page-header, then content
- [ ] Block spacing uses Bootstrap margin utilities, not pixel gaps
- [ ] Full-height: flex-column shell so footer stays at bottom on short pages
- [ ] Footer uses `module-footer border-top t3js-module-footer` + `footer-links` `btn-link`
- [ ] Footer holds secondary links only (no primary actions / form submits)
- [ ] Footer colours/borders use `--typo3-*` tokens (no hardcoded grey)
- [ ] Body + footer verified in light + dark on v12 · v13 · v14

---

## Cards (v14 primary pattern)

Reference: Styleguide → Components → **Cards**, and `backend/Resources/Private/Templates/SubmoduleOverview/Cards.fluid.html`.

### Grid

```html
<div class="card-container">
    <div class="card card-size-small">…</div>
    <div class="card card-size-medium">…</div>
    <div class="card card-size-large">…</div>
</div>
```

| Class | Behaviour (v14, container ≥992px) |
|-------|----------------------------------|
| `card-size-small` | 1 column (~25%) |
| `card-size-medium` | Spans 2 columns (~50%) |
| `card-size-large` | Full width (`grid-column: 1 / -1`) |
| `card-size-fixed-small` | Fixed small width at all viewports |

Core `.card-container` owns responsive layout via container queries:

| Container width | Core grid |
|-----------------|-----------|
| < 768px | 1 column (stack) |
| ≥ 768px | 2 columns |
| ≥ 1200px | 4 columns |

**Do not** add custom `@media` / `display: grid` on card wrappers — it drifts from core across versions. **v12 note:** `.card-container` may render as a simple stack; that is acceptable.

### Anatomy (default)

```html
<div class="card card-size-small">
    <div class="card-header">
        <div class="card-icon">
            <core:icon identifier="actions-cog" size="medium" alternativeMarkupIdentifier="inline" />
        </div>
        <div class="card-header-body">
            <h2 class="card-title">Title</h2>
            <span class="card-subtitle">Subtitle</span>
        </div>
    </div>
    <div class="card-body">
        <p class="card-text mb-0">Body content</p>
    </div>
    <div class="card-footer">
        <button type="button" class="btn btn-default">Action</button>
    </div>
</div>
```

### Title + badge (side by side)

Use a flex row inside `card-header-body` — not a badge inline inside `card-title` (wraps badly on narrow cards):

```html
<div class="card-header-body">
    <div class="d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title text-truncate mb-0">Title</h2>
        <span class="badge badge-default flex-shrink-0">label</span>
    </div>
    <span class="card-subtitle">Subtitle</span>
</div>
```

Status badges use core variants: `badge-success`, `badge-warning`, `badge-default`, `badge-primary`.

### Equal height in `card-container`

Do **not** use `h-100` on cards inside `.card-container` (`height: 100%` breaks flex row stretch):

```css
.my-grid.card-container { align-items: stretch; }
.my-grid.card-container .card { align-self: stretch; height: auto; }
.my-grid.card-container .card .card-footer { margin-top: auto; }
```

Put actions in **`card-footer`** (not `card-body`) so buttons align at the bottom when descriptions differ in length.

### Critical DOM rule — footer must be last

TYPO3 core card CSS (v13+): `.card-footer:last-child` gets bottom padding; `.card { overflow: hidden; }`. If any sibling comes after `.card-footer` (even `hidden`), the footer loses padding and can be clipped.

```
✓ card-header → card-body → [expandable, hidden] → card-footer   (last)
✗ card-header → card-body → card-footer → [expandable]            (breaks footer)
```

### Tables inside cards

Core keeps `.table-fit` `border-top` inside `.card`, which stacks with the card edge (looks like a bold 2px line). Zero the table-fit border — the card provides the frame:

```css
.my-panel .card > .table-fit {
    border: 0;
    box-shadow: none;
}
```

```html
<div class="card mb-0">
    <div class="table-fit">
        <table class="table table-striped table-hover align-middle mb-0">…</table>
    </div>
</div>
```

---

## Callouts (banners & empty states)

Prefer callouts over custom banner divs.

```html
<div class="callout callout-info mb-3">
    <div class="callout-icon">
        <core:icon identifier="actions-info" size="medium" />
    </div>
    <div class="callout-content flex-grow-1">
        <div class="callout-title">Title</div>
        <div class="callout-body"><p class="mb-0">Description</p></div>
    </div>
</div>
```

| Variant | Use |
|---------|-----|
| `callout-info` | Primary tab introduction |
| `callout-notice` | Empty state, neutral hint |
| `callout-secondary` | Secondary sections |

**v12 fallback:** if callout styles are thin, `f:be.infobox` remains valid; migrate to callout when minimum target is v13+.

---

## Buttons & segmented controls

```html
<!-- Primary actions -->
<button type="button" class="btn btn-primary">Save</button>
<a href="…" class="btn btn-default">Cancel</a>

<!-- Tab / filter switches -->
<div class="btn-group" role="group" aria-label="…">
    <a href="…" class="btn btn-default active is-active">Tab A</a>
    <a href="…" class="btn btn-default">Tab B</a>
</div>

<!-- Filter chips -->
<button type="button" class="btn btn-default btn-sm active is-active" data-filter="all">All</button>
```

**JS:** toggle both `active` and `is-active` for compatibility with core and custom scripts. Avoid custom ghost/outline buttons — use `btn btn-default`.

### Table row icon actions (v12 · v13 · v14)

Reference: `beuser/Resources/Private/Partials/BackendUser/PaginatedList.fluid.html`. Use core control column + segmented icon buttons.

```html
<td class="col-control">
    <div class="btn-group" role="group" aria-label="Actions">
        <a class="btn btn-default" href="…" title="Edit"><core:icon identifier="actions-open" size="small" /></a>
        <button type="button" class="btn btn-default" title="Refresh"><core:icon identifier="actions-refresh" size="small" /></button>
        <form method="post" action="…" id="record-delete-42"><input type="hidden" name="uid" value="42" /></form>
        <button type="submit"
                class="btn btn-danger t3js-modal-trigger"
                data-target-form="record-delete-42"
                title="Delete"
                data-severity="warning"
                data-title="Delete"
                data-button-close-text="Cancel"
                data-button-ok-text="Yes, delete">
            <core:icon identifier="actions-delete" size="small" />
        </button>
    </div>
</td>
```

| Rule | Detail |
|------|--------|
| Column | `col-control` (core: right-aligned, nowrap) |
| Group | `btn-group` + `role="group"` + `aria-label` |
| Delete | `btn btn-danger` + `t3js-modal-trigger` (core confirm modal — **not** `confirm()` in custom JS) |
| Avoid | custom icon-button skins, inline `style` on forms |

---

## Tabs / sub-tabs inside a module

When a module page has its own sub-tabs (not the docheader tabs):

- Use core `nav nav-tabs` (or a `btn-group` segmented control) with `data-bs-target="#paneId"` buttons and `tab-pane fade` panes inside one `.tab-content`.
- Give buttons `type="button"` when inside a `<form>`.
- Ensure **all panes are direct children** of the same `.tab-content`.
- If panes render blank when nested inside another tab system, drive switching with a small script that toggles `active` + `show` on the target pane (and `active` + `is-active` on the button), and add a scoped fallback:

```css
#yourTabContent > .tab-pane:not(.active) { display: none; }
#yourTabContent > .tab-pane.active { display: block; }
#yourTabContent > .tab-pane.fade.active { opacity: 1; }
```

---

## Search

```html
<div class="input-group">
    <label class="visually-hidden" for="search-id">Search</label>
    <span class="input-group-text"><core:icon identifier="actions-search" size="small" /></span>
    <input type="search" id="search-id" class="form-control" placeholder="…" />
</div>
```

---

## Badges

| Class | Use |
|-------|-----|
| `badge badge-default` | Counts, keys, neutral meta, inactive |
| `badge badge-success` | Active / OK |
| `badge badge-warning` | Needs attention |
| `badge badge-danger` | Error / locked |
| `badge badge-primary` | Highlighted meta |

Do **not** use inline `style="color/background"` or custom pill classes when core variants express the same meaning.

---

## Icons

Always use the ViewHelper — never Font Awesome / emoji in new UI.

```html
<core:icon identifier="actions-chevron-down" size="small" />
```

To resize a `core:icon` / `typo3-backend-icon`, target the icon element with `--icon-size` and size the inner SVG; don't replace the glyph with a text character (e.g. `✓`).

```css
.your-ok-icon typo3-backend-icon,
.your-ok-icon .typo3-icon { --icon-size: 16px; display: inline-flex; }
.your-ok-icon .typo3-icon svg { width: 16px; height: 16px; display: block; }
```

Common identifiers: `actions-cog`, `actions-check`, `actions-search`, `actions-chevron-down`, `actions-key`, `actions-info`, `actions-delete`, `actions-open`, `spinner-circle`.

---

## Expandable sections (accordion-style)

Core has no dedicated accordion. Pattern:

1. Keep the expandable block **before** `card-footer`.
2. Toggle `[hidden]` on the block via JS (`data-*` hooks).
3. Style panel headers with `--typo3-*` tokens, not custom white/grey backgrounds.
4. Chevron: `<core:icon identifier="actions-chevron-down" />` + rotate when `.is-open`.

---

## CSS rules for extension modules

### Prefer tokens with fallbacks (v12–v14)

```css
.my-block {
    background: var(--typo3-surface-container-low, #fcfcfc);
    border: 1px solid var(--typo3-component-border-color, #d7d7d7);
    color: var(--typo3-text-color-base, #1e1e1e);
    border-radius: var(--typo3-card-border-radius, var(--typo3-component-border-radius, .375rem));
}
```

Never nest `light-dark()` inside `var()` fallbacks — it fails on older v13 setups when a token is missing. Use a plain hex fallback and, if needed, add explicit dark overrides:

```css
[data-color-scheme=dark] .my-block,
[data-bs-theme=dark] .my-block {
    background: var(--typo3-surface-container-low, #333);
    border-color: var(--typo3-component-border-color, #4b4b4b);
}
```

### Common tokens

| Token | Use for |
|-------|---------|
| `--typo3-link-color` / `--typo3-state-primary-*` | Links, primary state |
| `--typo3-state-default-*` | Active chips, toggles, segmented picks |
| `--typo3-surface-container-low/high` | Panel/section surfaces |
| `--typo3-component-bg` / `--typo3-component-border-color` | Component surfaces & borders |
| `--typo3-text-color-base` / `--typo3-text-color-variant` | Body / muted text |
| `--typo3-card-border-color` / `--typo3-card-border-radius` | Card frame |

**Forbidden:** solid brand hex backgrounds (e.g. `#1a56db`), blue pill tabs, hero gradients, `Inter`/`Fira Code` font overrides, hard-coded light-only greys (`#f8fafc`, `#fff`) without a token fallback.

### Avoid

- Duplicating full card/panel skins when `.card` + core sections suffice
- Large custom grid systems when `.card-container` fits
- Parallel typography stacks (pixel `font-size`, non-core fonts)
- Re-theming core components (`.card`, `.btn`, `.badge`, `.callout`)

---

## JavaScript conventions

- Use `@typo3/backend/modal.js` for confirm dialogs and forms (not `confirm()`).
- Toggle `active` + `is-active` on filter/mode/tab buttons; toggle `show` + `active` on panes.
- Toggle `aria-expanded` and `.is-open` on expandable headers.
- Scope queries to a `[data-…-root]` element, not the whole document.
- Backend JS is ES modules — register them via the extension's `Configuration/JavaScriptModules.php`.

---

## Dark mode

- v12: `[data-bs-theme=dark]`
- v13+: `[data-color-scheme=dark]`
- Cover **both** selectors for any explicit dark rule.
- Prefer tokens (which already flip in dark) over manual overrides; only add explicit dark rules when a hardcoded fallback would otherwise show.

---

## Checklist for new UI

- [ ] Compared markup with **Styleguide → Components** for the same pattern
- [ ] Uses `.card-container` + `card-size-*` — no custom `@media` grid on card wrappers
- [ ] Title + badge: flex row inside `card-header-body`
- [ ] Equal-height grid: no `h-100`; `align-items: stretch` + `card-footer { margin-top: auto }`
- [ ] Card sections: `card-header` → `card-body` → optional expand → **`card-footer` last**
- [ ] Buttons: `btn btn-default` / `btn-primary`, `btn-group` for segments (`active` + `is-active`)
- [ ] Info banner: `callout callout-info`; empty: `callout callout-notice`
- [ ] Tables: `table table-striped table-hover` + `table-fit`; nested `card` when inside parent card
- [ ] Badges: core variants without inline colours or custom pills
- [ ] Icons: `<core:icon … />` (no emoji / text glyphs)
- [ ] Muted text: `text-variant`
- [ ] Typography: core only — module baseline, no Inter/Fira/pixel sizes
- [ ] Dark mode: `--typo3-*` tokens with fallbacks; both dark selectors covered
- [ ] JS hooks preserved when refactoring markup
- [ ] Tested on target TYPO3 version(s) in light **and** dark theme

---

## Migration map (legacy → core)

| Legacy (avoid in new code) | Core replacement |
|----------------------------|------------------|
| Custom banner divs | `callout callout-info` / `callout-notice` |
| Custom flash markup | `callout` or `f:flashMessages` / `f:be.infobox` |
| Custom provider/panel card skins | `card` + `card-header` / `card-body` + `badge badge-*` |
| Custom KPI tiles | `.card` tile or callout stats |
| Custom tool/tag chips | `btn btn-sm btn-default` |
| Custom table + code-chip classes | `table` + `<code>` |
| Ghost/outline buttons | `btn btn-default btn-sm` |
| Blue pill tabs | `btn btn-default btn-sm` + `active is-active` |
| Custom icon buttons in rows | `btn-group` + `btn btn-default` in `td.col-control` |
| `row g-3` only grids | `card-container` (v14) |
| Custom viewport `@media` card grids | Plain `card-container` + `card-size-*` |
| Custom status dots / coloured pills | `badge badge-success` / `warning` / `default` |
| `btn btn-link` card footers | `btn btn-default w-100` |
| CSS chevron pseudo-element | `core:icon actions-chevron-down` |
| `font-family: Inter` on wrappers | Remove — inherit `--typo3-font-family` |
| `font-family: "Fira Code"` | `var(--typo3-font-family-code)` or plain `<code>` |
| Custom large title classes | Semantic `<h1>` + core heading styles |
| Pixel label CSS (`10/11/13px`) | `small`, `text-variant`, or default body |
| Text glyph icons (`✓`, `✕`) | `core:icon` (`actions-check`, `actions-close`) |

---

## Reference files (TYPO3 core / styleguide)

| Pattern | Path |
|---------|------|
| **Primary (always available)** | |
| Backend CSS tokens + typography | `backend/Resources/Public/Css/backend.css` |
| Submodule cards (production core) | `backend/Resources/Private/Templates/SubmoduleOverview/Cards.fluid.html` |
| Table row actions (production core) | `beuser/Resources/Private/Partials/BackendUser/PaginatedList.fluid.html` |
| Form tabs (production core) | `backend/Resources/Private/Templates/Form/Tabs.fluid.html` |
| Record list / tables | `backend/Resources/Private/Templates/RecordList.fluid.html` |
| **Optional (when styleguide installed)** | |
| Styleguide card examples | `styleguide/Resources/Private/Templates/Backend/Components/Cards.html` |
| Styleguide buttons, forms, tables | `styleguide/Resources/Private/Templates/Backend/Components/*.html` |
| Styleguide typography / colours | Styleguide → **Styles** submodule |

---

## Testing (required before merge)

When changing Fluid markup or module CSS, **manually verify on each supported TYPO3 version** you have locally.

| Step | Action |
|------|--------|
| 1 | Flush caches / hard-refresh after CSS or template changes |
| 2 | Compare with **Styleguide → Components** *or*, if styleguide is not installed, the matching **core Fluid template** from the Reference files table |
| 3 | Check **light and dark** backend theme |
| 4 | Check responsive width (narrow module body, wide screen) |
| 5 | Click interactive controls (tabs, drawer, delete confirm, AJAX, filters) — JS hooks must still work |

**Version matrix (sign off each):**

| Check | v12.4 | v13.4 | v14.3 |
|-------|-------|-------|-------|
| Module loads without Fluid errors | ☐ | ☐ | ☐ |
| Typography matches backend (no custom font leak) | ☐ | ☐ | ☐ |
| `btn-group` / `btn-default` match list-module chrome | ☐ | ☐ | ☐ |
| `card-container` grids readable (stack OK on v12) | ☐ | ☐ | ☐ |
| Badges / callouts / tables in light + dark | ☐ | ☐ | ☐ |
| No horizontal scroll in docheader / tables | ☐ | ☐ | ☐ |

If only one version is available locally, state which version was tested and what remains unchecked.

---

*Generic TYPO3 core backend design adoption guide — reusable across projects. Replace `your-module-page` / `your-module-content` with your extension's wrapper class names.*
