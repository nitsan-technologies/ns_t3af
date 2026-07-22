# Feature — T3Planet Credits

**Status:** Publicly available (`CreditsReleaseGate::PUBLICLY_AVAILABLE = true`)  
**Release gate:** `CreditsReleaseGate::PUBLICLY_AVAILABLE` — compile-time switch; currently **on**.
**Deep specs:**
- Client: [`FEATURE_T3PlanetCredits_Client.md`](../specs/FEATURE_T3PlanetCredits_Client.md)
- Server: [`FEATURE_T3PlanetCredits_Server.md`](../specs/FEATURE_T3PlanetCredits_Server.md) (external commercial API on `composer.t3planet.cloud`)
- Rollout: [`FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md`](../specs/FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md)
- **API base URL (env / sync):** [`credits-api-base-url.md`](credits-api-base-url.md)

**User docs:** `Documentation/Credits.rst`, `Documentation/Developer/T3PlanetCredits.rst`

---

## What it does

- **Own API Keys** mode (default): decorators forward to inner services / local adapters.
- **T3Planet Credits** mode (when gate open): proxy executors route billable calls to the composer API; every successful settlement writes a row to `tx_nst3af_credit_receipt` via `CreditsChargeRecorder` → `LocalReceiptCache` (Recent AI Usage dashboard).
- Shared credit pool per install; token auth via `TokenResolver`.
- Backend: mode toggle, credits dashboard, balance/plan/usage, buy credits, feature catalog.
- Child extensions unchanged at call site — still use `AiServiceInterface`, `TtsServiceInterface`, `ImageGenerationServiceInterface`.

### Proxy + receipt matrix (credits mode ON)

| Call | Decorator | Proxy executor | API endpoint | Local receipt |
|---|---|---|---|---|
| `complete()` | `T3PlanetCreditAiService` | `ProxyAiExecutor` | `Charge.php` | yes |
| `stream()` | `T3PlanetCreditAiService` | `ProxyAiExecutor` | `Stream.php` / `Abort.php` | yes |
| `embed()` | `T3PlanetCreditAiService` | `ProxyAiExecutor` | `Embed.php` | yes |
| `speak()` | `T3PlanetCreditTtsService` | `ProxyTtsExecutor` | `Speak.php` | yes |
| `generate()` / `variation()` | `T3PlanetCreditImageService` | `ProxyImageExecutor` | `Image.php` | yes |

AI Logs (`RequestTelemetryService`) and Recent AI Usage (`LocalReceiptCache`) both update on every successful proxy call above.

---

## Key paths (client)

| Area | Path |
|---|---|
| AI decorator | `Classes/Credits/Service/T3PlanetCreditAiService.php` |
| TTS decorator | `Classes/Credits/Service/T3PlanetCreditTtsService.php` |
| Image decorator | `Classes/Credits/Service/T3PlanetCreditImageService.php` |
| AI proxy | `Classes/Credits/Service/ProxyAiExecutor.php` |
| TTS proxy | `Classes/Credits/Service/ProxyTtsExecutor.php` |
| Image proxy | `Classes/Credits/Service/ProxyImageExecutor.php` |
| Receipt mirror | `Classes/Credits/Service/CreditsChargeRecorder.php`, `LocalReceiptCache.php` |
| Mode toggle | `CreditModeResolver`, `RuntimeSettingsService` |
| Runtime DB | `tx_nst3af_runtime_setting` |
| Token resolve | `Classes/Credits/Service/TokenResolver.php` |
| License keys | `Classes/Credits/Service/LicenseKeyResolver.php` |
| HTTP / SSE | `Classes/Credits/Http/T3PlanetHttpClient.php`, `T3PlanetApiClient.php`, `T3PlanetSseStreamParser.php` |
| Dashboard | `Classes/Credits/Service/CreditsDashboardService.php`, `CreditsDashboardAssembler.php` |
| Estimate | `Classes/Credits/Service/CreditsEstimateService.php` |

---

## Auth model (locked — read revision banner in FEATURE files)

- **Bearer token** over TLS (`Authorization: Bearer <token>`).
- Token resolution: in-memory cache → encrypted `tx_nst3af_runtime_setting` → `POST /AI/Token.php`.
- `request_uuid` for idempotency only (not HMAC signing).
- Active license keys: comma-separated list on runtime setting (from `LicenseKeyResolver`).

