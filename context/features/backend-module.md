# Feature — Backend Module & AI Features Drawer

**Status:** Done (dashboard, providers, AI Context, credits, MCP Server, MCP Tools, AI Features drawer)  
**Routes:** `Configuration/Backend/Modules.php`

---

## What it does

- Top-level **AI Foundation** backend module with dashboard card grid.
- **Providers** tab: list + slide-in drawer CRUD.
- **AI Context** tab: per-site Brand Context Profiles (CRUD, default, completeness, auto-research). See `context/features/ai-context.md`.
- **MCP Server** tab: config + advanced OAuth/rate settings.
- **AI Features** tab: per-extension ext_conf scopes via `ExtensionExtConfCategoryService`.
- **AI Usage** / analytics from request log.
- **AI Logs** tab: centralized `sys_log` view for all AI extensions (filters, export, delete). See `context/features/ai-logs.md`.
- **Credits** tab: mode toggle, dashboard, buy/history/pricing (T3Planet Credits — publicly available via `CreditsReleaseGate`).
- **MCP Tools** tab: extension tool catalog (`McpToolsController`).
- **AI Permissions** tab (admin only): wizard + permission matrix — see `context/features/ai-access-roles.md`.

---

## Key paths

| Area | Path |
|---|---|
| Module controller | `Classes/Controller/Backend/ModuleController.php` |
| Provider controller | `Classes/Controller/Backend/ProviderController.php` |
| Feature settings AJAX | `Classes/Controller/Backend/FeatureSettingsController.php` |
| Ext conf scopes | `Classes/Service/ExtensionExtConfCategoryService.php` |
| AI Features JS | `Resources/Public/JavaScript/ai-features.js` |
| Templates | `Resources/Private/Templates/Module/` |
| AI Logs | `context/features/ai-logs.md` |
| AI Context | `context/features/ai-context.md` |
| Brand context dashboard bar | `Resources/Private/Partials/Module/Dashboard/AiContextOverview.html` |
| Access roles (admin) | `Classes/Controller/Backend/AccessRolesController.php`, `Templates/AccessRoles/` |
| Editor dashboard gating | `ModuleController::shouldShowFullDashboardOverview()`, `Partials/Module/Dashboard/AllowedTabsOverview.html` |
| Provider record ACL | `Access/RecordAccessGate.php`, `ProviderController` |

---

## AI Logs (utility tab)

Operational logs for all AI extensions — not request/token analytics (that is **AI Usage**). Child extensions link here via **AI Foundation Logs** (`BackendModuleLinkUtility`). Full design: `context/features/ai-logs.md`.

## AI Features scopes (ns_t3af)

Allowed ext_conf categories after provider cleanup:

- `universe-auth-api-translation` (basic auth, notifications, translation default)
- `deepl`, `google`, `translation`
- `openai usage api`, `t3planet credits`, `mcp server`

Provider config is **not** in AI Features — use Providers tab.

### Brand Context profile override (ns_t3ai only)

The **Brand Context profile** dropdown appears **only** on these AI Features cards — **not** on Feature Toggles:

- AI SEO (`seo`), AI Pages (`page`), AI Content (`content`), AI Translation (`translation`), AI Media (`t3ai-media` / `t3ai-media-*`)

Gate: `BrandContextFeatureSettingsService::supportsScopeOverride()`. Full detail: `context/features/ai-context.md`.

---

## Do / Don't

**Do:** Register child modules with `'parent' => 't3af'` when the parent is loaded.

**Don't:** Re-add per-vendor LLM categories to `ALLOWED_SCOPES_BY_EXTENSION`.

---

## Verification

Log in as admin → AI Foundation → each tab loads without JS errors.

Log in as restricted editor (configured via AI Access & Roles) → only permitted tabs/sections; Providers CRUD matches `tables_modify` on `tx_nst3af_provider`.
