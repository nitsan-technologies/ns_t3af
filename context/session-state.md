# Session State

*Living work log — update at end of each session. Historical detail from the pre-2026-06-08 monolithic AGENTS.md is preserved below.*

## 2026-07-15 — Context audit for public GitHub

**Done:**
- Resolved leftover merge conflicts in `context/features/{child-extensions,backend-module,ai-logs}.md` and `Typo3CoreBackendDesign.md` (keep `ns_t3af` naming).
- Aligned `context/core.md` with shipped floors (`composer.json` PHP/TYPO3 + TER `1.0.0`) and GitHub/TER canonical URLs.
- Credits feature status corrected to **Implemented + Coming soon gate** (`CreditsReleaseGate`) in `context/features/credits.md`, `backend-module.md`, `AGENTS.md`.
- Fixed `docs-map.md` broken pointer to missing AI Permissions prototype doc; MCP spec no longer references missing `Design/` path.
- Light public scrub: server credits spec banner; removed local Codex plan path from archive below.

**Canonical remote:** https://github.com/nitsan-technologies/ns_t3af/ (single source of truth).

**Last touched:** 2026-07-15

---

## 2026-07-15 — GitHub `.github` Phase 1 (Dependabot + community)

**Done:** Added portable `.github/` bootstrap (no Netresearch reusable workflows): `dependabot.yml` (composer + github-actions, weekly Monday, grouped, 7d cooldown), `PULL_REQUEST_TEMPLATE.md`, `ISSUE_TEMPLATE/{bug_report,feature_request,config}.yml`, `labeler.yml`. No `CODEOWNERS` (needs org team/`@user`; bare org invalid). Quality gate remains GitLab (`.gitlab-ci.yml`).

**Activate:** Push to [nitsan-technologies/ns_t3af](https://github.com/nitsan-technologies/ns_t3af) default branch; enable Dependabot alerts + security updates in repo settings. Auto-labeling needs a future `actions/labeler` workflow.

**Last touched:** 2026-07-15

---

## 2026-07-03 — AI Access provider registry (Feature 4)

**Done:** Provider-driven AI Access / Roles — `AiAccessCatalogProviderInterface` + `t3af.ai_access_catalog_provider` tag; `AiAccessCatalogProviderRegistry`, `FeatureAccessBindingRegistry`, catalog merge in `ModuleAccessCatalog` / `FeaturePermissionCatalog` / `RecordPermissionCatalog`; `WizardBootstrapFactory`, `MatrixScopeCatalog`, catalog-driven `GroupConfigNormalizer` / serializer / deserializer; `AiAccessCustomOptionsBootstrap` for native `be_groups` `T3Ai:*` options; shipped providers in `ns_t3ai`, `ns_t3aa`, `ns_t3cs`, `ns_t3as`, `ns_t3ac`. Docs: `Documentation/Developer/CustomAiAccess.rst`, `context/features/ai-access-roles.md`.

**Last touched:** 2026-07-03

---

## 2026-07-01 — T3Planet Credits release gate (Coming soon)

**Done:** Credits implementation kept intact but **not selectable** for this release. Central switch: `CreditsReleaseGate::PUBLICLY_AVAILABLE = false` in `Classes/Credits/Service/CreditsReleaseGate.php`. `CreditModeResolver` respects the gate; admin UI shows disabled T3Planet Credits card with "Coming soon"; buy/pricing/checkout routes redirect to dashboard; child extensions inherit via resolver.

**Re-enable next release:** set `CreditsReleaseGate::PUBLICLY_AVAILABLE = true`.

**Last touched:** 2026-07-01

---

## 2026-06-24 — AI Context (brand profiles)

**Done:** AI Context tab, profile drawer (collapsible personas), runtime `{brand_context}` injection, dashboard summary bar, wizard step 6, scope-gated AI Features override (SEO/Pages/Content/Translation/Media only), auto-research, document upload.

**Agent context:** `context/features/ai-context.md` (primary); `context/architecture.md` § AI Context; `Documentation/Architecture/AiContextImplementationPlan.md` (full phase plan).

**Last touched:** 2026-06-24

---

## 2026-06-08 — ext_conf cleanup + agent context system

**ext_conf provider cleanup (done):**
- LLM provider keys removed from `ext_conf_template.txt`; config lives in `tx_nst3af_provider`.
- Added `ProviderLegacyConfigService`, `ProviderSlugMapper`; `ns_t3cs` uses provider-backed merged config.
- `AiRequestService` / `BaseClient` deprecated; `AiServiceInterface` is the path forward.
- Migrator extended for `openai_oss_*`. Run: `typo3 upgrade:run nst3afMigrateExtConfProviders`.

**Agent context system (done):**
- Modular `context/`, `tasks/`, `.agents/skills/`, slim `AGENTS.md` router.
- Feature entry points: `context/features/*.md`.

**Agent context status audit (2026-06-08):**
- Governance / telemetry → **Done** (`AccessControlListener`, budgets, rate limits, privacy, request log).
- T3Planet Credits client → **Done** (`T3PlanetCreditAiService`, dashboard, Charge/Stream/Embed proxy).
- MCP Server & tools → **Done** (OAuth, stdio/HTTP, core + dynamic + custom tools, MCP Tools tab, Security/Analytics/Advanced sub-tabs, Dashboard MCP Overview, Playground, Skill Hub, prompt templates).
- Deep specs moved from package root → `context/specs/FEATURE_*.md`.

**Last touched:** 2026-06-08

---

## Archive (2026-05-15 and earlier)

**Last touched (archive):** 2026-05-15

**Post-leadership revision (2026-05-15 PM) — token auth + shared AI-ext pool:**
- Both FEATURE files gained `## Revision 2026-05-15 (post-leadership)` banner at top. Banner OVERRIDES original HMAC/signing-secret sections — read banner first when working on this feature.
- **Auth simplified to single opaque token.** No HMAC envelope, no nonce, no timestamp signing, no rotation. `Authorization: Bearer <token>` over TLS. `request_uuid` retained for client-retry idempotency only (not security).
- **Token source:** new `token VARCHAR(64) UNIQUE` column on `ns_product_license`. Generated server-side at license purchase (`NewOrderCreateLicense.php` + `CreateLicenseAfterOtp.php`) via `bin2hex(random_bytes(32))` when `extension_key` ∈ AI ext list and `token=''`. Backfill migration for existing licenses.
- **Client TokenResolver (3-tier):** 1) `tx_nst3af_runtime_setting.token_enc` cache → 2) `ns_product_license.token` (via `NsLicenseRepository::fetchData()` — now exposes `token` field on `LicenseContext`) → 3) `POST /AI/Token.php {license_key, domain}` fallback (only auth that accepts license_key directly; everything else is Bearer token).
- **Shared pool across AI child extensions** by design: all AI child ext (`ns_t3ai`, `ns_t3cs`, `ai_suite`) call `AiServiceInterface` → `T3PlanetCreditAiService` decorator attaches one token → server resolves to one pool. Pool keyed by token. Optional `ns_ai_pool_alias` retained for multi-license customer deals.
- **Dropped server pieces:** `ns_ai_install_secret` table, `/AI/Register.php`, `/AI/RotateSecret.php`, `AiSignatureVerifier.php`, `AiNonceService.php`.
- **Dropped client files:** `EnvelopeSigner.php`, `NonceGenerator.php`, `SecretRotationService.php`, `InstallBootstrapService.php`, `T3PlanetPublicKey.php` (sealed-box deferred). `signing_secret_enc` + `pending_signing_secret_enc` columns gone; new `token_enc`.
- **New endpoint:** `POST /AI/Token.php {license_key, domain}` → `{token}`. Idempotent, validates via existing `LicenseRepository` + new `DomainMatcher`.
- **Pre-flight per AI call:** Bearer token → license row by `token` → not EXPIRED_/expired → domain match → `request_uuid` idempotency → rate limit → charge.
- **Error code deltas:** drop `signature_*`, `nonce_replay`. Add `token_missing`, `token_invalid`. Client maps to `credits.token_missing` / `credits.token_invalid`. On `token_invalid` 401, client clears cached token, re-runs `TokenResolver::resolve()` (skips step 1), retries once.
- **Tier 0 hardening still mandatory:** DomainMatcher (AI endpoints only), prepared statements (AI repos only), transactions, `request_uuid` UNIQUE, rate limit, `license_key UNIQUE` (post dupe-audit), `random_bytes` for token gen. HMAC verifier row + clock skew row are gone.
- **Security trade-off accepted by leadership:** stolen token = bearer access until admin clears `ns_product_license.token`. TLS + server-side domain match = only network defences. Rotation reconsidered v1.1.
- Hold on implementation STILL applies — server team needs to confirm `ns_product_license.token` column + purchase-hook generation; ns_license version exposing `token` to repositories must be tagged before client work starts.

