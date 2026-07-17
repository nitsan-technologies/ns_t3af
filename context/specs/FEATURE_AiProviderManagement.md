> **Agent entry:** `context/features/providers.md`

# Feature — AI Provider Management

Status: **Implemented (v2.x)**
Owner: ns_t3af maintainers
Target version: ns_t3af v2.x

## Goals of AI Foundation (informs every feature)

1. **Better backend usability** — common AI model setup shared across all child products (`ns_t3ai`, `ns_t3cs`, …).
2. **Single source of truth** — all common/repeat configuration (AI models, global AI options) lives here, not duplicated in child extensions.
3. **Base dependency** — all AI extensions in the T3Planet family will declare a hard dep on `ns_t3af` (intent only — refactor lands per child extension, not in this feature).
4. **Developer-friendly** — Hooks / Listeners / Events let any developer build a child AI extension on top without touching core ns_t3af code.
5. **Centralized request/response pipeline** — AI prompts + the request/response middleware are owned by ns_t3af.
6. **Resolve base-child dependency churn** — child extensions stop bundling their own provider clients / API key fields.

## Context

`ns_t3af` today is a thin AI service layer: provider API keys live in `ext_conf_template.txt`, a single monolithic `Classes/Client/BaseClient.php` switches by provider name, no DB table for provider configs, no UI to add providers. The roadmap (per `CLAUDE.md`) repositions it as the **master TYPO3 AI extension**. This first feature replaces the static ext-conf approach with a TYPO3-backend-managed registry where admins can add unlimited AI providers, store credentials encrypted, declare per-record model + capabilities + temperature + system prompt, mark a default, see status, and verify connectivity.

Reference patterns reviewed (forked / inspired with credit per `CLAUDE.md` rules):
- `b13/aim` — flat TCA, attribute-based provider auto-discovery, verify probe.
- `netresearch/nr_llm` — adapter contract `ProviderInterface::testConnection`, vault key indirection, fallback chain.
- `hn/typo3_mcp_server` + `marekskopal/ms_mcp_server` — MCP server (Feature 3).
- `autodudes/ai-suite` — `CreditCostEnumeration`, `RequestsToolbarItem`, credit cost tracking → Feature 2.
- `lochmueller/seal_ai` + `lochmueller/seal` — Symfony AI bridge surface + vector store abstraction (adopted into this feature, see Architecture).
- `symfony/ai-*-platform` family — provider implementations.


## Decisions locked

| # | Decision | Choice |
|---|---|---|
| 1 | Schema | Flat single table `tx_nst3af_provider` (mockup-aligned). Three-tier deferred to v2. |
| 2 | API key storage | Symmetric encryption using `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']` via `sodium_crypto_secretbox`. Stored as base64 ciphertext. No external deps. |
| 3 | Verify connection | Per-adapter probe (`testConnection()` in adapter contract). Result persisted in row columns `last_status`, `last_status_at`, `last_status_message`. |
| 4 | UI | TCA list + custom Fluid slide-in drawer matching the design mockup (Identity / Connection / Model / Configuration sections + capability checkboxes + temperature slider + default toggle + Save/Cancel). |
| 5 | Adapters v1 | All providers supplied by `symfony/ai-*-platform` (OpenAI, Anthropic, Gemini, Mistral, Ollama, OpenAI-compatible, …) via SEAL bridge. Custom adapter contract still exposed for non-Symfony providers. |
| 6 | Symfony AI / SEAL | **Adopted now.** `lochmueller/seal_ai` for LLM provider auto-discovery, `lochmueller/seal` for vector stores. Hand-rolled adapter contract becomes a thin layer that can wrap either Symfony AI Platform instances or fully custom providers. |
| 7 | Mode toggle (T3Planet Credits / Your Own API Keys) | **Shipped.** `CreditModeResolver` + `RuntimeSettingsService`; credits mode routes via `T3PlanetCreditAiService`. |
| 8 | Migration of existing ext_conf | **Shipped.** `typo3 upgrade:run nst3afMigrateExtConfProviders`. Provider keys removed from `ext_conf_template.txt`; `ProviderLegacyConfigService` bridges transitional consumers. |

## Cross-cutting requirements (project-wide standards)

Apply to **every** ns_t3af feature, not just this one. Document once, inherit always. Each future spec in `context/specs/` must explicitly state how it satisfies these or which it intentionally defers.

