> **Agent entry:** `context/features/credits.md`

# T3Planet AI Credits — Customer rollout (`ns_t3af`)

**Audience:** Developers implementing Credits mode in **`ns_t3af`** (TYPO3 customer sites).  
**Server reference:** [`FEATURE_T3PlanetCredits_Server.md`](FEATURE_T3PlanetCredits_Server.md) (full spec) and this repo under `composer/API/AI/*`.  
**Canonical model:** Bearer identity = `ns_ai_token.token`; balances = `ns_ai_account`; license proof = `ns_product_license` + [`DomainMatcher`](composer/API/Utils/DomainMatcher.php). **Credits HTTP calls must not go through `ns_license`** — only this plain-PHP API.

---

## 1. What you are wiring

| Layer | Role |
|--------|------|
| **T3Planet composer host** | Plain PHP at `…/API/AI/*.php` (no TYPO3). Router: [`composer/API/index.php`](composer/API/index.php) → `/API/AI/{Name}`. |
| **`ns_t3af` (customer)** | Holds license keys + site domain; calls the API **from the server** (scheduler, middleware, AI features) with `Authorization: Bearer` and JSON body `domain`. |
| **End user browser** | **Must not** receive the Bearer token. Spec §1.6: SPA → Credits API is forbidden (token leakage). |

**Config you need in `ns_t3af`:** a single **base URL** for the Credits API, e.g. `https://composer.t3planet.cloud` (confirm **Phase 0** hostname in [`FEATURE_T3PlanetCredits_Server_CHECKLIST.md`](FEATURE_T3PlanetCredits_Server_CHECKLIST.md) — no trailing slash).

---

## 2. Endpoints implemented today (wire these)

All are **`POST`** unless noted. Send **`Content-Type: application/json`**.  
Bearer endpoints require header: `Authorization: Bearer <64-char-hex-token>`.

| Script | Purpose |
|--------|---------|
| **`/API/AI/Token.php`** | Issue or return pool token. **No Bearer.** Body: `license_keys` (CSV), `domain` (hostname). Returns `token`. |
| **`/API/AI/Balance.php`** | Balance snapshot + **60s ETag** (`If-None-Match` → 304). |
| **`/API/AI/CurrentPlan.php`** | Plan card + 60s ETag. |
| **`/API/AI/Features.php`** | Feature cost map + ~1h ETag. |
| **`/API/AI/Products.php`** | Purchasable SKUs + ~1h ETag. |
| **`/API/AI/Estimate.php`** | Cost for a `feature_key` (read-only, no debit). |
| **`/API/AI/Charge.php`** | Debit + upstream stub settle. Body: `request_uuid`, `feature_key`, optional `meta_json`, optional `simulate_upstream_failure` (QA). |
| **`/API/AI/Embed.php`** | Same as Charge; default `feature_key` = `embedding`; `endpoint` logged as `embed`. |
| **`/API/AI/Abort.php`** | Refund stuck pending debit by `request_uuid` (Bearer + `domain`). |

**Not in this repo yet (do not depend on them in v1 client):** `Stream.php`, `PurchaseHistory.php` (spec mentions them; implement client behind feature flags when server ships).

**Admin-only (not for `ns_t3af`):** `AdminAdjust.php` (header `X-Ai-Admin-Secret`).

---

## 3. Integration sequence (recommended)

### 3.1 Resolve `license_keys` and `domain`

- **`license_keys`:** CSV of **active** `ns_product_license.license_key` values for this install that should share one credit pool (sorted/deduped **on the server** on first `Token.php` — you should still send a consistent CSV from the client).
- **`domain`:** The **current site** host used for [`DomainMatcher`](composer/API/Utils/DomainMatcher.php) (exact match or allowed wildcard row on a linked license).

### 3.2 Mint or reuse token — `POST …/API/AI/Token.php`

```json
{
  "license_keys": "KEY-ONE,KEY-TWO",
  "domain": "customer.example.org"
}
```

Response includes `token`. **Cache persistently** (e.g. extension config encrypted, or DB table in `ns_t3af`) — treat like a secret. **Rotate** by calling Token again after server ops change pool or you change key set.

### 3.3 Every billed / read call

1. `Authorization: Bearer <token>`
2. JSON body always includes **`domain`** (same rules as Token) — required by [`AiTokenAuth`](composer/API/Services/AiTokenAuth.php).

### 3.4 Idempotency for writes

For **Charge / Embed**, send a fresh **RFC 4122 UUID** per logical AI operation in `request_uuid`. Retries with the **same** UUID replay the **cached** HTTP result (success or 502) without double debit — see spec §1.6.

### 3.5 Upstream failures

Today the server uses **`AiChargeUpstreamStub`**. On failure path, API returns **502** with snake_case `error_code` and performs **refund** + `refund-{request_uuid}` ledger event. Client should surface “try again” and **reuse the same `request_uuid`** only for true retries.

### 3.6 Abort

If a Charge is stuck after debit (e.g. client timeout while pending), call **`Abort.php`** with the same `request_uuid` to refund and close the row.

---

## 4. Error vocabulary (handle in client)

All new AI endpoints use [`AiErrorCodes`](composer/API/Services/AiErrorCodes.php) — **`error_code`** snake_case (no legacy `errorN`).

Typical mapping for UI:

| Code | Meaning (short) |
|------|------------------|
| `token_missing` / `token_invalid` | Fix Bearer or call Token.php |
| `domain_mismatch` | Domain not allowed on linked licenses |
| `license_expired` / `license_invalid` | License rows |
| `insufficient_credits` | 402 — upsell / block AI |
| `feature_unknown` | Unknown `feature_key` vs catalog |
| `rate_limited` | Back off |
| `upstream_ai_error` / `upstream_ai_timeout` | 502 — refunded where applicable |

---

## 5. Caching (`Balance`, `CurrentPlan`, `Features`, `Products`)

Endpoints emit **weak ETags**. Optional client optimisation:

1. Store last `ETag` + response per site/token.
2. Send `If-None-Match: W/"…"` — **304** means reuse cached JSON.

---

## 6. `feature_key` alignment

Costs come from **`ns_ai_feature_cost`** (and overrides). **`feature_key`** strings must match what **your** AI features use (often aligned with **ai-suite** / product enumeration). Discover dynamically via **`Features.php`** or ship a mapping table in `ns_t3af` updated when T3Planet publishes catalog changes.

Use **`Estimate.php`** before expensive flows to show cost without debiting.

---

## 7. Privacy & legal (mandatory before prod)

Per checklist **D.2.3** and spec §1.6 / §9:

- **`meta_json` / prompts** may be stored server-side on Charge — update **`ns_t3af`** `Resources/Private/Language/Privacy.rst (or equivalent)` with **full-prompt logging**, retention, and legal basis.
- Do **not** log Bearer tokens in TYPO3 logs.

---

## 8. Environment / rollout flags

### 8.1 Server allowlist (until general availability)

Composer host may gate AI endpoints with **`API_AI_CREDITS_ALLOWLIST_ENABLED`** and CSV allowlists — see [`composer/API/.env.example`](composer/API/.env.example).  
Before **production GA**, disable allowlist (**checklist D.2.2**) so any active customer token works.

### 8.2 Local / DDEV

Composer repo loads **`composer/.env.local`** under `*.ddev.site`; variables prefixed **`API_*`** can override container env — see [`composer/API/config.php`](composer/API/config.php).

---

## 9. purchasable flow (Phase D — when server ships Pabbly)

Checklist **D.2.4:** checkout URLs must carry **`license_key`**, **`domain`**, **`return`** so webhooks + customer pools reconcile. **`Products.php`** exposes `checkout_url` — client should append/query-merge parameters per ops runbook when **`AiPabblyApplier`** exists.

Until then, treat Products as **informational** only.

---

## 10. Implementation checklist (`ns_t3af`)

Use this as your ticket list:

- [ ] **Settings:** Extension configuration: Credits API **base URL** (prod/staging separate optional).
- [ ] **Secret storage:** Persist Bearer **token** after Token.php (encrypt at rest if stored in DB).
- [ ] **Token refresh:** On `token_invalid`, re-call Token.php with same keys/domain (handle rate limits).
- [ ] **HTTP client:** Single small service class (Guzzle/TYPO3 RequestFactory): POST JSON, read errors, optional ETag handling.
- [ ] **Decorator / middleware:** Before AI execution: Balance or Estimate gate; inject **`request_uuid`** for Charge; map `insufficient_credits` to UX.
- [ ] **Feature mapping:** Map internal AI actions → **`feature_key`**; stay in sync with `Features.php`.
- [ ] **Never expose Bearer** to frontend JS; no browser-side calls to `/API/AI/*`.
- [ ] **Privacy.rst** updated (prompt logging, credits telemetry).
- [ ] **Telemetry:** Optional structured logging (without token): `error_code`, `feature_key`, `request_uuid` prefix.

---

## 11. Quick sanity curl (replace placeholders)

```bash
BASE="https://composer.t3planet.cloud"
DOMAIN="customer.example.org"
KEYS="YOUR-LICENSE-KEY"

curl -sS -X POST "$BASE/API/AI/Token.php" \
  -H "Content-Type: application/json" \
  -d "{\"license_keys\":\"$KEYS\",\"domain\":\"$DOMAIN\"}"

TOKEN="<paste-token>"

curl -sS -X POST "$BASE/API/AI/Balance.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"domain\":\"$DOMAIN\"}"
```

---

## 12. Related files in **this** repo (for debugging)

| Area | Path |
|------|------|
| Schema | [`composer/API/AI/schema.sql`](composer/API/AI/schema.sql) |
| Debit / refund | [`composer/API/Services/AiCreditService.php`](composer/API/Services/AiCreditService.php) |
| Auth | [`composer/API/Services/AiTokenAuth.php`](composer/API/Services/AiTokenAuth.php) |
| Cron retention | [`composer/API/AI/cron_purge_ai_requests.php`](composer/API/AI/cron_purge_ai_requests.php), [`cron_scrub_ai_request_meta.php`](composer/API/AI/cron_scrub_ai_request_meta.php) |
| Progress tracking | [`FEATURE_T3PlanetCredits_Server_CHECKLIST.md`](FEATURE_T3PlanetCredits_Server_CHECKLIST.md) |

---

**Summary:** Configure base URL → obtain & store **Bearer** via **Token.php** → every call sends **Bearer + `domain`** → use **Charge/Embed/Abort** with **`request_uuid`** → handle **snake_case errors** → update **Privacy** copy → keep tokens **server-side only**.
