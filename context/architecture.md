# ns_t3af — Architecture

*Current runtime layout (not future wish-list).*

---

## Request flow (preferred)

```
Child ext / MCP tool
    → AiServiceInterface (complete | stream | embed)
        → ProviderLookupInterface (default or explicit identifier)
        → AdapterRegistry → AdapterInterface::platform()
        → PSR-14 events (Before/After/Failed)
        → RequestTelemetryService → tx_nst3af_request_log
```

Public API classes: `Classes/Api/{AiServiceInterface,AiOptions,AiResponse,EmbeddingResponse}.php`

---

## Provider registry

| Piece | Path |
|---|---|
| Table | `tx_nst3af_provider` |
| Model | `Classes/Domain/Model/Provider.php` |
| Repository | `Classes/Domain/Repository/ProviderRepository.php` |
| UI | `ProviderController` + drawer JS |
| Cipher | `Classes/Service/CredentialCipher.php` |
| Built-in HTTP adapter | `nst3af.openai_compatible` |
| Symfony bridges | `Classes/Provider/SymfonyAi/*` (auto per installed `symfony/ai-*-platform`) |

Legacy bridge: `ProviderLegacyConfigService` builds ext_conf-shaped arrays from DB rows for transitional consumers (`ns_t3cs`).

---

## Deprecated path (do not extend)

```
AiRequestService → AiServiceInterface facade
BaseClient → HTTP payload builder; kept for OpenAI usage stats + ns_t3cs streaming helpers
```

Mark new code against `AiServiceInterface` only.

---

## Backend module

Top-level `aiuniverse` parent module → `t3af_dashboard` submodule.

Tabs: Dashboard, Providers, AI Context, MCP Server, AI Features, Credits, AI Usage, AI Logs, etc.

Routes: `Configuration/Backend/Modules.php`, `Configuration/Backend/AjaxRoutes.php`

---

## Governance

`AccessControlListener` + `RecordBudgetUsageListener` on provider request events. Telemetry via `RequestTelemetryService` → `tx_nst3af_request_log`. UserTSconfig budgets/rate limits; provider `be_groups` + capability permissions.

See `context/features/governance.md`.

---

## MCP server & tools

HTTP/OAuth + stdio transports, core + dynamic + extension tools under `Classes/Mcp/`. Config via ext_conf **MCP Server** category. MCP Tools backend tab via `McpToolsRegistryService`.

See `context/features/mcp-server.md`.

---

## AI Prompts (T3AI runtime + Universe management)

Prompt **defaults** live in code (`ns_t3ai`); prompt **management UI** lives in AI Foundation. T3AI no longer exposes a Prompts dashboard tab — editors use **AI Foundation → AI Prompts**.

```
┌─────────────────────────────────────────────────────────────────┐
│ Child extensions — runtime (catalog + resolver + providers)     │
│  PromptContractRegistry      → built-in global prompt contracts │
│  PromptResolver              → explicit → extConf title → DB  │
│  SidebarPromptContractRegistry / SidebarPromptResolver          │
│  PromptCatalogProviderInterface → categories for AI Prompts UI  │
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│ ns_t3af — management UI + unified storage                       │
│  AiPromptsService + PromptCatalogProviderRegistry               │
│  AiPromptRepository + ModuleController (ai_prompts*)            │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
              tx_nst3af_ai_prompt (custom rows only; builtins in code)
```

### Resolution order (global prompts, runtime)

1. **Explicit prompt text** — request/modal sends full `prompt` string or user picked a dropdown option.
2. **Extension setting title** — e.g. `defaultAiPromptForKeyword` stores a **prompt title**; `PromptResolver` loads matching DB row (`prompt_type` + `prompt_title`).
3. **Built-in contract** — `PromptContractRegistry::getDefaultText($promptType)`.

`is_default` on DB rows is **not** used at runtime. Features work on fresh install with an **empty** global prompts table.

### Sidebar prompts (toolbar)

Built-ins: `SidebarPromptContractRegistry` (5 actions: summarize, fix grammar, bulletize, elaborate, rewrite).  
Runtime: `SidebarPromptResolver` merges builtins (negative synthetic `uid`) + custom DB rows at `pid=0`, deduped by title.  
Toolbar: `NsT3AiSidebar` → `SidebarPromptResolver::getAllAsModels()`.

### Management UI (ns_t3af)

| Route | Purpose |
|---|---|
| `t3af_dashboard.ai_prompts` | Overview + category detail |
| `t3af_dashboard.ai_prompts.create` | Create custom prompt |
| `t3af_dashboard.ai_prompts.update` | Update custom prompt |
| `t3af_dashboard.ai_prompts.delete` | Soft-delete |

Drawer: scope/type selects from catalog JSON; shows **required variables** + built-in default text hint; validates on save (`missing_required_variables`, `scope_mismatch`, `invalid_prompt_type`). Sidebar category lists builtins as read-only (`uid < 0`).

**Key classes:** `AiPromptsService`, `PromptCatalogProviderRegistry`, `AiPromptRepository`, `ModuleController::aiPromptsAction`.

See `Documentation/Architecture/PromptManagementProvidersAndFeatures.md` (section 1).

---

## AI Context (brand profiles + runtime injection)