### CC-1 — Security
- API keys + any secret stored only as ciphertext (sodium `crypto_secretbox`, key derived from `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`). Versioned prefix `enc:v1:` so future rotation doesn't break old rows.
- Secrets never logged, never echoed back in responses, never returned in JSON to the browser. UI only ever shows `mask()` form (`sk-••••••••`).
- Treat all LLM responses as untrusted user-content. Sanitize before rendering as HTML.
- All AJAX routes use TYPO3's standard backend session/CSRF protection (`Configuration/Backend/AjaxRoutes.php`).
- Rate-limit verification probes per BE user (5/min default) to prevent abuse.

### CC-2 — Backend user / group ACL
- Per-table TCA respects `$GLOBALS['BE_USER']` permissions out of the box (table_view / table_modify on `tx_nst3af_provider`).
- Add `be_groups` field on provider configs so a record is only usable by listed groups (pattern ported from `aim/tx_aim_configuration`). Admin always allowed.
- Capability-level permissions registered as `customPermOptions['ns_t3af']`: `provider_manage`, `provider_use`, `mcp_access`, `credits_view` (later features). Permissive-by-default until first permission is set anywhere.
- Capability-permissioned access ships in Governance (`AccessControlListener` + `customPermOptions['nst3af']`).

### CC-3 — Workspace support
- Provider config table is **not workspace-aware** (operational data; would create cost-tracking confusion across workspaces).
- BUT request-log + cost-log tables (Feature 2/5) live in workspace 0 always — explicit in TCA via `versioningWS = 0`.
- MCP tools (Feature 3) operate workspace-aware on content, transparent to LLM clients (port pattern from `hn/typo3_mcp_server`).

### CC-4 — Response cache
- TYPO3 cache framework. Cache config in `Configuration/Caching.php` (no hardcoded backend — host instance picks Redis/Valkey/Memcached). Caches:
  - `nst3af_responses` — key = sha256(provider_uid|model|temperature|prompt|system_prompt) → response payload + token counts. Default lifetime 1h. Per-call `noCache` flag bypass.
  - `nst3af_provider_models` — key = provider_uid → discovered models list. 24h lifetime.
- Cache invalidation: provider edit/delete → flush keys with provider_uid prefix.

### CC-5 — Streaming
- All AI request services expose both `complete(): Response` and `stream(): \Generator<Chunk>`. Symfony AI Platform exposes streaming natively — wrap as `\Generator`.
- Backend module supports SSE responses (`text/event-stream`) for streamed UI; child extensions opt-in by calling `stream()` instead of `complete()`.
- Stream + cache are mutually exclusive: streaming bypasses `nst3af_responses` (response not aggregated yet). After full stream consumed, accumulator stores final into cache.

### CC-6 — Hooks / Events / Listeners (developer-friendly API)
PSR-14 events fired by the central request pipeline (Feature 5: middleware pipeline). Documented + stable for child extensions:

| Event | Fired | Use case |
|---|---|---|
| `BeforeProviderRequestEvent` | before adapter call | mutate prompt, inject system prompt prefix, gate by ACL |
| `AfterProviderResponseEvent` | after adapter response | post-process content, sanitize, cost tracking |
| `ProviderRequestFailedEvent` | on adapter exception | fallback chain, alerting |
| `ProviderTestConnectionEvent` | after verify probe | external monitoring sync |
| `ProviderRegisteredEvent` | when an adapter registers itself | child-extension dynamic discovery |
| `CreditCostCalculatedEvent` | (Feature 2) per request | external billing systems |

Each event class lives under `Classes/Event/`. Listeners registered via standard `Configuration/Services.yaml` event listener tag.

Plus a single thin **Service Facade** for child extensions:
```php
namespace NITSAN\NsT3AF\Api;

interface AiServiceInterface
{
    public function complete(string $prompt, AiOptions $opts = new AiOptions()): AiResponse;
    public function stream(string $prompt, AiOptions $opts = new AiOptions()): \Generator;
    public function embed(string|array $text, AiOptions $opts = new AiOptions()): EmbeddingResponse;
    public function provider(?string $identifier = null): Provider;   // null = default
}
```
This is the public, semver-stable API. Child extensions (`ns_t3ai`, `ns_t3cs`, future) inject `AiServiceInterface`. No direct adapter use from outside `ns_t3af`.