**Product catalog gap closed (2026-05-15, pre-leadership-meeting patch):**
- Both FEATURE files extended with product/SKU catalog (was missing).
- **Server (`FEATURE_T3PlanetCredits_Server.md`):** new `ns_ai_product` table (sku, type plan/topup/trial, title, credits, renewal_period, price, pabbly_sku UNIQUE, checkout_url, features_json, badge, is_active, sort_order). Seed rows for trial/starter/pro/agency/topup_1000/topup_5000. New endpoints `GET /API/AI/Products.php`, `/PurchaseHistory.php`, `/CurrentPlan.php`. Pabbly webhook now resolves product via `pabbly_sku` lookup (no more hard-coded SKU map). Admin module "AI Products" CRUD added. Verification items 19–21 added.
- **Client (`FEATURE_T3PlanetCredits_Client.md`):** new `ProductCatalogService` + `CurrentPlanService` + `PurchaseHistoryService`. New backend module sub-routes `aiuniverse_credits → {buy, history, pricing}`. Buy Credits page renders cards from server (no local product editing). Checkout flow: `window.open(checkout_url, '_blank')` with `{license_key}`/`{domain}`/`{return}` placeholder substitution. Post-checkout 5-min balance polling. Current plan card on Dashboard+Buy. Read-only purchase history mirror. Feature cost (Pricing) read-only page. New cache table `tx_nst3af_product_catalog`. Phase 5 + verification 17–22 extended.
- Hold still in effect until leadership meeting clears T3Planet Credits plan.

**Last touched (prior session):** 2026-05-14

**T3Planet Credits feature plan finalized (2026-05-14):**
- Both FEATURE files fully rewritten and **awaiting leadership review** (user meeting 2026-05-15 morning). Hold implementation until that call clears.
- `FEATURE_T3PlanetCredits_Server.md` — credit endpoints layered onto existing `composer.t3planet.cloud` license API (NOT a new TYPO3 install — supersedes earlier `ns_t3p_credits` standalone design). New `composer/API/AI/*` endpoints + `ns_ai_*` tables. Plain PHP + raw mysqli, matches existing server stack.
- `FEATURE_T3PlanetCredits_Client.md` — all client code lives **inside ns_t3af** under `Classes/Credits/`. Loose dep on `nitsan/ns-license` (`suggest`, not `require`; class_exists guard in `LicenseKeyResolver` factory).
- **Identity model:** ns_license `license_key` IS the install identity. First-boot `/API/AI/Register.php` exchanges license_key+domain for `signing_secret` (encrypted via `CredentialCipher`). Subsequent calls = HMAC-SHA256 envelope (`METHOD|PATH|TS|NONCE|sha256(BODY)`), ±300s clock skew, `request_uuid` UNIQUE 24h idempotency.
- **Server Tier 0 hardening BLOCKING money endpoints:** safe DomainMatcher (replaces substring `strpos` vuln, AI endpoints only; legacy `LicenseRepository` untouched), prepared statements (AI repos only), mysqli transactions, idempotency table, rate limit table, HMAC verifier, `ALTER ns_product_license ADD UNIQUE uk_license_key` (post dupe-audit), `random_bytes` for secrets, no silent self-activation on read path.
- **Pricing:** feature-based credit cost map (`ns_ai_feature_cost`), seeded from `vendor/autodudes/ai-suite/Classes/Enumeration/CreditCostEnumeration.php`. 1 SEO meta = 5 credits, 1 image gen = 50, etc. Admin-editable; per-license overrides via `ns_ai_feature_cost_override`.
- **Pool model:** keyed by primary `license_key`. Multi-product sharing via `ns_ai_pool_alias` (customer owning ns_t3af + ns_t3ai + ai_suite all map to one pool). Buckets debit order plan → free → paid.
- **Mode:** server-proxy. composer.t3planet.cloud holds OpenAI/Anthropic/Gemini/Mistral/OpenRouter keys; client never sees provider keys. Outbound clients ported from `ns_t3af/Classes/Client/BaseClient.php` (attribution required).
- **Atomic debit:** `BEGIN → SELECT pool FOR UPDATE → reserve → pre-log → COMMIT → call upstream OUTSIDE lock → settle in second tx`. Refund-on-upstream-failure mandatory v1.
- **Pabbly:** `/webhook/pabbly-ai` adds top-up + plan; SKU map: trial 100, starter 2000/mo, pro 10000/mo, agency 50000/mo, topup 1000/5000. Trial auto-grant hooked into existing `NewOrderCreateLicense.php` + `CreateLicenseAfterOtp.php` (one-shot, gated by `trial_credits_granted=1`).
- **Full prompt logged server-side** (`ns_ai_credit_log.prompt_full`) — confirmed by user; GDPR disclosure in `Documentation/Privacy.rst`. Per-license hash-only opt-out deferred to v1.1.
- **Client deltas to public API:** `AiOptions::$featureKey` (NEW required arg in credits mode), `AiResponse::?CreditsUsage $credits`, `EmbeddingResponse::?CreditsUsage`, `StreamSummary` (generator return for SSE).
- **Decorator:** `T3PlanetCreditAiService decorates AiServiceInterface`; always wired; `CreditModeResolver` short-circuits to `$inner` when toggle OFF → zero HTTP traffic. phpat rule blocks `Classes/Credits/` from importing `Provider/*` adapters.
- **First-boot UI:** flip toggle → modal with license picker (drop-down of valid `ns_product_license` rows via `LicenseKeyResolver::listAvailable()`) → activate → Register call → toast confirms balance.
- **Server inventory captured (referenced in FEATURE_Server):** plain PHP + mysqli, files under `composer/API/`, env via `.env`/`.env.local` (`getApiConfig()`), CORS `*`, no current auth beyond `ns_license` + self-asserted `domain`, no rate limit anywhere, no idempotency anywhere, `license_key` not unique-indexed, SQL injection on legacy license-key paths, substring domain matcher vuln in `LicenseRepository::getLicenseDetails`, hard-coded webhook secret in `composer/webhook.php:16`, hard-coded dev-domain bypass keys in `check_if_dev_domain:217-222`. All flagged in FEATURE_Server §15 "Open/deferred" as out-of-scope for v1 credit work.
- **What user is doing tomorrow morning (2026-05-15):** leadership meeting; possible plan changes after. Do NOT start implementation until user returns with clearance.

