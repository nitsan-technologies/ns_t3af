# TYPO3 Core Backend Design Guide (v12 · v13 · v14)

Design reference for **ns_t3af** backend modules. Use TYPO3 core markup and CSS first; add extension CSS only when core cannot express the layout.

**Scope:** Backend module UI (Fluid templates, module CSS, JS toggles).  
**Supported TYPO3:** `^12.4 || ^13.4 || ^14.3` (see `composer.json`).  
**Reference implementation:** `Resources/Private/Partials/`, `Resources/Public/Css/module/`.

---

## Goals

1. **Look native** — Users should not notice a “custom skin” inside the TYPO3 backend.
2. **Survive upgrades** — Prefer core classes and `--typo3-*` tokens over hard-coded colours (`#f8fafc`, `#fff`).
3. **Support v12–v14** — One template set with progressive enhancement on v14; avoid v14-only markup without a fallback where feasible.
4. **Use TYPO3 core typography** — Inherit backend font stack, sizes, and line-height from `backend.css`. Never ship a parallel type scale (Inter, Fira Code, pixel labels, custom `__title` sizes).

### Core typography rule (always)

| Layer | Rule |
|-------|------|
| **Fluid / HTML** | Semantic `h1`–`h6`, `card-title`, `small`, `text-variant` — let core classes set size/weight |
| **Module CSS** | Layout only (margin, grid, flex). No `font-family`, no `font-size`, no `line-height` on text that core already styles |
| **Monospace** | `var(--typo3-font-family-code)` only — not `Fira Code`, not raw `ui-monospace` |
| **Enforcement** | Baseline in `base.css` under `.aiu-module-page` (see **Module typography baseline**) |

---

## Cross-version strategy

Use **v14 core patterns as the design target**, with graceful degradation on v12 and v13.

```
Design (v14 styleguide + SubmoduleOverview)
    ↓
Markup (core classes, one Fluid template set)
    ↓
CSS (layout + --typo3-* tokens with fallbacks in base.css)
    ↓
Test v12 + v13 + v14 (light + dark backend theme)
```

| Principle | Rationale |
|-----------|-----------|
| **Target v14 markup** | Newest patterns (`card-container`, structured `card-header`, `callout`) |
| **Token + fallback CSS** | `--typo3-*` on v13+; v12 gets sensible defaults via `base.css` |
| **One template set** | No version-specific Fluid files unless unavoidable |
| **Extension CSS = layout only** | Do not re-skin `.card`, `.btn`, `.badge` |
| **Toggle `active` + `is-active`** | Segmented controls work with core JS and extension scripts |

### Version compatibility matrix

| UI element | v12 | v13 | v14 | ns_t3af approach |
|------------|-----|-----|-----|------------------------|
| **Cards grid** | Bootstrap `row` / `col-*` | `.card-container` (partial) | `.card-container` + `card-size-*` | Prefer `.card-container`; Bootstrap grid OK as fallback |
| **Card anatomy** | Basic `.card` | + `card-icon`, `card-header-body` | Full header structure | Use full v14 anatomy — v12 degrades gracefully |
| **Banners** | `f:be.infobox`, `.alert` | `.callout` | `.callout callout-info\|notice\|…` | Prefer callout; infobox valid on v12-only |
| **Buttons / tabs** | `.btn-group` + `.btn-default` | Same | Same + `.active` / `.is-active` | Always toggle both classes in JS |
| **Badges** | `badge badge-*` | Same | Soft oklch tints | `base.css` bridge under `.aiu-module-page` |
| **Typography** | ~13px Bootstrap body | `--typo3-font-size` (12px) | Open Sans headings | **Mandatory:** inherit core — see **Typography** section |
| **Dark mode** | `[data-bs-theme=dark]` | `[data-color-scheme=dark]` | Both | `base.css` covers both selectors |
| **Icons** | `<core:icon … />` | Same | + `alternativeMarkupIdentifier="inline"` in headers | Safe on all versions (attribute ignored where unsupported) |
| **Tables in cards** | `.table.table-striped` | + `.table-fit` | Nested `.card` + `.table-fit` | Nested card wrapper when inside parent card |

---

## TYPO3 Styleguide (official core reference)

The **styleguide** extension ships with TYPO3 core. It is the live, browsable reference for backend UI — maintained by core developers alongside backend.css changes.

| | |
|---|---|
| **Extension key** | `styleguide` |
| **Typical path** | `typo3conf/ext/styleguide/` or `vendor/typo3/cms-styleguide/` |
| **Backend module** | **System → Styleguide** (also via `?` help menu → Styleguide) |
| **Access** | Admin only |

### Styleguide submodules

| Submodule | Use for ns_t3af |
|-----------|----------------------|
| **Components** | Buttons, cards, forms, tables, modals, badges, dropdowns, notifications |
| **Styles** | **Typography tokens**, colour scheme, `--typo3-*` CSS variables (check here after core upgrades) |
| **Manage page trees** | TCA / FormEngine examples (not module UI, but useful for record editing) |

### Component templates (styleguide source files)

| Component | Path under `styleguide/Resources/Private/Templates/Backend/Components/` |
|-----------|--------------------------------------------------------------------------|
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

### When to open styleguide vs this doc

| Need | Open |
|------|------|
| Copy-paste markup for a core component | Styleguide → Components (live preview + code snippet) |
| Cross-version rules, token fallbacks, migration | This document |
| Best implementation inside ns_t3af | `Partials/AiFeatures/Card.html`, `Partials/SchedulerCli/CommandLibrary.html` |
| Core source of truth (cards grid) | `typo3/sysext/backend/Resources/Private/Templates/SubmoduleOverview/Cards.fluid.html` |

Install or enable styleguide in local DDEV when designing new UI. After core upgrades, re-check styleguide Components for markup changes before refactoring extension templates.

---

## Version overview (technical delta)

| Area | v12 | v13 | v14 |
|------|-----|-----|-----|
| CSS foundation | Bootstrap 5 + backend.css | Redesign starts; `--typo3-*` tokens | Full token system + container queries |
| Cards | Basic `.card` | `.card` + early callouts | `.card-container`, `card-size-*`, structured header |
| Notices | `f:be.infobox`, `.alert` | `.callout` | `.callout callout-info\|notice\|…` |
| Segmented controls | `.btn-group` + `.btn-default` | Same | Same + `.active` / `.is-active` for JS |
| Tables in cards | `.table.table-striped` | + `.table-fit` | Nested `.card` + `.table-fit` for borders |
| Icons | `<core:icon identifier="…" size="small\|medium\|large" />` | Same | `alternativeMarkupIdentifier="inline"` in headers |
| Dark mode | `[data-bs-theme=dark]` | `[data-color-scheme=dark]` | Both attributes |
| Grid for cards | Bootstrap `row` / `col-*` | Mixed | **Prefer** `.card-container` (container queries) |
| **Base typography** | ~13px Bootstrap body | `--typo3-font-size` (.75rem) | Full scale + Open Sans headings |
| **Monospace** | Bootstrap `$font-family-monospace` | Same family stack | `--typo3-font-family-code` |

**Target for new ns_t3af UI:** v14 core patterns (below). They degrade reasonably on v13; on v12 use the same class names where backend.css provides them. Do not rely solely on `card-size-*` container-query behaviour on v12 — test at narrow widths.

---

## Typography — TYPO3 core only (mandatory)

**All ns_t3af backend UI must use TYPO3 core typography.** The backend already defines font family, size, weight, and line-height via `backend.css`. Extension code must not replace or duplicate that system.

### Do not use (forbidden in new code)

| Forbidden | Use instead |
|-----------|-------------|
| `font-family: Inter, …` on module wrappers | Inherit from backend (Verdana stack via `--typo3-font-family`) |
| `font-family: "Fira Code", ui-monospace, …` | `font-family: var(--typo3-font-family-code)` |
| Pixel sizes: `10px`, `11px`, `12px`, `13px`, `24px` | Default body, `small`, or semantic headings (`h1`–`h6`, `card-title`) |
| Custom title classes with `font-size: 1.45rem; font-weight: 700` | `<h1>` / `<h2 class="card-title">` — core sets scale |
| Bootstrap `text-muted` | `text-variant` (TYPO3 v13+) |
| `color: var(--aiu-text-500)` for muted copy | `class="text-variant"` |
| `<h3 class="card-title h6 mb-0">` (Bootstrap size fights core) | `<h2 class="card-title">` only |
| `<b>` inside titles for weight | Core `card-title` / headings already set weight |