Per-site **Brand Context Profiles** define brand voice for AI generations. Management UI in **AI Foundation → AI Context**; runtime hook on **`BeforeProviderRequestEvent`**.

```
┌─────────────────────────────────────────────────────────────────┐
│ Backend UI (ns_t3af)                                      │
│  BrandContextController + ai-context.js (drawer, personas)      │
│  BrandContextDashboardPresenter → Dashboard summary bar           │
│  BrandContextFeatureSettingsService → AI Features override UI   │
└────────────────────────────┬────────────────────────────────────┘
                             │
         ┌───────────────────┴───────────────────┐
         ▼                                       ▼
tx_nst3af_brand_context_profile    settings_json.brandContextProfileUid
(per site pid)                           (ns_t3ai site override, optional)
         │
         ▼
BrandContextResolver → BrandContextPlaceholderService / BrandContextAssembler
         │
         ▼
BrandContextPromptInjectionListener (BeforeProviderRequestEvent)
```

### Resolution

1. **`BrandContextResolver::resolveForPageId($pageId, $extensionKey, $scope)`** — per-feature override uid (`brandContextProfileUid_<scope>`) → legacy extension-wide value → default profile (`is_default=1`). `$scope` comes from `AiOptions.extra['brandContextScope']`.
2. **`BrandContextPromptInjectionListener`** — replaces placeholders in user prompt; prepends `{brand_context}` block to system prompt when provider has none.

### Placeholders

`{brand_context}`, `{brand_name}`, `{brand_voice}`, `{target_audience}`, `{target_persona}` (first persona), `{content_rules}`, `{keywords}`, `{forbidden_words}`, `{language}`, `{competitors}`, `{compliance_notes}`.

Skip: `AiOptions.extra['skipBrandContext'] = true`. Per-feature selection: `AiOptions.extra['brandContextScope'] = '<seo|page|content|translation|t3ai-media>'`.

### AI Features override (UI placement)

Profile dropdown **only** on T3AI feature scopes: `seo`, `page`, `content`, `translation`, `t3ai-media` (+ media sub-scopes). **Not** on Feature Toggles. One stored uid per `ns_t3ai` site settings — scopes gate visibility only.

**Agent entry:** `context/features/ai-context.md`

---

## Credits (client)

`T3PlanetCreditAiService` decorates `AiServiceInterface`; `ProxyAiExecutor` calls composer API when credits mode is on.

Runtime settings: `tx_nst3af_runtime_setting`. Server API on `composer.t3planet.cloud`.

See `context/features/credits.md`.

---

## AI Access & Roles (provider registry)

Per-group wizard + permission matrix for AI Foundation and child extensions. Third-party and suite children register via `AiAccessCatalogProviderInterface` (tag `t3af.ai_access_catalog_provider`).

```
┌─────────────────────────────────────────────────────────────────┐
│ Child ext — AiAccessCatalogProviderInterface                    │
│  module card | T3Ai:* features | record rows | tab/card bindings│
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│ ns_t3af                                                         │
│  AiAccessCatalogProviderRegistry                                │
│  FeatureAccessBindingRegistry (+ LegacyFeatureAccessBindings)   │
│  ModuleAccessCatalog / FeaturePermissionCatalog /               │
│    RecordPermissionCatalog (merge providers + legacy constants) │
│  WizardBootstrapFactory → default GroupConfig                   │
│  MatrixScopeCatalog → PermissionMatrixService                   │
│  AiAccessCustomOptionsBootstrap → be_groups T3Ai:* on boot      │
│  FeaturePermissionGate / RecordAccessEnforcer (runtime)         │
└────────────────────────────┬────────────────────────────────────┘
                             │
         ┌───────────────────┴───────────────────┐
         ▼                                       ▼
  access-roles.js (wizard + matrix)        be_groups ACL
```

**Agent entry:** `context/features/ai-access-roles.md`  
**Developer guide:** `Documentation/Developer/CustomAiAccess.rst`

---

## Caches

| Name | Purpose |
|---|---|
| `nst3af_responses` | Completion cache (1h default) |
| `nst3af_provider_models` | Discovered models (24h) |
| `nst3af_dashboard_analytics` | Dashboard request-log charts (15m) |
| `nst3af_api_alert` | Quota alert cooldown |

Declared in `Configuration/Caches.php` + `ext_localconf.php`.

---

## Architecture tests (phpat)

1. Controllers ↛ `Provider\SymfonyAi\`
2. `Api\` ↛ `Service\` / adapters / controllers
3. `Domain\Model\` ↛ controllers/services/repos
4. `Event\` ↛ service/controller layers

---

## Key tables

| Table | Purpose |
|---|---|
| `tx_nst3af_provider` | Provider registry |
| `tx_nst3af_request_log` | Per-call telemetry |
| `tx_nst3af_usage_budget` | Per-user budgets |
| `tx_nst3af_runtime_setting` | Credits mode + token cache |
| `tx_nst3af_oauth_*` | MCP OAuth clients/tokens |
| `tx_nst3af_ai_prompt` | Unified custom AI prompts (all child extensions; managed in AI Prompts UI) |
| `tx_nst3af_brand_context_profile` | Brand Context profiles (per site pid) |
| `tx_nst3af_group_settings` | Per-group credit/daily caps from AI Access wizard |