### CC-7 — Custom providers
Any extension declares an adapter by:
1. Implementing `AdapterInterface`.
2. Tagging service with `nst3af.adapter` (or `#[AsAdapter]` PHP attribute, port from `aim/AsAiProvider`).
3. Optionally publishing a Symfony AI bridge as a separate composer package (preferred — auto-discovered).

Documented in `Documentation/CustomProviders.rst` (Feature 1 ships skeleton; full guide in Feature 5).

### CC-8 — Per-user budgets (forward-looking)
Governance ships UserTSconfig budgets (period / maxCost / maxTokens / maxRequests) via `BudgetService` + `RecordBudgetUsageListener`. Provider schema and PSR-14 events support this.

### CC-9 — Quality bars
Reconciled with upstream `52258c7` ("[TASK] code improvements") tooling:
- **Existing tooling (already in repo).** `composer test` (PHPUnit 13), `composer stan` (PHPStan 2.1), `composer cs:check` (php-cs-fixer 3.94). Vendor at `.Build/vendor/`. Tests under `Tests/Unit/...` with three reference tests (`BaseClientTest`, `AiEngineConfigurationTest`, `AiUniverseChartHelperTest`). **Use these — do not introduce a parallel `runTests.sh` toolchain.**
- **PHP / TYPO3 floors.** Bumped on v2.x branch to **PHP 8.1+ / TYPO3 12.4+**. Update `composer.json` `require` (currently `^7.4 || ^8` + `^11 || ^12 || ^13`) and `ext_emconf.php` constraints as part of Feature 1. v1.x branch retains upstream floors.
- **strict_types.** `declare(strict_types=1);` mandatory in every new PHP file. Existing files migrated opportunistically when touched.
- **PHPStan.** Repo baseline stays at level 3 globally (upstream `phpstan.neon`). New namespaces `NITSAN\NsT3AF\Provider\*`, `NITSAN\NsT3AF\Api\*`, `NITSAN\NsT3AF\Service\AiService` opt into **level 8** via a path-scoped second config `phpstan-strict.neon` (or a `paths` override in the existing file). New code must pass level 8; legacy untouched at level 3.
- **Architecture tests (phpat).** Controllers cannot import adapters directly — must go through `AdapterRegistry`. Add `composer require --dev phpat/phpat` + `Tests/Architecture/` (port from `nr_llm`).
- **Test coverage targets.** Unit ≥ 80% on new code; mutation MSI ≥ 70% (Infection) — Infection install deferred to Feature 2 if not yet present.
- **CI matrix.** Expand `.github/workflows/ci.yml` from current single PHP 8.4 entry to PHP 8.1 / 8.2 / 8.3 / 8.4 × TYPO3 12.4 / 13.4. Run `cs:check` (still non-blocking on baseline), `stan`, `test` per cell. Architecture tests added once phpat is wired.

## Architecture

### Domain + persistence

**Table** `tx_nst3af_provider` (new `ext_tables.sql`):

```sql
CREATE TABLE tx_nst3af_provider (
    identifier VARCHAR(64) NOT NULL DEFAULT '',
    title VARCHAR(255) NOT NULL DEFAULT '',
    adapter_type VARCHAR(32) NOT NULL DEFAULT '',          -- openai | anthropic | gemini | ollama | openai_compatible
    endpoint_url VARCHAR(255) NOT NULL DEFAULT '',
    api_key TEXT,                                          -- ciphertext (base64) — never plaintext
    model_id VARCHAR(128) NOT NULL DEFAULT '',
    capabilities VARCHAR(255) NOT NULL DEFAULT '',         -- CSV: chat,completion,embeddings,vision,streaming,tool_use
    temperature DECIMAL(3,2) DEFAULT 0.70,
    system_prompt TEXT,
    is_default TINYINT(1) DEFAULT 0,
    priority INT DEFAULT 50,
    last_used_at INT(11) DEFAULT 0,
    last_status VARCHAR(16) DEFAULT '',                    -- '' | connected | disconnected
    last_status_at INT(11) DEFAULT 0,
    last_status_message TEXT,
    UNIQUE KEY identifier (identifier)
);
```

Standard TYPO3 columns (`uid`, `pid`, `tstamp`, `crdate`, `deleted`, `disabled`, `sorting`) added by Core helper.

**Domain model** `Classes/Domain/Model/Provider.php` — readonly DTO; no Extbase. Hydrated from row by `ProviderRepository` (DBAL QueryBuilder).