### Markup patterns (templates)

```html
<!-- Page intro -->
<h1 class="mb-2">Dashboard</h1>
<p class="text-variant mb-3">Overview of AI usage and providers.</p>

<!-- Card header — core sets title scale -->
<h2 class="card-title">Provider name</h2>
<span class="card-subtitle">OpenAI · gpt-4o</span>

<!-- Dense meta -->
<span class="small text-variant">Last used 2 hours ago</span>

<!-- CLI / API key / model id — backend.css styles <code> -->
<code>typo3 ai:request-log:list</code>
<code>gpt-4o</code>
```

### Core tokens (v12 · v13 · v14)

Source: `typo3/sysext/backend/Resources/Public/Css/backend.css` (`:root`). Live preview: **Styleguide → Styles**.

| Token | Value | Computed (≈) | Use |
|-------|-------|--------------|-----|
| `html` root | `max(1em, 14px)` | min 14px | Browser baseline |
| `--typo3-font-size` | `.75rem` | **12px** | Body, tables, most UI |
| `--typo3-font-size-small` | `.6875rem` | **11px** | Dense meta (use via `.small` or `btn-sm`) |
| `--typo3-line-height` | `1.5` | 18px @ 12px | Default text |
| `--typo3-font-family` | Verdana, Arial, Helvetica, sans-serif | UI sans | **Do not replace with Inter** |
| `--typo3-header-font-family` | `"Open Sans Variable", sans-serif` | Headings | `h1`–`h6`, module titles |
| `--typo3-font-family-code` | SFMono-Regular, Menlo, Monaco, Consolas… | Code / CLI | `<code>`, command tags |
| `--typo3-component-font-size` | `var(--typo3-font-size)` | 12px | Callouts, menus, panels |
| `--typo3-component-line-height` | `var(--typo3-line-height)` | 1.5 | Same |
| `--typo3-input-font-size` | `.75rem` | 12px | `.form-control`, `.btn` |
| `--typo3-input-line-height` | `1.5` | — | Inputs & buttons |
| `--typo3-input-sm-font-size` | `.6875rem` | 11px | `.btn-sm`, compact controls |
| `--typo3-text-color-base` | token | — | Primary text |
| `--typo3-text-color-variant` | 35% mix | — | Muted text → class **`text-variant`** |

**v12 note:** `--typo3-font-size` and related tokens may be partial or absent. Still use the same **class names** (`card-title`, `btn-sm`, `small`, `text-variant`) — they map to `backend.css` in all supported versions. Do not add a v12-specific font stack.

### v12 · v13 · v14 typography compatibility

| Concern | v12 | v13 · v14 | Extension rule |
|---------|-----|-----------|----------------|
| Body font | Verdana / Bootstrap ~13px | `--typo3-font-size` (.75rem ≈ 12px) | Never override on `.aiu-module-page` |
| Headings | Bootstrap heading scale | `--typo3-header-font-family` (Open Sans) | Use `h1`–`h6`; no custom `__title` CSS |
| Muted text | `text-muted` | `text-variant` | Always `text-variant` in new templates |
| Monospace | Bootstrap monospace | `--typo3-font-family-code` | Token only in CSS; `<code>` in HTML |
| Dense UI | — | `.small`, `btn-sm` | Prefer classes over custom rem/px |
| Module baseline | — | — | `base.css` → `.aiu-module-page` (required) |

### Component typography (let core CSS win)

Do **not** re-declare these if the element already uses the core class:

| Element / class | Core rule (v14) | Extension action |
|-----------------|-------------------|------------------|
| `body`, module content | `font-size: var(--typo3-font-size); line-height: 1.5` | No override |
| `h1`–`h6`, `.h1`–`.h6` | `font-family: var(--typo3-header-font-family); font-weight: 400` | Use semantic headings for page titles |
| `.card-header .card-title` | `font-size: 1.35em; font-weight: 500; line-height: 1.2em` | No `font-size` on `.card-title` |
| `.card-header .card-subtitle` | `font-size: 1em; line-height: 1.2em; color: subtle` | Use `card-subtitle`, not custom `<p class="small">` in header |
| `.card-body .card-text` | Inherits component size / 1.5 | Prefer `card-text` over custom desc classes |
| `.callout-title` | `font-size: 1.2em; line-height: 1.2` | No custom banner title size |
| `.callout-body` | Inherits + last-child margin reset | Use `mb-0` on last paragraph only |
| `.btn` / `.btn-default` | `font-size: var(--typo3-input-font-size); line-height: 1.5` | No override |
| `.btn-sm` | `font-size: var(--typo3-input-sm-font-size)` | Use for filter chips & command tags |
| `.badge` | `font-size: 0.91667em` (relative) | No pixel font sizes |
| `.form-label` | `font-size: var(--typo3-font-size); font-weight: 700` | In modals / run forms |
| `.table` | Inherits body size | No `aiu-table` font overrides |
| `code` | `--typo3-font-family-code` | Use plain `<code>`, not custom chips |

### Relative scale (em on top of 12px body)

| em | ≈ px @ 12px base | Typical core usage |
|----|------------------|-------------------|
| `1.35em` | ~16px | `.card-title` |
| `1.2em` | ~14px | `.callout-title` |
| `1em` | 12px | `.card-subtitle`, body |
| `0.91667em` | ~11px | `.badge` |
| `.6875rem` | 11px | `.small`, `btn-sm` (absolute rem) |

### Page titles & section headings

**Prefer HTML + core classes** instead of custom title classes:

```html
<!-- Good: core heading scale -->
<h1 class="mb-2">Scheduler &amp; CLI</h1>
<p class="text-variant mb-3">TYPO3 CLI commands for all AI extensions.</p>

<!-- Avoid: custom large title -->
<h1 class="aiu-scheduler-cli__title">…</h1>
<p class="aiu-scheduler-cli__intro">…</p>
```

If a wrapper class is needed for layout only, limit CSS to **margin**, not `font-size` / `font-weight`:

```css
.aiu-scheduler-cli__header {
    margin-bottom: 1rem;
}
```

### Monospace (CLI commands, tags)

Core token:

```css
font-family: var(--typo3-font-family-code);
```

For command tag buttons, only tighten size to match `btn-sm`:

```css
.aiu-scheduler-cli__command-tags .btn {
    font-family: var(--typo3-font-family-code);
}
```

Do **not** set separate `font-size: .6875rem` here — `btn-sm` already uses `--typo3-input-sm-font-size`.

### Muted / secondary text

| Avoid | Use |
|-------|-----|
| `color: var(--aiu-text-500)` / `--aiu-text-600` | `class="text-variant"` |
| `text-muted` (Bootstrap) | `text-variant` (TYPO3 v13+) |
| Custom `.72rem` grey captions | `class="small text-variant"` |

### What must change in ns_t3af (audit)

These overrides fight core typography and should be removed or rewritten when touching a file:

| File | Problem | Change to |
|------|---------|-----------|
| **`dashboard.css`** | ~~`.aiu-providers, .aiu-providers * { font-family: Inter, … }`~~ | **Done** — inherit `--typo3-font-family` from backend |
| **`dashboard.css`** | Pixel sizes: `10px`, `11px`, `12px`, `13px`, `24px` on KPIs, labels | Drop custom sizes; use default body, `small`, or semantic headings |
| **`shared-components.css`** | Pixel sizes, `"Fira Code"` on `.aiu-cell-title`, `.aiu-model-badge` | `small`, `text-variant`, `var(--typo3-font-family-code)` |
| **`credits.css`** | Custom title/stat sizes (`.7rem`, `1.35rem`, `font-weight: 800`) | `card-title`, `small text-variant`, semantic headings |
| **`setup.css`** | Many pixel/`rem` overrides on wizard copy | Core headings + `text-variant` / `small` |
| **`scheduler-cli.css`** | `.aiu-scheduler-cli__title { font-size: 1.45rem; font-weight: 700 }` | Replace template with `<h1>`; delete size/weight rules |
| **`scheduler-cli.css`** | `.aiu-scheduler-cli__intro`, KPI labels, quickref `font-size: .68rem–.95rem` | Use `text-variant`, `small`, or remove if redundant with callout |
| **`scheduler-cli.css`** | `.aiu-scheduler-cli__command-name { font-size: .875rem }` | Inherit; keep only `font-family: var(--typo3-font-family-code)` if needed |
| **`mcp-tools.css`** | `.aiu-tool-panel__name`, `__subtitle`, section titles with fixed rem | Rely on `btn-sm` / `small text-variant`; panel titles ≈ body + monospace |
| **`mcp-server.css`** | Many `11px`, `12px`, `.9375rem` headings | Align to `h2`/`h3` + component tokens |
| **Templates** | `<h3 class="card-title h6 mb-0">` | Use `<h2 class="card-title">` only — let core set size |
| **Templates** | `<h4 class="h6 … aiu-provider-card__title">` | Use `<h2 class="card-title">` in structured card header |
| **Templates** | Duplicate tagline in `card-subtitle` and `text-variant small` body | Single `card-subtitle` in header; body for tags only |
| **`dashboard.css`** | `.aiu-provider-cards--dashboard` viewport grid | **Removed** — use `card-container` only |
| **`McpOverview.html`** | Custom `__title`, `h3.card-title`, status dots, `btn-link`, nested `card-body` | **Migrated** — see **MCP overview (dashboard)** |

