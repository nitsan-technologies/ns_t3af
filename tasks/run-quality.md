# Run Quality Checks

From `packages/ns_t3af/`:

```bash
composer install    # first time; vendor at .Build/vendor/
composer test
composer stan
composer cs:check
composer test:functional   # when DB/integration paths touched
```

From monorepo via DDEV:

```bash
ddev exec bash -c "cd packages/ns_t3af && composer test"
ddev exec bash -c "cd packages/ns_t3af && composer stan"
ddev exec typo3 cache:flush
```

## When to run what

| Change | Minimum |
|---|---|
| PHP class logic | `composer test` + `composer stan` |
| Provider / adapter | + manual connection test in backend |
| TCA / SQL | `composer test:functional` if tests exist |
| Context/docs only | no PHP required |

## CI

`.github/workflows/` — PHP 8.x matrix. Match local PHP before debugging CI-only failures.

## Architecture tests

phpat rules run via `composer stan` (included in phpstan.neon). See `Tests/Architecture/ArchitectureTest.php`.