**Last touched (prior session):** 2026-05-12

**Unified provider backend + Docker PHPUnit (2026-05-12, commit `1985ec2`):**
- `ProviderListController` + `ProviderAjaxController` **merged** into single `Classes/Controller/Backend/ProviderController.php` (CRUD + AJAX test/set-default/search/models routes). Shared base `AbstractAiUniverseModuleController` extracted for module bootstrapping.
- AJAX routes in `Configuration/Backend/AjaxRoutes.php` now resolve to `ProviderController::*Action`; old controllers deleted.
- `Build/Scripts/runTests.sh` (Docker-based, mirrors TYPO3 core runner) + `Build/phpunit/{UnitTests.xml,FunctionalTests.xml}` + bootstraps. CI workflow uses it. Functional test layer wired: `Tests/Functional/Controller/Backend/ProviderControllerFunctionalTest.php`.
- `composer test` / `composer test:functional` still entry points; runner handles container.

**Analytics + request log + pricing (2026-05-11, commit `04fd4a3`):**
- New service `Classes/Service/RequestTelemetryService.php` (records every adapter call), `Classes/Service/DashboardAnalyticsService.php` (aggregates for module dashboard), repo `Classes/Domain/Repository/RequestLogRepository.php`.
- TCA `tx_nst3af_provider` extended with pricing fields (per-1k input/output token cost, currency). `ext_tables.sql` adds `tx_nst3af_request_log` table.
- `AiService` instrumented to dispatch telemetry around invoke/stream/embed. `SymfonyAiBridgeAdapter` enriched to surface token/cost metadata.
- Backend `Templates/Module/Dashboard.html` now renders usage cards + analytics from `DashboardAnalyticsService`. Drawer adds pricing inputs (`Partials/Provider/Drawer.html`).

**Adapter registry + OpenAI-compatible HTTP (2026-05-12):**
- Built-in `OpenAiCompatibleAdapter` — adapter id **`nst3af.openai_compatible`** (`Provider::ADAPTER_OPENAI_COMPATIBLE`, UI “Custom / Other”). Native HTTP in `Classes/Provider/OpenAiCompatible/` via `RequestFactory` (`/models`, `/chat/completions`, `/embeddings`). **Does not** require `symfony/ai-openai-platform`.
- Legacy DB values **`symfony.openai_compatible`** normalize to the new id via `Provider::normalizeAdapterType()` (hydration + `ProviderFormService::save()` + transient `modelsAction`).
- **Child extensions** implementing `AdapterInterface` must tag **`nst3af.adapter`** in **their own** `Configuration/Services.yaml` (`_instanceof` on `AdapterInterface` + e.g. `Classes/Provider/` resource). Rules in EXT:ns_t3af `Services.yaml` do not apply to foreign packages. Doc: `Documentation/Developer/CustomProviders.rst`. Demo: monorepo `packages/ns_t3ai/Classes/Provider/AcmeAdapter.php`.
- `LiveModelProbe` / `SymfonyAiBridgeAdapter::PROBE_CONFIG` keyed for the built-in compatible id where relevant.
- **PHPStan / CI:** `typo3/cms-install` is in `require-dev` (upgrade wizard interfaces); GitHub Quality workflow pins it next to core so `composer stan` passes.