### Module typography baseline (required — `base.css`)

All module tabs inherit core typography via `.aiu-module-page`. **Do not duplicate these rules in tab CSS files.**

Implemented in `Resources/Public/Css/module/base.css`:

```css
.aiu-module-page {
    font-size: var(--typo3-font-size, inherit);
    line-height: var(--typo3-line-height, 1.5);
    color: var(--typo3-text-color-base, var(--typo3-component-color, inherit));
    font-family: var(--typo3-font-family, inherit);
}

.aiu-module-page h1,
.aiu-module-page h2,
.aiu-module-page h3,
.aiu-module-page h4,
.aiu-module-page h5,
.aiu-module-page h6,
.aiu-module-page .h1,
.aiu-module-page .h2,
.aiu-module-page .h3,
.aiu-module-page .h4,
.aiu-module-page .h5,
.aiu-module-page .h6 {
    font-family: var(--typo3-header-font-family, inherit);
    font-weight: 400;
}

.aiu-module-page code,
.aiu-module-page pre,
.aiu-module-page pre code,
.aiu-module-page .form-control.font-monospace {
    font-family: var(--typo3-font-family-code, ui-monospace, monospace);
}
```

**Never** set `font-family` on `.aiu-providers`, `.aiu-dashboard`, `.aiu-module-content`, or `*` descendants inside module wrappers.

### Typography checklist

- [ ] Module content inherits `.aiu-module-page` baseline from `base.css` (no wrapper font overrides)
- [ ] No `font-family: Inter`, `Fira Code`, or other non-core stacks
- [ ] Page title is `h1` (or `h2` inside cards) — no custom `__title` font-size
- [ ] Card text uses `card-title` / `card-subtitle` / `card-text` without extra `font-size`
- [ ] Muted copy uses `text-variant` (+ `small` if denser)
- [ ] Buttons use `btn` / `btn-sm` without font-size overrides
- [ ] CLI/command strings use `var(--typo3-font-family-code)` only
- [ ] No pixel font sizes (`10px`, `11px`, `13px`) in new CSS
- [ ] No rem literals (.68rem, .72rem, .95rem) unless matching a documented core token
- [ ] Line-height not forced to `1.1` / `1.15` on body text (use core `1.5`)

---

## Layout shell

Every module tab renders inside the TYPO3 backend module frame (`module-body` → `module-body-container`). **Do not** add Bootstrap `container-fluid` or `py-2` on the extension wrapper — core already handles module chrome and spacing.

```html
<div class="module-body t3js-module-body">
    <div class="module-body-container t3js-module-body-container">
        <f:flashMessages … />

        <div class="aiu-module-page">
            <div class="aiu-module-content">
                <!-- tab content -->
            </div>
        </div>
    </div>
</div>
```

Defined in `Resources/Private/Layouts/Module.html`.

| Wrapper | Role |
|---------|------|
| `.aiu-module-page` | Extension scope hook for CSS (`base.css` badges, typography, tokens) |
| `.aiu-module-content` | Tab content; horizontal padding via `--aiu-module-inline` in `base.css` |

**Do not** use `container-fluid py-2` on `.aiu-module-page` — it duplicates core padding and can misalign content with the docheader tabs.

The docheader (`Partials/Module/DocHeader.html`) may keep its own `container-fluid` on the inner row so tabs align with core module navigation; that is separate from the main content area.

**Do not** override global `.card` / `.btn` outside `.aiu-module-page` unless bridging tokens (see `base.css`).

---

## Shared UI in child extensions (ns_t3aa · ns_t3ai · ns_t3cs · …)

Several T3Planet AI extensions embed the **same** AI Foundation setup checklist and will share more AI Foundation UI over time. **Design lives in `ns_t3af` only** — child extensions consume it; they do not fork templates or CSS.

### Architecture (single source of truth)

```
ns_t3af (master)
├── Partials/Module/SetupChecklist.html      ← markup (TYPO3 core card)
├── Partials/Module/ChildSetupChecklistSlot.html  ← embed wrapper for child dashboards
├── Partials/Backend/AiLogsModuleLink.html   ← “AI Foundation Logs” toolbar button
├── Resources/Public/Css/module/base.css     ← tokens, badges, typography scope
├── Resources/Public/Css/module/setup.css    ← checklist + wizard styles
├── Resources/Public/JavaScript/setup-checklist.js
├── Resources/Public/JavaScript/module-navigation.js  ← in-iframe filter/link nav (AI Logs)
├── Classes/Service/SetupChecklistPresenter.php   ← PHP API for all extensions
└── Classes/Utility/BackendModuleLinkUtility.php  ← buildAiLogsUri() for child deep links
```

| Layer | Owner | Child extension rule |
|-------|--------|----------------------|
| **Markup** | `ns_t3af` partials | Never copy `SetupChecklist.html` into `ns_t3aa` / `ns_t3ai` / … |
| **CSS** | `base.css` + `setup.css` | Never duplicate `.aiu-checklist` rules in child `Style.css` |
| **JS** | `@nitsan/nst3af/setup-checklist.js` | Load only via `SetupChecklistPresenter::registerAssets()` |
| **Data** | `SetupChecklistService` | Use `buildChildAssigns()` — do not rebuild checklist arrays in child controllers |
| **Labels** | `locallang_mod.xlf` | Checklist strings stay in AI Foundation |

### Child extension integration (3 steps)

**1. Controller** (dashboard action, when `ns_t3af` is loaded):

```php
use NITSAN\NsT3AF\Service\SetupChecklistPresenter;

$checklistPresenter = GeneralUtility::makeInstance(SetupChecklistPresenter::class);
$checklistAssigns = $checklistPresenter->buildChildAssigns($request);

if (($checklistAssigns['showSetupChecklist'] ?? 0) === 1) {
    $checklistPresenter->registerAssets($this->pageRenderer); // base.css + setup.css + JS
    $assign = array_merge($assign, $checklistAssigns);
    $assign['checklistBodyId'] = 't3aa-checklist-body'; // unique per extension
    $assign['checklistDefaultOpen'] = '0';
}

// TYPO3 v12+ ModuleTemplate:
$checklistPresenter->configureViewPartials($view); // adds ns_t3af partial paths
```

**2. Template** (one line — same in all child dashboards):

```html
<f:render partial="Module/ChildSetupChecklistSlot" arguments="{
    setupChecklist: setupChecklist,
    checklistBodyId: checklistBodyId,
    checklistDefaultOpen: checklistDefaultOpen,
    showSetupChecklist: showSetupChecklist
}" />
```

**3. Do not** add checklist-specific CSS in the child extension.

`ChildSetupChecklistSlot.html` wraps content in `.aiu-module-page.aiu-module-page--child-embed` so badge/typography rules from `base.css` apply inside legacy child layouts (`t3aa-module`, `t3ai-module`, …) without migrating the whole child theme.

### AI Foundation Logs button (toolbar)

Same ownership rules as the checklist — partial + URL builder in `ns_t3af` only.

**Controller** (assign in `initializeModuleTemplate()` or dashboard action):