**Repository** `Classes/Domain/Repository/ProviderRepository.php` — `findAll()`, `findByUid()`, `findByIdentifier()`, `findDefault()`, `setDefault(int $uid)` (clears flag on others, sets on target — same transactional pattern as `aim/Hooks/DefaultProviderHook.php`).

### Encryption service

`Classes/Service/CredentialCipher.php` — wraps `sodium_crypto_secretbox` keyed by `hash('sha256', $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], true)` (32-byte derived). Methods: `encrypt(string $plain): string` (random nonce, base64 of nonce|cipher), `decrypt(string $blob): string`, `mask(string $plain): string` (returns `sk-•••••••••••` style display string). Throws `CipherException` on tamper. Constants for prefix `enc:v1:` so future schemes coexist.

### Adapter contract (Symfony AI / SEAL-backed)

`Classes/Provider/Contract/AdapterInterface.php`:

```php
interface AdapterInterface
{
    public function getType(): string;                     // 'symfony.openai', 'symfony.anthropic', 'custom.x'
    public function getDisplayName(): string;
    public function getDefaultEndpoint(): string;
    public function getDefaultCapabilities(): array;       // hint for the form
    public function testConnection(Provider $provider): VerifyResult;   // throws nothing
    public function platform(Provider $provider): \Symfony\AI\Platform\PlatformInterface;
}
```

`VerifyResult` is a value object: `bool $ok`, `?string $message`, `array $models = []`, `int $latencyMs`.

**Provider sourcing — two routes:**

1. **Symfony AI Platform bridges (primary).** `Classes/Provider/SymfonyAi/SymfonyAiPlatformDiscovery.php` scans `Composer\InstalledVersions` for any package matching `symfony/ai-*-platform` (and its SEAL re-exports via `lochmueller/seal_ai`). For each found package:
   - Reads PSR-4 namespace + `PlatformFactory` + `ModelCatalog` (pattern ported from `aim/Classes/DependencyInjection/SymfonyAiCompilerPass.php`).
   - Registers a `SymfonyAiBridgeAdapter` instance keyed `symfony.<vendor>` (e.g. `symfony.openai`, `symfony.anthropic`, `symfony.gemini`, `symfony.mistral`, `symfony.ollama`, `symfony.openrouter`).
   - Maps Symfony AI `Capability` enums to `nst3af` capabilities (`input-image`→vision, `input-messages`→chat, `output-streaming`→streaming, `tool-calling`→tool_use, etc.).
   - Sanitizes model IDs (strips colons that break TYPO3 LangService).
   - `testConnection()` calls `PlatformInterface::request($modelCatalog->getModels()[0], 'ping')` with 1 max-token, or — when a list endpoint is documented — a cheaper `Models::list()` call where the bridge supplies one.
   - `platform()` returns the bridge's `PlatformInterface` instance for downstream services to invoke completions/embeddings.

2. **Custom adapter (escape hatch).** Any extension can implement `AdapterInterface` directly, tag with `nst3af.adapter`, and ship a non-Symfony provider (e.g. proprietary on-prem LLM). Registry treats it identically.

Registry: `Classes/Provider/AdapterRegistry.php` indexed by `getType()`. Compiler pass `Classes/DependencyInjection/AdapterCompilerPass.php` collects both Symfony AI bridges and custom adapters.

**Composer dependencies added (suggest, not require):**
```
"suggest": {
    "lochmueller/seal_ai": "Symfony AI bridge bundle for TYPO3 — recommended for OpenAI/Anthropic/Gemini/Mistral/Ollama/OpenRouter providers",
    "lochmueller/seal": "Vector store abstraction (used by Feature 4: RAG/Embeddings)",
    "symfony/ai-platform": "Core Symfony AI runtime"
}
```
Hard `require` deferred: SEAL is alpha-state. Bridges discovered at runtime; absence of the package = adapter type silently unavailable.

HTTP timeout / error handling — handled by Symfony AI Platform internally. Adapter wraps any `\Throwable` from `testConnection()` into `VerifyResult(ok:false)`.

### Backend module

Reuses skeleton, **relocated** (decision 2026-05-08): drop `tools_aiuniverse` (Admin Tools child). Now top-level parent module `aiuniverse` (position: after Web, before File, `access: user` so per-group ACL via standard BE group "Modules" tab). Provider UI lives under `t3af_dashboard` submodule. Sibling extensions (`ns_t3ai`, `ns_t3cs`) declare `parent: 't3af'` in their own `Configuration/Backend/Modules.php` and fall back to a top-level module when ns_t3af parent is absent.