**Dynamic model discovery (Feature 1 follow-up, 2026-05-11):**
- New namespace `Classes/Provider/Model/`: `ModelInfo` DTO, `ModelDiscoveryServiceInterface`, `ModelDiscoveryService`, `LiveModelProbe`, `SymfonyAiCatalogReader`, `CapabilityInferrer`.
- Merge precedence: live `/models` IDs win; capabilities overlaid from Symfony AI `ModelCatalog` then capability-inference heuristics on the model id.
- Cached 24h in `nst3af_provider_models` keyed by `models_{uid}_{adapterType}`. `?refresh=1` bypasses cache. Tags include `provider_{uid}` for future invalidation on save/delete.
- Probe endpoints (same auth map as `SymfonyAiBridgeAdapter::PROBE_CONFIG`): OpenAI `/models` bearer, Anthropic `/models` x-api-key, Gemini `/models?key=`, Mistral `/models` bearer, Ollama `/api/tags` none, OpenRouter `/models` bearer. Adapters without a usable list endpoint return [] → catalog/inference path remains authoritative.
- AJAX route: `nst3af_provider_models` → `ProviderAjaxController::modelsAction`. Params: `uid` OR (`adapterType`, `endpoint`, `apiKey` for transient draft). `apiKey` is encrypted in-memory via `CredentialCipher::encrypt()` for the transient `Provider` DTO; never persisted.
- Drawer JS (`provider-drawer.js`): adapter change OR refresh button → fetch → populate model `<select>`. Selecting a model autoticks capability checkboxes (user can override). `__custom__` option toggles free-text input for Azure deployments / proxy aliases. Live-fetch failure falls back to free-text + hint.
- DI: `nst3af.cache.provider_models` service factory binds `CacheManager::getCache('nst3af_provider_models')`. `Services.yaml` excludes `Provider/Model/ModelInfo.php` + `Provider/Model/ModelDiscoveryServiceInterface.php` from auto-registration.
- Tests added: `Tests/Unit/Provider/Model/CapabilityInferrerTest.php`, `Tests/Unit/Provider/Model/ModelDiscoveryServiceTest.php`.

**Upstream pulled:** commit `52258c7` "[TASK] code improvements" (merged 2026-05-07). Dev tooling: PHPUnit 13, PHPStan 2.1, php-cs-fixer 3.94, `composer test`/`stan`/`cs:check`. `.github/workflows/ci.yml`. Refactored `Classes/Client/BaseClient.php`. Vendor at `.Build/vendor/`.

**Backend module (RELOCATED to top-level, 2026-05-08):**
- `Configuration/Backend/Modules.php` registers top-level `aiuniverse` parent (position: after Web, `access: user`) + `t3af_dashboard` submodule.
- Routes under `t3af_dashboard`: `_default`, `providers`, `providers.new|.edit|.save|.delete`, `mcp_*`, `ai_*`, `scheduler_cli`.
- Single `ProviderController` (CRUD + AJAX test/set-default/search/models), `public: true`. (Was `ProviderListController` + `ProviderAjaxController` pre-`1985ec2`.)
- Layouts/Templates/Partials in `Resources/Private/{Layouts,Templates,Partials}/`. Layout `Module.html` guards `<core:icon>` against null `moduleLogoIdentifier`.
- Labels: `locallang_mod.xlf` (parent) + `locallang_mod_dashboard.xlf` (submodule + provider.* keys).
- Icons: only `ns-t3af-module` registered in `Configuration/Icons.php`. Templates use core identifiers (`actions-*`, etc.) directly — never re-register what core ships.

**Feature 1 — AI Provider Management (DONE, all 6 phases):**
- Spec: [`context/specs/FEATURE_AiProviderManagement.md`](specs/FEATURE_AiProviderManagement.md) — read this before touching any provider/adapter/cipher code.
- Locked decisions:
  1. Flat single table `tx_nst3af_provider` (mockup-aligned).
  2. API key encryption: `sodium_crypto_secretbox` keyed by `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`. Ciphertext prefix `enc:v1:`.
  3. Verify connection: per-adapter `testConnection()` probe. Result persisted to `last_status`/`last_status_at`/`last_status_message`.
  4. UI: TCA list + custom Fluid slide-in drawer matching the design mockup.
  5. Adapters v1: **Symfony AI / SEAL-backed** via `lochmueller/seal_ai` + `symfony/ai-*-platform` runtime auto-discovery. Custom adapter contract still exposed for non-Symfony providers.
  6. Symfony AI bridge — **adopted** (decision reversed from earlier draft).
  7. T3Planet Credits mode toggle — visual stub only (Credits card disabled "Coming soon"). Full credits = Feature 2.
  8. Migration: `MigrateExtConfProvidersUpdate` upgrade wizard auto-imports existing `*_api_key` ext_conf entries.
- Cross-cutting requirements (CC-1…CC-9 in FEATURE doc) apply project-wide: security/sodium, BE roles, workspace, response cache, streaming, hooks/events/`AiServiceInterface` facade, custom providers, per-user budgets (forward), quality bars (PHPStan 10, phpat, Infection MSI ≥ 70%, PHP 8.1+ × TYPO3 12.4/13.4 matrix).
- Public API: `NITSAN\NsT3AF\Api\AiServiceInterface` is the semver-stable surface. Child extensions (`ns_t3ai`, `ns_t3cs`, …) inject this; never touch adapters directly.
- File list (add/modify) and verification steps are in the FEATURE file.
- `BaseClient` / `AiRequestService` stay on ext_conf in v1 — switch happens in a follow-up patch after migration ships.
- Branch separation: master-extension feature work targets new v2.x line (PHP 8.1+, TYPO3 12.4+); existing v1.x compatibility kept.

**Forward-pointed features (separate FEATURE_*.md files later):**
- Feature 2 — AI Credits (port from `autodudes/ai-suite` → `packages/ai-suite/Classes/Enumeration/CreditCostEnumeration.php`, `Backend/ToolbarItems/RequestsToolbarItem.php`).
- Feature 3 — MCP Server (port from `hn/typo3_mcp_server` + `marekskopal/ms_mcp_server`).
- Feature 4 — RAG & Vector Stores (`lochmueller/seal`).
- Feature 5 — Governance & Pipeline (port from `aim` + `nr_llm`).
- Feature 6 — Child extension refactor (`ns_t3ai`, `ns_t3cs` switch to `AiServiceInterface`).

**Tooling (already shipped by upstream `52258c7`):**
- Run tests: `composer test` (PHPUnit 13, `Tests/Unit/`)
- Static analysis: `composer stan` (PHPStan 2.1, level 3 globally; new `Provider/`, `Api/`, `Service/AiService` namespaces opt into level 8 via path-scoped config to be added in Feature 1)
- Code style: `composer cs:check` (php-cs-fixer 3.94, non-blocking baseline)
- Bootstrap: `.Build/vendor/autoload.php`
- CI: `.github/workflows/ci.yml` — currently PHP 8.4 only; expand to PHP 8.1/8.2/8.3/8.4 × TYPO3 12.4/13.4 in Feature 1
- 3 reference unit tests already in `Tests/Unit/` — mirror their pattern