```php
use NITSAN\NsT3AF\Utility\BackendModuleLinkUtility;

$pageId = (int) ($request->getQueryParams()['id'] ?? 0);
$assign['aiUniverseLogsUri'] = BackendModuleLinkUtility::buildAiLogsUri($pageId, 'ns_t3cs');
// configureViewPartials($view) — same call as checklist (registers partial root)
```

**Template** (dashboard toolbar, typically next to checklist / tabs):

```html
<f:render partial="Backend/AiLogsModuleLink" arguments="{aiUniverseLogsUri: aiUniverseLogsUri}" />
```

**Do not** pass `extensionName` on `f:render`. See `context/features/ai-logs.md` for iframe filter navigation pitfalls (`token`, nested backend).

### Currently integrated

| Extension | Template | Controller |
|-----------|----------|------------|
| **ns_t3aa** | `Resources/Private/Templates/Backend/Dashboard.html` | `BackendController.php` |
| **ns_t3ai** | `Resources/Private/Templates/T3AiBackend/Dashboard.html` | `T3AiBackendController.php` |
| **ns_t3cs** | `Resources/Private/Partials/Dashboard/Dashboard.html` | `T3CsBackendController.php` |
| **ns_t3af** | `DashboardChecklistSlot.html` | `ModuleController.php` |

**ns_t3as** — not integrated yet; use the same 3-step pattern when adding the checklist.

### Migrating a child extension to full core design

Do **gradually**:

1. **Phase 1 (now):** Shared widgets only (checklist + AI Foundation Logs link) via partials + `configureViewPartials()`.
2. **Phase 2:** Wrap the whole child `module-body` content in `<div class="aiu-module-page"><div class="aiu-module-content">…</div></div>` and load `base.css` once in the child layout or controller.
3. **Phase 3:** Replace child-specific cards/buttons with patterns from this guide; delete duplicated CSS that fights core.

Child-specific branding (tabs, breadcrumbs, product copy) stays in each extension. **Shared AI chrome** (checklist, AI Logs link, credits badge, provider drawer patterns) stays in `ns_t3af`.

### Adding a new shared component (for all extensions)

1. Build partial + CSS under `ns_t3af` (follow this design guide).
2. Add a small **Presenter** or method on `SetupChecklistPresenter` (or new `SharedModuleUiPresenter`) with:
   - `registerAssets(PageRenderer)` — always include `base.css` first
   - `configureViewPartials(ModuleTemplate)` — register partial root path
   - `buildAssigns(Request)` — data for the partial
3. Document the Fluid one-liner and controller calls in this section.
4. **Never** copy the partial into child extensions.

### Checklist for child extension developers

- [ ] Uses `SetupChecklistPresenter` — no local checklist HTML/CSS
- [ ] Uses `BackendModuleLinkUtility` + `AiLogsModuleLink` — no local “view logs” button markup
- [ ] Unique `checklistBodyId` per extension (e.g. `t3aa-checklist-body`)
- [ ] `configureViewPartials()` called before render (v12+ ModuleTemplate)
- [ ] No `container-fluid py-2` around shared partials
- [ ] New shared UI added to `ns_t3af`, not forked per child ext

---

## Cards (v14 primary pattern)

Reference: TYPO3 Styleguide → Components → **Cards**, Submodule Overview (`backend/Resources/Private/Templates/SubmoduleOverview/Cards.fluid.html`), AI Features (`Partials/AiFeatures/Card.html`).

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
| `card-size-small` | 1 column (~25% of container) |
| `card-size-medium` | Spans 2 columns (~50%) |
| `card-size-large` | Full width (`grid-column: 1 / -1`) |
| `card-size-fixed-small` | Fixed small width at all viewports (styleguide; good for dense tile rows) |

Use **`card-size-large`** for expandable lists (Scheduler CLI command library). Use **`card-size-small`** or **`card-size-fixed-small`** for feature tiles (AI Features grid, provider cards).

**v12 note:** `.card-container` may render as a simple stack. That is acceptable — do not maintain a separate v12 grid template unless layout breaks visibly.

### Anatomy (default)

```html
<div class="card card-size-small">
    <div class="card-header">
        <div class="card-icon">
            <core:icon identifier="actions-terminal" size="medium" alternativeMarkupIdentifier="inline" />
        </div>
        <div class="card-header-body">
            <h2 class="card-title">Title only</h2>
            <span class="card-subtitle">Short description</span>
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

### Card title + badge (side by side)

When a card shows a **title and a badge** together (extension key, status, count), use a flex row inside `card-header-body` — not a badge inline inside `card-title` (which wraps badly on narrow cards).

**Pattern:** `card-header-body__top` + Bootstrap flex utilities (extension hook; layout-only, no custom CSS required).

```html
<div class="card-header-body">
    <div class="card-header-body__top d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title text-truncate mb-0">Feature Toggles</h2>
        <span class="badge badge-default flex-shrink-0">ns_t3ai</span>
    </div>
    <span class="card-subtitle">Manage features</span>
</div>
```

| Class | Purpose |
|-------|---------|
| `card-header-body__top` | Extension wrapper for title + badge row (not in core CSS — layout hook only) |
| `d-flex align-items-center justify-content-between gap-2` | Title left, badge right |
| `card-title text-truncate mb-0` | Core title scale; truncate long names; no extra bottom margin in flex row |
| `badge badge-default flex-shrink-0` | Core badge; never shrink or wrap under the title |

Status badges use core variants: `badge-success`, `badge-warning`, `badge-default`, `badge-primary` — same row pattern.

**Reference:** `Resources/Private/Partials/AiFeatures/Card.html`, `Resources/Private/Partials/Module/Dashboard/ProviderCard.html`.

**MFA core alternative:** Core MFA overview sometimes places a badge *inside* `card-title` when only one short badge follows the title. Prefer **`card-header-body__top`** when the badge must stay aligned top-right (extension keys, status labels).

### Equal height in `card-container`

Do **not** use `h-100` on cards inside `.card-container` — `height: 100%` breaks flex row stretch.

```css
.my-grid.card-container {
    align-items: stretch;
}
.my-grid.card-container .card {
    align-self: stretch;
    height: auto;
}
.my-grid.card-container .card .card-footer {
    margin-top: auto;
}
```

Put actions in **`card-footer`** (not `card-body`) so buttons align at the bottom when descriptions differ in length (styleguide → Cards → Alignment).

Reference: `Resources/Public/Css/module/ai-features.css` (`.aiu-ai-features__grid`).

### Critical DOM rule — footer must be last

TYPO3 core card CSS (v13+):

- `.card-footer:last-child` gets bottom padding.
- `.card-footer { margin-top: auto; }` inside flex column cards.
- `.card { overflow: hidden; }`

**If any sibling comes after `.card-footer` (even `hidden`), the footer loses padding and can be clipped.**

**Correct order:**

```
card-header → card-body → [expandable section, hidden] → card-footer   ← last
```

**Wrong:**

```
card-header → card-body → card-footer → .tools[hidden]   ← breaks footer
```

See `Partials/SchedulerCli/CommandLibrary.html`.

### Tables inside cards

Core strips most of `.table-fit` border inside `.card` (left/right/bottom) but **keeps `border-top`**. That stacks with the card edge and looks like a bold 2px line. For nested parameter tables, zero the table-fit border entirely — the card alone provides the frame:

```css
.aiu-tool-panel__body .card > .table-fit,
.aiu-mcp-tool-modal__body .card > .table-fit {
    border: 0;
    box-shadow: none;
}
```

Do **not** add a second `border: 1px` on `.card` when core `.card` already supplies it.

```html
<div class="card mb-0">
    <div class="table-fit">
        <table class="table table-striped table-hover align-middle mb-0">…</table>
    </div>
