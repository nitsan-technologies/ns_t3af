# Implement Provider / Adapter Work

## Before coding

1. `context/features/providers.md`
2. `context/specs/FEATURE_AiProviderManagement.md` (locked decisions + file list)
3. `Documentation/Developer/CustomProviders.rst`

## Checklist

- [ ] Schema change? Update `ext_tables.sql` + TCA `tx_nst3af_provider.php`
- [ ] New adapter? Implement `AdapterInterface`, tag `nst3af.adapter`
- [ ] Symfony bridge? Check `AdapterCompilerPass` / discovery — may not need manual registration
- [ ] UI field? Update `ProviderFormService`, drawer partial, `locallang_mod_dashboard.xlf`
- [ ] AJAX route? `Configuration/Backend/AjaxRoutes.php` + `ProviderController`
- [ ] Cache flush on save? Provider model edit invalidates `nst3af_provider_models`
- [ ] Tests: unit test for form validation / migrator / adapter contract
- [ ] PHPStan clean on `Provider/`, `Api/`, `Service/AiService` paths
- [ ] Update `context/features/providers.md` if behavior changed

## Custom adapter in child extension

Adapter class lives in **child** package. Tag in **child** `Services.yaml` — not only in ns_t3af.

## Verification

```bash
composer test && composer stan
```

Backend: Providers → add → test connection → save → call via `AiServiceInterface` from a child ext.
