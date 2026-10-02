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
- On provider edit, clear `last_status*` to `unknown` when connection-relevant fields change (`adapter_type`, `endpoint_url`, `api_key`, `model_id`, `embedding_model_id`, `api_version`). Cosmetic edits (title, pricing, toggles) keep the prior probe.
- When `adapter_type` changes, also clear `model_id` / `embedding_model_id` (and `api_version` when leaving Azure). Drawer JS clears those inputs on adapter change so a vendor model id is not kept as Custom.
- Changing a non-empty embedding model shows a TYPO3 confirm modal (trained-data warning) before applying.
- Embeddings cannot be unchecked while an embedding model is selected. The drawer restores the checkbox and shows a warning. Clear the embedding model first.
- Save requires at least one capability for normal adapters. Missing `capabilities[]` in POST (all boxes unchecked) is treated as none selected and rejected — it must not silently keep the previous CSV.
- Exception: adapters with `getDefaultCapabilities() === []` (DeepL Translate `ns_t3ai.deepl_translate`, Google Translate `ns_t3ai.google_translate`) may save with zero capabilities — they are translate-only and must not advertise chat/completion.
- Drawer save is AJAX (`provider-drawer.js`): validation errors re-render inside the open panel; do not rely on a full-page `providers.save` HTML response for the happy path.

**Don't:**
- Add provider API keys back to ext_conf.
- Import adapters from controllers (phpat blocks this).
- Return decrypted keys to the browser (mask only).
- Auto-run `testConnection()` on every save (latency / rate limits); require an explicit Test connection.
---

## Verification

```bash
cd packages/ns_t3af && composer test && composer stan
```

Backend: AI Foundation → Providers → add row → test connection → set default.