</div>
```

Use plain `<code>` for parameter names — avoid custom chip classes when core styling is enough.

---

## Dashboard migration (in progress)

The Dashboard tab still mixes custom skins with core patterns. Migrate in this order when touching dashboard UI:

| Priority | File / class | Current | Target (styleguide / core) |
|----------|--------------|---------|----------------------------|
| 1 | `ProviderCard.html` | `aiu-provider-card`, custom status badges | `card card-size-fixed-small` + `card-header` / `card-icon` + `badge badge-success\|warning\|default` |
| 2 | `dashboard.css` | `font-family: Inter` on `.aiu-providers` | Remove; inherit backend typography |
| 3 | `aiu-panel` sections | Custom panel chrome in `DashboardOwnKeysView.html`, `DashboardCreditsView.html` | Outer `.card card-size-large`; chart area in `card-body` |
| 4 | `aiu-kpi-card` | Custom KPI tiles in `FooterKpis.html`, `PeriodSummary.html` | Core `.card` tiles or callout stats row |
| 5 | `ModeBanner.html` | `.card` with custom `aiu-dashboard-mode-banner` skin | `callout callout-info` (or keep compact `.card` with token-only CSS) |
| 6 | `aiu-provider-cards--dashboard` grid | Custom CSS grid in `dashboard.css` | **Done** — plain `.card-container`; see **Provider cards (dashboard)** |
| 7 | `McpOverview.html` | Custom title class, `h3.card-title`, status dots, `btn-link` footers | **Done** — see **MCP overview (dashboard)** below |

Provider card status mapping:

| Status | Badge class | Icon identifier |
|--------|-------------|-----------------|
| active | `badge badge-success` | `actions-shield` |
| attention | `badge badge-warning` | `actions-exclamation-triangle` |
| offline / inactive | `badge badge-default` | `actions-ban` |

Metric sub-blocks inside a card may use Bootstrap `row` / `col-*` (styleguide pattern) — not custom `aiu-provider-card__metric-card` skins.

### Provider cards (dashboard)

Reference: `DashboardOwnKeysView.html` → `card-container` + `Partials/Module/Dashboard/ProviderCard.html` (`card card-size-small`).

**Do not** add custom viewport grids (`.aiu-provider-cards--dashboard`, `@media` column counts, `display: grid` on card wrappers). TYPO3 core `.card-container` owns responsive layout via **container queries** in `backend.css`:

| Container width | Core grid |
|-----------------|-----------|
| &lt; 768px | 1 column |
| ≥ 768px | 2 columns |
| ≥ 1200px | 4 columns |

Each tile is `card card-size-small` (one grid cell). **No extension CSS** for the grid — same rule as MCP overview and Submodule Overview.

```html
<div class="card-container">
    <f:for each="{providerCards}" as="card">
        <f:render partial="Module/Dashboard/ProviderCard" arguments="{card: card}" />
    </f:for>
</div>
```

**Forbidden:** `aiu-provider-cards`, `aiu-provider-cards--dashboard`, duplicate `@media (max-width: …)` breakpoints that mirror `card-container` — they drift from core on v12–v14 upgrades.

### MCP overview (dashboard)

Reference: `Resources/Private/Partials/Module/Dashboard/McpOverview.html` — **no extension CSS**; grid and card chrome from `backend.css`.

Three `card card-size-small` tiles inside plain `.card-container` (same as `SubmoduleOverview/Cards.fluid.html`). TYPO3 core manages the responsive grid:

| Container width | Core grid |
|-----------------|-----------|
| &lt; 768px | 1 column (stack) |
| ≥ 768px | 2 columns |
| ≥ 1200px | 4 columns (three tiles use the first three cells) |

Do **not** add custom `@container` column overrides or stretch helpers — core `.card` is already `display:flex; flex-direction:column` with `.card-footer { margin-top: auto }`.

**Critical:** Render **outside** `.aiu-providers` in `Dashboard.html`. The dashboard wrapper sets `font-family: Inter` on all descendants (`dashboard.css`), which overrides core `--typo3-font-family` / `--typo3-font-size` even inside `.aiu-module-page`. Only blocks outside `.aiu-providers` inherit true backend typography.

| Card | Header | Body | Footer |
|------|--------|------|--------|
| **Server** | `card-header-body__top` + `badge-success\|danger` | `<dl>` rows: `dt.fw-bold` + `dd`; transports inline; **metrics bar**: `row` + `h4.fw-bold` values + `small text-variant` labels + `border-top` / `border-start` | `btn btn-default` |
| **Top tools** | `card-icon` + `card-title` / `card-subtitle` | Ranked list + core `.progress` bar; empty → `card-text text-variant` | `btn btn-default w-100` |
| **Active clients** | Same header pattern | `list-group-flush` rows or empty `card-text` | `btn btn-default w-100` |

**v12 · v13 · v14 rules for this block:**

| Concern | Approach |
|---------|----------|
| Section title | `<h2 class="mb-0">` — inherits `--typo3-header-font-family` via `.aiu-module-page` |
| Card titles | `<h2 class="card-title">` inside `card-header-body` (not `h3`, not `h6`) |
| Status | Core `badge badge-success` / `badge-danger` in `card-header-body__top` — **no** custom status dots |
| KPI row | Bootstrap `row g-2` + `col-4` + `small text-variant` labels |
| Muted copy | `text-variant`, `small text-variant` — not `text-muted` or `fw-semibold` |
| Footers | `btn btn-default` (not `btn-link`) — last child in card DOM |
| Equal height | Core `.card` flex column + `.card-footer { margin-top: auto }` — no extension CSS |
| Client list empty | Split `f:if` so `card-body p-0` only wraps the list — avoids nested `card-body` |
| Inter isolation | Partial rendered **outside** `.aiu-providers` in `Dashboard.html` |
| Dark mode | No hard-coded greys; progress bars and list groups inherit `backend.css` |

---

## Callouts (banners & empty states)

Prefer callouts over custom banner divs (`aiu-skills-banner`, `aiu-dashboard-mode-banner` with heavy custom CSS, etc.).

```html
<div class="callout callout-info mb-3">
    <div class="callout-icon">
        <core:icon identifier="actions-terminal" size="medium" />
    </div>
    <div class="callout-content flex-grow-1">
        <div class="callout-title">Title</div>
        <div class="callout-body">
            <p class="mb-0">Description</p>
        </div>
    </div>
    <div class="text-end text-variant small flex-shrink-0">
        <!-- optional stats / CTA -->
        <a href="…" class="btn btn-default btn-sm">Action</a>
    </div>
</div>
```

| Variant | Use |
|---------|-----|
| `callout-info` | Primary tab introduction, mode banner (credits / own keys) |
| `callout-notice` | Empty state, neutral hint |
| `callout-secondary` | Secondary sections (e.g. Custom MCP tab) |

Extension hook: `.callout.callout-info.mb-3.aiu-*__banner` — only flex alignment, no background overrides.

**v12 fallback:** If callout styles are thin, `f:be.infobox` remains valid; migrate to callout when minimum target is v13+.

---

## Buttons & segmented controls

### Primary actions

```html
<button type="button" class="btn btn-primary">Save</button>
<a href="…" class="btn btn-default">Cancel</a>
```

### Tab / filter switches (segmented control)

```html
<div class="btn-group" role="group" aria-label="…">
    <a href="…" class="btn btn-default active is-active">Tab A</a>
    <a href="…" class="btn btn-default">Tab B</a>
</div>
```

For filter chips:

```html
<button type="button" class="btn btn-default btn-sm active is-active" data-filter="all">All</button>
<button type="button" class="btn btn-default btn-sm" data-filter="…">…</button>
```

**JS:** Toggle both `active` and `is-active` for compatibility with core and extension scripts.

### MCP client setup tabs (filter chips)

Reference: `Partials/McpServer/Connect/ClientSetupGuides.html`.

Wrapping client picker (Claude, Cursor, VS Code, …) uses core **`btn btn-default btn-sm`** in a `d-flex flex-wrap gap-1` row — **not** custom pill tabs (`aiu-mcp-client-tab`, `--aiu-primary` blue fill).

```html
<div class="d-flex flex-wrap gap-1 mb-3" data-mcp-client-tabs role="tablist" aria-label="…">
    <button type="button"
            class="btn btn-default btn-sm d-inline-flex align-items-center gap-1 active is-active"
            data-client-tab="cursor"
            role="tab"
            aria-selected="true">
        <core:icon identifier="…" size="small" />
        Cursor
    </button>