**Decisions confirmed:**
- **Compatibility (FINAL, 2026-05-08):** v2.x targets **TYPO3 13.4 + 14.0 × PHP 8.2 / 8.3 / 8.4 / 8.5**. Update `composer.json` (`php: ^8.2 || ^8.3 || ^8.4 || ^8.5`, `typo3/cms-core: ^13.4 || ^14.0`) + `ext_emconf.php` constraints in Feature 1. CI matrix expands to 4 PHP × 2 TYPO3 = 8 jobs. v1.x line keeps current floors on separate branch.
- PHPStan: level 3 baseline + level 8 on new namespaces (path-scoped). No global bump.
- **Backend module relocation (FINAL, 2026-05-08):** Drop `tools_aiuniverse` (Admin Tools child). Register **new top-level parent module `aiuniverse`** in main nav, position **after Web, before File**. Access gated by configurable `be_groups` (TCA/extconf — not admin-only). Submodules under it:
  - `t3af_dashboard` — owned by ns_t3af. Single submodule containing landing card grid + Providers list + Stats (matches mockup, no further split).
  - Sibling extensions (`ns_t3ai`, `ns_t3cs`, …) own their own `Configuration/Backend/Modules.php` with `'parent' => 't3af'`. Loose coupling — child ext must fall back to its own top-level module if `aiuniverse` parent absent (so child works standalone).
  - Existing skeleton controller/templates/labels keep — rename module key + adjust parent.

**Phase decisions locked (2026-05-08):**
- Branch: `2.x` = current `master` state (no reset, just continue). Floor bump + module relocation landed this session.
- SEAL (`lochmueller/seal_ai`, `lochmueller/seal`) = `suggest` only in v1, revisit at Feature 4.
- Provider records pid: `0` (root, system administration data — not a sysfolder).
- TCA edit form: kept available as fallback. Custom drawer is primary UI.

**Phases — all six COMPLETE on `2.x` (2026-05-08):**

