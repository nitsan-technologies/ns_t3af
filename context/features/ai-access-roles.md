# AI Permissions

**Route:** `t3af_dashboard.ai_access_roles`  
**Package:** `ns_t3af`  
**Admin only:** yes (configure wizard + matrix); **enforcement applies to all backend users.**

## Purpose

Guided wizard to configure TYPO3 backend user groups for AI Foundation and child extensions (T3AI, T3AA, T3CS) without overwriting unrelated ACL. Includes a cross-group **Permission Matrix** and runtime enforcement so editors only see UI they are allowed to use.

## UI (TYPO3 core — match other module tabs)

**Do not invent a one-off sidebar.** Reuse the same shell and nav pattern as **MCP Tools → Tools & Resources**:

| Pattern | Reference |
|---------|-----------|
| Sidebar + content grid | `aiu-mcp-tools-shell`, `aiu-mcp-tools-shell__sidebar`, `aiu-mcp-tools-shell__content` |
| Group list nav | `nav.aiu-mcp-tools-nav` + `button.aiu-mcp-tools-nav__item` (icon + `__label` h4 + `__meta` subtitle) |
| Active item | `active is-active` on the selected `__item` |
| Card chrome | `card card-size-small` + `card-header` / `h3.card-title` + `card-body` |
| Primary sidebar action | `btn btn-default w-100 mb-3` with `core:icon` / `typo3-backend-icon` (`actions-plus`) |

**Live references**

- Fluid: `Resources/Private/Partials/McpTools/ToolsResourcesShell.html`
- CSS: `Resources/Public/Css/module/mcp-tools.css` (`.aiu-mcp-tools-shell`, `.aiu-mcp-tools-nav__*`)
- JS implementation: `renderGroupsLayout()` / `renderGroupList()` in `access-roles.js`
- Page shell: `Templates/AccessRoles/Index.html` (`aiu-module-page`, tab heading via `data-tab-heading` / `data-tab-intro`)
- Design guide: `context/Typo3CoreBackendDesign.generic.md` → **Sidebar nav shell**

Access Roles–only CSS (`access-roles.css`) should add scroll/overflow on the nav list and sidebar-button flex alignment — not duplicate nav item styles.

**Setup wizard (`Configuring: <group>`)** — every step uses core card anatomy, not ad-hoc markup:

| Piece | Pattern |
|-------|---------|
| Wizard frame | `card` → `card-header` (`card-icon` + `card-header-body` with `card-title` + `card-subtitle`) → `card-body` → `card-footer` |
| Step indicator | `nav.aiu-ar-steps` of `span.btn.btn-default.btn-sm.aiu-ar-step` chips, mirroring `SetupWizard.html` stepper: `is-complete` = primary fill, `is-active active` = current, plain number (`aiu-ar-step__num`, **no circle background**), `aria-current="step"` |
| Section heading | `h3.aiu-ar-section-title` (no Bootstrap `.h5`/`.h6` size utilities) |
| Modules step (child + admin) | Reuse `card card-size-small aiu-wizard__ext-card` from `SetupWizard.html` step 5 — icon + title + optional `badge badge-default` on the right + toggle track (`aiu-wizard__ext-toggle`); **no** custom accent colors or purple-filled checkboxes |
| Records | `table-fit` → `table table-striped table-hover align-middle` |
| Limits | **two-column** `row g-3` of `aiu-ar-limit-tile` (bordered tile per limit), `form-check form-switch` + `input-group` unit suffix |
| Footer nav | `btn btn-default` Back / `btn btn-primary` Next/Apply — **text only, no icons** |
| Review | `card` summary tiles with `card-title` + count `badge`; callout intro; `be_groups` preview blocks in a **two-column** `row g-3` of `aiu-ar-review-block` tiles (no full-width blank space) |