</div>
```

| Avoid | Use instead |
|-------|-------------|
| `aiu-mcp-client-tab`, pill `border-radius: 999px` | `btn btn-default btn-sm` |
| `--aiu-primary` / blue active background | Core `.btn-default.active` / `.is-active` (light gray chrome) |
| Custom `font-size: 12px`, `font-weight: 600` on tabs | Core button typography |

**JS:** `mcp-server.js` toggles `active` + `is-active` on `[data-client-tab]` (same as mode switches).

### MCP Tools sidebar (Tools & Resources)

Reference: `Partials/McpTools/ToolsResourcesShell.html`, `mcp-tools.css`.

| Avoid | Use instead |
|-------|-------------|
| `btn btn-primary` on **Create Custom Tool** | `btn btn-default w-100` (gray chrome, not module purple) |
| `--typo3-surface-container-primary` / `--aiu-primary-soft` on active nav | `--aiu-selected-bg` + `--aiu-selected-border` |
| Bootstrap `--bs-primary` / `#6f42c1` icon tints | `--aiu-tint-neutral-bg` + `--typo3-text-color-base` |
| Purple Skill Hub install / dropzone hover | `btn btn-default` + `--typo3-state-default-*` |

Sidebar uses **`card card-size-small`** with **`card-header`** title + **`card-body`** list. Each nav row is **icon left, two-line label right** (`.aiu-mcp-tools-nav__item` → `__title` → `span.__label` + `span.__meta`) — **no `h4`/`h6` inside tab buttons** (core typography). Layout matches Data Sources sidebar; active/hover use `--aiu-selected-*` gray chrome, not brand blue.

**Per-tab content panel banners** (all use `.aiu-mcp-tools__banner` + `.aiu-mcp-tools__banner-title` with inline `core:icon`):

| Sidebar tab | Panel partial | Banner variant | Content pattern |
|-------------|---------------|----------------|-----------------|
| AI Extensions | `ExtensionsPanel.html` | `callout-info` | `btn-group` category filter + `card-container` extension cards |
| Custom | `CustomTab.html` (via `CustomPanel`) | `callout-secondary` | `btn-default` discover/config + striped table + `col-control` row actions |
| Prompt Templates | `PromptsPanel.html` | `callout-info` (same as Extensions) | Same card anatomy as Extensions: `card-header` (icon + body only) → expandable body → `card-footer` + `btn-group`; footer hint = `callout-notice` |
| Custom Tools | `CustomToolsListPanel.html` | `callout-secondary` | `badge-default` count + striped table + `col-control` delete |

**Self-check (sidebar + each tab):**

- [ ] Create Custom Tool: `btn btn-default w-100` (not `btn-success`)
- [ ] Nav labels are `span`, not heading elements
- [ ] Active tab: gray `--aiu-selected-*` background, meta text same colour as label
- [ ] Each panel banner: icon + title on one line (`__banner-title`), body below
- [ ] Resources / Prompts / Custom Tools banners match Extensions / Custom layout (no legacy `callout-icon` column)
- [ ] Table action columns use `col-control` + `btn-group`

All MCP Tools partial actions (playground run, prompt save, custom tool submit, resource preview) use **`btn btn-default`** unless the action is explicitly destructive (`btn-danger`).

### MCP Tools extension cards (AI Extensions panel)

Reference: `Partials/McpTools/ExtensionsPanel.html`, `McpToolsExtensionCardProviderInterface`, `mcp-tools.css`.

| Section | Pattern |
|---------|---------|
| Grid | `card-container` + `card card-size-large` (full-width expandable rows) |
| Header icon | **`core:icon`** with catalog `iconIdentifier` + `alternativeMarkupIdentifier="inline"` — **never emoji** in `card-icon` |
| Header body | `card-header-body__top` (title + `badge badge-default` count) + `card-subtitle` |
| Skill trigger | `code.aiu-mcp-inline-code` (neutral gray chip) — **not** bare `<code>` (inherits link/red colour) |
| Tool preview | `card-body` → `btn btn-sm btn-default` tags with `--typo3-font-family-code` |
| Expandable detail | `.aiu-ext-card__tools[hidden]` **before** `card-footer`; skill intro uses `aiu-ext-card__skill-strip` |
| Download Skill | **`btn btn-success btn-sm`** + `actions-download` with `alternativeMarkupIdentifier="inline"` (same as AI Usage export) |
| View Tools | `btn btn-default btn-sm` in same `btn-group` |
| Borders | **`--typo3-card-border-color`** on card, header, footer, tool list — no coloured `iconBg` tiles |

**Catalog:** each extension entry must define `iconIdentifier` (e.g. `content-widget-text`, `actions-search`, `actions-extension`, `actions-chat`, `actions-message`). PHP fallback: `actions-extension`.

**Forbidden:** emoji in `card-icon`; inline `style="background-color: …"` icon tiles; `btn-default` on Download Skill; badge inline inside `card-title`; actions in `card-header`; expandable block after `card-footer`; custom border colours (`--aiu-border-soft`) on extension cards.

**Self-check (MCP Tools → AI Extensions):**

- [ ] Card icons are TYPO3 SVG sprites (not emoji on coloured squares)
- [ ] Download Skill is green `btn-success` with visible download icon
- [ ] Trigger `/t3ai` etc. uses gray `aiu-mcp-inline-code`, not red link colour
- [ ] Card borders match other backend cards (single `--typo3-card-border-color` weight)
- [ ] Expanded tool list has no double border gap between tags and first tool row

### MCP Tools tool panel + drawer (expanded row / slide-over)

Reference: `Partials/McpTools/ToolDetailDrawer.html` (`ToolBody` + `Drawer` sections), `mcp-tools.css`.

| Area | Pattern |
|------|---------|
| Inline expand | `.aiu-tool-panel` accordion row inside extension card (not a Bootstrap modal) |
| Parameter table | Section label (`text-variant fw-bold`) + nested `card mb-0` → `table-fit` → `table table-striped table-hover` |
| Param names | Plain `<code>` in table cells — neutral colour via scoped CSS in `.aiu-tool-panel__body` / `.aiu-mcp-tool-drawer .aiu-drawer__body` (same as Scheduler CLI `CommandLibrary.html`) |
| Param types | `span.text-variant` — not chip-styled `<code>` |
| Required column | `badge badge-default` for required; `text-variant small` for optional — not red text |
| Example prompts | `ul.aiu-mcp-tool-prompts` list items with `--typo3-card-border-color` — not nested `card` per prompt |
| Slide-over drawer | Shared `aiu-drawer` + `aiu-drawer__panel` (same shell as `AiFeatures/Drawer.html`); close `btn btn-default btn-sm` top-right in `aiu-drawer__header` |
| Drawer title | `span.aiu-mcp-tool-drawer__name` with `--typo3-font-family-code` inside `h2.aiu-drawer__title` |
| Drawer borders | `--typo3-card-border-color` on panel edge, header, footer; flush to viewport edge (no radius on right) |

**Basic test cases (required — do not skip when sharing final status):**

1. **View Tools** → expand one tool row → parameter table visible with gray monospace names (not red/pink).
2. Click a **tool tag chip** → slide-over drawer opens; same table styling; close button works.
3. **Download Skill** stays green; **View Tools** / drawer actions stay gray `btn-default`.
4. Compare parameter table border weight to **Styleguide → Tables** nested in card.
5. Light + dark theme on expanded row and drawer.

### Action groups in rows

```html
<div class="btn-group" role="group">
    <button type="button" class="btn btn-sm btn-default">Run</button>
    <a href="…" class="btn btn-sm btn-default">Schedule</a>
</div>
```

Avoid custom ghost buttons (`aiu-mcp-action-btn`, `aiu-btn-outline`) in new UI — use `btn btn-default`.

### Table row icon actions (v12 · v13 · v14)

Reference: `beuser/Resources/Private/Partials/BackendUser/PaginatedList.fluid.html`, `Partials/Provider/Row.html`.

Use core table control column + segmented icon buttons — **no** `aiu-icon-btn` in list tables.

```html
<td class="col-control">
    <div class="btn-group" role="group" aria-label="Actions">
        <a class="btn btn-default" href="…" title="Edit" role="button">
            <core:icon identifier="actions-open" size="small" />
        </a>
        <button type="button" class="btn btn-default" title="Test">
            <core:icon identifier="actions-refresh" size="small" />
        </button>
        <span class="btn btn-default disabled" title="Already default">
            <core:icon identifier="actions-check" size="small" />
        </span>
        <form method="post" action="…" id="record-delete-42">
            <input type="hidden" name="uid" value="42" />
        </form>
        <button type="submit"
                class="btn btn-danger t3js-modal-trigger"
                data-target-form="record-delete-42"
                title="Delete"
                data-severity="warning"
                data-title="{core delete title}"
                data-content="Do you really want to delete …?"
                data-button-close-text="Cancel"
                data-button-ok-text="Yes, delete this record">
            <core:icon identifier="actions-delete" size="small" />
        </button>
    </div>
</td>
```