**Routes** (`Configuration/Backend/Modules.php` extended):

| Route | Target | Purpose |
|---|---|---|
| `_default` | `ModuleController::indexAction` | Dashboard (existing) |
| `providers` | `ProviderListController::indexAction` | List + drawer page |
| `providers.new` | `ProviderListController::newAction` | Returns drawer HTML (server-rendered partial) |
| `providers.edit` | `ProviderListController::editAction` | Drawer HTML for existing row |
| `providers.save` | `ProviderListController::saveAction` | POST → upsert + redirect |
| `providers.delete` | `ProviderListController::deleteAction` | POST → soft-delete |

**AJAX routes** (`Configuration/Backend/AjaxRoutes.php` new):

| Name | Path | Action |
|---|---|---|
| `nst3af_provider_test` | `/nst3af/provider/test` | `ProviderAjaxController::testAction` — runs adapter probe, persists `last_status*`, returns JSON |
| `nst3af_provider_set_default` | `/nst3af/provider/set-default` | toggles is_default |
| `nst3af_provider_search` | `/nst3af/provider/search` | live-filter for the search input in the mockup |

**Controllers** under `Classes/Controller/Backend/`:
- `ProviderListController` — list + form (drawer is a partial rendered server-side and slid in via small JS)
- `ProviderAjaxController` — JSON endpoints

### View layer

`Resources/Private/Templates/Provider/List.html` — header (count badges, encryption-at-rest pill, fallback-warning callout), mode-toggle stub section, search input, "+ New Provider" button, `<table>` with rows (identifier code-tag, default badge, model badge, last-used, priority chip, status dot+label, actions: edit/test/delete).

`Resources/Private/Partials/Provider/Drawer.html` — drawer partial. Sections: Identity (Identifier, Display Name, Adapter Type select), Connection (Endpoint URL, API Key — type=password with reveal toggle), Model (Model ID, Capabilities checkbox group), Configuration (Temperature range slider with live label, System Prompt textarea, Default toggle). Endpoint placeholder updates from `AdapterInterface::getDefaultEndpoint()` when adapter changes.

`Resources/Public/JavaScript/provider-drawer.js` (ES module via `Configuration/JavaScriptModules.php`):
- Open/close drawer (slide-in transform).
- On adapter change → fetch defaults via `nst3af_provider_search`-sibling AJAX, set endpoint placeholder + check default capabilities.
- Form submit via fetch → reload table on success.
- "Test connection" button per row → AJAX, swap status badge, toast result.

`Resources/Public/Css/module.css` — minimal styles to match mockup card/drawer aesthetic on top of TYPO3 backend bootstrap.

### Migration & compatibility

`Classes/Updates/MigrateExtConfProvidersUpdate.php` — implements `UpgradeWizardInterface`. On run: read `ExtensionConfiguration::get('ns_t3af')`, for each non-empty `*_api_key` create one provider row (identifier auto-generated `openai-<6 hex>` style, model from corresponding `*_model` key, encrypted via `CredentialCipher`). First created row → `is_default=1`. Idempotent: skips if rows already exist with matching identifier. Registered in `ext_localconf.php`.

`AiRequestService` and `BaseClient` stay untouched in v1 — they keep reading ext_conf. A follow-up subtask (NOT in this plan) will switch them to `ProviderRepository::findDefault()` once the migration wizard has run on enough installs.

### Capabilities representation

CSV in `capabilities` column. Constants in `Classes/Provider/Capability.php`:
```
chat, completion, embeddings, vision, streaming, tool_use
```
Form renders these as 6 checkboxes (matches mockup). `Provider::hasCapability(string $cap): bool` helper.

### Reference attribution

Header in each ported file:
```
/**
 * License: GPL-2.0-or-later
 */
```
- `AdapterCompilerPass.php` ← `b13/aim` `AiProviderCompilerPass.php`
- `DefaultProviderHook` style → repo applies same single-default invariant in `ProviderRepository::setDefault`
- Adapter `testConnection` shape ← `netresearch/nr_llm` `ProviderInterface::testConnection`
- Drawer/list visual ← original design (no port)

`Documentation/Credits.rst` (new) lists each entry with author + license + scope.

## Files to add / modify

