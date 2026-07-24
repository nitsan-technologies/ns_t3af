# Feature — Credits API base URL

**Parent:** [`credits.md`](credits.md)  
**Code:** `Classes/Credits/Service/CreditsApiBaseUrlResolver.php`, `RuntimeSettingsService::syncApiBaseUrlIfNeeded()`

---

## What it does

Chooses which composer host credits HTTP calls use (`Token.php`, `Balance.php`, `Charge.php`, …) and keeps `tx_nst3af_runtime_setting.t3planet_api_base_url` in sync for built-in T3Planet hosts.

**Extension config `t3planetApiBaseUrl` is removed** — use env or the runtime row instead.

---

## Resolution order

| Priority | Source | Example |
|----------|--------|---------|
| 1 | Env `T3PLANET_CREDITS_API_BASE_URL` | `https://composer.ddev.site` |
| 2 | `TYPO3_CONTEXT=Development` | `https://composer.thebetaspace.com` |
| 3 | Shipped default | `https://composer.t3planet.cloud` |

Constants: `CreditsConstants::{DEFAULT,STAGING,LOCAL_DDEV}_API_BASE_URL`.

---

## Database sync

On every credits HTTP call (`getApiBaseUrl()` → `T3PlanetHttpClient`):

- If DB URL is **empty** or a **known built-in** (empty, `.cloud`, betaspace, `composer.ddev.site`) → update DB to the **resolved** URL above.
- If DB URL is **custom** (e.g. `https://credits-staging.customer.example`) → **never** overwrite.

This lets deploys move installs from betaspace → production without manual SQL.

Legacy `t3planetApiBaseUrl` in ext_conf / `settings.php` is read **once** when the DB field is empty and the value is a known built-in, then synced like other built-ins.

---

## Typical setups

### Your local DDEV (local composer API)

`.ddev/config.yaml`:

```yaml
web_environment:
  - T3PLANET_CREDITS_API_BASE_URL=https://composer.ddev.site
```

→ Calls **composer.ddev.site** (overrides Development → betaspace).

### Tester DDEV (shared staging API)

No env var, `TYPO3_CONTEXT=Development` (DDEV default):

→ Calls **composer.thebetaspace.com**.

### Production customer sites

No env, `TYPO3_CONTEXT=Production`:

→ Calls **composer.t3planet.cloud**.

---

## Verify

```sql
SELECT t3planet_api_base_url FROM tx_nst3af_runtime_setting WHERE uid = 1;
```

After opening AI Foundation backend or activating credits, the row should match the resolved host for that environment.

---

## Tests

```bash
cd packages/ns_t3af && composer test -- --filter 'CreditsApiBaseUrl|RuntimeSettingsServiceApiBaseUrl'
```