| Rule | Detail |
|------|--------|
| Column | `th` / `td` → `col-control` (core: right-aligned, nowrap — `backend.css`) |
| Group | `btn-group` + `role="group"` + `aria-label` |
| Buttons | `btn btn-default` for edit / test / meta — icons via `<core:icon size="small" />` |
| Delete | `btn btn-danger` + `t3js-modal-trigger` + `data-target-form` (core confirm modal — **not** `confirm()` in custom JS) |
| Disabled slot | `span.btn.btn-default.disabled` + `empty-empty` or status icon (core beuser pattern) |
| Delete form | Separate `<form id="…">` with hidden fields only — button uses `data-target-form`, not `form=""` attribute |
| Avoid | `aiu-icon-btn`, `confirm()` in extension JS, inline `style="display:inline"` on forms, custom square icon skins |

**JS:** Keep `data-aiu-*` hooks on non-delete buttons. Delete confirmation is handled by TYPO3 core `t3js-modal-trigger` (loaded with backend module chrome).

---

## Search

```html
<div class="input-group">
    <label class="visually-hidden" for="search-id">Search</label>
    <span class="input-group-text">
        <core:icon identifier="actions-search" size="small" />
    </span>
    <input type="search" id="search-id" class="form-control" placeholder="…" />
</div>
```

Reference: `Templates/Module/McpTools.html`, styleguide → Components → **Input**.

---

## Badges

| Class | Use |
|-------|-----|
| `badge badge-default` | Counts, extension keys, neutral meta, inactive status |
| `badge badge-success` | Active / schedulable / OK |
| `badge badge-warning` | Caution, needs attention |
| `badge badge-danger` | Error / locked |
| `badge badge-primary` | Default provider, highlighted meta |

Do **not** use inline `style="color: …; background: …"` on badges. Do **not** use Bootstrap `text-bg-*` alone in module pages — `base.css` bridges soft badge colours to match core.

Do **not** use custom pill classes (`aiu-provider-card__status-badge`, `rounded-pill` with hard-coded colours) when core badge variants express the same meaning.

---

## Typography helpers (quick reference)

| Class / token | Use |
|---------------|-----|
| `text-variant` | Muted / secondary text |
| `small` | Dense meta (maps to ~`--typo3-font-size-small`) |
| `card-title` / `card-subtitle` / `card-text` | Card hierarchy — **no extra font-size** |
| `var(--typo3-font-family-code)` | CLI snippets, `<code>`, monospace tags |
| `h1`–`h6` | Page/section titles — Open Sans, weight 400 |

Full typography rules: see **Typography (font, size, line-height)** above.

---

## Icons

Always use ViewHelper — never Font Awesome / emoji in new UI (legacy emoji in catalog data may remain in icons until migrated).

```html
<core:icon identifier="actions-chevron-down" size="small" />
```

Common identifiers: `actions-terminal`, `actions-search`, `actions-chevron-down`, `actions-lightbulb-on`, `actions-key`, `actions-shield`, `spinner-circle`.

Reference: styleguide → Components (any component with icons).

---

## Expandable sections (accordion-style)

Core cards do not ship a dedicated accordion. Pattern used in Scheduler CLI / MCP Tools:

1. Keep expandable block **before** `card-footer`.
2. Toggle `[hidden]` on the block via JS (`data-*` hooks).
3. Style panel headers with extension CSS using **`--typo3-*` tokens**, not MCP-specific white/grey backgrounds.
4. Chevron: `<core:icon identifier="actions-chevron-down" />` + rotate when `.is-open`.

Preserve stable hooks: `data-scheduler-cli-toggle`, `data-scheduler-cli-card`, `.aiu-scheduler-cli__tools`, etc.

---

## CSS rules for extension modules

### Prefer tokens with fallbacks (v12–v14)

```css
.my-block {
    background: var(--typo3-surface-container-low, var(--aiu-bg-soft));
    border: 1px solid var(--typo3-component-border-color, var(--aiu-border));
    color: var(--typo3-text-color-base, var(--aiu-text-700));
    border-radius: var(--typo3-card-border-radius, var(--typo3-component-border-radius, .375rem));
}
```

Brand colours (`--aiu-primary`) are **bridged to TYPO3 core** in `base.css` (`--typo3-link-color`, `--typo3-state-primary-*`). Do **not** hardcode extension blue (`#1a56db`, `#2563eb`, `rgba(26, 86, 219, …)`).

### Colour tokens (v12–v14)

| Token | Maps to | Use for |
|-------|---------|---------|
| `--aiu-primary` | `--typo3-link-color` / `--typo3-state-primary-color` | **Links only** (`.aiu-panel__link`, server URLs) |
| `--aiu-selected-*` | `--typo3-state-default-*` | Active chips, toggles, wizard picks, transport cards |
| `--aiu-tint-info-*` | `--typo3-badge-info-*` | Info badges / MCP client glyph (not solid blue fills) |
| `--typo3-text-color-variant` | core muted text | Progress rings, chart bars, hero metrics |

**Forbidden:** solid `#1a56db` / `#2563eb` backgrounds, blue pill tabs, blue hero gradients, `Inter` font override on `.aiu-providers`.

Surfaces, borders, and body text must use `--typo3-*` first (see token block above).

### Avoid

- Hard-coded light-only greys: `#f8fafc`, `#fff` without `var(--typo3-…)` fallback
- Duplicating full card/panel skins when `.card` + core sections suffice
- Large custom grid systems when `.card-container` fits
- Parallel typography stacks (`Inter`, pixel `font-size`)

### Module-specific CSS files

| File | Tab |
|------|-----|
| `base.css` | Shell, badges bridge, docheader, dark-mode token bridge |
| `dashboard.css` | Dashboard, provider cards, panels, KPIs (MCP overview: **no** dedicated CSS) |
| `shared-components.css` | Table, mode toggle, drawer, toolbar (Providers + shared) |
| `mcp-tools.css` | MCP Tools (shared tool-panel styles — do not reuse in Scheduler CLI) |
| `scheduler-cli.css` | Scheduler & CLI |
| `ai-features.css` | AI Features card grid |
| `ai-usage.css` | AI Usage |
| `ai-prompts.css` | AI Prompts |
| `setup.css` | Setup wizard / checklist |

Add layout-only rules (flex banner, grid gaps, chart height). Do not re-theme core components.

---

## JavaScript conventions

- Use `@typo3/backend/modal.js` for forms (Run command, etc.).
- Toggle `active` + `is-active` on filter/mode buttons.
- Toggle `aria-expanded` and `.is-open` on expandable headers.
- Scope queries to `[data-aiu-scheduler-root]` / `[data-mcp-tools]` / `[data-aiu-features-root]` roots.

---

## Checklist for new UI

- [ ] Compared markup with **styleguide → Components** for the same pattern
- [ ] Uses `.card-container` + `card-size-*` — **no** custom `@media` grid on card wrappers (see **Provider cards (dashboard)**, **MCP overview**)
- [ ] Title + badge: `card-header-body__top` flex row (see **Card title + badge**)
- [ ] Equal-height grid: no `h-100`; `align-items: stretch` + `card-footer { margin-top: auto }`
- [ ] Card sections: `card-header` → `card-body` → optional expand → **`card-footer` last**
- [ ] Buttons: `btn btn-default` / `btn-primary`, `btn-group` for segments
- [ ] Info banner: `callout callout-info`, empty: `callout callout-notice`
- [ ] Tables: `table table-striped table-hover` + `table-fit`; nested `card` when inside parent card
- [ ] Badges: `badge badge-default|success|…` without inline colours or custom pill skins
- [ ] Icons: `<core:icon … />`
- [ ] Muted text: `text-variant`
- [ ] Typography: core only — `.aiu-module-page` baseline, no Inter/Fira/pixel sizes
- [ ] Dark mode: `--typo3-*` tokens with fallbacks in new CSS
- [ ] JS hooks preserved when refactoring markup
- [ ] Tested on target TYPO3 version(s) in light **and** dark backend theme

---

## Migration map (legacy → core)