Step chips reuse the existing setup-wizard look (`Resources/Public/Css/module/setup.css` → `.aiu-wizard__step-btn`). Use only verified core icon identifiers (e.g. `actions-cog`, `actions-star`, `actions-plus`) — avoid unverified `*-alt` variants. Wizard footer/cancel buttons carry no icons.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Child / suite extensions — AiAccessCatalogProviderInterface             │
│  (tag t3af.ai_access_catalog_provider in child Services.yaml)           │
│  ModuleAccessDescriptor | FeaturePermissionDescriptor |                 │
│  RecordPermissionDescriptor | FeatureAccessBindingsDescriptor           │
└────────────────────────────┬────────────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────────────┐
│ ns_t3af — merge + UI + enforcement                                        │
│  AiAccessCatalogProviderRegistry → catalogs (modules / features / records)│
│  FeatureAccessBindingRegistry → tab/card legacy + provider bindings       │
│  WizardBootstrapFactory → default GroupConfig for new groups              │
│  MatrixScopeCatalog → permission matrix scope tabs                        │
│  AiAccessCustomOptionsBootstrap → be_groups custom_options T3Ai:*       │
└────────────────────────────┬────────────────────────────────────────────┘
                             │
         ┌───────────────────┼───────────────────┐
         ▼                   ▼                   ▼
  access-roles.js      be_groups ACL      FeaturePermissionGate /
  (wizard + matrix)    + group_settings   RecordAccessEnforcer