### Add
- `ext_tables.sql`
- `Configuration/TCA/tx_nst3af_provider.php` (minimal TCA — needed for backend record permissions, search, language overlays even though edit form is custom)
- `Configuration/Backend/AjaxRoutes.php`
- `Configuration/JavaScriptModules.php` (extend or add)
- `Configuration/Caching.php` — `nst3af_responses` + `nst3af_provider_models` cache configs (CC-4)
- `Classes/Domain/Model/Provider.php`
- `Classes/Domain/Repository/ProviderRepository.php`
- `Classes/Provider/Capability.php`
- `Classes/Provider/Contract/AdapterInterface.php`
- `Classes/Provider/Contract/VerifyResult.php`
- `Classes/Provider/AdapterRegistry.php`
- `Classes/Provider/SymfonyAi/SymfonyAiPlatformDiscovery.php` — runtime scan of installed `symfony/ai-*-platform` packages
- `Classes/Provider/SymfonyAi/SymfonyAiBridgeAdapter.php` — generic adapter wrapping any Symfony AI Platform bridge
- `Classes/Provider/SymfonyAi/CapabilityMapper.php` — Symfony AI `Capability` → ns_t3af capability strings
- `Classes/DependencyInjection/AdapterCompilerPass.php`
- `Classes/Service/CredentialCipher.php`
- `Classes/Exception/CipherException.php`
- `Classes/Api/AiServiceInterface.php` + `AiOptions.php` + `AiResponse.php` + `EmbeddingResponse.php` (CC-6 public facade — semver-stable)
- `Classes/Service/AiService.php` (default `AiServiceInterface` impl; routes to default provider via `AdapterRegistry`)
- `Classes/Event/{BeforeProviderRequestEvent,AfterProviderResponseEvent,ProviderRequestFailedEvent,ProviderTestConnectionEvent,ProviderRegisteredEvent}.php` (CC-6)
- `Classes/Controller/Backend/ProviderListController.php`
- `Classes/Controller/Backend/ProviderAjaxController.php`
- `Classes/Updates/MigrateExtConfProvidersUpdate.php`
- `Resources/Private/Templates/Provider/List.html`
- `Resources/Private/Partials/Provider/Drawer.html`
- `Resources/Private/Partials/Provider/Row.html`
- `Resources/Private/Partials/Provider/ModeToggle.html`
- `Resources/Public/JavaScript/provider-drawer.js`
- `Resources/Public/Css/module.css`
- `Documentation/Credits.rst`
- `Documentation/CustomProviders.rst` — skeleton (CC-7)
- `Documentation/PublicApi.rst` — `AiServiceInterface` reference for child extensions (CC-6)

### Modify
- `Configuration/Backend/Modules.php` — add new routes
- `Configuration/Services.yaml` — register adapters with tag, public ProviderListController + ProviderAjaxController, configure `_instanceof: AdapterInterface { tags: [nst3af.adapter] }`
- `ext_localconf.php` — register upgrade wizard, register Services.php compiler pass
- `Classes/Controller/Backend/ModuleController.php` — link Dashboard "Providers" card to new route
- `Resources/Private/Language/locallang_mod.xlf` — add `provider.*` keys (form labels, status strings, capability labels, errors)
- `composer.json` — bump v2.x branch (`"branch-alias": {"dev-main": "2.0.x-dev"}`); raise floor to PHP 8.1+, TYPO3 12.4+ for v2 line. Add `suggest` block for `lochmueller/seal_ai`, `lochmueller/seal`, `symfony/ai-platform`. v1.x stays on existing constraints. (User confirmation of branch separation can happen during implementation.)

## Upstream merge notes (commit `52258c7`)

This commit ("[TASK] code improvements", merged 2026-05-07) landed dev tooling that affects this feature:

