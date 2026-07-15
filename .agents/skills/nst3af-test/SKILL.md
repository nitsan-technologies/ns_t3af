---
name: nst3af-test
description: Run ns_t3af quality checks (PHPUnit, PHPStan, cs:check) from packages/ns_t3af.
---

Run from `packages/ns_t3af/` after `composer install`:

```bash
composer test
composer test:functional   # when DB/integration relevant
composer stan
composer cs:check
```

From monorepo root via DDEV:

```bash
ddev exec bash -c "cd packages/ns_t3af && composer test"
ddev exec bash -c "cd packages/ns_t3af && composer stan"
```

Rules:

- Vendor lives at `.Build/vendor/` (not root `vendor/`).
- Fix PHPStan in new namespaces (`Provider/`, `Api/`, `Service/AiService`) before merging.
- Architecture tests: `Tests/Architecture/ArchitectureTest.php` (phpat via phpstan.neon).