- Phase 0 — Infra prep ✅
  - composer.json: `php ^8.2 || ^8.3 || ^8.4 || ^8.5`, `typo3/cms-core ^13.4 || ^14.0`, plus `cms-backend` + `cms-fluid` (controller deps).
  - `ext_emconf.php` constraints aligned. Version `2.0.0-dev`.
  - CI matrix: 4 PHP × 2 TYPO3 = 8 jobs (`.github/workflows/ci.yml`).
  - Module relocation done. `Configuration/Services.php` registers `AdapterCompilerPass` (signature: `ContainerConfigurator $configurator, ContainerBuilder $containerBuilder` only — TYPO3 v13 doesn't pass `LoaderInterface`).

- Phase 1 — Encryption + persistence ✅
  - `ext_tables.sql` + `Configuration/TCA/tx_nst3af_provider.php` (read-only `api_key` field; drawer is primary editor).
  - `Classes/Service/CredentialCipher.php` — sodium `crypto_secretbox` keyed by `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`. Prefix `enc:v1:`. `sodium_memzero` after key use.
  - `Classes/Domain/Model/Provider.php` (readonly DTO, `fromRow()`, `hasCapability()`).
  - `Classes/Domain/Repository/ProviderRepository.php` implements `ProviderRepositoryInterface` (extends `ProviderLookupInterface`). Methods: `findAll/ByUid/ByIdentifier/Default`, `setDefault` (single-default invariant), `save` (insert/update), `softDelete`, `updateStatus`.
  - `Classes/Provider/Capability.php` — class with string constants. Enum migration deferred (DB CSV column).
  - `Classes/Exception/{AiUniverseException,CipherException,UnknownAdapterException,AdapterRuntimeException}.php`. Marker interface `AiUniverseException` on every domain exception.

- Phase 2 — Adapter contract + Symfony AI bridge ✅
  - `Classes/Provider/Contract/{AdapterInterface,VerifyResult}.php` (`@api`).
  - `Classes/Provider/AdapterRegistry.php` — iterator over `nst3af.adapter`-tagged services.
  - `Classes/Provider/SymfonyAi/{BridgeDescriptor,CapabilityMapper,SymfonyAiPlatformDiscovery,SymfonyAiBridgeAdapter}.php`.
  - `Classes/DependencyInjection/AdapterCompilerPass.php` — `registerForAutoconfiguration(AdapterInterface)` + per-discovered-package `SymfonyAiBridgeAdapter` definitions. **`BridgeDescriptor` must be registered as its own scalar-arg `Definition` and `Reference()`d** — Symfony DI cannot serialize raw objects as constructor args. **`OpenAiCompatibleAdapter`** lives under `Provider/OpenAiCompatible/` and is picked up by the extension PSR-4 scan + `_instanceof` tag — **not** created by this compiler pass.
  - `Configuration/Services.yaml` — `_instanceof: AdapterInterface { tags: [nst3af.adapter] }` + `AdapterRegistry { $adapters: !tagged_iterator nst3af.adapter }` + interface aliases. **Bulk PSR-4 scan excludes** `Api/`, `Domain/Model/`, `Event/`, `Exception/`, `DependencyInjection/`, `Provider/Capability.php`, `Provider/Contract/*`, `Provider/SymfonyAi/{BridgeDescriptor,SymfonyAiBridgeAdapter}.php`, `Service/ProviderFormResult.php` — non-service classes.
  - `composer.json` `suggest`: `lochmueller/seal_ai`, `lochmueller/seal`, `symfony/ai-platform`. Bridges install on demand: `ddev composer require symfony/ai-anthropic-platform` (transitively pulls `symfony/ai-platform`).
  - `SymfonyAiPlatformDiscovery` matches both `symfony/ai-*-platform` and `lochmueller/seal-ai-*`. Hyphenated vendors (`open-ai`) normalised via `canonicalVendor()` (strips hyphens for endpoint lookup) and `pascalVendor()` (PascalCase for FQCN resolution).

- Phase 3 — Public API + events + cache ✅
  - `Classes/Api/{AiServiceInterface,AiOptions,AiResponse,EmbeddingResponse}.php` — `@api` semver-stable surface. Inject `AiServiceInterface` only.
  - `Classes/Service/AiService.php` — depends on `ProviderLookupInterface`, `AdapterRegistry`, `EventDispatcherInterface`. Duck-typed platform calls (`invoke`/`request`/`stream`/`embed`).
  - 5 PSR-14 events in `Classes/Event/` — `BeforeProviderRequestEvent` (cancellable), `AfterProviderResponseEvent`, `ProviderRequestFailedEvent`, `ProviderTestConnectionEvent`, `ProviderRegisteredEvent` (DTO only — **not** auto-dispatched when adapters register; child code may dispatch if needed).
  - Caches `nst3af_responses` (1h) + `nst3af_provider_models` (24h) — declared in both `Configuration/Caches.php` (canonical) and `ext_localconf.php` (older-patch fallback). No backend hardcoded.

- Phase 4 — Backend UI ✅
  - Drawer UI uses AI Foundation CSS tokens (`--aiu-*`); 520px right slide-in.
  - Templates: `Templates/Provider/List.html`, Partials `Provider/{Drawer,Row,ModeToggle}.html`. Mode toggle: T3Planet credits stubbed `Coming soon`; Own Keys is `is-active`.
  - `Configuration/Backend/AjaxRoutes.php` (3 routes), `Configuration/JavaScriptModules.php` (`@nitsan/nst3af/provider-drawer.js`).
  - `Resources/Public/JavaScript/provider-drawer.js` (ESM, depends on `@typo3/core/ajax/ajax-request.js` + `@typo3/backend/notification.js`) — slide-in, AJAX test/set-default, live filter, password reveal, temp slider live label.
  - `ProviderFormService` (validation: identifier required + pattern + unique, adapter known, title required) + `ProviderFormResult` DTO. Decoupled from controller for testability.

- Phase 5 — Migration wizard ✅
  - `Classes/Updates/{ExtConfProviderMigrator,MigrateExtConfProvidersUpdate}.php`. Wizard auto-discovered via `#[UpgradeWizard('nst3afMigrateExtConfProviders')]` attribute (TYPO3 v13). Idempotent: identifier = `<vendor>-<6-char sha256 hash of key>`. First migrated row gets `is_default=1` only when no default already set. 8 LLM-relevant ext_conf keys mapped; translation/admin keys skipped.
  - Run: `ddev typo3 upgrade:run nst3afMigrateExtConfProviders`.
  - Functional test layer deferred — migrator unit-tested with mocked `ExtensionConfiguration` + repo.

- Phase 6 — Architecture tests + docs ✅
  - `phpat/phpat ^0.11` wired through `phpstan.neon` (`extension.neon` include). 4 rules in `Tests/Architecture/ArchitectureTest.php`:
    1. Controllers ↛ `Provider\SymfonyAi\` (must go through registry).
    2. `Api\` ↛ `Service\`/`Provider\SymfonyAi\`/`DependencyInjection\`/`Controller\`/`Updates\`.
    3. `Domain\Model\` ↛ Controller/Service/Domain\Repository/Updates.
    4. `Event\` ↛ Service/Controller/DependencyInjection.

**Test status (2026-05-08):**
- 71 unit tests, 192 assertions. PHPStan level 3 + 4 phpat rules clean.
- Verify after every change: `cd /Users/nitsan/Public/www/t3-planet/AI\ Universe/aiuniverse && ddev typo3 cache:flush && ddev composer dump-autoload` (saved as memory).

**Real test-connection (post Phase 6 patch):**
`SymfonyAiBridgeAdapter::testConnection` does a real authenticated HTTP probe per vendor (`/models`-style listing). 401/403 → invalid creds; 404 → wrong endpoint; timeout → unreachable. No paid tokens consumed. Probe map (`PROBE_CONFIG`) covers `symfony.{openai,anthropic,gemini,mistral,ollama,openrouter}` plus the native **`nst3af.openai_compatible`** path (same bearer `/models` semantics). Custom Symfony-less HTTP adapters implement their own probe in `testConnection()`. Adapters without a curated probe fall back to platform construction with an explicit "credentials NOT validated" note. `RequestFactory` injected via DI.

**Open items / next steps:**
- Manual smoke pass per FEATURE §Verification with at least one real ext_conf API key seeded.
- `BaseClient` / `AiRequestService` still on ext_conf — switch to `AiServiceInterface`/`ProviderRepository::findDefault()` is a follow-up patch (NOT in Feature 1 scope).
- Functional test layer (`typo3/testing-framework`) — add when the first feature actually needs DB integration tests; tracked but not blocking Feature 1 close-out.
- PHPStan level 8 path-scoped config for `Provider/`, `Api/`, `Service/AiService` namespaces — `phpstan-strict.neon` not yet split out.

## Vision

`ns_t3af` (vendor: `nitsan`, ext-key: `ns_t3af`, composer: `nitsan/ns-t3af`) is being upgraded from a thin AI-provider service layer into the **master AI extension for the TYPO3 community**. Goal: one extension covering provider connectivity, governance, MCP server, dynamic tool registration, custom-table CRUD via AI, and editor-facing features — all in a single install.

Current state (v1.x): service/base layer only. Provider clients (`Classes/Client/BaseClient.php` + per-provider clients), `AiRequestService`, `AiLogService`, `AiStatisticsService`, helpers/utilities. TYPO3 11/12/13, PHP 7.4–8.4. No FE plugin.

Target state: feature-complete master extension. New features will be added incrementally. Earlier sibling extensions in this monorepo are studied as reference and code may be adapted from them with attribution.

## Reference extensions (sibling packages)

We borrow patterns and code from these. **Always credit them** in source headers, `Documentation/`, and commit messages when porting.

| Source | License | What we borrow / study |
|---|---|---|
| `b13/aim` (`packages/aim`) | GPL-2.0-or-later | Capability interfaces, middleware pipeline (retry, ACL, smart routing, capability validation, logging, cost, events, dispatch), `#[AsAiProvider]` attribute auto-discovery, governance (budgets, capability permissions, privacy levels), Symfony AI bridge auto-discovery, dashboard widgets, request log module, three-tier API (proxy / fluent / direct pipeline), structured output + tool calling. Author: Oli Bartsch / b13 GmbH. |
| `netresearch/nr_llm` (`packages/t3x-nr-llm`) | (per LICENSE) | Three-tier Provider→Model→Configuration, `#[AsLlmProvider]` + `ProviderCompilerPass`, fallback chain DTO + middleware, usage tracking middleware, vault-based API key storage (UUID identifiers), TCA form elements (`ModelIdElement`, `ModelConstraintsWizard`), specialized services (DeepL, Whisper, TTS, image), feature-folder layout under `Service/Feature/<X>`, architecture tests (phpat). |
| `hn/typo3-mcp-server` (`packages/typo3_mcp_server`) | GPL-2.0-or-later | Workspace-first MCP design, OAuth backend-user binding, page-tree + content-discovery tools, language overlay handling, auto-workspace creation, LLM E2E tests pattern. Author: Marco Pfeiffer / hauptsache.net. |
| `marekskopal/typo3-mcp-server` (`packages/typo3-mcp-server`, ext-key `ms_mcp_server`) | GPL-2.0-or-later | OAuth 2.1 + PKCE (S256), dynamic client registration (RFC 7591), token revocation (RFC 7009), protected resource metadata (RFC 9728), IP-based rate limiting, Streamable HTTP transport at `/mcp`, 48+ tools (Pages/Content/File/Schema/Search/Batch/Permission/BackendUser/BackendGroup/Cache/Redirect/Scheduler), `DynamicToolRegistrar` for runtime CRUD on extension tables, `ExtensionTableDiscoveryService`, `ErrorHandlingProxy` for tools, `AuditLogger` to `sys_log`, `BackendLayoutResource` template, `mcp:cleanup` CLI. Author: Marek Skopal. |

Attribution rules:
- `Documentation/Credits.rst` listing every borrowed component.
- Commit prefix: `feat(<area>): port <thing> from <source-ext>` with co-authored-by.
- License compatibility: GPL-2.0-or-later only — do not pull from MIT/proprietary code without clearance.

## Target feature set (master extension scope)

Grouped by area. Tick as features land.

### AI provider connectivity
- [ ] Multi-provider clients (existing): OpenAI, Gemini, Azure, Codex/Anthropic, DeepSeek, xAI, Mistral, custom endpoints
- [ ] Capability interfaces (Vision, Conversation, TextGeneration, Translation, ToolCalling, Embedding) — port from `aim`
- [ ] `#[AsAiProvider]` attribute auto-registration via compiler pass — port from `aim` / `nr_llm`
- [ ] Symfony AI bridge auto-discovery (`symfony/ai-*-platform`) — port from `aim`
- [ ] Three-tier API: proxy / fluent builder / direct pipeline — port from `aim`
- [ ] Fallback chain + retry middleware — port from `nr_llm`
- [ ] Smart routing (complexity heuristics → cheap-model downgrade) — port from `aim`
- [ ] Auto model switch per capability — port from `aim`
- [ ] Structured output (JSON Schema) + tool calling requests — port from `aim`
- [ ] Streaming responses

### Governance & ACL
- [ ] Capability permissions in BE groups — port from `aim`
- [ ] Per-config `be_groups` restriction
- [ ] UserTSconfig budgets (period, maxCost, maxTokens, maxRequests) + rate limits
- [ ] Privacy levels (standard / reduced / none), user-escalatable
- [ ] Vault-stored API keys (UUID indirection) — port from `nr_llm`
- [ ] Rerouting protection flag

### Observability
- [ ] Request log table + module (filter, stats, full content) — port from `aim`
- [ ] Cost tracking middleware + cumulative cost per config
- [ ] Dashboard widgets: Recent Requests, Provider/Model/Extension Usage, Success Rate — port from `aim`
- [ ] Audit log to `sys_log` — port from `marekskopal/ms_mcp_server`
- [ ] Events: `BeforeAiRequestEvent`, `AfterAiResponseEvent`, `AiRequestReroutedEvent`

### MCP server
- [ ] Streamable HTTP transport at `/mcp` (MCP 2025-03-26) — port from `ms_mcp_server`
- [ ] stdio transport CLI (`vendor/bin/typo3 ns_t3af:mcp:server`) — port from both MCP exts
- [ ] OAuth 2.1 + PKCE auth, dynamic client registration, token revocation, rate limiting — port from `ms_mcp_server`
- [ ] OAuth-bound backend-user mapping — port from `hn/typo3_mcp_server`
- [ ] Workspace-safe mode (auto-create workspace, transparent overlays) — port from `hn/typo3_mcp_server`
- [ ] Direct/autonomous mode (live edits) — port from `ms_mcp_server`
- [ ] `mcp:cleanup` command (expired tokens + stale sessions)

### MCP tools (target surface)
- [ ] Pages CRUD
- [ ] Content (`tt_content`) CRUD with backend-layout awareness
- [ ] Files: list, search, info, upload (incl. from URL), copy/move/rename/delete, dir ops, file references
- [ ] Schema introspection (`TableSchemaTool`)
- [ ] Search: record search w/ operators (eq/neq/like/gt/gte/lt/lte/in/null/notNull), record count, pages search, content search
- [ ] Batch: delete/update/move
- [ ] Permission checks (table/page, summary)
- [ ] Backend user/group inspect (admin-only, no `password`/`mfa`)
- [ ] Cache flush tool (all/pages/groups)
- [ ] Conditional: redirects (when `cms-redirects`), scheduler (when `cms-scheduler`)
- [ ] Translation tool with language-overlay handling
- [ ] Backend layout resource template

### Custom table support
- [ ] `DynamicToolRegistrar` — runtime CRUD + batch tools for extension tables — port from `ms_mcp_server`
- [ ] `ExtensionTableDiscoveryService` — TCA scan, label/prefix gen, system-table filter
- [ ] Backend module: discover → enable/disable → edit label/prefix
- [ ] Per-table allow/deny configuration via TSconfig
- [ ] `tx_nst3af_discovered_table` storage

### Editor-facing features
- [ ] Existing usage statistics pipeline (OpenAI usage retrieval, normalization, charts, cache) — keep
- [ ] AI wizards in TCA (alt text, meta description, translation, summarization)
- [ ] Backend module landing dashboard

### Quality
- [ ] PHPStan level 10 with strict rules — port config from `nr_llm`
- [ ] Architecture tests (phpat) enforcing layered boundaries — port from `nr_llm`
- [ ] Centralized error handling proxy for MCP tools — port from `ms_mcp_server`
- [ ] Mutation testing (Infection) target MSI ≥ 70%
- [ ] Playwright E2E + LLM-against-real-instance tests — port from `hn/typo3_mcp_server`

## Architecture (target)

```
ns_t3af/
├── Classes/
│   ├── Attribute/                  # #[AsAiProvider], #[AsAiMiddleware], #[McpTool]
│   ├── Capability/                 # *CapableInterface
│   ├── Client/                     # Provider clients (existing) — to be folded into Provider/
│   ├── Provider/                   # Provider adapters + Contract/ + Symfony AI bridge
│   ├── DependencyInjection/        # Compiler passes (Provider, Middleware, McpTool)
│   ├── Middleware/                 # AI request middleware pipeline
│   ├── Mcp/
│   │   ├── Server/                 # Server factory, transports
│   │   ├── OAuth/                  # AuthorizationService, PKCE, ClientRepository, RateLimit
│   │   ├── Tool/                   # Tool/<Category>/* (Pages, Content, File, Schema, Search, Batch, Permission, Cache, Dynamic, …)
│   ├── Service/
│   │   ├── Feature/                # Feature/Completion, Feature/Translation, Feature/Embedding, …
│   │   ├── AiRequestService.php    # existing
│   │   ├── AiLogService.php        # existing
│   │   └── AiStatisticsService.php # existing
│   ├── Domain/                     # Entities, repos, DTOs, value objects, enums
│   ├── Form/                       # TCA form elements, wizards
│   ├── Controller/Backend/         # Backend modules (Providers, Request Log, OAuth Clients, Discovered Tables)
│   ├── Widgets/DataProvider/       # Dashboard widgets
│   ├── Logging/                    # AuditLogger
│   ├── Helper/                     # existing
│   └── Utility/                    # existing
├── Configuration/
│   ├── Backend/Modules.php
│   ├── Services.yaml               # DI + tags: ai.provider, ai.middleware, mcp.tool, mcp.prompt
│   ├── RequestMiddlewares.php      # OAuth + MCP HTTP middleware
│   ├── TCA/                        # tx_nst3af_* tables
│   ├── Caching.php                 # nst3af_responses (no hardcoded backend)
│   └── SmartRouting/ComplexitySignals.php
├── Resources/
│   ├── Private/Language/locallang*.xlf  # EN + DE minimum
│   ├── Public/Icons/provider-*.svg
│   └── Private/Templates/...
├── Tests/
│   ├── Unit/
│   ├── Functional/
│   ├── Architecture/               # phpat layered-boundary tests
│   └── E2E/                        # Playwright + LLM-real
├── Documentation/
│   ├── Adr/                        # Architecture Decision Records
│   ├── Credits.rst                 # Attribution for ported code
│   └── ...
└── ext_*.php / composer.json
```

## Tables (target)

| Table | Purpose |
|---|---|
| `tx_nst3af_configuration` | Provider configurations (TCA) |
| `tx_nst3af_request_log` | Per-request log (no TCA) |
| `tx_nst3af_usage_budget` | Per-user rolling budget counters |
| `tx_nst3af_oauth_client` | MCP OAuth clients (TCA) |
| `tx_nst3af_oauth_token` | Issued access/refresh tokens |
| `tx_nst3af_discovered_table` | Extension tables exposed to MCP |

## Code conventions (forward-looking)

- `declare(strict_types=1);` in all PHP files (existing files to be migrated)
- PSR-12 + TYPO3 conventions via PHP-CS-Fixer
- All properties typed, all methods have return types
- Conventional commits: `feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert|security(scope)?: message`
- Namespace: `NITSAN\NsT3AF\…` (existing, keep)
- API keys never plaintext in TCA / yaml / env — vault UUID indirection (port from `nr_llm`)
- MCP tool descriptions use `#[McpTool]` attributes — auto-discovered via DI tags

## Ground rules for porting

1. Read the source extension's `LICENSE` first; refuse non-GPL-compatible imports.
2. Adapt namespaces to `NITSAN\NsT3AF\…` and table prefix to `tx_nst3af_`.
3. Keep upstream class/method names where it helps maintenance, rename only where TYPO3 idiom requires.
5. When two source extensions solve the same thing differently, document the choice in `Documentation/Adr/` with rationale.
6. Tests come with the port — never import logic without its tests.

## Compatibility

- Continue supporting TYPO3 11 / 12 / 13 + PHP 7.4–8.4 for v1.x line.
- Master-extension feature work targets a new major (v2.x): TYPO3 13.4 / 14, PHP 8.2+ — bump cleanly, don't backport heavy features to v1.x.

## Commands (current)

From `packages/ns_t3af/` (own `.Build/vendor/` after `composer install`):

```bash
composer test
composer test:functional
composer stan
composer cs:check          # dry-run; may be non-blocking in CI
composer doc-watch        # optional documentation watcher
```

Makefile `docs` target may still exist for legacy doc builds — prefer `composer doc-watch` when available.

## AI Permissions (2026-06)

Implemented in `ns_t3af` — route `t3af_dashboard.ai_access_roles` (admin configure). **Canonical spec:** `context/features/ai-access-roles.md`.

| Area | Key files |
|---|---|
| Provider contract + registry | `Contract/AiAccessCatalogProviderInterface.php`, `Registry/AiAccessCatalogProviderRegistry.php`, `Registry/FeatureAccessBindingRegistry.php` |
| Merged catalogs + wizard defaults | `Access/ModuleAccessCatalog.php`, `FeaturePermissionCatalog.php`, `RecordPermissionCatalog.php`, `WizardBootstrapFactory.php`, `MatrixScopeCatalog.php` |
| Native T3Ai:* options | `Bootstrap/AiAccessCustomOptionsBootstrap.php` |
| Wizard + matrix UI | `Resources/Public/JavaScript/access-roles.js`, `Resources/Public/Css/module/access-roles.css` |
| Apply / preview AJAX | `AccessRolesAjaxController.php` (JSON body parser) |
| Merge-safe apply | `Access/BeGroupPermissionMerger.php`, `GroupConfigNormalizer.php` |
| Permission checks (v13 bool) | `Access/BackendPermissionCheck.php`, `RecordAccessGate.php`, `FeaturePermissionGate.php` |
| Provider UI enforcement | `ProviderController.php`, `Templates/Provider/List.html` |
| Dashboard gating (editors) | `ModuleController.php`, `Templates/Module/Dashboard.html` |
| T3CS tab gating | `ns_t3cs/DataProvider/Tabs.php`, `T3CsBackendController.php` |
| Tab gating (AI Foundation) | `Access/ModuleTabAccessService.php` |
| Group limits on AI calls | `Governance/GroupLimitsListener.php` |

Recent fixes (2026-06): Permission Matrix detailed headers + green SVG checkmarks; apply/preview JSON payload parsing; `check()` bool compatibility; editor dashboard/T3CS/provider enforcement.

Provider-driven registration (2026-07): child extensions ship `*AccessCatalogProvider` classes; see `Documentation/Developer/CustomAiAccess.rst`.

## Next

Refer to `context/features/ai-access-roles.md` for wizard/matrix/enforcement details. Use `context/session-state.md` + feature context when extending ACL or child-extension gates.
