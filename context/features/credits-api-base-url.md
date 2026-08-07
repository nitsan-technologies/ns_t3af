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
| 1 | Env `T3PLANET_CREDITS_API_BASE_URL` | `https://composer.ddev.site`, staging host, etc. |
| 2 | Shipped default | `https://composer.t3planet.cloud` |

Constants: `CreditsConstants::{DEFAULT,LOCAL_DDEV}_API_BASE_URL`.

Staging / custom hosts are **not** inferred from `TYPO3_CONTEXT`. Set the env var explicitly.

---

## Database sync

On every credits HTTP call (`getApiBaseUrl()` → `T3PlanetHttpClient`):

- If DB URL is **empty** or a **known built-in** (empty, `.cloud`, `composer.ddev.site`) → update DB to the **resolved** URL above.
- If DB URL is **custom** (e.g. `https://composer.thebetaspace.com` or any other host) → **never** overwrite.

Legacy `t3planetApiBaseUrl` in ext_conf / `settings.php` is read **once** when the DB field is empty and the value is a known built-in, then synced like other built-ins.

---

## Typical setups

### Your local DDEV (local composer API)

`.ddev/config.yaml`:

```yaml
web_environment:
  - T3PLANET_CREDITS_API_BASE_URL=https://composer.ddev.site
```

→ Calls **composer.ddev.site**.

### Staging / shared staging API

```yaml
web_environment:
  - T3PLANET_CREDITS_API_BASE_URL=https://composer.thebetaspace.com
```

→ Calls the host from the env var (any URL works).

### Production customer sites

No env:

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
