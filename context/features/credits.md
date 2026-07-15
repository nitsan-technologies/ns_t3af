# Feature — T3Planet Credits

**Status:** Implemented (client), **gated Coming soon** for public release  
**Release gate:** `CreditsReleaseGate::PUBLICLY_AVAILABLE = false` — flip to `true` to enable selection.  
**Deep specs:**
- Client: [`FEATURE_T3PlanetCredits_Client.md`](../specs/FEATURE_T3PlanetCredits_Client.md)
- Server: [`FEATURE_T3PlanetCredits_Server.md`](../specs/FEATURE_T3PlanetCredits_Server.md) (external commercial API on `composer.t3planet.cloud`)
- Rollout: [`FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md`](../specs/FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md)

**User docs:** `Documentation/Credits.rst`, `Documentation/Developer/T3PlanetCredits.rst`

---

## What it does

- **Own API Keys** mode (default): `T3PlanetCreditAiService` forwards to inner `AiService` / local adapters.
- **T3Planet Credits** mode (when gate open): `ProxyAiExecutor` routes `complete()`, `stream()`, `embed()` to composer API (`Charge.php`, `Stream.php`, `Embed.php`, `Abort.php`).
- Shared credit pool per install; token auth via `TokenResolver`.
- Backend: mode toggle (disabled “Coming soon” while gated), credits dashboard, balance/plan/usage, buy credits, feature catalog.
- Child extensions unchanged at call site — still use `AiServiceInterface`.

---

## Key paths (client)

| Area | Path |
|---|---|
| Decorator | `Classes/Credits/Service/T3PlanetCreditAiService.php` |
| Proxy executor | `Classes/Credits/Service/ProxyAiExecutor.php` |
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

While gated: Providers / mode UI shows T3Planet Credits as **Coming soon** (not selectable).  
When gate open: enable T3Planet Credits → verify dashboard balance and a test `complete()` returns `CreditsUsage`.

See `context/specs/FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md` §10 checklist.
