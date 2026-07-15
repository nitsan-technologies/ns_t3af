> **Agent entry:** `context/features/credits.md`

# Feature — T3Planet Credits (Client side, inside `ns_t3af`)

Status: **Implemented (client v1)**
Last updated: **2026-05-18 (b)** — `Token.php` sends comma-separated `license_keys` from setup; token from `ns_ai_token` (not on license row).
Owner: ns_t3af maintainers
Target version: ns_t3af v2.x
Pair: [`FEATURE_T3PlanetCredits_Server.md`](FEATURE_T3PlanetCredits_Server.md) — server-side spec on `composer.t3planet.cloud`.

All credit-client code ships **inside ns_t3af** (no separate ext). Public OSS-safe — no private secrets baked in.

---

## Revision 2026-05-15 (post-leadership) — token-based auth, shared AI-ext pool

Mirrors server FEATURE Revision 2026-05-15. Overrides HMAC/signing-secret design.

**New auth model:**
- Single opaque `token` per install pool, stored server-side in **`ns_ai_token`** (not on `ns_product_license`).
- Every AI call: `Authorization: Bearer <token>` over TLS. No HMAC.
- Request body: `domain` + `request_uuid` on charge/stream endpoints.

**Active license keys (setup):** Comma-separated list of all **valid AI extension** license keys for this install (from `LicenseKeyResolver::collectActiveLicenseKeys()` — typically every non-expired row for `ns_t3af`, `ns_t3ai`, `ns_t3cs`, `ai_suite`, … configured in credits setup). Persisted on `tx_nst3af_runtime_setting.license_keys` (plain text list; not secret).

**Token resolution (dashboard load + before any AI call):**

```
TokenResolver::resolve(): string
  1. $token = RuntimeSettingsService::getToken();     // token_enc decrypted
     if ($token) return $token;

  2. $keys = RuntimeSettingsService::getLicenseKeys(); // comma-separated from setup
     if ($keys === '') throw LicenseUnavailableException;

  3. POST {base}/API/AI/Token.php {
       license_keys: $keys,    // e.g. "LIC-UNIV-1,LIC-T3AI-2"
       domain: <HTTP_HOST or site primary>
     }
     → { token }
     RuntimeSettingsService::saveToken($token);
     return $token;
```

Encrypted at rest: `tx_nst3af_runtime_setting.token_enc` via `CredentialCipher`.

**Credit pool shared across all AI child extensions:** One Bearer token maps to one `ns_ai_account` row on the server. Child extensions only call `AiServiceInterface`; decorator always uses the same resolved token. Server row `ns_ai_token.license_keys` lists every license key in the pool.

**No `token` on `LicenseContext`** — license rows from `ns_license` are used only for picker UI and building the `license_keys` string. No ns_license schema change for tokens.

**Drop (vs original plan):**

- `Classes/Credits/Security/EnvelopeSigner.php`
- `Classes/Credits/Security/NonceGenerator.php`
- `Classes/Credits/Service/SecretRotationService.php`
- `Classes/Credits/Service/InstallBootstrapService.php` (no more `/Register.php`)
- `Classes/Credits/Security/T3PlanetPublicKey.php` (sealed-box deferred indefinitely; HMAC was its only consumer for v1)
- `signing_secret_enc`, `pending_signing_secret_enc` columns on runtime settings
- First-boot Register modal step

**Add (vs original plan):**

- `Classes/Credits/Service/TokenResolver.php` (3-tier lookup above)
- `Classes/Credits/Http/T3PlanetApiClient::issueToken(string $licenseKeys, string $domain): string`
- `tx_nst3af_runtime_setting.token_enc` + `license_keys VARCHAR(1024)` (comma-separated active keys)

**Simplified first-boot flow (overrides §4.2):**

1. Admin opens dashboard, flips toggle ON.
2. Modal: configure credits — pick **primary** license for display (drop-down via `LicenseKeyResolver::listAvailable()`).
3. On Activate: `LicenseKeyResolver::buildLicenseKeysCsv()` → comma-separated valid AI keys; save to `runtime_setting.license_keys`.
4. `TokenResolver::resolve()` → `POST /API/AI/Token.php` with `{ license_keys, domain }`.
5. Cache `token_enc`, set `credit_mode=1`, toast from `/AI/Balance.php`.

**Errors retired:** `credits.signature_invalid`, `credits.signature_expired`. **Errors added:** `credits.token_missing`, `credits.token_invalid`.

**Token re-fetch on `token_invalid` 401:**
1. Clear `runtime_setting.token_enc`.
2. Re-run `TokenResolver::resolve()` (skips cache → `/API/AI/Token.php` with stored `license_keys`).
3. Retry the original call once. Second 401 → surface error, banner: "Token rejected — verify license or contact support."

