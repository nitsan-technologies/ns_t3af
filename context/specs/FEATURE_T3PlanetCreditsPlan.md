> **Agent entry:** `context/features/credits.md` (superseded — see split specs below)

# Feature — T3Planet Credits & Proxy

Status: **SUPERSEDED 2026-05-13** — split into:
- [`FEATURE_T3PlanetCredits_Client.md`](FEATURE_T3PlanetCredits_Client.md) — customer side (ns_t3af)
- [`FEATURE_T3PlanetCredits_Server.md`](FEATURE_T3PlanetCredits_Server.md) — server side (ns_t3p_credits, runs on T3Planet central TYPO3)

Topology change: client+decorator live **inside public `ns_t3af`**. Private decorator package idea dropped. `ns_t3p_credits` becomes the server-side TYPO3 extension that exposes the API + ledger + Pabbly webhook + admin UI.

Original status: **Approved plan — not yet implemented**
Owner: ns_t3af maintainers (public ext) + T3Planet platform team (private `ns_t3p_credits` + T3Planet API)
Target version: ns_t3af v2.x (toggle + wiring) + private `ns_t3p_credits` 1.x

Supersedes `Documentation/T3PlanetCreditsPlan.md` (kept as historical sketch). This file is the build spec.

---

## 0. Goals

1. Single prepaid credit pool per TYPO3 install, used by all AI traffic when **T3Planet Credits mode** is ON.
2. Authoritative billing + debit lives on **T3Planet API** only — TYPO3 cannot choose debit amount or bypass server.
3. Product catalog (bundles, prices, Pabbly URL per product) served by T3Planet API, cached in TYPO3, manually refreshable from the dashboard.
4. Commerce: one Pabbly URL per product. Pabbly webhooks land on T3Planet only. Post-payment confirmation email includes new balance. User checks balance in instance later.
5. Performance: one main round-trip TYPO3 → T3Planet per AI op (auth + balance + execute + debit fused server-side). Connection reuse, keep-alive, gzip. Server-side phase timings exposed for SLO monitoring.
6. `ns_t3af` stays fully usable standalone with toggle OFF — public-OSS distribution unaffected by the private credits ext.

---

## 1. Scope