---

## Free trial credits (server-side only)

Trial grant amount is **not configured in ns_t3af**. The server resolves it on mint/attach:

```text
ns_ai_settings.trial_credits (DB, ops admin) → API_AI_TRIAL_CREDITS env → default 100
```

| Trigger (server) | Client call | Idempotency |
|---|---|---|
| First pool mint | `TokenResolver::issueFreshToken()` → `POST /API/AI/Token.php` | `trial_granted=1` on account |
| New license key on existing pool | `TokenResolver::syncLicensePool()` → `POST /API/AI/AttachLicenses.php` | Once per new key |

Client reads balance from **`Balance.php`** / **`CurrentPlan.php`** only (`CreditsDashboardAssembler::summarizeBalance()`). Product cards use **`Products.php`** `credits` field — no hardcoded trial amount in PHP/Fluid grant paths.

Setting **`trial_credits=0`** on the server disables new grants; existing accounts unchanged.

---

## Billing model

Server debits **after** upstream AI from actual token usage:

```text
credits = max(1, ceil(total_tokens / tokens_per_credit))
```

Default 1 credit ≈ 1000 tokens. Read `AiResponse::$credits`, `StreamSummary::$credits`, or `EmbeddingResponse::$credits` — do not hardcode per-feature prices in child extensions.

---

## Do / Don't

**Do:** Call `CreditsEstimateService` before UI submit when showing approximate cost.

**Do:** Pass `feature_key` in `AiOptions` when credits mode is on.

**Don't:** Implement HMAC/signing-secret paths (dropped per 2026-05-15 revision).

**Don't:** Store upstream provider API keys client-side when credits mode is active.

---

## Verification

```bash
cd packages/ns_t3af && composer test -- --filter Credits
```

When gate open (default): enable T3Planet Credits → verify dashboard balance and a test `complete()` returns `CreditsUsage`.

See `context/specs/FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md` §10 checklist.

### Dev reset — re-mint after server `trial_credits` change

Trial amount applies only on **first** `ns_ai_account` creation (or AttachLicenses for new keys). To re-test a fresh grant:

1. **Server:** set `ns_ai_settings.trial_credits` (AI Credits admin Dashboard) or delete/recreate the test `ns_ai_token` + `ns_ai_account` row for that license pool.
2. **TYPO3 client:** clear stored bearer so the next activation calls `Token.php` again:
   - `tx_nst3af_runtime_setting`: empty `token_enc`, optionally reset `credit_mode` / re-enable via wizard
   - Or use backend flow that calls `TokenResolver::invalidate()` (clears cache + `token_enc` + API response cache)
3. Enable credits mode → `CreditModeController` → `syncLicensePool()` / `issueFreshToken()` → dashboard **`Balance.free`** should match current server setting.

**Smoke matrix (credits gate on):**

| Server `trial_credits` | Expected after fresh mint |
|---|---|
| 50 | `Balance.free` ≈ 50 |
| 0 | Empty free bucket; no client error |
| 100 (default) | `Balance.free` ≈ 100 |

Attach new license key → `AttachLicenses.credits_added` equals current server setting (once per key).

---

## Server readiness (ops checklist)

Before enabling credits on customer sites, confirm on the composer API host:

| Check | Notes |
|-------|-------|
| API base URL | Resolved via env / context — see [`credits-api-base-url.md`](credits-api-base-url.md); stored in `tx_nst3af_runtime_setting.t3planet_api_base_url` |
| `API_AI_CREDITS_ALLOWLIST_ENABLED` | Must be **off** (unset or `0`) for GA — see `composer/API/.env.example` |
| `ns_ai_settings.trial_credits` | Admin dashboard on server; overrides `API_AI_TRIAL_CREDITS` env |
| Upstream AI keys | `API_OPENAI_API_KEY` etc. or `API_AI_UPSTREAM_MODE=stub` for QA only |
| `ns_license` on customer TYPO3 | Required for `LicenseKeyResolver` (root distribution already requires `nitsan/ns-license`) |
| Pabbly checkout | Optional for v1 — purchase webhooks still incomplete on server; activation + trial + Charge is MVP |
