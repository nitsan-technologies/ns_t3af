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
   Covers severity resolution, entitlement mirroring, draft service, and behaviour contracts mapped from `verify.cjs` safety properties.

Functional/E2E coverage against a live TYPO3 backend should extend `Tests/Functional/Agent/` in a follow-up when Playwright infrastructure is wired for this distribution.