| In | Out |
|---|---|
| Toggle in `ns_t3af` dashboard (UI setting, not ext_conf) | Webhook receiver in TYPO3 (Pabbly → T3Planet only) |
| `AiServiceInterface` decorator wiring | Multi-vendor billing engines |
| Public events bridge (so telemetry + child-ext listeners still fire on proxy path) | Replacing existing local adapters when OFF |
| Local read-only debit ledger cache (last N receipts) for dashboard UX | Authoritative ledger in TYPO3 |
| Catalog cache + refresh button + ETag/304 | Editable catalog in TCA |
| Streaming pass-through (SSE) | Token-by-token rewriting in TYPO3 |
| Install-key rotation triggered remotely + dashboard one-click confirm | CLI scheduler rotation (rejected — customer wouldn't schedule it) |

---

## 2. Architecture

```text
TYPO3  (ns_t3af + private ns_t3p_credits)
  ┌───────────────────────────────────────────┐
  │ Caller                                    │
  │   └─► AiServiceInterface                  │
  │         └─► T3PlanetCreditAiService (decorates)
  │               ├─ ON  → ProxyAiExecutor ──► T3Planet API (auth+balance+exec+debit)
  │               └─ OFF → inner AiService (existing adapters / BYO keys)
  │                                           │
  │  Dashboard ──► ProductCatalogService ─────┼─► T3Planet GET /v1/products (ETag)
  │            ──► BalanceService ────────────┼─► T3Planet GET /v1/balance
  │            ──► LocalReceiptCache (DB)     │     (filled from proxy responses)
  └───────────────────────────────────────────┘
                                              │
                                              ▼
                              T3Planet API + Pabbly webhook + ledger + outbound email
```

- **`ns_t3af` (public):** toggle UI + dashboard credit section + decorator interface contract + telemetry bridge. **Zero hard dep** on `ns_t3p_credits`. Toggle has no effect (greyed out with "Install ns_t3p_credits") when private ext absent.
- **`ns_t3p_credits` (private):** HTTP client, signing/encryption, proxy executor, catalog/balance services, decorator implementation. Wired via Symfony `decorates: AiServiceInterface`. Distributed via private Composer repo only.

---

## 3. Mode toggle

### 3.1 Storage (decision: UI setting, not ext_conf)

- Field on a singleton row `tx_nst3af_runtime_setting` (uid=1, scaffolded by Feature 1 follow-up migration). Column: `credit_mode TINYINT(1) DEFAULT 0`.
- Reason: admins set/unset from the dashboard without touching `LocalConfiguration.php`; round-trip stays inside backend.
- Read via `RuntimeSettingsService::isCreditModeOn(): bool`. Cached in `nst3af_responses`-sibling cache `nst3af_runtime` (5 min, invalidated on save).

### 3.2 UI behaviour when ON

- Provider list view stays visible, rendered **read-only** with banner: "T3Planet Credits mode active — local providers preserved, not used. Toggle off to revert to BYO keys."
- Rationale: instant toggle-off without rebuilding provider rows. No edit affordances (Edit / Delete / Test / Set-default disabled).
- "New Provider" button hidden in credits mode.

### 3.3 Toggle ON + proxy unreachable

- **Hard error to caller.** No silent fallback to local adapters. Listed in §7 (Security).
- UX: backend toast "T3Planet API unreachable — credits mode is ON. Toggle off to use local providers." Surfaced from `T3PlanetCreditAiService` exception path.
- `ProviderRequestFailedEvent` dispatched with `reason: 'credits.proxy_unreachable'` so child extensions can react.

---

## 4. Public API contract (drift fixes #1–#6)

### 4.1 Method names

`AiServiceInterface` real surface (shipped Phase 3, duck-typed on `platform()`):

```php
namespace NITSAN\NsT3AF\Api;

interface AiServiceInterface
{
    public function invoke(string $prompt, AiOptions $opts = new AiOptions()): AiResponse;
    public function stream(string $prompt, AiOptions $opts = new AiOptions()): \Generator;
    public function embed(string|array $text, AiOptions $opts = new AiOptions()): EmbeddingResponse;
    public function provider(?string $identifier = null): Provider;
}
```

Decorator implements identical signatures. Plan must use `invoke()` everywhere (not `complete()` from sketch).

### 4.2 `AiOptions::$featureKey` prerequisite

Status: **not in shipped `AiOptions` (Phase 3).** Adding `$featureKey` is a **prerequisite work item in ns_t3af**, NOT a ns_t3p_credits task:

```php
final class AiOptions
{
    public function __construct(
        public readonly ?string $providerIdentifier = null,
        public readonly ?string $model = null,
        public readonly ?float $temperature = null,
        public readonly ?string $systemPrompt = null,
        public readonly ?int $maxTokens = null,
        public readonly bool $noCache = false,
        public readonly string $featureKey = 'default',   // NEW — lowercase, e.g. seo|translation|image
    ) {}
}
```

Migration: existing callers default to `'default'`. Server-side allowlist (§7) defines which keys can debit.

### 4.3 DI wiring — `decorates:` not compiler pass

```yaml
# packages/ns_t3p_credits/Configuration/Services.yaml
services:
    NITSAN\NsT3pCredits\Service\T3PlanetCreditAiService:
        decorates: NITSAN\NsT3AF\Api\AiServiceInterface
        arguments:
            $inner: '@.inner'
            $modeResolver: '@NITSAN\NsT3pCredits\Service\CreditModeResolver'
            $executor: '@NITSAN\NsT3pCredits\Service\ProxyAiExecutor'
            $eventDispatcher: '@Psr\EventDispatcher\EventDispatcherInterface'
            $telemetry: '@NITSAN\NsT3AF\Service\RequestTelemetryService'
```

TYPO3 v13 Symfony DI supports `decorates:` natively. No compiler pass needed.

### 4.4 Event surface must fire on proxy path too

Decorator dispatches the same Phase 3 events the local `AiService` dispatches, in the same order:

| Event | When proxy ON |
|---|---|
| `BeforeProviderRequestEvent` (cancellable) | Before `ProxyAiExecutor` call. Listener cancel → throw `RequestCancelledException`, no API call, no debit. |
| `AfterProviderResponseEvent` | After successful response. Payload includes `usedCredits`, `balanceAfter`, `featureKey`, `model`, `phaseTimings`. |
| `ProviderRequestFailedEvent` | On any executor throw. `reason` distinguishes `credits.insufficient`, `credits.proxy_unreachable`, `credits.api_error`, `credits.signature_invalid`. |
| `ProviderTestConnectionEvent` | Repurposed to credits-API ping when toggle ON. |

Result: existing telemetry (`RequestTelemetryService`) writes `tx_nst3af_request_log` rows for proxy calls without modification — same listener path.

### 4.5 `AiResponse` shape — credits metadata

Extend Phase 3 DTO (additive, backwards compatible):

```php
final class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly array $raw = [],
        public readonly ?CreditsUsage $credits = null,   // NEW — null when toggle OFF
    ) {}
}

final class CreditsUsage
{
    public function __construct(
        public readonly int $charged,
        public readonly int $balanceAfter,
        public readonly string $featureKey,
        public readonly string $serverRequestId,
    ) {}
}
```

Same `CreditsUsage` optional field added to `EmbeddingResponse`. Stream generator yields a final `\NITSAN\NsT3AF\Api\StreamSummary` value carrying `CreditsUsage` after content chunks (so streaming + cost report stay one round-trip).

---

## 5. Request flow

Non-streaming (`invoke` / `embed`):

```
Caller
 → AiServiceInterface (decorator)
 → BeforeProviderRequestEvent (cancellable)
 → ProxyAiExecutor.signAndEncrypt({prompt, opts, featureKey, request_id, ts, nonce, install_id})
 → HTTP POST T3Planet /v1/ai/invoke (TLS, gzip, keep-alive, 30s read timeout)
 → T3Planet: verify signature → check skew window ±5 min → dedupe by request_id (24h window) →
            auth install → check balance → resolve feature→model→cost (server-side) →
            atomic reserve+execute+debit (single tx) → return {content, usage, credits}
 → Decorator: AfterProviderResponseEvent with credits payload
 → RequestTelemetryService writes log row (listener)
 → LocalReceiptCache appends receipt
 → AiResponse to caller
```

Streaming (`stream`):

- Transport: **HTTP chunked with `text/event-stream` (SSE)** pass-through. One long-lived connection.
- Server reserves credits up front (estimated upper bound), debits actual at end, refunds excess. Refund event = single final SSE frame `event: usage\ndata: {charged, balanceAfter}`.
- Mid-stream failure policy (decision): **partial debit proportional to tokens emitted to client**, rounded up to nearest credit. Server emits `event: usage` even on abort. Decorator surfaces `CreditsUsage` via `StreamSummary` generator return value (`$gen->getReturn()` after exhaustion).
- TYPO3 SSE handler keeps connection open with `ignore_user_abort(true)` + `flush()` per chunk; on client disconnect, decorator catches `ClientDisconnectException`, calls T3Planet `/v1/ai/abort` with `request_id` so server can finalize debit immediately.

---

## 6. Feature keys & cost mapping (footgun closed)

- `AiOptions::$featureKey` lowercase canonical.
- Server-side authoritative map: feature_key → backend → model → credits-per-call.
- **Unknown feature_key → HTTP 422 `credits.feature_unknown`.** No fallback debit. Plan sketch's "fallback to OpenAI" rejected — too easy to typo and silently bill at fallback rate.
- Feature keys registered explicitly in T3Planet admin per product. List exposed via `GET /v1/features` (cached 1h in TYPO3) so dashboard can show "Available features".
- Multitenancy (decision): **global pricing, per-install overrides supported** (enterprise contracts). API response includes `effective_cost` per feature for the calling install.

---

## 7. Security (non-negotiables)

| Control | Spec |
|---|---|
| Debit authority | T3Planet only. Amount derived from server-side rules + install context. |
| Transport | TLS 1.2+. HSTS preload on api domain. |
| Envelope | Hybrid: payload encrypted with libsodium `crypto_box_seal` (T3Planet public key pinned in ext). Outer JWS-like header HMAC-SHA256 signed with per-install secret over canonical `(method, path, ts, nonce, body_hash)`. Mirrors `CredentialCipher` pattern from Phase 1. |
| Replay defence | `ts` claim required; server rejects skew > ±5 min. `nonce` 128-bit random; server dedupes nonces 10 min window. |
| Idempotency | `request_id` UUIDv4 per logical op; server dedupes 24h (≥ retry budget × 2). Replay returns original response + zero additional debit. |
| Install binding | API key + **explicit canonical host registration** (no `Host`-header trust). Host registered at install bootstrap (§9.2). |
| Unknown feature key | 422, no debit. |
| Catalog & balance endpoints | Same envelope. Catalog signed; `If-None-Match` + 304. |
| Refresh-catalog rate limit | Server-side 1 req / min / install. Excess returns 429. |
| Toggle OFF | Restores local adapters fully; zero proxy traffic. |
| Webhook | Pabbly → T3Planet only. No TYPO3 endpoint. |
| Decorator bypass | Architecture test (phpat) forbids any controller / service in `ns_t3p_credits` from constructing or calling adapters in `Provider\*` directly. All AI must traverse `AiServiceInterface`. |

### 7.1 Install-secret rotation (no scheduler)

Plan sketch suggested CLI command. Rejected — customers won't schedule. Replacement: **server-initiated rotation with admin one-click confirm**.

Flow:

1. T3Planet decides rotation (policy: e.g. 90 days, or on incident).
2. Next API response carries header `X-T3p-Rotate-Next: <encrypted next-secret blob>` + a 7-day dual-acceptance window starts server-side.
3. `ns_t3p_credits` stores `current_secret` + `pending_secret` (encrypted with TYPO3 `encryptionKey` via reused `CredentialCipher`). Dashboard shows banner: "Security key rotation available — confirm to activate". One-click "Activate now" writes `current = pending`, posts `POST /v1/install/secret/activate` with new key's HMAC.
4. If admin ignores for 7 days, T3Planet auto-flips on the server side and starts accepting the new key only; client falls back to pending automatically on the first 401, with no user action.
5. Old secret invalidated server-side 24h after admin confirm or auto-flip.

Result: zero scheduler dependency, zero downtime, admin can act sooner if they want, automatic flip if they don't.

### 7.2 Domain allowlist registration

- First boot of `ns_t3p_credits`: dashboard prompt "Register this install" → admin clicks → ext POSTs `/v1/install/register` with install_id, declared canonical host (read from TYPO3 site config + admin-confirmable), public key.
- Subsequent requests bound to that host. Changing host requires re-register flow (same prompt). Prevents stolen-key replay from another origin.

---

## 8. Concurrency & performance (#17–#19, speed-conscious)

### 8.1 Atomic debit

Server-side single transaction:

```text
BEGIN
  SELECT balance FROM ledger WHERE install_id=? FOR UPDATE
  IF balance < cost THEN ROLLBACK; return 402 credits.insufficient
  INSERT INTO ledger_entry (request_id, install_id, cost, ts) -- UNIQUE(request_id)
  UPDATE ledger SET balance = balance - cost WHERE install_id=?
  -- now execute AI provider
COMMIT
```

Concurrent requests serialize on `FOR UPDATE`. Hot install with parallel ops: throughput bounded by AI latency, not lock — provider call is the long leg.

### 8.2 Single round-trip mandate

- Non-streaming: 1 HTTP call, persistent connection, gzip both ways.
- HTTP/2 multiplexing over a single TLS connection — Guzzle `curl_multi` + `CURLMOPT_PIPELINING` (HTTP/2). Plan target: **TYPO3-side overhead ≤ 25 ms p95** on top of upstream AI provider latency.
- Connection pool reused across decorator instantiations (DI singleton `T3PlanetHttpClient`).

### 8.3 Streaming refund correctness

- Server reserves estimated upper bound (heuristic: max_tokens × per-token rate, rounded up).
- Final SSE `event: usage` frame refunds delta atomically (same transaction wraps reserve + debit + refund).
- Client disconnect: server finalizes within 5 s (worker drains pending stream, computes actual emitted tokens, debits proportional).

### 8.4 Speed safeguards

- `nst3af_runtime` toggle cache 5 min → no DB hit per call.
- `nst3af_provider_models` already 24h cached.
- Catalog 60 min + ETag.
- Balance: 60 s cache, invalidated on any debit response (response carries fresh balance).
- Avoid double serialization: payload built once, signed once, sent once.
- Telemetry write is async (TYPO3 `Symfony\Component\Messenger` bus when available, sync fallback) — must not block response delivery.

---

## 9. UX / observability (#20–#22)

### 9.1 Local debit ledger cache

- Table `tx_nst3af_credit_receipt`: `request_id`, `feature_key`, `model`, `cost`, `balance_after`, `crdate`, `extra JSON`.
- Filled by decorator from `CreditsUsage` payload after every successful response.
- Read by dashboard "Recent activity" panel (last 50). Pruned to 1 000 most recent on insert.
- Authoritative ledger stays on T3Planet — local cache is read-only mirror for UX speed and offline visibility.

### 9.2 Balance refresh

- `GET /v1/balance` fired on backend module landing render (cheap, < 100 ms typical). Cached 60 s.
- Every AI response carries `balanceAfter` → cache update inline, no extra request.
- Pabbly purchase → T3Planet email → user revisits backend → next module load shows fresh balance.

### 9.3 Streaming UX

- SSE `event: token` frames render incrementally in dashboard chat panel.
- Final `event: usage` frame updates balance widget live.

### 9.4 Multitenancy contract freeze

- **Decision:** global pricing, per-install override allowed. `GET /v1/features` returns `{key, default_cost, install_cost?}` — `install_cost` when override exists. Frozen before OpenAPI publish.

---

## 10. Process (#23–#25)

### 10.1 ADR

Add `Documentation/Adr/0001-t3planet-credits-proxy.md` capturing:

1. Why proxy-only (not hybrid) — debit authority + tamper resistance.
2. Why UI setting (not ext_conf) — admin ergonomics.
3. Why server-initiated key rotation — no scheduler dependency.
4. Why unknown feature_key hard-fails — silent debit risk.
5. Why decorator (not compiler pass) — TYPO3 v13 native support.

### 10.2 Public-OSS posture

Explicit in `Documentation/Index.rst` + `README.md`: `ns_t3af` runs standalone with credit toggle OFF and shows the toggle as disabled with note "T3Planet Credits (requires `ns_t3p_credits`, private)". No code path in public ext depends on credits ext.

### 10.3 Approval gate (with owners + dates)

| # | Step | Owner | Target |
|---|---|---|---|
| 1 | Plan approval | Product (T3Planet) + ns_t3af maintainers | by 2026-05-20 |
| 2 | OpenAPI v1 freeze | T3Planet platform team | by 2026-05-27 |
| 3 | Crypto/signing scheme review | Security reviewer | by 2026-05-27 |
| 4 | `AiOptions::$featureKey` prerequisite PR (public) | ns_t3af maintainers | by 2026-06-03 |
| 5 | `ns_t3p_credits` skeleton + decorator wiring | T3Planet ext team | by 2026-06-10 |
| 6 | Streaming + refund correctness review | Security + platform | by 2026-06-17 |
| 7 | Bypass red-team (forged envelope, replay, double-debit, decorator bypass) | Security | before GA |

---

## 11. OpenAPI / server contract (to freeze at gate 2)

| Endpoint | Purpose | Notes |
|---|---|---|
| `POST /v1/install/register` | First-boot install registration (host, public key) | One-shot per install |
| `POST /v1/install/secret/activate` | Confirm pending rotation key | Dashboard one-click |
| `GET  /v1/balance` | Current balance | 60 s cache; ETag |
| `GET  /v1/products` | Catalog (bundles, Pabbly URLs, prices, sort, active) | ETag + 304; `If-None-Match` honoured. Refresh button bypasses cache (sends `Cache-Control: no-cache`); server rate-limits 1/min/install |
| `GET  /v1/features` | Registered feature keys + costs (with per-install overrides) | 1h cache |
| `POST /v1/ai/invoke` | Non-streaming AI op | Encrypted body, idempotent on `request_id` |
| `POST /v1/ai/stream` | SSE streaming AI op | Final `event: usage` frame mandatory |
| `POST /v1/ai/abort` | Client-disconnect finalize | Idempotent on `request_id` |
| `POST /v1/ai/embed` | Embeddings | Same envelope |
| `POST /v1/purchases/intent` | Optional Pabbly metadata pre-binding | Response: `{intent_id, pabbly_url}` schema |
| Webhook handler (Pabbly → T3Planet, **not TYPO3**) | Credit apply + email | HMAC verify on T3Planet side |

Common envelope header (all endpoints):

```
X-T3p-Install: <install_id>
X-T3p-Ts:      <unix seconds>
X-T3p-Nonce:   <hex 32>
X-T3p-Sig:     base64(hmac_sha256(secret, method|path|ts|nonce|sha256(body)))
Content-Encoding: gzip
```

Encrypted bodies: `application/vnd.t3p.sealed+octet-stream`, libsodium sealed box, T3Planet public key pinned in ext.

---

## 12. Files to add / modify

### `ns_t3af` (public)

**Add**
- `Classes/Domain/Model/RuntimeSetting.php`
- `Classes/Domain/Repository/RuntimeSettingsRepository.php`
- `Classes/Service/RuntimeSettingsService.php`
- `Classes/Api/CreditsUsage.php`
- `Classes/Api/StreamSummary.php`
- `Classes/Updates/AddRuntimeSettingRowUpdate.php` (singleton row scaffold)
- `Configuration/TCA/tx_nst3af_runtime_setting.php`
- `Tests/Unit/Service/RuntimeSettingsServiceTest.php`
- `Documentation/Adr/0001-t3planet-credits-proxy.md`

**Modify**
- `Classes/Api/AiOptions.php` — add readonly `string $featureKey = 'default'`
- `Classes/Api/AiResponse.php` — add optional `?CreditsUsage $credits`
- `Classes/Api/EmbeddingResponse.php` — same
- `Classes/Controller/Backend/ProviderController.php` — render credits banner + read-only mode when toggle ON
- `Resources/Private/Templates/Module/Dashboard.html` — credit section: balance widget, catalog grid, recent receipts (data injected by `ns_t3p_credits` listener if present, else greyed out)
- `Resources/Private/Partials/Provider/ModeToggle.html` — wire to RuntimeSettingsService; activate Credits card when `ns_t3p_credits` installed
- `ext_tables.sql` — `tx_nst3af_runtime_setting`, `tx_nst3af_credit_receipt`
- `Documentation/Index.rst` — note credits toggle + private-ext dependency
- `README.md` — public-OSS posture statement

### `ns_t3p_credits` (private, separate repo)

**Add**
- `Classes/Http/T3PlanetHttpClient.php` — keep-alive, HTTP/2, gzip
- `Classes/Http/EnvelopeSigner.php` — HMAC + sealed-box helpers
- `Classes/Client/T3PlanetApiClient.php` — endpoints in §11
- `Classes/Service/ProxyAiExecutor.php`
- `Classes/Service/CreditModeResolver.php`
- `Classes/Service/T3PlanetCreditAiService.php` — decorator
- `Classes/Service/ProductCatalogService.php` — fetch, ETag, refresh
- `Classes/Service/BalanceService.php`
- `Classes/Service/LocalReceiptCache.php` — writes `tx_nst3af_credit_receipt`
- `Classes/Service/InstallRegistrationService.php` — first-boot prompt
- `Classes/Service/SecretRotationService.php` — pending/current key handling
- `Classes/EventListener/TelemetryBridgeListener.php` — adapts proxy responses to existing `RequestTelemetryService`
- `Configuration/Services.yaml` — `decorates: AiServiceInterface`
- `Tests/Unit/Service/ProxyAiExecutorTest.php`, `Tests/Unit/Http/EnvelopeSignerTest.php`, `Tests/Functional/Service/T3PlanetCreditAiServiceTest.php`
- `Tests/Architecture/NoAdapterBypassTest.php` (phpat — controllers/services in `ns_t3p_credits` cannot import `NITSAN\NsT3AF\Provider\*`)

---

## 13. Verification

1. Toggle OFF → AI call uses local adapter, no T3Planet traffic. Tcpdump verifies.
2. Toggle ON, `ns_t3p_credits` absent → toggle disabled in UI; toggling impossible.
3. Toggle ON, balance sufficient → `invoke()` succeeds; `AiResponse->credits` populated; receipt row written; `tx_nst3af_request_log` row written via existing telemetry path.
4. Toggle ON, balance zero → `ProviderRequestFailedEvent(reason='credits.insufficient')`; user-facing message "Buy credits or toggle off".
5. Toggle ON, proxy down → hard error, no fallback, banner toast surfaced.
6. Concurrent debits (10 parallel calls, balance 5) → exactly 5 succeed, 5 fail with `credits.insufficient`, no negative balance.
7. Stream + mid-stream `Ctrl-C` → server finalizes within 5 s, debit proportional, final usage frame in client log.
8. Replay (`request_id` reused) → identical response, no additional debit.
9. Tampered envelope (1 byte flipped in body) → 401 `signature_invalid`.
10. Clock skew +10 min → 401 `ts_out_of_window`.
11. Refresh catalog twice in 1 min → second returns 429.
12. Server-initiated rotation → banner appears, one-click activates new key, next call uses new key, old key invalidated after 24 h.
13. PHPStan + phpat clean; architecture test fails build if any class in `ns_t3p_credits` imports adapter classes directly.
14. SLO: `phaseTimings.total_ms` p95 ≤ upstream provider latency + 25 ms.

---

## 14. Out of scope (forward pointers)

| Item | Future feature |
|---|---|
| Per-user credit budgets within an install | Feature 5 — Governance |
| BYO-key + credits hybrid (some features local, some proxied) | Post-GA review |
| Multi-currency / FX | T3Planet billing roadmap |
| Self-hosted T3Planet API for on-prem | Not planned |

---

## 15. Revision history

| Date | Note |
|---|---|
| 2026-05-12 | Initial sketch as `Documentation/T3PlanetCreditsPlan.md` |
| 2026-05-13 | Promoted to FEATURE_*. Drift fixes #1–#6, UI toggle, hard-error on proxy down, server-initiated rotation, atomic debit, streaming refund policy, local receipt cache, ADR, owners + dates, ETag, OpenAPI table, files list. |