**Security trade-off (document in `Documentation/Privacy.rst`):** stolen token = bearer access until revoked in `ns_ai_token` (admin TYPO3 ext). Domain must match at least one linked license. TLS-only on the wire.

---

## Revision 2026-05-18 (b) — aligns with server FEATURE

- Tokens live in **`ns_ai_token`** only — **no** `ns_product_license.token`, **no** ns_license schema change for tokens.
- Client persists **`license_keys`** (comma-separated) on runtime settings; `Token.php` receives that string.
- Server matches if **any** requested key appears in the row's `license_keys` column; returns one Bearer token for the shared pool.
- Admin TYPO3 extension is on **`packages/<server-ai-ext>/`**, not on `composer.t3planet.cloud`.

---

## 0. Goals

1. Toggle inside ns_t3af dashboard (already stubbed as "Coming soon" — wire it up).
2. When toggle ON: all AI traffic routes through `composer.t3planet.cloud/API/AI/*` using the customer's existing **ns_license license_key** as identity. No second registration form.
3. When toggle OFF: local adapters work unchanged. Zero proxy traffic.
4. One HTTP round-trip per AI op. HTTP/2 keep-alive, gzip, `Authorization: Bearer <token>`.
5. SSE streaming pass-through. Local read-only receipt cache. Balance + catalog caches with ETag.
6. Per-install **comma-separated `license_keys`** sent to `Token.php` — shared pool across all AI child extensions on the install.

---

## 1. Scope

| In | Out |
|---|---|
| Toggle UI + DB-backed runtime setting | Server endpoints, ledger, atomic debit — see server file |
| License picker (drop-down of valid `ns_product_license` rows) | Pabbly webhook handler |
| HTTP client + Bearer token (`TokenResolver`) | Editable catalog UI inside customer install |
| `AiServiceInterface` decorator | Token-by-token rewriting in TYPO3 |
| DTO additions: `featureKey`, `CreditsUsage`, `StreamSummary` | Hybrid BYO + credits per request (v1.1) |
| Public events fire on proxy path (telemetry intact) | Authoritative ledger (server only) |
| Catalog + balance caches + receipt mirror (read-only) | Self-hosted T3Planet API |
| Product catalog (Buy Credits page) — read-only mirror of server `ns_ai_product` | Editing products inside customer install (admin-only on server) |
| Current plan card + purchase history mirror | Refunds / cancellations (T3Planet billing portal handles) |
| First-boot via `TokenResolver` (+ optional `/AI/Token.php`) | Multi-currency |
| Bridge to ns_license (read license rows) | Modifying ns_license itself |

---

## 2. Architecture

```text
Customer TYPO3 (only ns_t3af + ns_license)
  ┌───────────────────────────────────────────────────────────────┐
  │ Caller → AiServiceInterface (decorator)                       │
  │   ├─ credit_mode ON  → T3PlanetCreditAiService                │
  │   │                       → TokenResolver (cached token)      │
  │   │                       → T3PlanetHttpClient (HTTP/2)       │
  │   │                       → composer.t3planet.cloud/API/AI/*  │
  │   │                                                           │
  │   └─ credit_mode OFF → $inner AiService (local adapters)      │
  │                                                               │
  │ LicenseKeyResolver ←─ reads ns_product_license (ns_license)   │
  │ Dashboard → BalanceService / CatalogService / ReceiptCache    │
  │ Toolbar widget → live balance pill                            │
  └───────────────────────────────────────────────────────────────┘
                                       │ HTTPS Bearer + JSON body
                                       ▼
                              composer.t3planet.cloud
                              (see server FEATURE file)
```

Decorator is **always wired** via Symfony DI `decorates: AiServiceInterface`; runtime resolver short-circuits to `$inner` when toggle OFF (no HTTP traffic).

---

## 3. ns_license bridge

### 3.1 LicenseKeyResolver

Single bridge service — only point in this feature that touches ns_license code.

```php
namespace NITSAN\NsT3AF\Credits\Service;

final class LicenseKeyResolver
{
    public function __construct(
        private readonly NsLicenseRepository $licenseRepo,    // from ns_license
        private readonly RuntimeSettingsService $settings,
    ) {}

    /**
     * Returns the license context selected by admin for AI credit auth.
     */
    public function resolve(): ?LicenseContext
    {
        $selectedExtKey = $this->settings->getSelectedLicenseExtensionKey()
            ?: 'ns_t3af';

        $rows = $this->licenseRepo->fetchData($selectedExtKey);
        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];

        // ns_license convention: EXPIRED_* / *_EXPIRED order_id marks invalid
        if (str_contains((string) $row['order_id'], 'EXPIRED')) {
            return null;
        }

        $isLifetime = (string) $row['is_life_time'] === '1';
        if (!$isLifetime && (int) $row['expiration_date'] <= time()) {
            return null;
        }

        return new LicenseContext(
            licenseKey:   $row['license_key'],
            extensionKey: $row['extension_key'],
            orderId:      $row['order_id'],
            expiresAt:    (int) $row['expiration_date'],
            isLifetime:   $isLifetime,
            domains:      array_filter(array_map('trim', explode(',', $row['domains'] ?? ''))),
        );
    }

    /**
     * List all valid license rows for the UI picker.
     * @return list<LicenseContext>
     */
    public function listAvailable(): array { /* iterates ns_product_license */ }
}
```