- **`Classes/Client/BaseClient.php` was refactored (~156 lines).** Re-read it before touching. The plan's "leave BaseClient untouched in v1" assumption still holds (it stays on `ext_conf`-driven mode), but cross-check method signatures before wiring `AiServiceInterface` in v2-line callers.
- **`composer test`, `composer stan`, `composer cs:check` are the canonical commands.** Don't shell out to `phpunit` / `phpstan` directly — go through `composer` so paths and bootstrap stay correct.
- **3 unit tests already exist** as reference for new tests: `Tests/Unit/Client/BaseClientTest.php`, `Tests/Unit/Configuration/AiEngineConfigurationTest.php`, `Tests/Unit/Helper/AiUniverseChartHelperTest.php`. Match their style (PHPUnit 13, no Functional layer yet).
- **`.Build/vendor/` is the autoload root.** Run `composer install` to populate before tests.
- **`composer.json` `require-dev`** locked to `phpunit/phpunit ^13.0`, `phpstan/phpstan ^2.1`, `friendsofphp/php-cs-fixer ^3.94`. Don't downgrade.
- **PHP / TYPO3 floors in upstream `composer.json` (`^7.4 || ^8` + `^11 || ^12 || ^13`)** to be raised by Feature 1 — open PR with floor bump + corresponding ext_emconf change.
- **CI is currently PHP 8.4 only** — expand matrix as part of Feature 1 alongside floor bump.

## Verification

End-to-end check after implementation:

1. **Install / migrate**
   ```bash
   ddev exec vendor/bin/typo3 cache:flush
   ddev exec vendor/bin/typo3 extension:setup
   ddev exec vendor/bin/typo3 upgrade:run nst3afMigrateExtConfProviders
   ```
   Expect: row(s) created in `tx_nst3af_provider` matching pre-set ext_conf keys, first marked default.

2. **List view**
   - Login as BE user with module access → top-level **AI Foundation** → Dashboard → Providers tab.
   - Header shows N providers, X active, default badge, encryption pill.
   - Mode-toggle cards render; Credits card disabled.
   - Search filters rows live.

3. **Add provider (drawer)**
   - Click "+ New Provider" → drawer slides in.
   - Adapter dropdown switching pre-fills endpoint placeholder + checks default capabilities.
   - Save with empty Identifier → shows validation error.
   - Save valid OpenAI provider → row appears, masked key in drawer if reopened.
   - DB check: `api_key` is base64 ciphertext starting with `enc:v1:`.

4. **Test connection**
   - Click Wi-Fi icon on row → AJAX call.
   - Wrong key → status badge flips to Disconnected with message tooltip.
   - Valid key → badge flips to Connected, `last_status_at` updated.
   - Repeat for Anthropic, Gemini, Ollama (against `http://host.docker.internal:11434`).

5. **Default toggle + delete**
   - Set default on a different row → previous default's badge clears.
   - Delete row → soft-delete (gone from list, present in DB with `deleted=1`).

6. **Encryption integrity**
   - `SELECT api_key FROM tx_nst3af_provider` from DB CLI shows ciphertext only.
   - Tamper one byte → next test-connection shows decryption failure surfaced as Disconnected + log entry, not a 500.

7. **Backwards compat smoke**
   - `AiRequestService::sendRequest` still works using ext_conf (existing callers untouched).

8. **Tests** — run via existing `composer test` / `composer stan` (upstream `52258c7` shipped the toolchain):
   - Unit (under `Tests/Unit/`, mirror existing layout): `CredentialCipherTest` (round-trip, tamper detection), `ProviderRepositoryTest` (default invariant), `SymfonyAiPlatformDiscoveryTest` (mocked `Composer\InstalledVersions`), `CapabilityMapperTest`, `SymfonyAiBridgeAdapterTest::testConnection` against a mocked `PlatformInterface`.
   - Functional (new `Tests/Functional/`, requires extending `phpunit.xml.dist`): upgrade wizard creates expected rows from a fixture ext_conf.
   - Architecture (new `Tests/Architecture/` once phpat installed): controllers must not import `Provider\Adapter\*` directly.

## Out of scope (explicit) — forward pointers

| Item | Status |
|---|---|
| Three-tier Provider / Model / Configuration split | Deferred — flat `tx_nst3af_provider` for v2.x |
| T3Planet Credits client | **Done** — `context/features/credits.md` |
| MCP server + tools | **Done** — `context/features/mcp-server.md` |
| Governance (ACL, budgets, telemetry) | **Done** — `context/features/governance.md` |
| RAG / vector stores via `lochmueller/seal` | Deferred |
| Smart routing / `no_rerouting` enforcement | Deferred — flag stored, not enforced |
| Child extension refactor (`ns_t3ai`, `ns_t3cs`, `ns_t3aa`) | Ongoing — `context/features/child-extensions.md` |
| `nr-vault` dependency | Not adopted — `CredentialCipher` (CC-1) |
| `BaseClient` / `AiRequestService` | Deprecated — use `AiServiceInterface` |
