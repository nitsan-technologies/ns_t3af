> **Agent entry:** `context/features/credits.md` (external server spec)
>
> **Public note:** This document describes the **commercial** T3Planet composer/license API (`composer.t3planet.cloud`), not open-source PHP shipped in this repo. Treat host paths, hardening checklists, and leadership revision notes as internal product planning. Do not assume they describe a public server codebase in this GitHub package.

# Feature — T3Planet Credits (Server side, `composer.t3planet.cloud` + `ns_license`)

Status: **Approved plan — not yet implemented**
Last updated: **2026-05-18 (b)** — `ns_ai_token` table (comma-separated `license_keys`), account keyed by `token`; admin TYPO3 ext in `packages/` (not on composer host).
Owner: T3Planet platform team
Target host: `composer.t3planet.cloud` (existing license/composer API server)
Pair: [`FEATURE_T3PlanetCredits_Client.md`](FEATURE_T3PlanetCredits_Client.md) — client-side spec living inside `ns_t3af`.
Supersedes earlier `ns_t3p_credits` standalone-TYPO3 design — the credits API is now layered onto the existing license server, not a new TYPO3 install.

---

## Revision 2026-05-15 (post-leadership) — token-based auth, simpler server

Leadership ask: drop HMAC envelope complexity. Single opaque **token** per license = the only verifier. Credit pool shared across **all AI child extensions** on an install (`ns_t3af`, `ns_t3ai`, `ns_t3cs`, future AI ext) via the ns_t3af decorator path.

**What changes (overrides original sections below):**

1. **Identity = `token` column on `ns_product_license`.** Generated server-side at license purchase (any AI extension key triggers it). Replaces `ns_ai_install_secret` table + `signing_secret` flow.
   - SQL: `ALTER TABLE ns_product_license ADD COLUMN token VARCHAR(64) NOT NULL DEFAULT '' AFTER license_key, ADD UNIQUE KEY uk_token (token);`
   - Backfill migration: `UPDATE ns_product_license SET token = SHA2(CONCAT(license_key, uid, RAND()), 256) WHERE token = '' AND extension_key IN ('ns_t3af','ns_t3ai','ns_t3cs','ai_suite');` (use `random_bytes(32)` per row via migration script — NOT `RAND()` in prod).
   - Generation point: `NewOrderCreateLicense.php` + `CreateLicenseAfterOtp.php` — after license insert, if `extension_key` matches AI list AND `token=''`, write `bin2hex(random_bytes(32))`.

2. **Auth on every AI call = `Authorization: Bearer <token>`.** No HMAC, no timestamp, no nonce, no signing secret, no rotation. TLS-only.
   - Replaces FEATURE §1.5 + §4.1 envelope + §2 HMAC row + §9 HMAC/clock/replay rows.
   - Replay: still keep `ns_ai_credit_log.request_uuid UNIQUE` (24h idempotent replay = cached response). Not for security — for client retry safety.

3. **Drop:** `ns_ai_install_secret` table, `/AI/Register.php`, `/AI/RotateSecret.php`, `AiSignatureVerifier.php`, `AiNonceService.php`. Drop `ns_ai_rate_limit` if leadership wants minimum surface (recommended keep — defends against stolen token). Default: keep rate limit table, rest goes.