`LicenseContext` = readonly DTO. No dependency from `ns_t3af` composer.json on `ns_license` (loose — class_exists guard in service factory; if ns_license absent, credit mode disabled with UI hint "Install ns_license to use T3Planet Credits").

### 3.2 Settings on the runtime row

| Column | Purpose |
|---|---|
| `credit_mode TINYINT(1)` | toggle OFF/ON |
| `selected_license_ext_key VARCHAR(64)` | which `ns_product_license.extension_key` to authenticate as |
| `t3planet_api_base_url VARCHAR(255)` | default `https://composer.t3planet.cloud/API` |
| `license_keys VARCHAR(1024)` | Comma-separated active `license_key` values for this install (sent to `/AI/Token.php`) |
| `token_enc VARCHAR(512)` | Bearer token encrypted via `CredentialCipher` (from `/AI/Token.php`) |
| `activated_at INT` | first time credits mode enabled |
| `last_balance_synced INT` | balance cache TTL marker |

---

## 4. Toggle

### 4.1 Storage

- Table `tx_nst3af_runtime_setting`, singleton row uid=1.
- Read via `RuntimeSettingsService::isCreditModeOn()`. Cache `nst3af_runtime` (5 min, invalidated on save).

### 4.2 First-boot flow (toggle ON for first time)

1. Admin opens dashboard, flips toggle ON.
2. Modal: configure credits — primary license for display (drop-down from `LicenseKeyResolver::listAvailable()`).
3. Admin clicks Activate:
   - `license_keys` = `LicenseKeyResolver::buildLicenseKeysCsv()` (all valid AI extension keys on this install, comma-separated).
   - Save `license_keys` on runtime row.
   - `TokenResolver::resolve()` → cache `token_enc`.
4. Set `credit_mode=1`, `activated_at=now`.
5. `POST /API/AI/Balance.php` → toast: "Credits mode active. Balance: …"

### 4.3 UI when ON

- Provider list rendered read-only with banner: "T3Planet Credits mode active — local providers preserved, not used. Toggle off to revert to BYO keys."
- Edit / Delete / Test / Set-default / New disabled.
- Toolbar widget shows pool snapshot: `🆓 12 · 💳 480 · 📅 235/5000 (pro)`.

### 4.4 Proxy unreachable

- **Hard error.** No silent fallback to local adapters.
- Toast: "T3Planet API unreachable — credits mode is ON. Toggle off to use local providers."
- `ProviderRequestFailedEvent` with `reason: 'credits.proxy_unreachable'`.

### 4.5 License expiration during operation

- Server returns `license_expired` → client shows banner + auto-toggle to OFF only if admin confirms. Otherwise stays ON and every call errors (force the admin to address it).

---

## 5. Public API contract

### 5.1 `AiOptions::$featureKey`

`AiOptions` already exposes optional `featureKey` for telemetry. When `credit_mode=ON`, callers **must** pass a stable key aligned with server `ns_ai_feature_cost.feature_key` (snake_case), e.g. `seo_meta_description`, `image_generation`.

When `credit_mode=ON` AND `featureKey` is null/empty → log a warning. Server returns 422 `feature_unknown` if key not in `ns_ai_feature_cost`.

Child extensions (`ns_t3ai`, `ns_t3cs`, `ai_suite`, …) pass `featureKey` on every `AiServiceInterface` call.

### 5.2 `AiResponse` + `EmbeddingResponse` additive fields

```php
final class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly array $raw = [],
        public readonly ?CreditsUsage $credits = null,   // NEW
    ) {}
}

final class CreditsUsage
{
    public function __construct(
        public readonly int $charged,
        public readonly string $bucket,            // 'plan|free|paid|dev'
        public readonly string $featureKey,
        public readonly string $serverRequestId,   // request_uuid
        public readonly int $balanceFree,
        public readonly int $balancePaid,
        public readonly int $planUsed,
        public readonly int $planTotal,
        public readonly string $planName,
        public readonly int $planExpiresAt,
    ) {}
}

final class StreamSummary
{
    public function __construct(
        public readonly string $content,
        public readonly ?CreditsUsage $credits = null,
        public readonly array $raw = [],
    ) {}
}
```

