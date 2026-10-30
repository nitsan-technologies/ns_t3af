# F3 spike — per-caller generation mode

Phase 0 design spike for the T3AI Agent-first Reform. Locked product outcome: T3AI's own
prompts/brand profile/glossary must ALWAYS apply on every agent Fix, regardless of the global
MCP mode setting, while external MCP clients keep following the global setting. Only the
mechanism is open. Investigation + design only — nothing implemented.

## Current state

### What "mode" actually means — this is not what the task brief implied

`mcpMode` is a two-value (`native` / `context`) setting that governs **dual-mode MCP tool
schema and content sourcing**, not a "whose prompt/brand/glossary wins" switch directly — but the
two line up:

- `McpModeResolver::getMode()` (`Classes/Mcp/Service/McpModeResolver.php:38-50`): if
  `McpModeOverride::current()` (`:40`) is non-null, return it; otherwise read the extension
  configuration's `mcpMode` key (`:45`, default `context`). `isNative()`/`isContext()` (`:52-60`)
  are the two convenience booleans everything else branches on.
- `McpModeOverride` (`Classes/Mcp/Service/McpModeOverride.php`) is a **request-scoped stack** of
  exactly those two literal strings: `push()`/`pop()`/`current()` (`:35-62`), plus a
  `run(string $mode, callable $callback)` helper (`:69-77`) that pushes, runs, and pops in a
  `finally` — so nesting is safe, and the override only lives for the duration of one callback.
  Its own docblock (`:22-27`) already states the real intent: "so DualMode tools can preview in
  native mode and apply in context mode without mutating Extension Configuration."
