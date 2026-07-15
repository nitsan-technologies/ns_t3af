# ns_t3af — Core Context

*Always load with `context/principles.md`.*

---

## Identity

| Field | Value |
|---|---|
| Extension key | `ns_t3af` |
| Composer | `nitsan/ns-t3af` |
| Vendor | NITSAN |
| Role | Master AI extension for T3Planet child products |
| Canonical repo | https://github.com/nitsan-technologies/ns_t3af/ |
| TER | https://extensions.typo3.org/extension/ns_t3af |

**Vision:** Single install for provider connectivity, governance, MCP, credits proxy, and shared `AiServiceInterface` for `ns_t3ai`, `ns_t3cs`, `ns_t3aa`, etc.

**Source of truth:** GitHub only (development + releases). Agent context lives in this package (`AGENTS.md`, `context/`).

---

## Compatibility (shipped)

| | Floor |
|---|---|
| PHP | `>=8.2 <9` |
| TYPO3 | `^12.4 \|\| ^13.4 \|\| ^14.3` |
| Version | `1.0.0` (`ext_emconf.php` / TER) |

Match `composer.json` + `ext_emconf.php` — do not invent a separate “v2.x” floor unless those files change.

---

## Tooling

From the package root (isolated `.Build/vendor/`). Host folder is often `packages/ns-t3af/` (Composer `nitsan/ns-t3af`; TYPO3 key `ns_t3af`):

```bash
composer install
composer test
composer test:functional
composer stan
composer cs:check
```

Monorepo DDEV (from distribution root):

```bash
ddev start
ddev composer install
ddev exec bash -c "cd packages/ns-t3af && composer test"
ddev exec typo3 cache:flush
```

---

## Code conventions

- `declare(strict_types=1);` in all new PHP files
- Namespace: `NITSAN\NsT3AF\…`
- Conventional commits: `feat|fix|docs|refactor|test(scope): message`
- PHPStan: level 3 global; new `Provider/`, `Api/`, `Service/AiService` target level 8
- Architecture tests (phpat): `Tests/Architecture/ArchitectureTest.php`

---

## Reference extensions (monorepo siblings)

Borrow patterns with **GPL attribution** in headers, `Documentation/Credits.rst`, and commits.

| Package | What we study |
|---|---|
| `b13/aim` | Middleware, governance, `#[AsAiProvider]`, dashboards |
| `t3x-nr-llm` | Three-tier provider, vault keys, phpat, PHPStan 10 |
| `typo3_mcp_server` (hn) | Workspace-safe MCP |
| `typo3-mcp-server` (marekskopal) | OAuth 2.1, broad tool surface, audit log |
| `lochmueller/seal_ai` | Symfony AI bridge discovery |

Porting rules: read LICENSE first; adapt to `tx_nst3af_*` prefix; document choices in `Documentation/Adr/`.

---

## Extension configuration (non-provider)

LLM keys/models live in **Providers** UI (`tx_nst3af_provider`). `ext_conf_template.txt` retains only:

- DeepL / Google translation
- Basic auth, API quota notifications
- OpenAI admin usage key
- T3Planet credits fallback
- MCP server OAuth/rate limits

See `context/features/providers.md` for migration from legacy ext_conf.