Stream generator returns `StreamSummary` via `$gen->getReturn()`.

### 5.3 DI

```yaml
# Configuration/Services.yaml
services:
    NITSAN\NsT3AF\Credits\Service\T3PlanetCreditAiService:
        decorates: NITSAN\NsT3AF\Api\AiServiceInterface
        arguments:
            $inner: '@.inner'
            $modeResolver: '@NITSAN\NsT3AF\Credits\Service\CreditModeResolver'
            $executor: '@NITSAN\NsT3AF\Credits\Service\ProxyAiExecutor'
            $eventDispatcher: '@Psr\EventDispatcher\EventDispatcherInterface'
            $telemetry: '@NITSAN\NsT3AF\Service\RequestTelemetryService'
```

Decoration always wired; `CreditModeResolver` returns `false` if toggle OFF / no license / `TokenResolver` cannot obtain a token → decorator forwards to `$inner` unchanged.

### 5.4 Events on proxy path

Phase 3 events fire in the same order:
- `BeforeProviderRequestEvent` (cancellable)
- executor → HTTP call
- `AfterProviderResponseEvent` (with `usedCredits`, `bucket`, `balanceAfter`, `featureKey`, `model`, `phaseTimings`)
- Failure → `ProviderRequestFailedEvent` with `reason` in:
  - `credits.insufficient`
  - `credits.proxy_unreachable`
  - `credits.api_error`
  - `credits.token_missing`
  - `credits.token_invalid`
  - `credits.feature_unknown`
  - `credits.license_expired`
  - `credits.license_suspended`
  - `credits.rate_limited`
  - `credits.upstream_ai_error`

Telemetry listener writes `tx_nst3af_request_log` unchanged (existing analytics module shows credits used per provider/feature).

---

## 6. Request flow

### 6.1 Non-streaming

```
Caller invokes AiServiceInterface->complete($prompt, AiOptions(featureKey: 'seo_meta_description'))
  → T3PlanetCreditAiService (decorator)
  → CreditModeResolver: ON?
  → BeforeProviderRequestEvent
  → RequestUuidService: request_uuid = UUIDv4
  → TokenResolver::resolve() → Bearer token
  → T3PlanetHttpClient: POST {base}/AI/Charge.php
       Authorization: Bearer <token>
       body = { domain, request_uuid, feature_key, prompt, model, max_tokens, ... }
       30s read timeout, HTTP/2, gzip, keep-alive
  → 401 token_invalid: clear token_enc, re-resolve once, retry; second 401 → banner + error
  → response 200
       → AfterProviderResponseEvent(credits, balanceAfter, ...)
       → LocalReceiptCache::append(receipt)
       → RuntimeSettingsService::touchLastBalanceSync()
       → AiResponse{content, credits}
  → response 402 insufficient_credits
       → ProviderRequestFailedEvent(reason='credits.insufficient', topup_url)
       → throw InsufficientCreditsException(topupUrl)
  → response 5xx / timeout
       → ProviderRequestFailedEvent(reason='credits.proxy_unreachable')
       → throw ProxyUnreachableException
```

### 6.2 Streaming

SSE pass-through (implemented):
- `T3PlanetHttpClient::stream()` yields raw lines from `text/event-stream` (120s read timeout).
- `T3PlanetSseStreamParser` maps `token` → incremental deltas, `usage` → settlement payload.
- `ProxyAiExecutor::stream()` returns `StreamSummary` via generator `getReturn()` after all chunks.
- Pre-stream errors (402, 409, …) return JSON (same mapping as Charge).
- Caller disconnect / early `break` → `ProxyAiExecutor` `finally` calls `Abort.php` within ≤5s (idempotent).
- **409 `stream_in_progress`:** thrown as `CreditsApiException` — caller must wait for the in-flight stream to finish and retry the **same** `request_uuid`, or start a **new** UUID for a parallel request (no auto-retry in the client).

### 6.3 Idempotent replay (caller-side)

