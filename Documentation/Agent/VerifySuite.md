# AI Agent verify suite

Two layers validate the approved behaviour contract:

1. **Design regression (prototype)** — run from the handover package:
   ```bash
   npm install --prefix /tmp/vt jsdom --silent
   NODE_PATH=/tmp/vt/node_modules node packages/t3af-ai-agent-handover-2026-08-17/products/t3af/specs/02-ai-agent/verify.cjs
   ```
   Expect **47/47**. This asserts the HTML prototype only.

2. **Implementation contract (PHPUnit)** — run from `packages/ns_t3af`:
   ```bash
   composer test -- Tests/Unit/Agent/
   ```
   Covers severity resolution, entitlement mirroring, draft service, behaviour contracts, tool selection, structural router, preview draft isolation, and suggestions apply.

## Phase 3–5 verify (routing / preview / apply)

| Phase | What to check | Command / place |
|---|---|---|
| 3 Tool selection | Core set, `find_tools` ranking, argument validation, group policy | `composer test -- Tests/Unit/Agent/AgentToolSelectionTest.php Tests/Unit/Agent/AgentToolGuardsTest.php Tests/Unit/Agent/AgentRunnerTest.php` · warm index `t3af:agent:index-tools` |
| 4 Router | Slash / `@` / chips structural; NL → `AgentRunner` only | `Tests/Unit/Agent/AgentTurnRouterTest.php` |
| 5 Preview apply | Suggestions card → `applySuggestions` exact values | BE: DualMode SEO/file tool → card → Apply · `AgentWriteServiceSuggestionsTest` |
| 6 Eval | Fixture replay without API keys | `vendor/bin/typo3 t3af:agent:eval` · docs `Documentation/Agent/Eval.md` |

Docs: `Routing.md`, `PreviewApply.md`, `Eval.md`.

Functional/E2E coverage against a live TYPO3 backend should extend `Tests/Functional/Agent/` in a follow-up when Playwright infrastructure is wired for this distribution.