```

| Layer | Location |
|---|---|
| Provider contract | `Contract/AiAccessCatalogProviderInterface.php` (tag `t3af.ai_access_catalog_provider`) |
| Provider registry | `Registry/AiAccessCatalogProviderRegistry.php` |
| Binding registry | `Registry/FeatureAccessBindingRegistry.php`, `Access/LegacyFeatureAccessBindings.php` |
| Merged catalogs | `Access/ModuleAccessCatalog.php`, `FeaturePermissionCatalog.php`, `RecordPermissionCatalog.php` |
| Wizard defaults | `Access/WizardBootstrapFactory.php` (used by `AccessRolesController`) |
| Matrix scopes | `Access/MatrixScopeCatalog.php` → `Service/PermissionMatrixService.php` |
| Custom options bootstrap | `Bootstrap/AiAccessCustomOptionsBootstrap.php`, `AiAccessCustomOptionsBootstrapListener.php` |
| Wizard + matrix UI | `Resources/Public/JavaScript/access-roles.js`, `Resources/Public/Css/module/access-roles.css`, `Templates/AccessRoles/Index.html` |
| Apply / read / preview | `Service/BeGroupAccessService.php`, `Controller/Backend/AccessRolesAjaxController.php` |
| Merge existing ACL | `Access/BeGroupPermissionMerger.php` |
| Normalize before apply | `Access/GroupConfigNormalizer.php` (catalog-driven; not hardcoded t3ai/t3aa/t3cs maps) |
| Serialize / deserialize | `Access/GroupConfigSerializer.php`, `Access/GroupConfigDeserializer.php` |
| Payload parsing | `Access/PayloadBoolean.php` (FormData `"false"` → bool) |
| Presets | `Access/GroupPresetRegistry.php` |
| Group limits table | `tx_nst3af_group_settings` |
| Tab gating (AI Foundation) | `Access/ModuleTabAccessService.php`, `Utility/ModuleTabUtility.php` |
| Child extension checks | `Access/FeaturePermissionGate.php` (generic `grantsModuleTab` / `grantsModuleCard`) |
| TYPO3 v13 permission checks | `Access/BackendPermissionCheck.php` — wraps `BackendUserAuthentication::check()` (**returns bool**, not legacy `"TRUE"` string) |
| Record table ACL (runtime) | `Access/RecordAccessGate.php`, `Access/RecordAccessEnforcer.php` — `tables_select` / `tables_modify` |
| Runtime limits | `Governance/GroupLimitsListener.php` |

## Permission storage (TYPO3 core fields)

| Wizard concept | `be_groups` field |
|---|---|
| Child module access | `groupMods` (`nitsan_nst3ai_dashboard`, `nitsan_nst3cs_t3cs`, …) |
| AI Foundation shell | `groupMods (handled separately)`, `t3af_dashboard` (when any admin/child module enabled) |
| AI Foundation tabs | `custom_options` → `nst3af_tab:*` |
| Feature bits | `custom_options` → `T3Ai:*` (e.g. `T3Ai:T3CS.Chat`) |
| Capability bits | `custom_options` → `nst3af:capability_*` |
| Record read | `tables_select` |
| Record read/write | `tables_select` + `tables_modify` |
| Credit/daily caps, allowlist | `tx_nst3af_group_settings` (not core ACL) |

**Merge rule:** `BeGroupPermissionMerger` strips only managed keys before writing wizard output; unrelated modules, custom options, and tables are preserved.

**Apply preview:** Review step calls `nst3af_access_roles_preview` with JSON body; shows merged `groupMods`, `custom_options`, `tables_select`, `tables_modify` before apply.

## Wizard (Groups & Permissions tab)

Five steps: **Modules → Features → Records → Limits → Review**.

- Step 1: child extension cards + AI Foundation admin module toggles.
- Step 2–3: feature levels and record access filtered by enabled modules (`GroupConfigNormalizer` clears orphan rows on apply).
- Step 5: merged preview of `be_groups` fields written on Apply.
- Apply POST: JSON `{ groupUid, config }` — server parses raw JSON body (see AJAX below).

## Permission Matrix tab

Cross-group table with scope tabs driven by `MatrixScopeCatalog` (typically **AI Foundation** plus one tab per registered child module from `AiAccessCatalogProviderInterface`).

- Subtitle: `{n} groups, {n} configured`; unconfigured groups dimmed at bottom with amber **Not configured** badge.
- **Two-tier headers:** section row (Group / Admin Modules / AI Safety or Module / Features / Records) + column labels.
- **Legend:** green SVG checkmark = Use / Read / On; violet **Mgr** badge = Manage / R+W; grey **—** = no access.
- **AI Foundation columns:** Name, Members, 8 admin modules (`providers`, `mcpServer`, `mcpTools`, `aiContext`, `aiFeatures`, `aiUsage`, `aiPrompts`, `schedulerCli`), then Credits / Workspace / Audit from limits.
- **Child scopes:** module on/off checkmark + feature level badges (`Use`, `Mgr`, `Scoped`, …) + record badges (`Read`, `R+W`); feature/record cells dim when parent module off.

Implementation: `renderMatrix()` in `access-roles.js`; scope tabs and column keys from `PermissionMatrixService::buildMatrix()` → `scopes` (via `MatrixScopeCatalog`).

## Runtime enforcement (editors)

Configuration in the wizard is not enough — UI must respect the same ACL at runtime.

### AI Providers (`ProviderController` + `List.html` / `Row.html`)

- `RecordAccessGate::canModifyTable($user, 'tx_nst3af_provider')` gates **New Provider**, Import, Edit, Delete, Set default.
- Read-only when credits mode is active **or** no `tables_modify` on provider table.
- Server-side guards on `new`, `edit`, `save`, `delete` actions (`denyWhenProvidersReadOnly()`).

### AI Foundation Dashboard (`ModuleController`)

- Restricted editors (no `aiUsage`, `aiContext`, or MCP tabs) see **Allowed tabs overview** cards instead of analytics/MCP/checklist.
- `indexAction` redirects to first visible non-dashboard tab when full overview not allowed.
- Admins always see full dashboard.

### T3CS (`ns_t3cs`)

- Tab visibility: `DataProvider/Tabs.php` → `FeaturePermissionGate::grantsT3CsTab()`.
- Requires `modules` → `nitsan_nst3cs_t3cs` (via `BackendPermissionCheck`).
- When granular `T3Ai:T3CS.*` bits exist: each tab needs its feature bit; **Dashboard** requires `T3CS.Index` (not coarse `T3CS` alone).
- `Tabs::resolveActiveTab()` falls back to first allowed tab (avoids blank page when default tab filtered out).
- Disallowed actions redirect via `redirectToTab()`; empty tab list shows infobox (`t3cs.access.noTabs.*`).
- Dashboard cards gated by `showSearchModuleCard`, `showChatbotModuleCard`, `showUsageAnalyticsCard`, `showTrainingPipelineSection` in `T3CsBackendController::buildBaseAssign()`.

### Record-level enforcement (`RecordAccessEnforcer`)

Central guard for `tables_modify` / wizard catalog rows. Inject `RecordAccessEnforcer` (public DI) or call `denyUnlessCanModifyCatalogId()` / `denyUnlessCanModifyTable()` on mutating routes.

| T3CS area | Catalog ID | `canModify*` Fluid flag |
|---|---|---|
| Data sources | `t3csDatasource` | `canModifyDatasource` |
| Source groups | `t3csSourceGroup` | `canModifySourceGroup` |
| Training queue | `t3csDatasourceQueue` | `canModifyTrainingQueue` |
| Search settings | `t3csSearchSettings` | `canModifySearchSettings` |
| Chatbot config | `t3csChatbot` | `canModifyChatbot` |
| Search history delete | `t3csSearchHistory` | `canModifySearchHistory` |
| Chat history delete | `t3csChatbotHistory` | `canModifyChatbotHistory` |

Maps: `FeatureAccessBindingRegistry` + child `AiAccessCatalogProviderInterface` record bindings. POST handlers return **403 JSON** `{ ok: false, message }` when read-only. Fluid partials hide CRUD buttons when flag `!= 1` (same pattern as AI Providers).

**ns_t3af surfaces:** Brand Context (`brandProfiles`), AI Prompts (`aiPromptStorage`), AI Features save (`extensionSettings`), AI Usage log delete/export (`usageRequestLog`), MCP OAuth (`oauthClients`), MCP Tools (`mcpDiscoveredTables`, `mcpCustomTools`, `mcpPromptTemplates`). See `tasks/record-access-enforcement.md`.

### Child extensions (feature bits)

`FeaturePermissionGate` resolves bindings from `FeatureAccessBindingRegistry` (provider `FeatureAccessBindingsDescriptor` + legacy fallbacks). Checks legacy `tx_t3ai_*` / `tx_t3aa_*` / `tx_t3cs_*` **or** merged `T3Ai:*` bits (hybrid during migration).

| Extension | Provider | Enforcement |
|---|---|---|
| `ns_t3ai` | `T3AiAccessCatalogProvider` | Dashboard, button bar, context menus; content writes via `denyUnlessPageContentWrite()` |
| `ns_t3aa` | `T3AaAccessCatalogProvider` | Dashboard tab/card filtering; FAL metadata via `denyUnlessT3AaFileMetaWrite()` |
| `ns_t3cs` | `T3CsAccessCatalogProvider` | `DataProvider/Tabs.php`, dashboard cards |
| `ns_t3as` | `T3AsAccessCatalogProvider` (records only; rides on `t3cs` module) | Search history delete, search settings |
| `ns_t3ac` | `T3AcAccessCatalogProvider` (records only; rides on `t3cs` module) | Chatbot config/history mutating routes |

## AJAX endpoints (admin)

| Route | Method | Body | Action |
|---|---|---|---|
| `nst3af_access_roles_groups` | GET | — | List groups summary |
| `nst3af_access_roles_group` | GET | `uid` query | Group detail + config |
| `nst3af_access_roles_preview` | POST | JSON `{ groupUid?, config }` | Merged `be_groups` preview |
| `nst3af_access_roles_apply` | POST | JSON `{ groupUid, config }` | Apply wizard config |
| `nst3af_access_roles_matrix` | GET | — | Permission matrix payload |

**JSON POST:** `AccessRolesAjaxController::parseRequestBody()` decodes raw JSON when `getParsedBody()` is empty (TYPO3 `AjaxRequest.post()` with `Content-Type: application/json`).

## Tests

```bash
cd packages/ns_t3af && composer test
```

Relevant unit tests: `PayloadBooleanTest`, `GroupConfigNormalizerTest`, `GroupConfigSerializerTest`, `BeGroupPermissionMergerTest`, `FeaturePermissionGateTest`, `RecordAccessGateTest`, `RecordAccessEnforcerTest`, `RecordPermissionCatalogTest`, `BackendPermissionCheckTest`, `ModuleTabAccessServiceTest`, `AiAccessCatalogProviderRegistryTest`, `FeatureAccessBindingRegistryTest`, `MatrixScopeCatalogTest`.

## Manual verification (editor group)

1. Apply wizard config; flush caches.
2. **AI Providers:** with `tables_modify` on `tx_nst3af_provider` → **+ New Provider** + Edit/Delete visible; read-only → list + Test only.
3. **AI Foundation Dashboard:** restricted group → allowed-tab cards or redirect to Providers/AI Logs; not full analytics/MCP.
4. **T3CS:** tabs match granted `T3Ai:T3CS.*` features; module loads content (not blank footer-only page).
5. **Permission Matrix:** detailed column headers + green checkmarks in cells/legend.

## Provider-driven registration

Third-party and suite child extensions self-register access metadata via
`NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface` (DI tag `t3af.ai_access_catalog_provider` in **the child extension's** `Configuration/Services.yaml`).

| Surface | Merged by |
|---------|-----------|
| Wizard module cards (step 1) | `ModuleAccessCatalog::childModules()` |
| Wizard features (step 2) | `FeaturePermissionCatalog::all()` |
| Wizard records (step 3) | `RecordPermissionCatalog::all()` |
| Matrix scope tabs | `MatrixScopeCatalog` → `PermissionMatrixService::buildMatrix()` |
| Native `be_groups` custom options | `AiAccessCustomOptionsBootstrap` on `BootCompletedEvent` |
| Runtime tab/card gating | `FeatureAccessBindingRegistry` → `FeaturePermissionGate` |
| Manageable feature list | `T3AiPermissionResolver` (from bindings) |
| Record catalog → table map | `AiAccessCatalogProviderInterface::getRecordPermissions()` + bindings |

**Shipped providers** (each under `Classes/Access/` in the child ext):

| Extension | Class | Notes |
|-----------|-------|-------|
| `ns_t3ai` | `T3AiAccessCatalogProvider` | Full module + features + records + bindings |
| `ns_t3aa` | `T3AaAccessCatalogProvider` | Full module + features + records + bindings |
| `ns_t3cs` | `T3CsAccessCatalogProvider` | Suite hub module + features + records + bindings |
| `ns_t3as` | `T3AsAccessCatalogProvider` | Record rows only; `getCatalogModuleKey()` → `t3cs` |
| `ns_t3ac` | `T3AcAccessCatalogProvider` | Record rows only; `getCatalogModuleKey()` → `t3cs` |

Developer guide: `Documentation/Developer/CustomAiAccess.rst`.

When a third-party extension registers `AiAccessCatalogProviderInterface`, its module card, feature bits, and record rows appear in the **wizard**, **permission matrix** (dynamic scope tab), native **Custom module options → T3Ai**, and runtime enforcement — assign via native `be_groups` or the AI Access / Roles UI.

## BE ACL smoke — provider-only `customPermOptions['T3Ai']`

`ext_localconf.php` declares `customPermOptions['T3Ai']` with an empty `items` array. Feature bits are filled at boot by `AiAccessCustomOptionsBootstrap` from registered `AiAccessCatalogProviderInterface` providers (`BootCompletedEvent`).

**Smoke checklist (backend):**

1. Flush caches after enabling/disabling a child extension that ships an access catalog provider.
2. Open **Backend Users → Backend user groups → [group] → Access Lists → Custom module options**.
3. Confirm the **T3Ai** section header is present even when no child providers are active (empty items is OK).
4. With `ns_t3ai` / `ns_t3aa` / `ns_t3cs` (and add-ons) active, confirm feature bits appear under **T3Ai** (e.g. `T3Ai:…` labels from each provider’s `FeaturePermissionDescriptor`).
5. Grant only a subset of bits to an editor group; log in as that editor and confirm `FeaturePermissionGate` hides disallowed tabs/cards (and mutating routes return 403 where enforced).
6. Disable a child extension that contributed bits; flush caches; confirm those items disappear from **Custom module options → T3Ai** while unrelated bits remain.

## Related docs

- Prototype reference: `AI Permissions — Feature Documentation.md` (repo root)
- Backend module overview: `context/features/backend-module.md`
- Child integration: `context/features/child-extensions.md`
