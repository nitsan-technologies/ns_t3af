# Child extensions — T3Planet Credits API migration (2026-07-31)

**T3AF commit:** `6175102` — `feat(credits): migrate T3Planet API client`  
**Audience:** `ns_t3ai`, `ns_t3cs`, `ns_t3aa`, `ns_t3as`, `ns_t3ac`, and any extension calling `AiServiceInterface` / `TtsServiceInterface` / `ImageGenerationServiceInterface` with credits mode on.

**Related:** `context/features/credits.md`, `context/features/child-extensions.md`, `Classes/Credits/CreditsFeatureKeyCatalog.php`

---

## TL;DR for child teams

| Topic | Action required in child ext? |
|---|---|
| Call sites (`complete()`, `stream()`, `embed()`, …) | **No** — keep using `AiServiceInterface` as today |
| Legacy `AiOptions::$featureKey` values (e.g. `seo.meta_description`) | **No** — T3AF maps + unwraps in `ProxyAiExecutor` |
| SEO single-field JSON responses | **No** — T3AF unwraps `{ "meta_description": "…" }` back to plain string when needed |
| Reading `CreditsUsage` / `CreditsEstimate` token counts | **Yes if you relied on them** — input/output token fields stay at `0`; use `cost` / `cost_units` |
| License keys in credits pool | **Yes if you expected child licenses to mint tokens** — only **`ns_t3af`** licenses bind now |
| New guardrail API errors | **Optional** — handle in UX if you catch `CreditsApiException` |
| Canonical feature keys in new code | **Recommended** — see table below; not blocking |

---

## What changed inside T3AF (not in children)

1. **Canonical `feature_key` set (10 values)** — server catalog aligned; old 15+ client constants removed from `CreditsFeatureKeyCatalog`.
2. **`CreditsFeatureKeyMapper::mapWithMeta()`** — maps extension-local keys → canonical key; adds `meta_json.fields[]` for granular SEO/metadata keys.
3. **SEO compat shim** — `ProxyAiExecutor` merges mapper meta into Charge/Stream payload and unwraps single-field JSON `content` for legacy callers.
4. **`LicenseKeyResolver`** — credits token pool uses **`extension_key === 'ns_t3af'`** licenses only (`AttachLicenses` / `Token.php`).
5. **Token metrics dropped from client parsing** — Charge/Stream/Embed responses no longer populate usage DTO token breakdown (server contract change).
6. **Metered pricing** — `CreditsPricing` reads `model: metered` + `rate_card[]`; dashboard footnote from API.
7. **New error codes** — `charge_in_progress`, `daily_cap_exceeded`, `concurrency_limit`, `usage_anomaly`, `batch_invalid` (+ xlf labels).
8. **Idempotency** — one automatic retry with fresh UUID on `idempotency_conflict`.
9. **Dashboard UI only** — trial plan card, Products `renewal_period`, Plans/Top-ups tabs (no child Fluid changes).

---

## Canonical feature keys (composer server)

Use these in **new** child code when setting `AiOptions::$featureKey` (credits mode). Existing keys still work via mapper.

| Canonical key | Typical child use |
|---|---|
| `seo_page_metadata` | Page title, meta description, OG tags |
| `seo_image_metadata` | File alt text, image title/description |
| `content_generate` | RTE, topics, outlines, rewrite, chat (non-stream) |
| `content_translate` | Translation features |
| `content_page_structure` | Page tree / structure generation |
| `easy_language` | Easy-language rewrite |
| `assistant_chat` | Streaming chat (`stream()` endpoint) |
| `embedding` | `embed()` |
| `text_to_speech` | `speak()` |
| `image_generate` | `generate()` / image variations |

Constants: `CreditsFeatureKeyCatalog::*` in `ns_t3af` (reference only — children should use string literals or own constants mirroring these names).

---

## Legacy keys — still supported (no child diff required)

Examples automatically mapped in `CreditsFeatureKeyMapper` (non-exhaustive):

- `seo.meta_description`, `seo_page_title`, `seo_og_title`, … → `seo_page_metadata` + `fields[]`
- `metadata.alt_text`, `file.alt_text`, … → `seo_image_metadata` + `fields[]`
- `content.generation`, `rte.content`, `chat.response`, … → `content_generate`
- `stream`, `chat.assistance` → `assistant_chat`
- `embed`, `embedding` → `embedding`
- `tts`, `media.tts` → `text_to_speech`

**Custom keys:** register via `CreditsFeatureKeyAliasProviderInterface` (DI tag in child `Services.yaml`) or:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases']['my_ext'] = [
    'my.feature.key' => 'content_generate',
];
```

---

## License pool (breaking for multi-license setups)

Credits mode **Token** / **AttachLicenses** now sends only valid **`ns_t3af`** license keys from `ns_license`.

- Child extension licenses (`ns_t3ai`, `ns_t3cs`, …) are **not** included in the pool.
- Sites need an active **AI Foundation (`ns_t3af`)** license for credits activation.
- Empty pool → `LICENSE_INVALID` on token sync (wizard / activate flow).

---

## Usage & cost DTOs

| Field | Status |
|---|---|
| `CreditsUsage::$cost`, `$costUnits` | **Use these** — authoritative after Charge |
| `CreditsUsage::$inputTokens`, `$outputTokens`, … | Deprecated for display; remain `0` |
| `CreditsEstimate` token fields | Same — prefer `cost` / `cost_units` from Estimate API |
| Local receipt / AI Logs | Still written; feature_key stored as **canonical** after mapping |

If child UI showed token counts from credits responses, switch to credits cost or drop the column.

---

## New API errors (optional UX)

| `error_code` | When |
|---|---|
| `charge_in_progress` | Duplicate in-flight charge (UUID retry handled in T3AF) |
| `daily_cap_exceeded` | Account daily cap |
| `concurrency_limit` | Too many parallel requests |
| `usage_anomaly` | Server guardrail |
| `batch_invalid` | Batch endpoint (not used by T3AF client yet) |

Labels: `Resources/Private/Language/locallang_credits.xlf` in `ns_t3af`.

---

## Upgrade checklist (child maintainers)

1. Bump `ns_t3af` dependency to build containing `6175102` or later.
2. Confirm site has **`ns_t3af` license** if using T3Planet Credits mode.
3. Search child code for `inputTokens`, `outputTokens`, `promptTokens` on **credits** DTOs — remove or replace with `cost`.
4. (Optional) Align new `featureKey` strings to canonical table above.
5. (Optional) Add `CreditsFeatureKeyAliasProviderInterface` for extension-specific keys not in global map.
6. Run child tests with credits decorators enabled; one `complete()` + one SEO metadata call smoke test.

**T3AF verification:**

```bash
cd packages/ns_t3af && composer test -- --filter Credits
```

---

## What did *not* change

- `AiServiceInterface` method signatures
- Own API Keys mode behaviour
- Adapter registration (`AdapterInterface` + `nst3af.adapter` tag)
- MCP tool tags, AI Access catalog providers
- Request telemetry table shape (`tx_nst3af_request_log`)

---

## Questions?

| Need | Load |
|---|---|
| Full credits feature | `context/features/credits.md` |
| Public API usage | `context/features/public-api.md` |
| Child wiring overview | `context/features/child-extensions.md` |
| Deep client spec | `context/specs/FEATURE_T3PlanetCredits_Client.md` |
