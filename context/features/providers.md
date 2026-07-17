# Feature — AI Providers

**Status:** Done (v2.x)  
**Deep spec:** [`FEATURE_AiProviderManagement.md`](../specs/FEATURE_AiProviderManagement.md)  
**User docs:** `Documentation/Developer/CustomProviders.rst`, `Documentation/Configuration/Index.rst`

---

## What it does

- Backend **Providers** drawer: unlimited provider rows with encrypted API keys.
- Per-row: adapter type, endpoint, models, capabilities, temperature, default flag.
- Symfony AI bridges auto-discovered; built-in OpenAI-compatible HTTP adapter (`nst3af.openai_compatible`).
- Connection test persists `last_status*` on the row.

---

## Key paths

| Area | Path |
|---|---|
| Table | `tx_nst3af_provider` (`ext_tables.sql`) |
| Model | `Classes/Domain/Model/Provider.php` |
| Repository | `Classes/Domain/Repository/ProviderRepository.php` |
| Runtime | `Classes/Service/AiService.php` |
| Registry | `Classes/Provider/AdapterRegistry.php` |
| Cipher | `Classes/Service/CredentialCipher.php` |
| Form/UI | `Classes/Service/ProviderFormService.php`, `ProviderController` |
| Drawer JS | `Resources/Public/JavaScript/provider-drawer.js` |
| Legacy bridge | `Classes/Service/ProviderLegacyConfigService.php` |

---

## Decisions locked

- Flat single table (not three-tier).
- API keys: sodium `crypto_secretbox`, prefix `enc:v1:`.
- Default provider: at most one `is_default = 1` row.
- Provider rows are not workspace-aware.
- `ext_conf_template.txt` no longer holds LLM keys — providers only.

---

## Do / Don't

**Do:**
- Inject `AiServiceInterface` from child extensions.
- Tag custom adapters with `nst3af.adapter` in the **child** `Services.yaml`.

**Don't:**
- Add provider API keys back to ext_conf.
- Import adapters from controllers (phpat blocks this).
- Return decrypted keys to the browser (mask only).

---

## Verification

```bash
cd packages/ns_t3af && composer test && composer stan
```

Backend: AI Foundation → Providers → add row → test connection → set default.