4. **Add:** `POST /AI/Token.php` — client fallback issuance.
   - Body: `{ license_key, domain }`
   - Validates license via existing `LicenseRepository` + safe `DomainMatcher`.
   - Returns `{ token }` from `ns_product_license.token` if set; else generates one, persists, returns. Idempotent.
   - Auth: this endpoint accepts `license_key` directly (token doesn't exist yet on the client) — same trust level as legacy license endpoints (license_key + domain match).

5. **Pool sharing across AI child extensions.** Pool keyed by `license_key` (resolved from Bearer `token`). Customer owning multiple AI ext licenses → multiple tokens → multiple pools by default. For shared pool across multiple licenses, set `ns_ai_account.pool_owner_license_key` on secondary licenses (replaces `ns_ai_pool_alias` table). Single-install case: one selected license + token → all child extensions hitting ns_t3af share that one pool naturally.

6. **Tier 0 hardening retained (minus HMAC bits):** `DomainMatcher`, prepared statements, transactions, idempotency, rate limit, `license_key UNIQUE`, `random_bytes` for token gen, no silent self-activation. Domain check still mandatory on every call (token alone is not enough — token bound to license, license bound to domain).

**Updated endpoint table (overrides §4.2):**

| Method | Path | Purpose |
|---|---|---|
| POST | `/API/AI/Token.php` | Issue/return token for `{license_key, domain}`. Fallback only — token usually present in `ns_product_license` already from purchase hook. |
| POST | `/API/AI/Balance.php` | Pool snapshot. Bearer token. 60s ETag. |
| POST | `/API/AI/Estimate.php` | Pre-flight cost. Bearer token. |
| POST | `/API/AI/Features.php` | Feature cost map. Bearer token. 1h ETag. |
| POST | `/API/AI/Products.php` | Product catalog. Bearer token. 1h ETag. |
| POST | `/API/AI/PurchaseHistory.php` | Paginated history for token's license. Bearer token. |
| POST | `/API/AI/CurrentPlan.php` | Plan card snapshot. Bearer token. 60s ETag. |
| POST | `/API/AI/Charge.php` | Hot path. Bearer token + `{domain, request_uuid, feature_key, prompt, ...}`. |
| POST | `/API/AI/Stream.php` | SSE. Bearer token. |
| POST | `/API/AI/Abort.php` | Stream cancel finalize. Bearer token. |
| POST | `/API/AI/Embed.php` | Embeddings. Bearer token. |
| POST | `/API/AI/AdminAdjust.php` | Admin only. Separate admin auth (still `.env`-keyed shared secret). |
| POST | `/webhook/pabbly-ai` | Pabbly purchase top-up. Pabbly HMAC retained on the webhook side (separate from per-license tokens). |

**Pre-flight on every AI call (replaces FEATURE §1.5 pre-flight list):**

1. `Authorization: Bearer <token>` present → else `token_missing` 401.
2. `SELECT * FROM ns_product_license WHERE token = ? LIMIT 1` → else `token_invalid` 401.
3. `order_id` not prefixed `EXPIRED_`, `expiration_date > now()` OR `is_life_time=1` → else `license_expired` 403.
4. `DomainMatcher::matches($body['domain'], $licenseRow)` → else `domain_mismatch` 403.
5. `request_uuid` idempotency check → if hit, return cached response.
6. Rate limit check.
7. Charge flow (§5 unchanged except: drop HMAC step, pool lookup uses `token` instead of `license_key` as key; `resolvePrimary` if alias table present).

**Error codes (overrides §11):** drop `signature_invalid`, `signature_expired`, `signature_missing`, `nonce_replay`. Add `token_missing`, `token_invalid`. Keep rest.

**Verification deltas (overrides §14 items 6, 7, 8):**

- 6. Tampered Authorization header → `token_invalid` 401.
- 7. Token issued for license A used from domain not in A's CSVs → `domain_mismatch` 403.
- 8. Token revoked server-side (admin sets `token=''` on license row) → next call `token_invalid` until client re-fetches via `/AI/Token.php`.
- New: Stolen token replay from different IP — still validates (token alone is the bearer). Rate limit + domain match are the only soft defences. Document this trust model in Privacy/Security doc.

**Trade-offs (leadership noted):**

- Lower security ceiling than HMAC: stolen token = bearer access until rotated. Mitigation: TLS-only, short-lived rotation in v1.1 if needed, server-side admin revoke (delete/disable row in `ns_ai_token`).
- Much smaller server surface — plain PHP team can ship faster.
- Drops 6 server files (signature/nonce services + Register/Rotate endpoints) and 4 client files (EnvelopeSigner, NonceGenerator, SecretRotationService, RequestUuidService stays for idempotency).

---

## Revision 2026-05-18 (b) — `ns_ai_token` + admin ext outside composer host

**Overrides Revision 2026-05-15 items 1, 4, 5 and §3 schema where they mention `ns_product_license.token`.**

1. **Dedicated `ns_ai_token` table** — do **not** add `token` to `ns_product_license`.
   - `token` VARCHAR(64) PRIMARY KEY — Bearer secret (`bin2hex(random_bytes(32))`).
   - `license_keys` TEXT — **comma-separated** list of `ns_product_license.license_key` values bound to this pool (one install / shared AI-universe pool across `ns_t3af`, `ns_t3ai`, `ns_t3cs`, `ai_suite`, …).
   - Example: `license_keys = 'KEY-UNIVERSE,KEY-T3AI,KEY-SUITE'`.

2. **`ns_ai_account` primary key = `token`** (not `license_key`). One balance row per Bearer token. Drop `pool_owner_license_key` — multi-license sharing is expressed in `ns_ai_token.license_keys`, not a separate alias table.

3. **`POST /API/AI/Token.php`** (no Bearer):
   - Body: `{ "license_keys": "key1,key2,key3", "domain": "example.com" }` — `license_keys` = **active keys from customer setup** (comma-separated, same format as stored).
   - Server splits both sides on `,`, trims whitespace, finds a row in `ns_ai_token` where **at least one** requested key appears in `license_keys`.
   - If found: validate `domain` against `ns_product_license` for **any** linked, non-expired license key → return `{ "token": "..." }`.
   - If not found: validate each requested key exists in `ns_product_license` + domain match on at least one → `INSERT ns_ai_token` + `INSERT ns_ai_account` → return new token (idempotent if same key set retried).

4. **Bearer pre-flight** (all other endpoints):
   - `SELECT * FROM ns_ai_token WHERE token = ? AND status = 'active'`.
   - Load linked license keys; ensure ≥1 license still valid; `DomainMatcher` on `domain` against **any** linked license row.
   - Debit `ns_ai_account WHERE token = ? FOR UPDATE`.

5. **Server admin TYPO3 extension** lives in **`packages/<server-ai-ext>/`** (this monorepo or sibling repo) on a **real TYPO3 instance** (e.g. T3Planet license management host). **`composer.t3planet.cloud` has no TYPO3** — only plain PHP `/API/AI/*`. Admin ext and API share the **same MySQL** (DSN configured in both).

---

## 0. Goals

1. Add **AI credit billing endpoints** under existing `composer.t3planet.cloud/API/AI/*` alongside the license API.
2. Re-use `ns_product_license` as the identity layer — **license_key IS the install identity**. No separate registration flow; if customer owns a license, they can authenticate.
3. Server holds AI provider keys (OpenAI, Anthropic, Gemini, …), runs upstream calls (proxy mode), debits credits atomically, returns AI response.
4. Feature-based pricing (port `CreditCostEnumeration` style from `autodudes/ai-suite`): 1 SEO suggestion = 5 credits, 1 image generation = 50 credits, etc. Admin-editable.
5. Pabbly purchase flow tops up credits per license_key.
6. Hardening tier 0 mandatory **before** AI endpoints go live (no money endpoints on current substring-domain + raw-mysqli auth model).

---

## 1. Topology

```text
Customer TYPO3 (ns_t3af, credits mode ON)
   │ HTTPS  Authorization: Bearer <token>
   ▼
composer.t3planet.cloud          (plain PHP only — no TYPO3)
   ├─ composer/API/              (existing license endpoints, untouched)
   └─ composer/API/AI/           (NEW credit endpoints)

packages/<server-ai-ext>/        (separate TYPO3 install — e.g. T3Planet internal host)
   ├─ BE: AI Tokens / Features / Products / Usage / Dashboard
   └─ TCA on ns_ai_token, ns_ai_feature_cost, ns_ai_product (+ same MySQL as API)

composer/API/Database/           (on composer host)
   │     ├─ LicenseRepository.php         (existing, unchanged)
   │     ├─ AiAccountRepository.php       (NEW — balances + rate limits)
   │     ├─ AiRequestRepository.php       (NEW — per-call log + idempotency)
   │     ├─ AiTransactionRepository.php   (NEW — purchases, debits, refunds)
   │     ├─ AiFeatureCostRepository.php   (NEW)
   │     └─ AiProductRepository.php       (NEW)
   ├─ composer/API/Services/
   │     ├─ AiCreditService.php           (NEW, debit logic)
   │     ├─ AiCostCalculator.php          (NEW, reads ns_ai_feature_cost)
   │     ├─ AiTokenRepository.php        (NEW)
   │     ├─ AiTokenAuth.php              (NEW, Bearer → ns_ai_token + license CSV)
   │     └─ AiErrorCodes.php              (NEW)
   ├─ composer/API/Utils/
   │     ├─ DomainMatcher.php
   │     └─ AiProviderRouter.php
   └─ composer/API/Provider/              (outbound AI clients)
```

Plain PHP + raw mysqli on `composer.t3planet.cloud`. TYPO3 admin extension in **`packages/<server-ai-ext>/`** connects to the **same database** (not deployed to customer TYPO3 sites).

---

## 1.5 Identity model (ns_license bridge)

| Field on the wire | Source on server |
|---|---|
| `Authorization: Bearer <token>` | `ns_ai_token.token` (PRIMARY KEY) |
| `license_keys` (Token.php body only) | Comma-separated keys from customer setup; matched against `ns_ai_token.license_keys` |
| `domain` (JSON body) | `DomainMatcher` against **any** linked row in `ns_product_license` for keys on the token |
| `request_uuid` (JSON body) | client UUIDv4 — `ns_ai_request.request_uuid` UNIQUE for 24h idempotent replay |

`POST /API/AI/Token.php` is the **only** endpoint without Bearer (accepts `license_keys` + `domain`). All other AI endpoints require Bearer token.

**License key matching helper** (PHP, not SQL `FIND_IN_SET` — keys may contain characters that break SET functions):

```php
// Returns true if any needle appears in haystack CSV (trimmed, case-sensitive)
function licenseKeySetIntersects(string $storedLicenseKeys, array $requestedKeys): bool
```

Pre-flight on every AI call (except `Token.php`):
1. Bearer present → else `token_missing` 401.
2. `SELECT * FROM ns_ai_token WHERE token = ? AND status = 'active'` → else `token_invalid` 401.
3. Split `license_keys`; load each from `ns_product_license` → ≥1 must be non-expired → else `license_expired` 403.
4. `DomainMatcher::matches($domain, $row)` for **any** linked license → else `domain_mismatch` 403.
5. `request_uuid` hit on `ns_ai_request` → return cached `response_body`.
6. Rate limit on `ns_ai_account` for this `token` (`rl_*` columns).
7. Charge / stream / embed flow (`ns_ai_account.token` FOR UPDATE).

---

## 2. Tier 0 — hardening BEFORE AI endpoints (blocking)

Money endpoints cannot ship on current server hygiene. Land these first as a separate PR:

| Issue (from server inventory) | Fix | Where |
|---|---|---|
| `strpos`-based domain match (`*.foo.com` matches `myfoo.com.evil.tld`) | `Utils/DomainMatcher.php` — exact host OR strict `.suffix` match for wildcards. **Used by AI endpoints only**; legacy paths untouched | NEW file |
| SQL injection on `license_key` paths | All AI repos use mysqli prepared statements (`bind_param`). Don't touch legacy `LicenseRepository` | NEW repos |
| No transactions in mysqli wrapper | Wrap debit + log + balance update in `mysqli::begin_transaction` + commit/rollback | `AiCreditService` |
| No idempotency anywhere | `ns_ai_request.request_uuid UNIQUE`; second call returns cached `response_body` | Schema + middleware |
| No rate limiting | Rolling counters on `ns_ai_account` (`rl_minute_*`, `rl_day_*`) → 429 on breach | `AiAccountRepository` |
| `license_key` NOT unique-indexed | `ALTER TABLE ns_product_license ADD UNIQUE KEY uk_license_key (license_key)` — confirm zero dupes in prod first | Migration |
| Weak token generation | `random_bytes(32)` for `ns_ai_token.token` | `Token.php` + admin regenerate |
| Silent self-activation on read path | AI endpoints NEVER append domains to license CSVs. Validate-only function `DomainMatcher::matches()` is read-only | NEW code |
| Hard-coded webhook secret in `webhook.php:16` | Move to `.env` (`API_WEBHOOK_SECRET`) — out of this PR but flag | TODO |
| Inconsistent error envelopes | All AI endpoints use **snake_case codes only**. See §11 | `AiErrorCodes` |

What we deliberately do NOT touch:
- Legacy `error1..error5` codes on existing endpoints.
- `LicenseRepository::check_if_dev_domain` hard-coded bypass keys.
- `webhook.php` + satis flow.
- `LicenseRepository::getLicenseDetails` substring matcher (legacy callers may depend on quirks).

---

## 3. Schema additions

**Summary:** **6 new tables** + **ALTER** `ns_product_license` (`uk_license_key` only — **no `token` column**). Drops `ns_ai_install_secret`, `ns_ai_credit_pool`, `ns_ai_pool_alias`, `ns_ai_credit_log`, `ns_ai_rate_limit`, `ns_ai_purchase_event`.

| Table | Role |
|---|---|
| `ns_product_license` (existing) | Per-product license rows; domain/expiry validation only |
| `ns_ai_token` | Bearer token + comma-separated `license_keys` for shared pool |
| `ns_ai_account` | Balances + rate limits — **PK = `token`** |
| `ns_ai_request` | Per AI call + idempotency + usage stats |
| `ns_ai_transaction` | Purchases, debits, refunds |
| `ns_ai_feature_cost` / `ns_ai_product` | Admin catalog (TYPO3 ext in `packages/`) |

```sql
-- Tier 0 on existing license table (no token column)
ALTER TABLE ns_product_license
  ADD UNIQUE KEY uk_license_key (license_key);

-- 0) Shared pool token — one row per customer install / credit pool
CREATE TABLE ns_ai_token (
  token VARCHAR(64) NOT NULL,
  license_keys TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  crdate INT UNSIGNED NOT NULL,
  tstamp INT UNSIGNED NOT NULL,
  PRIMARY KEY (token),
  KEY idx_status (status)
) ENGINE=InnoDB;

-- license_keys: comma-separated ns_product_license.license_key values (trimmed, no spaces)
-- Example: 'LIC-NSAIU-xxx,LIC-NST3AI-yyy,LIC-SUITE-zzz'

-- 1) Credit account — keyed by Bearer token
CREATE TABLE ns_ai_account (
  token VARCHAR(64) NOT NULL,
  plan_sku VARCHAR(64) NOT NULL DEFAULT 'none',
  free_credits INT UNSIGNED NOT NULL DEFAULT 0,
  paid_credits INT UNSIGNED NOT NULL DEFAULT 0,
  plan_used INT UNSIGNED NOT NULL DEFAULT 0,
  plan_total INT UNSIGNED NOT NULL DEFAULT 0,
  plan_renewed_at INT UNSIGNED NOT NULL DEFAULT 0,
  plan_expires_at INT UNSIGNED NOT NULL DEFAULT 0,
  trial_granted TINYINT(1) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  rl_minute_start INT UNSIGNED NOT NULL DEFAULT 0,
  rl_minute_count INT UNSIGNED NOT NULL DEFAULT 0,
  rl_day_start INT UNSIGNED NOT NULL DEFAULT 0,
  rl_day_count INT UNSIGNED NOT NULL DEFAULT 0,
  config_json MEDIUMTEXT DEFAULT NULL,
  crdate INT UNSIGNED NOT NULL,
  tstamp INT UNSIGNED NOT NULL,
  PRIMARY KEY (token),
  KEY idx_plan_expires (plan_expires_at),
  CONSTRAINT fk_account_token FOREIGN KEY (token) REFERENCES ns_ai_token (token)
) ENGINE=InnoDB;

-- 2) Per-request log + idempotency (replaces ns_ai_credit_log)
CREATE TABLE ns_ai_request (
  uid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_uuid CHAR(36) NOT NULL,
  token VARCHAR(64) NOT NULL,
  license_key_used VARCHAR(255) DEFAULT NULL,
  endpoint VARCHAR(32) NOT NULL,
  feature_key VARCHAR(64) NOT NULL,
  cost INT UNSIGNED NOT NULL DEFAULT 0,
  bucket VARCHAR(16) DEFAULT NULL,
  status_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  error_code VARCHAR(64) DEFAULT NULL,
  response_body MEDIUMTEXT DEFAULT NULL,
  meta_json MEDIUMTEXT DEFAULT NULL,
  crdate INT UNSIGNED NOT NULL,
  PRIMARY KEY (uid),
  UNIQUE KEY uk_request (request_uuid),
  KEY idx_token_crdate (token, crdate),
  KEY idx_crdate (crdate)
) ENGINE=InnoDB;

-- license_key_used = which linked key was used for domain validation (audit)

-- 3) All credit ledger events (replaces ns_ai_purchase_event; includes debits)
CREATE TABLE ns_ai_transaction (
  uid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(128) NOT NULL,
  token VARCHAR(64) NOT NULL,
  license_key VARCHAR(255) DEFAULT NULL,
  type ENUM('trial','purchase_plan','purchase_topup','debit','refund','admin') NOT NULL,
  credits_delta INT NOT NULL,
  sku VARCHAR(64) DEFAULT NULL,
  amount DECIMAL(10,2) DEFAULT NULL,
  currency CHAR(3) DEFAULT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'system',
  raw_payload MEDIUMTEXT DEFAULT NULL,
  applied_at INT UNSIGNED NOT NULL,
  crdate INT UNSIGNED NOT NULL,
  PRIMARY KEY (uid),
  UNIQUE KEY uk_event (event_id),
  KEY idx_token_applied (token, applied_at),
  KEY idx_license_applied (license_key, applied_at)
) ENGINE=InnoDB;

-- event_id: pabbly-{id} | debit-{request_uuid} | refund-{request_uuid} | trial-{token} | admin-{uuid}

-- 4) Feature → credit cost map (admin-editable via packages/<server-ai-ext>/)
-- Seed values ported from autodudes/ai-suite CreditCostEnumeration
CREATE TABLE ns_ai_feature_cost (
  feature_key VARCHAR(64) PRIMARY KEY,
  label VARCHAR(128) NOT NULL,
  default_cost INT NOT NULL,
  default_model VARCHAR(96),
  default_backend VARCHAR(32),         -- 'openai|anthropic|gemini|mistral|openrouter'
  active TINYINT(1) DEFAULT 1,
  sort INT DEFAULT 0,
  tstamp INT NOT NULL
);

-- Per-license cost override (optional v1 — agency deals; else use ns_ai_account.config_json)
CREATE TABLE ns_ai_feature_cost_override (
  license_key VARCHAR(255) NOT NULL,
  feature_key VARCHAR(64) NOT NULL,
  cost INT NOT NULL,
  PRIMARY KEY (license_key, feature_key)
);

-- 5) Product / SKU catalog (admin-editable via server TYPO3 ext)
CREATE TABLE ns_ai_product (
  uid INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(64) NOT NULL,                  -- 'trial' | 'starter' | 'pro' | 'agency' | 'topup_1000' | 'topup_5000'
  type ENUM('plan','topup','trial') NOT NULL,
  title VARCHAR(190) NOT NULL,
  subtitle VARCHAR(255) DEFAULT NULL,
  description TEXT NULL,
  credits INT UNSIGNED NOT NULL,             -- credits granted on purchase
  renewal_period ENUM('monthly','yearly','one_time') NOT NULL DEFAULT 'one_time',
  price_amount DECIMAL(10,2) NULL,
  price_currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
  pabbly_sku VARCHAR(64) NOT NULL,           -- maps incoming Pabbly webhook payload back to this row
  checkout_url VARCHAR(500) NOT NULL,        -- Pabbly checkout link; client opens in new tab w/ license_key appended
  features_json MEDIUMTEXT NULL,             -- JSON array of bullet points for marketing card
  badge VARCHAR(32) NULL,                    -- 'popular' | 'best_value' | NULL
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  crdate INT NOT NULL,
  tstamp INT NOT NULL,
  UNIQUE KEY uk_sku (sku),
  UNIQUE KEY uk_pabbly_sku (pabbly_sku),
  KEY idx_type_active (type, is_active)
);

-- Outbound AI provider configs (admin module)
CREATE TABLE ns_ai_provider_config (
  uid INT AUTO_INCREMENT PRIMARY KEY,
  backend VARCHAR(32) NOT NULL,        -- 'openai|anthropic|gemini|mistral|openrouter'
  endpoint VARCHAR(255) NOT NULL,
  api_key_enc VARCHAR(1024) NOT NULL,  -- sodium_crypto_secretbox
  default_model VARCHAR(96),
  active TINYINT(1) DEFAULT 1,
  sort INT DEFAULT 0,
  tstamp INT NOT NULL,
  UNIQUE KEY uk_backend (backend)
) ENGINE=InnoDB;

-- DROPPED: ns_ai_install_secret, ns_ai_credit_pool, ns_ai_pool_alias, ns_ai_credit_log,
-- ns_ai_rate_limit, ns_ai_purchase_event, token column on ns_product_license.
```

Seed `ns_ai_feature_cost` from `packages/ai-suite/Classes/Enumeration/CreditCostEnumeration.php`. Recommended starting values (admin tunable):

| feature_key | label | default_cost | default_backend | default_model |
|---|---|---|---|---|
| `metadata_alt_text` | Image alt text | 1 | openai | gpt-4o-mini |
| `metadata_title` | Image title | 1 | openai | gpt-4o-mini |
| `metadata_description` | Image description | 1 | openai | gpt-4o-mini |
| `seo_meta_description` | Page SEO meta description | 5 | openai | gpt-4o |
| `seo_page_title` | Page SEO title | 5 | openai | gpt-4o |
| `seo_og_title` | OpenGraph title | 5 | openai | gpt-4o |
| `seo_og_description` | OpenGraph description | 5 | openai | gpt-4o |
| `content_generation` | Content element generation | 10 | openai | gpt-4o |
| `content_translation` | Content translation | 8 | openai | gpt-4o |
| `easy_language` | Easy-language rewrite | 12 | anthropic | claude-3-5-sonnet |
| `page_structure_generation` | Page tree generation | 25 | openai | gpt-4o |
| `image_generation` | Image generation | 50 | openai | dall-e-3 |
| `embedding` | Embedding | 1 | openai | text-embedding-3-small |

Seed `ns_ai_product` (admin can edit/disable/reorder afterwards):

| sku | type | title | credits | period | price (EUR) | pabbly_sku | badge |
|---|---|---|---|---|---|---|---|
| `trial` | trial | Free Trial | 100 | one_time | 0.00 | `t3p-ai-trial` | NULL |
| `starter` | plan | Starter | 2000 | monthly | 19.00 | `t3p-ai-starter` | NULL |
| `pro` | plan | Pro | 10000 | monthly | 49.00 | `t3p-ai-pro` | `popular` |
| `agency` | plan | Agency | 50000 | monthly | 149.00 | `t3p-ai-agency` | `best_value` |
| `topup_1000` | topup | 1,000 Credits | 1000 | one_time | 15.00 | `t3p-ai-topup-1000` | NULL |
| `topup_5000` | topup | 5,000 Credits | 5000 | one_time | 60.00 | `t3p-ai-topup-5000` | NULL |

`checkout_url` filled in per environment (Pabbly dashboard URLs). Client appends `?license_key=<key>&domain=<host>&return=<callback>` for binding.

---

## 4. HTTP API

All endpoints under `composer/API/AI/`. Plain `.php` files. Router alias in `composer/API/index.php` map.

### 4.1 Request format (all AI endpoints except `Token.php`)

```
POST /API/AI/<Endpoint>.php
Headers:
  Content-Type: application/json
  Authorization: Bearer <token>
Body:
  { "domain": "<host>", "request_uuid": "<uuid-v4>", ... endpoint-specific }
```

Reject if (see §1.5): `token_missing`, `token_invalid`, `license_expired`, `domain_mismatch`, `rate_limited`, etc.

`Token.php` only — no Bearer; body `{ "license_keys": "key1,key2", "domain": "..." }` → `{ "token": "..." }` (see §Revision 2026-05-18 (b)).

### 4.2 Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/API/AI/Token.php` | `license_keys` + `domain` in body | Resolve/create `ns_ai_token` row (comma-separated keys). Idempotent. |
| POST | `/API/AI/Balance.php` | Bearer | Pool snapshot from `ns_ai_account`. 60s ETag. |
| POST | `/API/AI/Estimate.php` | Bearer | Pre-flight cost from `ns_ai_feature_cost`. No debit. |
| POST | `/API/AI/Features.php` | Bearer | Feature cost map (+ overrides). 1h ETag. |
| POST | `/API/AI/Products.php` | Bearer | Active rows from `ns_ai_product`. 1h ETag. |
| POST | `/API/AI/PurchaseHistory.php` | Bearer | Paginated `ns_ai_transaction` (purchase types). |
| POST | `/API/AI/CurrentPlan.php` | Bearer | Plan card from `ns_ai_account`. 60s ETag. |
| POST | `/API/AI/Charge.php` | Bearer | Hot path: validate → debit → upstream AI → response. |
| POST | `/API/AI/Stream.php` | Bearer | SSE; settle on `event: usage`. |
| POST | `/API/AI/Abort.php` | Bearer | Stream cancel finalize. |
| POST | `/API/AI/Embed.php` | Bearer | Embeddings. |
| POST | `/API/AI/AdminAdjust.php` | Admin secret (`.env`) | Manual top-up / refund. |
| POST | `/webhook/pabbly-ai` | Pabbly HMAC | Purchase → `ns_ai_transaction` + `ns_ai_account`. |

**Removed (vs earlier draft):** `/AI/Register.php`, `/AI/RotateSecret.php`, `/AI/GrantTrial.php` (trial via license hook + `ns_ai_transaction`), `/AI/PurchaseIntent.php` (optional later).

### 4.2.1 Products.php response

```json
{
  "status": true,
  "currency_default": "EUR",
  "products": [
    {
      "sku": "pro",
      "type": "plan",
      "title": "Pro",
      "subtitle": "Best for growing agencies",
      "description": "10,000 credits / month ...",
      "credits": 10000,
      "renewal_period": "monthly",
      "price_amount": 49.00,
      "price_currency": "EUR",
      "checkout_url": "https://pabbly.t3planet.de/checkout/pro?license_key={license_key}&domain={domain}",
      "features": ["10,000 credits/mo", "All AI features", "Priority support"],
      "badge": "popular",
      "sort_order": 20
    }
  ],
  "current_plan_sku": "starter"
}
```

`checkout_url` may include `{license_key}` / `{domain}` placeholders — client substitutes before opening. `current_plan_sku` is informational (lets UI dim the user's current plan card).

### 4.2.2 PurchaseHistory.php response

```json
{
  "status": true,
  "page": 1,
  "per_page": 20,
  "total": 7,
  "events": [
    {
      "event_id": "pab-...",
      "sku": "topup_5000",
      "title": "5,000 Credits",
      "type": "topup",
      "credits_granted": 5000,
      "plan_assigned": null,
      "amount": 60.00,
      "currency": "EUR",
      "applied_at": 1736294400
    }
  ]
}
```

### 4.3 Charge.php contract

```json
// Request body
{
  "domain": "example.com",
  "request_uuid": "9a7c...",
  "feature_key": "seo_meta_description",
  "prompt": "...full text...",
  "model": "gpt-4o",
  "system_prompt": null,
  "temperature": 0.7,
  "max_tokens": 500,
  "language": "en",
  "typo3_version": 13,
  "ext_version": "2.0.0",
  "ai_endpoint": "completion"
}

// Response 200 — success
{
  "status": true,
  "request_uuid": "9a7c...",
  "content": "Discover ...",
  "prompt_tokens": 245,
  "completion_tokens": 87,
  "credits": {
    "free": 12,
    "paid": 480,
    "plan_used": 235,
    "plan_total": 5000,
    "plan_name": "pro",
    "expires_at": 1736294400
  },
  "charged": {
    "bucket": "plan",
    "amount": 5,
    "feature_key": "seo_meta_description",
    "model": "gpt-4o"
  }
}

// Response 402 — insufficient credits
{
  "status": false,
  "error_code": "insufficient_credits",
  "credits": { ... },
  "topup_url": "https://t3planet.de/buy-credits?license=...&return=..."
}

// Response 429
{
  "status": false,
  "error_code": "rate_limited",
  "retry_after": 42
}

// Response 401/403
{
  "status": false,
  "error_code": "token_missing|token_invalid|license_expired|domain_mismatch"
}

// Response 422
{ "status": false, "error_code": "feature_unknown", "feature_key": "..." }

// Response 502 — upstream AI failed
{
  "status": false,
  "error_code": "upstream_ai_error",
  "upstream_status": 500,
  "credits": { ... },        // unchanged (refunded)
  "request_uuid": "..."
}
```

---

## 5. Atomic debit transaction (Charge.php internals)

```text
-- 0. Auth (before transaction)
Bearer token → ns_ai_token → split license_keys → validate domain on any linked ns_product_license row

-- 1. Idempotency (no lock)
SELECT response_body, status_code FROM ns_ai_request WHERE request_uuid = ?
→ if row: return cached body, exit

BEGIN TRANSACTION
  -- 2. Rate limit: bump rl_* on ns_ai_account WHERE token = ?
  -- 3. cost = AiCostCalculator(feature_key, token) from ns_ai_feature_cost (+ override/config_json)
  -- 4. SELECT * FROM ns_ai_account WHERE token = ? FOR UPDATE → 402 if insufficient
  -- 5. Debit buckets (plan → free → paid); UPDATE account
  -- 6. INSERT ns_ai_request (token, license_key_used, pending, meta_json with prompt_full)
  -- 7. INSERT ns_ai_transaction (token, event_id=debit-{request_uuid}, type=debit, credits_delta=-cost)
COMMIT

-- 9. Upstream AI (OUTSIDE transaction)
try { result = AiProviderRouter::... } catch {
  BEGIN → refund account → ns_ai_transaction type=refund → UPDATE ns_ai_request status=502 → COMMIT
  return 502 upstream_ai_error
}

BEGIN
  UPDATE ns_ai_request SET status_code=200, response_body=..., meta_json+=tokens
COMMIT
return 200
```

Notes:
- `FOR UPDATE` on pool row guarantees serialized debit per pool. mysqli `begin_transaction()` + `autocommit(false)`.
- Upstream call OUTSIDE the lock — otherwise a slow OpenAI response blocks every other request from same license.
- Refund path is its own transaction → atomic, no orphan debits if upstream times out.

---

## 6. Bucket debit order

```
1. plan_credits        (use-it-or-lose-it monthly; drain first)
2. free_credits        (trial gift; drain second)
3. paid_credits        (top-up never expires; drain last)
```

Client UI shows all three; server decides bucket. `charged.bucket` reflects which was used.

For partial coverage (cost > one bucket): split across buckets, log dominant bucket in `bucket` column, store full split in `response_body` JSON.

---

## 7. Pabbly webhook → top-up

Route: `POST /webhook/pabbly-ai`. Pabbly POSTs JSON.

Steps:
1. HMAC verify `X-Pabbly-Signature` against `API_PABBLY_AI_SECRET` from `.env`.
2. Dedupe on `event_id` (`ns_ai_transaction.event_id UNIQUE`).
3. Resolve license_key from Pabbly metadata (set via `/AI/PurchaseIntent.php` pre-bind OR fallback email lookup against `ns_product_license`).
4. Resolve product via `ns_ai_product.pabbly_sku = <payload SKU>` (single source of truth — values come from §3 catalog table, NOT a hard-coded constant). Apply by `type`:
   - `type='trial'` → `+credits` to `free_credits`, set `plan='trial'`. One-time only (gated by `trial_credits_granted=1`).
   - `type='plan'` → `plan_name=sku`, `plan_credits_total=credits`, `plan_renewed_at=now`, `plan_expires_at=now+30d` (monthly) / `+365d` (yearly).
   - `type='topup'` → `+credits` to `paid_credits` (never expires).
   - Unknown `pabbly_sku` → event stored `applied=0`, alert email to ops. No silent credit grant.
5. Update `ns_ai_account` atomically; insert `ns_ai_transaction` (`type=purchase_plan|purchase_topup`, `source=pabbly`).
6. Send confirmation email (existing `Services/EmailService.php`).

Idempotent on `event_id`. Replay-safe.

---

## 8. Trial auto-grant on new license

Hook into existing `NewOrderCreateLicense.php` + `CreateLicenseAfterOtp.php`:

Do **not** auto-create `ns_ai_token` on license insert (client calls `Token.php` with full key list).

Optional: when first AI license for a customer is created, ops may pre-provision via admin BE. Trial grant runs when `ns_ai_account` is first created (via `Token.php` or admin):
- `INSERT ns_ai_account` + `INSERT ns_ai_transaction (event_id='trial-{token}', type='trial', credits_delta=100)`

One-shot. Trial cannot be re-granted.

---

## 9. Security

| Control | Spec |
|---|---|
| Identity | Bearer `token` on `ns_ai_token` (PRIMARY KEY) |
| License binding | `ns_ai_token.license_keys` comma-separated list |
| Auth | `Authorization: Bearer <token>` over TLS 1.2+ only (no HMAC in v1) |
| Domain binding | `domain` must match **any** linked `ns_product_license` row via `DomainMatcher` |
| Replay (client safety) | `request_uuid` UNIQUE on `ns_ai_request` — return cached `response_body` ≤24h |
| Token revoke | Admin suspends/regenerates row in `ns_ai_token` via `packages/<server-ai-ext>/` |
| SQL | All AI repos use prepared statements (`mysqli_stmt::bind_param`) |
| Provider keys | `.env` or `ns_ai_provider_config.api_key_enc` (sodium secretbox) |
| Prompt logging | **Full prompt in `ns_ai_request.meta_json`** (GDPR notice in client `Privacy.rst`) |
| Admin / webhook | `API_ADMIN_HMAC_KEY` and `API_PABBLY_AI_SECRET` in `.env` |
| Rate limit | `ns_ai_account.rl_*` counters (default 60/min, 5000/day) |
| Audit | `ns_ai_request` (calls) + `ns_ai_transaction` (money movements) |

---

## 10. Server TYPO3 admin extension (`packages/<server-ai-ext>/`)

**Not** on `composer.t3planet.cloud` (no TYPO3 there). Extension lives under **`packages/<server-ai-ext>/`** in the monorepo (or sibling repo), deployed on the **T3Planet internal TYPO3 host** that already runs `ns_license`. Uses the **same MySQL** as the plain PHP API (shared DSN). Never installed on customer instances.

| BE module | Tables | Purpose |
|---|---|---|
| **AI Tokens** | `ns_ai_token` | CRUD token rows, edit comma-separated `license_keys`, regenerate token, suspend |
| **AI Accounts** | `ns_ai_account` | Balances per `token`, manual adjust |
| **AI Features** | `ns_ai_feature_cost` (+ optional `ns_ai_feature_cost_override`) | CRUD feature costs, models, backends |
| **AI Products** | `ns_ai_product` | CRUD plans/top-ups, Pabbly SKU, checkout URL, `is_active` |
| **AI Usage** | `ns_ai_request` | Per-call log, filter by license/feature/date, inspect `meta_json` |
| **AI Dashboard** | aggregates on `ns_ai_request`, `ns_ai_transaction`, `ns_ai_account` | Credits burned, top features, active installs, purchase revenue |
| **AI Providers** (optional v1) | `ns_ai_provider_config` | Encrypted upstream API keys |

Example dashboard queries:
- Credits burned (7d): `SUM(cost) FROM ns_ai_request WHERE status_code=200 AND crdate > ?`
- Top features: `GROUP BY feature_key` on `ns_ai_request`
- Purchases: `ns_ai_transaction WHERE type IN ('purchase_plan','purchase_topup')`

Backend access gated by existing T3Planet admin BE group.

---

## 11. Error code vocabulary (snake_case only — no legacy errorN)

```
token_missing               — Authorization Bearer absent
token_invalid               — token not found or revoked
license_invalid             — license not found (Token.php)
license_expired             — past expiration_date, not lifetime
license_suspended           — status=suspended manually
domain_mismatch             — domain not in license CSV columns
insufficient_credits        — pool drained, return topup_url
plan_expired              — plan_expires_at < now, paid_credits still usable
rate_limited              — per-license throttle hit
idempotency_conflict      — request_uuid seen with different body hash
feature_unknown           — feature_key not in ns_ai_feature_cost
upstream_ai_error         — provider returned 5xx, credits refunded
upstream_ai_timeout       — provider timeout, credits refunded
required_field_missing    — body validation
method_not_allowed        — non-POST
internal_error            — catch-all server-side
```

Document in `composer/API/Services/AiErrorCodes.php` constants.

---

## 12. Files (server)

### Add — under `composer/API/`

**Endpoints (AI/)**
- `AI/Token.php`
- `AI/Balance.php`
- `AI/Estimate.php`
- `AI/Features.php`
- `AI/Products.php`
- `AI/PurchaseHistory.php`
- `AI/CurrentPlan.php`
- `AI/Charge.php`
- `AI/Stream.php`
- `AI/Abort.php`
- `AI/Embed.php`
- `AI/AdminAdjust.php`

**Webhook**
- `webhook/pabbly-ai.php` (sibling of existing `webhook.php`)

**Database/**
- `Database/AiAccountRepository.php`
- `Database/AiRequestRepository.php`
- `Database/AiTransactionRepository.php`
- `Database/AiFeatureCostRepository.php`
- `Database/AiProductRepository.php`
- `Database/AiProviderConfigRepository.php` (optional)

**Services/**
- `Services/AiCreditService.php` (debit/refund/transaction)
- `Services/AiCostCalculator.php`
- `Services/AiTokenAuth.php`
- `Services/AiCredentialCipher.php` (port from ns_t3af)
- `Services/AiPabblyApplier.php`
- `Services/AiTrialGranter.php`
- `Services/AiErrorCodes.php`

**Utils/**
- `Utils/DomainMatcher.php`
- `Utils/AiKeyGenerator.php` (random_bytes)
- `Utils/AiProviderRouter.php`

**Provider/ (outbound — port from `vendor/nitsan/ns-t3af/Classes/Client/BaseClient.php`)**
- `Provider/Contract/ProviderClientInterface.php`
- `Provider/BaseClient.php`
- `Provider/OpenAi/Client.php`
- `Provider/Anthropic/Client.php`
- `Provider/Gemini/Client.php`
- `Provider/Mistral/Client.php`
- `Provider/OpenRouter/Client.php`

**Schema**
- New SQL file `composer/API/AI/schema.sql` — runs once on deploy
- `ALTER TABLE ns_product_license ADD UNIQUE KEY uk_license_key (license_key);` (precondition: zero dupes)

**Router**
- Modify `composer/API/index.php` — add `AI/*` routes

**Env**
- New `.env` keys: `API_PABBLY_AI_SECRET`, `API_ADMIN_HMAC_KEY`, `API_ENCRYPTION_KEY` (32-byte hex; existing TYPO3 install reuses encryptionKey)

### Tests (PHPUnit when wired)

- `Tests/Service/AiCreditServiceTest.php` (atomic debit, FOR UPDATE, concurrent)
- `Tests/Service/AiTokenAuthTest.php`
- `Tests/Utils/DomainMatcherTest.php` (exact + wildcard + reject substring vuln)
- `Tests/AI/ChargeIdempotencyTest.php` (replay returns cached)
- `Tests/AI/ChargeRefundTest.php` (upstream 5xx → refund)
- `Tests/Webhook/PabblyAiTest.php`

---

## 13. Phased rollout

```
Phase A — Tier 0 + schema (no customer impact)
  □ DomainMatcher for AI endpoints only
  □ Prepared statements in AI repos
  □ schema.sql: ns_ai_account, ns_ai_request, ns_ai_transaction, ns_ai_feature_cost, ns_ai_product
  □ ALTER ns_product_license (token, UNIQUE license_key)
  □ Server TYPO3 ext scaffold + seed feature/product rows

Phase B — Read-only API + admin CRUD
  □ Token.php, Balance.php, Estimate.php, Features.php, Products.php
  □ TYPO3 modules: Features, Products, Tokens (read-only token list)
  □ Internal allowlist of tokens/license_keys

Phase C — Write path, internal team
  □ Charge.php + Stream.php + Embed.php + Abort.php
  □ Trial auto-grant + token generation in license hooks
  □ TYPO3: Usage log + Dashboard (read-only stats)
  □ AdminAdjust.php + AI Accounts module

Phase D — Pabbly + public + customer client
  □ /webhook/pabbly-ai
  □ Allowlist removed
  □ ns_t3af client decorator (see client FEATURE)
```

---

## 14. Verification

1. Tier 0: domain match `*.foo.com` rejects `myfoo.com.attacker.tld`.
2. Tier 0: prepared statement test — payload `' OR 1=1 --` returns license_invalid, no SQL exec.
3. Token.php with valid license + domain → returns token; idempotent on repeat.
4. Charge with valid Bearer + sufficient credits → debit once; correct bucket; cached on replay.
5. Same `request_uuid` replayed → cached response, no extra debit.
6. Invalid Bearer → 401 token_invalid.
7. Token for license A + domain not in A's CSV → 403 domain_mismatch.
8. Admin clears token → next call token_invalid until Token.php re-issued.
9. Concurrent 10 charges, pool=5 → exactly 5 succeed (FOR UPDATE on ns_ai_account).
10. Unknown feature_key → 422, pool unchanged.
11. Upstream AI 500 → 502 upstream_ai_error, credit refunded atomically.
12. Stream + customer disconnect → Abort.php finalizes ≤5s, proportional debit.
13. Pabbly webhook valid → balance + plan applied, email sent, event applied=1.
14. Pabbly replay → idempotent, no double-credit.
15. Pabbly bad HMAC → 401, no DB write.
16. Trial auto-grant: new license_key insert → 100 free credits, second insert with same key (theoretical) → no re-grant (idempotent via `trial_credits_granted=1`).
17. Multi-license install: one `ns_ai_token` row with comma-separated keys; all child extensions share Bearer token and `ns_ai_account`.
18. Substring domain check vuln does NOT regress legacy `LicenseRepository::getLicenseDetails` behaviour (out of scope).
19. `Products.php` returns only `is_active=1` rows; deactivating a row in admin hides it on next cache cycle (≤1h or after flush).
20. Pabbly webhook with `pabbly_sku` not matching any `ns_ai_product.pabbly_sku` → event stored `verified=1 applied=0` + alert; no credit grant.
21. `PurchaseHistory.php` returns events only for the Bearer token's license — cross-license enumeration blocked.

---

## 15. Open / deferred

| Item | Resolution |
|---|---|
| Sealed-box / encrypted prompt body | v1.1 |
| Pool sharing for multi-product licenses | Single `ns_ai_token.license_keys` CSV per install (Revision 2026-05-18 (b)) |
| Auto-refund on upstream AI failure | Mandatory v1 — §5 refund path + `ns_ai_transaction` type=refund |
| Bearer token rotation / short TTL | v1.1 if abuse; v1 = admin regenerate token in TYPO3 |
| Full prompt logging | Confirmed (user answer #8). GDPR opt-out flag deferred to v1.1 |
| Plan prices/tiers | Starter 2000 / Pro 10000 / Agency 50000 monthly (user answer #2 — tune later) |
| Cost model: flat per-feature vs token-based | Flat per-feature for v1 (user answer #3 — ai_suite pattern). Token-cap safety net at upstream-cost × multiplier deferred |
| Refactor legacy `LicenseRepository::getLicenseDetails` substring matcher | Out of scope. Risk too high for unrelated change |
| Remove hard-coded webhook secret from `composer/webhook.php:16` | Track in separate PR |
| Remove dev-domain bypass keys in `check_if_dev_domain:217-222` | Track separately — product call |
| Per-user budgets within an install | Customer-side, deferred to `ns_t3af` Feature 5 (Governance) |
| Multi-currency | T3Planet billing roadmap |

---

## 16. Attribution

- Feature cost seed values port from `autodudes/ai-suite Classes/Enumeration/CreditCostEnumeration.php` — credit in seed migration comment.
- `Services/AiCredentialCipher.php` ports from `nitsan/ns-t3af Classes/Service/CredentialCipher.php`.