If a previous identical call failed with network error (no response received), caller can retry with same `request_uuid` and same body → server returns cached response (if it completed server-side) OR processes fresh (if it didn't reach server). Either way no double-debit.

---

## 7. Security (client side)

| Control | Spec |
|---|---|
| Debit authority | Server only. Client never computes cost |
| Transport | TLS 1.2+. HSTS expected |
| Auth | `Authorization: Bearer <token>` on every AI call |
| Token at rest | `CredentialCipher::encrypt()` on `token_enc` (sodium, TYPO3 `encryptionKey`, prefix `enc:v1:`) |
| Token refresh | On `token_invalid` 401: clear cache → `TokenResolver` (license row + `/AI/Token.php`) → one retry |
| Replay (retry safety) | Client may reuse same `request_uuid` on network failure; server idempotency on `ns_ai_request` |
| Toggle OFF | Decorator forwards to `$inner`. Zero HTTP traffic. Verified by tcpdump in CI |
| Decorator bypass guard | phpat: no `Classes/Credits/*` imports `Provider\*` adapters |
| GDPR | Full prompt logged server-side (`ns_ai_request.meta_json`). See `Documentation/Privacy.rst` |
| License loss | `LicenseKeyResolver::resolve()` null → disable credit_mode + admin banner |

---

## 8. Performance

- One HTTP call per op. Persistent connection (Guzzle HTTP/2 + multiplexing).
- TYPO3-side overhead target: **≤ 25 ms p95** on top of upstream provider latency (the server adds another ~15 ms before the AI provider call).
- Connection pool singleton `T3PlanetHttpClient`.
- Catalog cache 60 min + ETag/304.
- Features cache 60 min + ETag (cost map).
- Balance cache 60 s, invalidated by every response's `credits.balanceFree/Paid/PlanUsed`.
- Telemetry write async (Messenger bus when available, sync fallback).

---

## 9. UX

### 9.1 Local receipt cache

- Table `tx_nst3af_credit_receipt`:
  - `request_uuid VARCHAR(64) UNIQUE`
  - `feature_key VARCHAR(64)`
  - `model VARCHAR(96)`
  - `bucket VARCHAR(16)`
  - `cost INT`
  - `balance_free INT`, `balance_paid INT`, `plan_used INT`, `plan_total INT`
  - `crdate INT`
  - `extra MEDIUMTEXT` (JSON: timings, status_code)
- Last 50 in dashboard. Pruned to 1000 most recent via Scheduler task.
- Read-only mirror. Server ledger authoritative.

### 9.2 Balance widget (top-right toolbar)

- Three pill badges: `🆓 12 · 💳 480 · 📅 235/5000 pro`.
- `GET /API/AI/Balance.php` on module landing (60s cache).
- Every AI response updates inline via JS event from `T3PlanetCreditAiService`.
- After Pabbly purchase: email confirms; next module render refreshes.
- Click → drawer with receipt log (last 50) + top-up button (Pabbly URL).

### 9.3 Catalog + features

- `GET /API/AI/Features.php` on demand. ETag + `If-None-Match`. Rendered in admin module under "Pricing".
- Manual refresh button sends `Cache-Control: no-cache`. Server rate-limits 1/min/install → 429.

### 9.5 Buy Credits page (NEW)

Backend module sub-route `aiuniverse_credits → buy`. Renders product catalog fetched from `POST /API/AI/Products.php` (server source = `ns_ai_product` table — see server FEATURE §3/§4.2.1).

Layout:
- **Plans section** — cards for `type='plan'` rows, sorted by `sort_order`. Each card: title, `badge` chip (`popular`/`best_value`), monthly credits, price/period, feature bullets from `features_json`. CTA button "Subscribe" → `window.open(checkout_url_substituted, '_blank')`.
- **Top-ups section** — cards for `type='topup'` rows. CTA "Buy".
- Current plan dimmed + labelled "Current plan" via `current_plan_sku` from response.
- After click → toast "Complete purchase in the new tab. Balance updates automatically once Pabbly confirms." Balance widget polls `/AI/Balance.php` every 15s for the next 5 min.

`checkout_url` placeholder substitution (client-side):
```
{license_key} → LicenseContext.licenseKey
{domain}      → site primary domain
{return}      → urlencode(BackendUtility::getModuleUrl('aiuniverse_credits.buy'))
```

Cache: `ProductCatalogService` stores `/AI/Products.php` response in `tx_nst3af_product_catalog` (single-row JSON blob + etag + fetched_at). TTL 1h. Manual refresh button → `If-None-Match` revalidation.

### 9.6 Current plan card (NEW)

On dashboard landing + top of Buy Credits page:
- Renders snapshot from `POST /API/AI/CurrentPlan.php` (60s ETag cache).
- Card content: `plan_name`, big `plan_credits_used / plan_credits_total` progress bar, "Renews on <plan_renewed_at>+30d" line, `free`+`paid` secondary badges, "Upgrade" link → Buy Credits page.
- Falls back to "No active plan — start with Free Trial" CTA when `plan_name='none'`.

### 9.7 Purchase history (NEW)

Backend module sub-route `aiuniverse_credits → history`. Calls `POST /API/AI/PurchaseHistory.php` (paginated, no client-side cache — always fresh).

Table columns: Date (`applied_at`), Product (`title`), Type (plan/topup/trial chip), Credits granted, Amount, Currency, Event ID (monospace, truncated).
Pagination 20/page. No mutation actions — read-only mirror of server `ns_ai_transaction` (purchase types) for the authenticated license.

### 9.8 Feature cost page (read-only)

Backend module sub-route `aiuniverse_credits → pricing`. Renders `POST /API/AI/Features.php` cost map (`feature_key → cost`). User-facing reference so editors know "1 SEO meta = 5 credits, 1 image = 50". No edit affordance — admin edits happen server-side only.

### 9.4 License picker

- Settings modal under credit_mode toggle. Lists licenses returned by `LicenseKeyResolver::listAvailable()`. Each row shows: `extension_key · order_id · expires <date> · is_lifetime`.
- Default selection: row where `extension_key='ns_t3af'`. Fallback: first valid row.

---

## 10. Files

### Add

**Bridge to ns_license**
- `Classes/Credits/Domain/Model/LicenseContext.php` (readonly DTO)
- `Classes/Credits/Service/LicenseKeyResolver.php`

**Runtime settings**
- `Classes/Domain/Model/RuntimeSetting.php`
- `Classes/Domain/Repository/RuntimeSettingsRepository.php`
- `Classes/Service/RuntimeSettingsService.php`
- `Configuration/TCA/tx_nst3af_runtime_setting.php`
- `Classes/Updates/AddRuntimeSettingRowUpdate.php`

**Public API additions**
- `Classes/Api/CreditsUsage.php`
- `Classes/Api/StreamSummary.php`

**HTTP layer**
- `Classes/Credits/Security/RequestUuidService.php` (UUIDv4)
- `Classes/Credits/Http/T3PlanetHttpClient.php` (HTTP/2, keep-alive, gzip, streaming, Bearer header)
- `Classes/Credits/Http/T3PlanetApiClient.php` (token, charge, stream, balance, features, products, abort, …)

**Services**
- `Classes/Credits/Service/TokenResolver.php` (3-tier token lookup)
- `Classes/Credits/Service/CreditModeResolver.php`
- `Classes/Credits/Service/CreditClientService.php`
- `Classes/Credits/Service/ProxyAiExecutor.php`
- `Classes/Credits/Service/T3PlanetCreditAiService.php` (decorator)
- `Classes/Credits/Service/BalanceService.php`
- `Classes/Credits/Service/CurrentPlanService.php` (wraps `/AI/CurrentPlan.php`, 60s ETag)
- `Classes/Credits/Service/FeatureCatalogService.php`
- `Classes/Credits/Service/ProductCatalogService.php` (wraps `/AI/Products.php`, 1h ETag, placeholder substitution in `checkout_url`)
- `Classes/Credits/Service/PurchaseHistoryService.php` (wraps `/AI/PurchaseHistory.php`, paginated, no cache)
- `Classes/Credits/Domain/Model/Product.php` (readonly DTO)
- `Classes/Credits/Domain/Model/PurchaseEvent.php` (readonly DTO)
- `Classes/Credits/Domain/Repository/ProductCatalogCacheRepository.php`
- `Classes/Credits/Service/LocalReceiptCache.php`

**Exceptions**
- `Classes/Credits/Exception/InsufficientCreditsException.php`
- `Classes/Credits/Exception/ProxyUnreachableException.php`
- `Classes/Credits/Exception/CreditsApiException.php` (carries server error_code)
- `Classes/Credits/Exception/LicenseUnavailableException.php`

**Event listener**
- `Classes/Credits/EventListener/TelemetryBridgeListener.php`

**Backend**
- `Classes/Controller/Backend/CreditModeController.php` (toggle + license picker AJAX)
- `Classes/Controller/Backend/CreditsModuleController.php` (sub-routes: `dashboard`, `buy`, `history`, `pricing`)
- `Classes/Backend/ToolbarItems/CreditBalanceToolbarItem.php`
- `Resources/Public/JavaScript/credit-mode-toggle.js`
- `Resources/Public/JavaScript/buy-credits.js` (renders cards from `/AI/Products.php`, opens checkout in new tab, post-checkout balance polling)
- `Resources/Private/Partials/Credit/Toolbar.html`
- `Resources/Private/Partials/Credit/Drawer.html` (receipt log + top-up)
- `Resources/Private/Templates/Credits/BuyCredits.html` (plans + top-ups grid)
- `Resources/Private/Templates/Credits/CurrentPlan.html` (current plan card — included in Dashboard.html and BuyCredits.html)
- `Resources/Private/Templates/Credits/PurchaseHistory.html`
- `Resources/Private/Templates/Credits/Pricing.html` (feature cost reference)

**Schema**
- `ext_tables.sql` — `tx_nst3af_runtime_setting`, `tx_nst3af_credit_receipt`, `tx_nst3af_product_catalog` (single-row cache for `/AI/Products.php` response: `etag`, `body_json MEDIUMTEXT`, `fetched_at`)

**Docs**
- `Documentation/Adr/0001-t3planet-credits-proxy.md`
- `Documentation/Privacy.rst` (full-prompt-logged disclosure)
- `Documentation/Credits.rst` — bump (port notes)

**Tests**
- `Tests/Unit/Service/RuntimeSettingsServiceTest.php`
- `Tests/Unit/Credits/Service/LicenseKeyResolverTest.php`
- `Tests/Unit/Credits/Service/CreditModeResolverTest.php`
- `Tests/Unit/Credits/Service/TokenResolverTest.php`
- `Tests/Unit/Credits/Service/ProxyAiExecutorTest.php`
- `Tests/Functional/Credits/Service/T3PlanetCreditAiServiceTest.php`
- `Tests/Architecture/NoAdapterBypassFromCreditsTest.php` (phpat)

### Modify

- `Classes/Api/AiOptions.php` — enforce/document `featureKey` when credit_mode ON (field may already exist)
- `Classes/Api/AiResponse.php` — add `?CreditsUsage $credits`
- `Classes/Api/EmbeddingResponse.php` — same
- `Classes/Controller/Backend/ProviderController.php` — read-only banner when credit_mode ON
- `Resources/Private/Templates/Module/Dashboard.html` — balance widget, catalog grid, recent receipts
- `Resources/Private/Partials/Provider/ModeToggle.html` — wire to `RuntimeSettingsService`, license picker
- `Configuration/Services.yaml` — `decorates: AiServiceInterface`; loose binding to `NsLicenseRepository` (class_exists guard via factory)
- `composer.json` — `suggest: { "nitsan/ns-license": "Required for T3Planet Credits mode" }` (NOT `require`)
- `ext_emconf.php` — `suggests`
- `Documentation/Index.rst`, `README.md`

### Do NOT modify

- ns_license itself — read-only consumption only via `NsLicenseRepository::fetchData()`.
- Existing `Provider/*` adapters — credit path doesn't touch them; decorator handles top-level routing.

---

## 11. Verification

1. Toggle OFF → AI uses local adapter. Zero T3Planet traffic (tcpdump in CI).
2. First-boot: toggle ON, ns_license absent → modal blocked with "Install ns_license to enable credits mode".
3. First-boot: ns_license present, valid license → picker → Activate → `TokenResolver` caches token → toast confirms balance from `/AI/Balance.php`.
4. Toggle ON, sufficient balance → `AiResponse->credits` populated; receipt row written; telemetry row written.
5. Toggle ON, balance zero → `ProviderRequestFailedEvent(reason='credits.insufficient')` + `InsufficientCreditsException` carrying topup_url.
6. Toggle ON, proxy down → hard error, no fallback, banner toast, no debit (server never reached).
7. Concurrent calls (10 parallel, balance 5) → exactly 5 succeed (server-side serialized).
8. Stream + Ctrl-C → Abort.php called within 5s; final usage frame in client log.
9. Replay (same `request_uuid`, same body) → identical response, no extra debit (server cache hit).
10. License deleted from ns_license while ON → next call → `LicenseUnavailableException`; admin banner; auto-disable proposed.
11. License expires → server returns `license_expired` → banner; admin must renew on T3Planet.
12. `token_invalid` 401 → clear `token_enc`, re-resolve, single retry succeeds.
13. Multi-license customer: `license_keys` lists all AI keys; adding a license updates CSV and re-runs `TokenResolver` / `Token.php`.
14. PHPStan + phpat clean. Architecture test fails if `Credits\` imports `Provider\*` adapters.
15. SLO: client overhead p95 ≤ 25 ms (excluding upstream).
16. No private secret leaks in OSS bundle: `grep -R "PRIVATE_KEY\|SHARED_SECRET" Classes/` returns zero hits.
17. Buy Credits page renders ≥1 plan card + ≥1 top-up card from `/AI/Products.php` response. Disabling a product server-side (`ns_ai_product.is_active=0`) → card disappears after cache TTL or manual refresh.
18. Clicking "Subscribe" opens `checkout_url` in new tab with `{license_key}` substituted (verify via JS unit test on URL builder).
19. After simulated Pabbly webhook applies a top-up, `/AI/Balance.php` poll within 5min reflects new credits; balance widget updates without page reload.
20. Current plan card shows accurate `plan_credits_used/total` progress bar matching `/AI/CurrentPlan.php` response.
21. Purchase history shows only this license's events; wrong Bearer token → `token_invalid`.
22. Feature cost page renders ≥1 feature row; values match `/AI/Features.php` response.

---

## 12. Open / deferred

| Item | Resolution |
|---|---|
| Server-proxy vs client-direct | Server-proxy (user answer #1) |
| Trial/plan prices | Use server defaults for now (user answer #2) — tune later via Pabbly SKU map |
| Per-feature cost model | Flat per-feature credits (user answer #3) — server `ns_ai_feature_cost` table |
| Multi-product pool sharing | Client sends all active keys in `license_keys`; server `ns_ai_token` row |
| Auto-refund on upstream AI failure | Yes, server-side (user answer #5) — client sees credit balance unchanged on 502 |
| `license_key` UNIQUE migration on server | Done as part of Tier 0 (user answer #6) |
| Bearer token rotation | Admin regenerates on server; client re-fetches via `TokenResolver` / `/AI/Token.php` |
| Full prompt logged server-side | Yes (`ns_ai_request.meta_json`). Privacy doc lists this. Opt-out → v1.1 |
| Sealed-box / encrypted prompts | v1.1 |
| Hybrid BYO + credits per request | Post-GA |
| Per-user budgets within an install | Feature 5 — Governance |
| Self-hosted T3Planet API for enterprise | Not v1; allowed later |

---

## 13. Implementation phases

```
Phase 1 — Schema + DTOs
  □ tx_nst3af_runtime_setting (license_keys + token_enc) + wizard
  □ tx_nst3af_credit_receipt, tx_nst3af_product_catalog
  □ AiResponse::$credits, EmbeddingResponse::$credits
  □ CreditsUsage, StreamSummary, LicenseContext
  □ LicenseKeyResolver::buildLicenseKeysCsv()

Phase 2 — ns_license bridge
  □ LicenseKeyResolver (class_exists guard)
  □ TokenResolver + unit tests
  □ Privacy.rst

Phase 3 — HTTP client
  □ T3PlanetHttpClient (Bearer, HTTP/2, streaming)
  □ T3PlanetApiClient (token, charge, stream, balance, …)
  □ RequestUuidService

Phase 4 — Decorator + executor
  □ CreditModeResolver, ProxyAiExecutor, T3PlanetCreditAiService
  □ DI decorates AiServiceInterface
  □ Events + TelemetryBridgeListener

Phase 5 — UX
  □ Toggle + license picker + TokenResolver on Activate
  □ Toolbar balance, receipts, Buy Credits, Current plan, History, Pricing

Phase 6 — Streaming + abort
  ☑ Stream pass-through (`Stream.php` SSE), Abort on disconnect

Phase 7 — Hardening + tests
  □ phpat NoAdapterBypassFromCredits
  □ Functional tests (mock API)
  □ tcpdump: zero traffic when OFF
```

---

## 14. Attribution

- `Classes/Credits/Service/LocalReceiptCache.php` UI patterns mirror `vendor/autodudes/ai-suite/Classes/Backend/ToolbarItems/RequestsToolbarItem.php` — credit in `Documentation/Credits.rst`.
- `featureKey` constants seeded from `packages/ai-suite/Classes/Enumeration/CreditCostEnumeration.php` keys.
- Bearer token + simplified server schema documented in server FEATURE §3 (2026-05-18) — keep both files in sync.

---

## 15. Server rollout alignment (v1 client)

**Ground truth:** `FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md` and `T3Planet-AI-Credits.postman_collection.json`.

### Implemented client endpoints

| Client method | Server endpoint | Notes |
|---|---|---|
| `issueToken()` | `Token` | No Bearer; body `license_keys` (comma-separated string) + `domain` |
| `balance()` | `Balance` | Bearer + `domain` |
| `currentPlan()` | `CurrentPlan` | Optional `If-None-Match` |
| `features()` | `Features` | Pricing table |
| `products()` | `Products` | Buy Credits grid (informational until Pabbly checkout) |
| `estimate()` | `Estimate` | Pre-flight cost |
| `charge()` | `Charge` | `request_uuid`, `feature_key`, `meta_json` |
| `embed()` | `Embed` | Default `feature_key` = `embedding` |
| `abort()` | `Abort` | Cancel in-flight stream / proportional settle |
| `stream()` (via `T3PlanetHttpClient`) | `Stream` | SSE chat proxy; body same as Charge |

### Deferred (v1)

- `PurchaseHistory.php` — purchase history UI deferred.

### Configuration

- Default API base: `https://composer.t3planet.cloud` (`t3planet_api_base_url` on runtime row or extension setting `t3planetApiBaseUrl`).
- Local DDEV: `https://composer.ddev.site` (see Postman collection).

### Phase adjustments (§13)

- Phase 6 includes **Stream.php** SSE pass-through and **Abort.php** on disconnect.
- `LicenseKeyResolver::buildLicenseKeysCommaSeparated()` — comma-separated string, not a CSV file upload.
- `CreditsDomainResolver` resolves hostname for every API body `domain` field.
- `PurchaseHistoryService` deferred.