- What the two modes actually change, confirmed in `ns_t3ai\Classes\Mcp\Support\McpDualModeContentResolver`
  (`../ns_t3ai/Classes/Mcp/Support/McpDualModeContentResolver.php:18-142`, read-only): if the
  caller already supplied a value, use it; otherwise, **only in native mode** call the `$generate`
  callback (T3AI's own internal AI generation — brand profile, glossary, prompts, the lot) and
  throw if it comes back empty; **in context mode with nothing supplied, throw** ("value is
  required in context mode. In native mode omit it to generate internally.", `:36-39`). So:
  **native mode = T3AI generates the content itself, server-side, with its own full pipeline.
  Context mode = the caller must already have generated/supplied the content; T3AI's generation
  path is not invoked at all for that call.** `McpToolSchemaAugmenter::applyDualModeContentRequirements()`
  (`Classes/Mcp/Service/McpToolSchemaAugmenter.php:477-511`) mirrors this at the schema level:
  context mode marks the content params `required`; native mode strips them from `required` and
  additionally exposes an `aiProvider` schema param only in native mode
  (`resolveIncludeAiProvider()`, `:456-471`, native branch `:467`).

### The Agent dispatcher already forces the correct mode, unconditionally, for every tool it calls

This is the central finding. Every code path inside the backend Agent that actually invokes a
dual-mode MCP tool already wraps that call in an explicit `McpModeOverride::run()`/`invokeWithMode()`,
**independent of which extension owns the tool**:

- `McpPlaygroundService::invokeWithMode()` (`Classes/Mcp/Service/Backend/McpPlaygroundService.php:256-264`)
  is a thin wrapper: `return $this->modeOverride->run($mode, fn() => $this->invoke(...));`.
- Read-only auto-run tool calls inside an agent turn:
  `AgentToolTurnProcessor::processNativeReadTurn()` calls
  `$this->playgroundService->invokeWithMode($tool['name'], $arguments, McpModeResolver::MODE_NATIVE)`
  unconditionally (`Classes/Agent/Service/AgentToolTurnProcessor.php:367-371`).
- **The draft/generation step for Write+Previewable+DualMode tools — exactly the "Fix" case** —
  goes through `McpPlaygroundService::preview()`, which **already hardcodes**
  `$this->modeOverride->run(McpModeResolver::MODE_NATIVE, ...)` at
  `Classes/Mcp/Service/Backend/McpPlaygroundService.php:222-223`, called from
  `AgentToolTurnProcessor::processPreviewToolTurn()` at `:496`
  (`Classes/Agent/Service/AgentToolTurnProcessor.php:496`), itself only reached for
  `severity === Write/Destructive && isDualMode && isPreviewable`
  (`AgentToolTurnProcessor.php:198-204`).
- The literal-apply step (editor already edited/confirmed exact field values on a preview card)
  forces the opposite mode on purpose:
  `AgentWriteService::applySuggestions()` → `invokeWithMode($toolName, $arguments,
  McpModeResolver::MODE_CONTEXT)` (`Classes/Agent/Service/AgentWriteService.php:645-649`) — correct,
  because at apply time the content is already finished; re-invoking native generation would
  silently discard the editor's edits.
- Plain write/plan tool calls that are **not** dual-mode-previewable
  (`AgentToolTurnProcessor::processWriteToolTurn()`, `:944-1210`) never touch
  `McpModeResolver`/`McpModeOverride` at all — they call `AgentToolPlanResolver::plan()`
  (`Classes/Agent/Service/AgentToolPlanResolver.php:47`), which reflects into the tool handler
  class directly for a deterministic DataHandler-style diff, bypassing the MCP dual-mode schema
  layer entirely. Mode is structurally not applicable there.
- Precedent from the child extension side: `ns_t3ai\Classes\Mcp\Service\McpPageTranslationService`
  (read-only) already calls `$this->modeOverride->run(McpModeResolver::MODE_NATIVE, fn() =>
  $this->translateAll(...))` at `../ns_t3ai/Classes/Mcp/Service/McpPageTranslationService.php:156-159`
  — i.e. `ns_t3ai` itself already reaches into `ns_t3af`'s `McpModeOverride` to force native mode
  for its own bulk-translate pipeline, confirming this exact mechanism is the one child
  extensions are already expected to use.

### Where the global setting actually still applies

`McpModeResolver::getMode()` only falls through to the extension-configuration value
(`:45`) when the override stack is empty (`:40-43`). The only call paths that never push an
override are requests that never go through `AgentAjaxController`/`AgentToolTurnProcessor`/
`AgentWriteService` at all — i.e. a genuine external MCP client (Claude Desktop, etc.) hitting
`ns_t3af`'s MCP server endpoint directly. That path is a structurally different entry point, not
a branch inside the Agent dispatcher.

## Design

### The mechanism the task asks for already exists — the gap is narrower than "per-caller mode"

Given the above, **there is no missing "does the global setting get bypassed" mechanism to
build** — `McpModeOverride::run()` already bypasses it completely, nests safely, and is already
used by both `ns_t3af`'s own Agent code and by `ns_t3ai` directly. What F3 actually needs is much
smaller:

1. **No new interface/attribute on tools.** The distinguishing signal for "should this call
   ignore the global setting" is not "which extension owns the tool" — it is "did this call
   originate from the backend Agent (`AgentAjaxController` → `AgentToolTurnProcessor`/
   `AgentWriteService`) or from the standalone MCP server." That distinction is already made
   structurally by which controller/service handles the request, not by anything the tool itself
   declares. A per-tool marker interface would be solving a problem the entry points already
   solve for free, and would be actively wrong if read literally: an attribute like "this tool is
   always-native" would also force native mode for a hypothetical external MCP client calling
   that *same* tool through the public MCP endpoint — which contradicts "external MCP clients
   keep following the global setting."
2. **What Phase 2 actually has to do for F3** is make sure every *new* T3AI generation tool that a
   Fix button drives is correctly classified as `severity = Write` + implements whatever
   `*Previewable*`/`McpDualModeContentToolInterface` markers `isDualMode`/`isPreviewable` key off
   of in `AgentToolTurnProcessor` (`:198`), so it is routed into the already-native-forced
   `processPreviewToolTurn()` → `preview()` path, exactly like `t3ai_generate_all_seo` and friends
   already are. This is tool classification inside `ns_t3ai`, not new `ns_t3af` plumbing.
3. **The one legitimate `ns_t3af`-side design question**: today `preview()`'s
   `MODE_NATIVE` override at `McpPlaygroundService.php:222-223` is unconditional for *every*
   Write+Previewable+DualMode tool, with no opt-out. That happens to be exactly what T3AI wants by
   default, and is also exactly what every other dual-mode tool wants by default (there is no
   known counter-example in the codebase today). If Phase 2 ever needs a tool that, even when
   invoked through the Agent, should still respect the caller's/global mode (e.g. a future
   third-party integration that wants Agent and MCP-server behaviour to be identical), the additive
   seam is a **narrow, optional escape hatch** — not a new generic "declare your mode" interface —
   e.g. a marker interface a tool handler can implement to opt *out* of the Agent's automatic
   native-forcing, checked once at the two existing call sites
   (`AgentToolTurnProcessor.php:367`, `McpPlaygroundService.php:222`) via `is_subclass_of()`, the
   same pattern `McpToolSchemaAugmenter` already uses for `McpNonAiToolInterface`/
   `McpExternalContentToolInterface` (`McpToolSchemaAugmenter.php:458-464`). Default (no marker) =
   today's behaviour, unchanged.

### Recommended mechanism, concretely

Do not add a mechanism in Phase 0/Phase 2-start unless a concrete tool needs the opt-out from
point 3. If/when one does: add `McpAgentRespectsGlobalModeInterface` (empty marker, same shape as
the existing `Mcp*ToolInterface` markers already in the codebase) in `ns_t3af`; check it at the
two hardcoded `MODE_NATIVE` call sites before pushing the override; leave `applySuggestions()`'s
`MODE_CONTEXT` push alone (that one is about "don't regenerate what the editor already finalized,"
unrelated to whose prompts apply).

## Constraints satisfied

- **T3AI's prompts/brand/glossary always apply on Fix**: already true today for any T3AI tool
  that is Write+Previewable+DualMode, by virtue of `preview()`'s unconditional `MODE_NATIVE` push
  — confirmed by `McpDualModeContentResolver`'s native-only `$generate()` callback, which is where
  T3AI's brand/glossary/prompt pipeline actually executes.
- **External MCP clients keep following the global setting**: already true, because such clients
  never execute inside `AgentAjaxController`/`AgentToolTurnProcessor`/`AgentWriteService`, so
  `McpModeOverride::current()` is `null` for them and `McpModeResolver::getMode()` falls through
  to the extension-configuration value.
- **No new `ns_t3af` → `ns_t3ai` dependency**: nothing above requires `ns_t3af` to know about
  `ns_t3ai`; the existing override mechanism is extension-agnostic, and the one proposed opt-out
  marker (if ever needed) is a generic interface any tool owner could implement, same as the
  existing `McpNonAiToolInterface` pattern.

## Open risks

1. **Scheduler/async hand-off is unverified.** `AgentSchedulerHandoff`
   (`Classes/Agent/Service/AgentSchedulerHandoff.php`) exists for long-running Fix-style work
   handed off from the Agent chat to a scheduler task. `McpModeOverride`'s stack is per-request/
   in-memory (confirmed: plain array property, no persistence,
   `McpModeOverride.php:33`) — it cannot survive a hand-off to a separate scheduled PHP process. A
   grep of `ns_t3ai`'s scheduler tasks (`BulkMassSeoOptimizeTask`, `BulkPageTranslateTask`,
   `BulkPageReTranslateTask`, `AbstractBulkTranslateSchedulerTask`) found **no** reference to
   `McpModeOverride`/`McpModeResolver` at all, which most likely means those tasks call T3AI's
   internal generation services directly rather than through the dual-mode MCP tool layer (making
   mode moot for them structurally, same as `processWriteToolTurn`'s plan path) — but this was not
   traced down to the actual LLM call inside those tasks, so Phase 2 should confirm it before
   relying on it, specifically for any *new* Fix flow that hands off to a scheduler task rather
   than resolving synchronously inside one Agent turn.
2. **The unconditional `preview()` override has no current opt-out**, as described in Design
   point 3. Not a risk for F3 as scoped (T3AI wants the default), but worth flagging so Phase 2
   doesn't block on building an interface nobody needs yet.
3. **Tool classification, not mechanism, is the real remaining work.** If a new T3AI Fix tool is
   *not* marked `Previewable` (or isn't recognized as dual-mode), it falls into
   `processWriteToolTurn()`'s `ToolPlanResolver` path, which requires `supportsPlanning($toolName)`
   to return true (`AgentToolTurnProcessor.php:947`) or the turn errors out ("plan not supported")
   before any mode question even arises. This is an easy way for a new Fix tool to silently miss
   the native-mode guarantee by being mis-classified rather than by any `ns_t3af` gap — worth a
   checklist item in the `ns_t3ai` rebuild, not a `ns_t3af` code change.

## Recommendation (implementation order for Phase 2)

1. Treat F3 as **mostly already solved**: write a regression test in `ns_t3af` asserting that
   `McpPlaygroundService::preview()` always runs its callback with `McpModeResolver::getMode() ===
   'native'` regardless of the `mcpMode` extension-configuration value (locks in the existing
   guarantee so a future refactor can't accidentally wire `preview()` through plain `getMode()`).
2. In `ns_t3ai`: audit every new Fix-triggered generation tool to confirm it is classified
   Write + Previewable + DualMode so it is routed through `processPreviewToolTurn()`/`preview()`,
   not through the plan-only path.
3. Verify (don't assume) the scheduler hand-off question (Open risk 1) for any Fix flow that can
   defer to a queue rather than finish inside the synchronous turn.
4. Only build the opt-out marker interface from Design point 3 if/when a concrete tool actually
   needs Agent-invoked calls to respect the global/caller mode instead of always-native — do not
   build it speculatively.