| Legacy (avoid in new code) | Core replacement |
|----------------------------|------------------|
| `aiu-skills-banner` | `callout callout-info` |
| `aiu-dashboard-mode-banner` (heavy custom CSS) | `callout callout-info` or token-only `.card` |
| `aiu-flash` | `callout` or `f:flashMessages` / `f:be.infobox` |
| `aiu-provider-card`, `aiu-provider-card__status-badge` | `card` + `card-header` + `badge badge-*` |
| `aiu-panel`, `aiu-panel__head` | `.card` + `card-header` / `card-body` |
| `aiu-kpi-card` | `.card` tile or callout stats |
| `aiu-ext-card__header` layout | `card-header` + `card-icon` + `card-header-body` |
| `aiu-ext-card__tool-tag` | `btn btn-sm btn-default` |
| `aiu-table`, `aiu-code-chip` | `table` + `<code>` |
| `aiu-mcp-action-btn--ghost`, `aiu-btn-outline` | `btn btn-default btn-sm` |
| `aiu-mcp-client-tab` (blue pill tabs) | `btn btn-default btn-sm` + `active is-active` |
| `aiu-icon-btn` in table rows | `btn-group` + `btn btn-default` in `td.col-control` |
| `row g-3` only grids | `card-container` (v14) |
| `aiu-provider-cards`, `aiu-provider-cards--dashboard` + viewport `@media` grids | Plain `card-container` + `card-size-small` (core container queries) |
| Custom status dot / coloured pill (`aiu-mcp-overview-card__status-dot`) | `badge badge-success` / `badge-warning` / `badge-default` in `card-header-body__top` |
| `aiu-dashboard__mcp-overview-title` custom heading | `<h2 class="mb-0">` (core heading scale) |
| `btn btn-link` card footers | `btn btn-default w-100` |
| CSS chevron pseudo-element | `core:icon actions-chevron-down` |
| `font-family: Inter` on `.aiu-providers` | Remove — inherit `.aiu-module-page` / `--typo3-font-family` |
| `font-family: "Fira Code"` on cells, badges, fields | `var(--typo3-font-family-code)` or plain `<code>` |
| `.aiu-*__title` with `font-size: 1.45rem` | Semantic `<h1>` + core heading styles |
| `10px` / `11px` / `13px` label CSS | `small`, `text-variant`, or default body |
| `.aiu-tool-panel__subtitle { font-size: .72rem }` | `small text-variant` in markup |

---

## Reference files

### Inside ns_t3af

| Pattern | File |
|---------|------|
| Core card tile (title + badge row) | `Resources/Private/Partials/AiFeatures/Card.html` |
| Core card tile (title + status badge) | `Resources/Private/Partials/Module/Dashboard/ProviderCard.html` |
| Dashboard MCP overview (3-card grid) | `Resources/Private/Partials/Module/Dashboard/McpOverview.html` |
| Dashboard provider cards grid | `DashboardOwnKeysView.html` + `Partials/Module/Dashboard/ProviderCard.html` |
| Callout banner + card grid | `Resources/Private/Partials/McpTools/ExtensionsPanel.html` |
| Callout + `card-container` + expandable footer | `Resources/Private/Partials/SchedulerCli/CommandLibrary.html` |
| Mode / filter `btn-group` | `Partials/SchedulerCli/ScheduledTasks.html`, `Partials/AiUsage/ModeSwitch.html` |
| Table row icon `btn-group` | `Partials/Provider/Row.html` |
| Dashboard (needs migration) | `Partials/Module/Dashboard/ProviderCard.html`, `DashboardOwnKeysView.html` |
| Token bridge + typography baseline | `Resources/Public/Css/module/base.css` |

### TYPO3 core / styleguide

| Pattern | Path |
|---------|------|
| Styleguide card examples | `styleguide/Resources/Private/Templates/Backend/Components/Cards.html` |
| Styleguide buttons, forms, tables | `styleguide/Resources/Private/Templates/Backend/Components/*.html` |
| Submodule cards (production core) | `typo3/sysext/backend/Resources/Private/Templates/SubmoduleOverview/Cards.fluid.html` |
| Backend CSS tokens + typography | `typo3/sysext/backend/Resources/Public/Css/backend.css` |
| Styleguide typography / colours | Styleguide → **Styles** submodule |

---

## Testing

### Self-design testing (required before merge)

When changing Fluid markup or module CSS, **manually verify on each supported TYPO3 version** you have locally (v12.4, v13.4, v14.3). Do not rely on markup-only review.

| Step | Action |
|------|--------|
| 1 | Flush caches / hard-refresh after CSS or template changes |
| 2 | Open **System → Styleguide → Components** on the **same** TYPO3 version and compare the matching pattern (Cards, Buttons, Tables) |
| 3 | Check **light and dark** backend theme |
| 4 | Check responsive width (narrow module body, wide screen) |
| 5 | Click interactive controls (drawer, delete confirm, AJAX test, segmented filters) — JS hooks must still work |
| 6 | Log findings per version in the PR / task (e.g. “v13 dark: btn-group borders OK”) |

**Version matrix (sign off each):**

| Check | v12.4 | v13.4 | v14.3 |
|-------|-------|-------|-------|
| Module loads without Fluid errors | ☐ | ☐ | ☐ |
| Typography matches backend (no Inter leak where removed) | ☐ | ☐ | ☐ |
| `btn-group` / `btn-default` match list-module chrome | ☐ | ☐ | ☐ |
| `card-container` grids readable (stack OK on v12) | ☐ | ☐ | ☐ |
| Badges / callouts / tables in light + dark | ☐ | ☐ | ☐ |
| No horizontal scroll in docheader / tables | ☐ | ☐ | ☐ |
| MCP Tools → AI Extensions: core icons, `btn-success` download, card borders | ☐ | ☐ | ☐ |
| MCP Tools → expand tool row + drawer: table/code colours, card borders | ☐ | ☐ | ☐ |

If only one version is available locally, state which version was tested and what remains unchecked.

### Final status template (agent / developer — paste when handing off)

When reporting “final status” after a design migration, **include all rows** below. Do not mark Done without running the basic test cases above.

```markdown
## Final status — [area name] — [date]

### Files changed
- …

### Basic test cases (mandatory)
| Test | Result | Notes |
|------|--------|-------|
| Cache flush + hard refresh | ☐ Pass / ☐ Fail | |
| Core icons (no emoji / custom colour tiles) | ☐ Pass / ☐ Fail | |
| Download / export actions (`btn-success` where specified) | ☐ Pass / ☐ Fail | |
| Borders use `--typo3-card-border-color` (compare Styleguide) | ☐ Pass / ☐ Fail | |
| Bare `<code>` not red/pink (use `aiu-mcp-inline-code`) | ☐ Pass / ☐ Fail | |
| Expand / drawer / modal interactions still work | ☐ Pass / ☐ Fail | |
| Light theme | ☐ Pass / ☐ Fail | |
| Dark theme | ☐ Pass / ☐ Not tested | |

### Version coverage
| v12.4 | v13.4 | v14.3 |
|-------|-------|-------|
| ☐ | ☐ | ☐ |

### Known gaps / not tested
- …
```

### Per change

1. Hard-refresh after CSS changes (or flush TYPO3 caches).
2. Verify **light and dark** backend appearance.
3. Test **card footer** visible before and after expanding command/tool lists.
4. Test responsive widths: `card-size-medium` is half-width only at large container sizes — use `card-size-large` when full width is required.

### Cross-version (before release)

| Check | v12 | v13 | v14 |
|-------|-----|-----|-----|
| Module loads without Fluid errors | ✓ | ✓ | ✓ |
| Cards readable (grid may stack on v12) | ✓ | ✓ | ✓ |
| Badges / buttons match backend chrome | ✓ | ✓ | ✓ |
| Dark mode via backend preference | `[data-bs-theme=dark]` | `[data-color-scheme=dark]` | both |
| Callouts render (or infobox fallback documented) | ✓ | ✓ | ✓ |
| No horizontal scroll in docheader / dashboard | ✓ | ✓ | ✓ |
| MCP overview: 3 cards + metrics + client list | ✓ (stacked grid) | ✓ | ✓ (container grid) |
| MCP overview: badges / progress in dark theme | ✓ | ✓ | ✓ |

Compare visually against **System → Styleguide → Components** on the same TYPO3 version when unsure.

---

*Last updated: 2026-06-22 — MCP tool panel/drawer patterns, final status template, basic test cases.*
