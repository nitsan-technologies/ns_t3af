---
name: nst3af-provider
description: Implement or fix AI provider registry, adapters, drawer UI, or ext_conf migration in ns_t3af.
---

Before coding, read:

1. `context/features/providers.md`
2. `tasks/implement-provider.md`

Key paths:

- Table: `tx_nst3af_provider`
- UI: `Classes/Controller/Backend/ProviderController.php`, `Resources/Private/Partials/Provider/`
- Runtime: `Classes/Service/AiService.php`, `Classes/Provider/AdapterRegistry.php`
- Custom adapters: tag `nst3af.adapter` in the **child** extension's `Services.yaml`

Do not:

- Store API keys in `ext_conf_template.txt` (providers use DB + `CredentialCipher`).
- Import adapters directly from controllers (phpat enforces registry indirection).

Deep spec: `context/specs/FEATURE_AiProviderManagement.md`
