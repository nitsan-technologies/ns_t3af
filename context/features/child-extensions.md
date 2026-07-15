# Feature — Child Extension Integration

**Status:** Ongoing (ns_t3ai, ns_t3cs, ns_t3aa in monorepo)  
**Deep spec:** `Documentation/Developer/ExtensionIntegration.rst`

---

## What it does

- Child AI extensions depend on `nitsan/ns-t3af` and call `AiServiceInterface`.
- Custom providers: implement `AdapterInterface` + tag in **child** `Services.yaml`.
- MCP tools: tag `mcp.tool` in child extension.
- Credits: shared pool when mode toggle is on (all children share one token).

---

## Monorepo children

| Extension | Integration |
|---|---|
| `ns_t3ai` | `AbstractAIController` → `AiServiceInterface`; `T3AiAccessCatalogProvider` for ACL |
| `ns_t3cs` | `ExtensionConfigurationHelper` → `ProviderLegacyConfigService`; `EmbeddingHelper` → `embed()`. Suite hub for T3AS/T3AC. `T3CsAccessCatalogProvider`. **Agent entry:** `packages/ns_t3cs/AGENTS.md` |
| `ns_t3aa` | `AiServiceInterface` for vision/metadata; `T3AaAccessCatalogProvider`. **Agent entry:** `packages/ns_t3aa/AGENTS.md` |
| `ns_t3as` | Search add-on for T3CS; `T3AsAccessCatalogProvider` (record ACL only, rides on `t3cs` module key) |
| `ns_t3ac` | Chatbot add-on for T3CS; `T3AcAccessCatalogProvider` (record ACL only, rides on `t3cs` module key) |

---

## Custom adapter wiring

In child `Configuration/Services.yaml`:

```yaml
_instanceof:
  NITSAN\NsT3AF\Provider\Contract\AdapterInterface:
    tags: ['nst3af.adapter']
  NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface:
    tags: ['t3af.ai_access_catalog_provider']

MyVendor\MyExt\Access\:
  resource: '../Classes/Access/'
```

Demo adapter: `packages/ns_t3ai/Classes/Provider/AcmeAdapter.php` (when present).  
Demo access provider: see `Documentation/Developer/CustomAiAccess.rst` and shipped `T3*AccessCatalogProvider.php` classes.

---

## Do / Don't

**Do:** Use `AiOptions::$extensionKey` and `$featureKey` for attribution.

**Don't:** Duplicate provider HTTP clients in children.

**Don't:** Assume `ns_t3af` ext_conf has `openai_api_key` — use provider DB.

---

## AI Foundation Logs button (child dashboards)

When `ns_t3af` is loaded, child modules show **AI Foundation Logs** in the dashboard toolbar (right side). It opens AI Foundation → AI Logs with:

- `id` — current page-tree selection
- `extension` — pre-filter (`ns_t3ai`, `ns_t3aa`, `ns_t3cs`, …)

**Implementation:** `BackendModuleLinkUtility::buildAiLogsUri()` + `Partials/Backend/AiLogsModuleLink.html` + `SetupChecklistPresenter::configureViewPartials()`.  
**Full guide:** `context/features/ai-logs.md` (do not fork the button partial).

---

## AI Permissions (permission gating)

When `ns_t3af` is loaded, child modules and AI Foundation tabs can be restricted per backend user group via **AI Access & Roles** (admin wizard).

| Extension | Gate | Where |
|---|---|---|
| `ns_t3cs` | `FeaturePermissionGate::grantsT3CsTab()` | `DataProvider/Tabs.php`, `T3CsBackendController` dashboard cards |
| `ns_t3as` / `ns_t3ac` | Record catalog rows on `t3cs` module | `T3AsAccessCatalogProvider`, `T3AcAccessCatalogProvider` → `RecordAccessEnforcer` |
| `ns_t3ai` | `FeaturePermissionGate` (T3AI tabs/cards) | Dashboard / button bar / context menus; `denyUnlessPageContentWrite()` |
| `ns_t3aa` | `FeaturePermissionGate` (T3AA tabs/cards) | Dashboard tab/card filtering; `denyUnlessT3AaFileMetaWrite()` |
| `ns_t3af` | `ModuleTabAccessService` + `RecordAccessGate` | Module tabs, Providers CRUD |

**Important:** TYPO3 v13 `BackendUserAuthentication::check()` returns **bool** — use `BackendPermissionCheck::isGranted()` (not `=== 'TRUE'`).

**Full guide:** `context/features/ai-access-roles.md`.

---

## Verification

Enable child ext + ns_t3af → trigger one AI action → row in `tx_nst3af_request_log`.
