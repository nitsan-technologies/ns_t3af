# F1 spike — agent-open API for child extensions

Phase 0 design spike for the T3AI Agent-first Reform. Goal: let a child extension (`ns_t3ai`
first, but not exclusively) open the existing AI Agent panel with context chips and a
pre-filled prompt for one specific finding, **additively**, with **zero** compile/run-time
dependency from `ns_t3af` on `ns_t3ai`, **no licence check**, and the brand profile staying
owned by `ns_t3af`. Investigation + design only — nothing implemented.

## Current state

### Server-side context resolution

- `NITSAN\NsT3AF\Agent\Context\AgentContextResolver::resolve()`
  (`Classes/Agent/Context/AgentContextResolver.php:45-94`) takes one untrusted
  `array $clientContext` and a `BackendUserAuthentication`, re-validates every page/record id
  against the user's permissions (`userCanReadPage()`, `:96-106`), and returns an immutable
  `AgentContext` DTO (`Classes/Agent/Context/AgentContext.php:27-58`). There is exactly **one**
  resolution path; nothing merges several sources today — `$clientContext` is already the
  single input, which matters for the additive design below.
- `AgentContextPresenter::present()` (`Classes/Agent/Context/AgentContextPresenter.php:62-112`)
  wraps the resolved `AgentContext` into `chips` (small key/value pills,
  `AgentContextPresenter.php:202-232`) and a `details` block consumed by
  `AgentContextPresenter::promptBlock()` (`:119-196`) — the "Current context" text block injected
  into the system prompt.
- Brand profile: `NITSAN\NsT3AF\Service\BrandContextResolver` (`Classes/Service/BrandContextResolver.php`).
  `AgentContextResolver::resolve()` already calls it at line 71
  (`$this->brandContextResolver->resolveDefaultForPageId($pageId > 0 ? $pageId : null)`), and the
  resolved `brandContextProfileUid`/`brandName` flow straight into `AgentContext` (lines 91-92) and
  from there into the `brand` chip (`AgentContextPresenter.php:226-227`) and the prompt block's
  "Brand: …" line. **The panel already has full access to the brand profile; nothing new is
  needed there.** `BrandContextResolver` also exposes `resolveForPageId($pageId, $extensionKey,
  $scope)` (`BrandContextResolver.php:43-62`) for an extension-scoped override, which
  `AgentContextResolver` does not currently use (it always calls the extension-agnostic
  `resolveDefaultForPageId()`) — available if Phase 2 ever wants a T3AI-specific override, but
  out of scope for F1 itself.
- `AgentStarterBuilder::choose()` (static, `Classes/Agent/Service/AgentStarterBuilder.php:127-148`)
  confirmed — it picks up to `MAX_STARTERS` (4) starter chips from a per-module `ORDER` table
  (`:70-77`) filtered by `fits()` (`:153-171`) against the resolved context array and by which
  tools are permitted. This is the mechanism that today decides "what starter chips fit this
  page/record" — conceptually adjacent to F1 but not the entry point itself.

### Client-side: how "what am I looking at" is actually produced

This is the part that matters most for F1 and is **not visible from the PHP side alone**:

- `resolveBackendContext()` (`Resources/Public/JavaScript/agent/context.js:168-188`) is pure DOM
  sniffing: active module route from `window.top.TYPO3.ModuleMenu.App.getCurrentModule()` or a
  `.modulemenu-action-active` element (`:21-38`), page id from the content iframe URL
  (`resolveContentIframeUrl()`, `:58-74`), language id from the Page module's `languages[n]=`
  query params (`:80-110`), and FAL storage/folder from the file-list module's `id` param
  (`:136-163`). **There is no server round-trip and no payload parameter here at all** — the
  panel infers context purely from the live backend DOM each time it needs it.
- On panel open, `restoreSession()` (`Resources/Public/JavaScript/agent/controller-sessions.js:247-308`)
  calls `resolveBackendContext()` (`:253`) and GETs `nst3af_agent_conversation`
  (`Configuration/Backend/AjaxRoutes.php:346-349` →
  `AgentAjaxController::conversationAction()`) forwarding **only `pageId` and `module`** as query
  params (`controller-sessions.js:255-256`) — language id, storage/folder, record focus, and brand
  are not sent at all at open time.
- On the server, `conversationAction()` (`Classes/Agent/Controller/AgentAjaxController.php:203-254`)
  calls `$this->resolveContext($request)` with **no** `$clientContext` argument (`:219`), and
  `resolveContext()` (`AgentAjaxController.php:1658-1675`) only reads `pageId`/`module` from
  query/body in that case — everything else defaults to empty/zero. So the context used to build
  the first greeting + starters on open is deliberately thin (module + page only); record/language/
  brand richness only shows up once a **turn** is submitted.
- On turn submit, `submitTurn()` (`Resources/Public/JavaScript/agent/controller-turn.js:58-103`)
  builds `body.context = { ...resolveBackendContext(), ...this.context }` (`:85-94`) — fresh DOM
  state merged over the last context the server returned — and POSTs it to
  `nst3af_agent_turn`/`nst3af_agent_turn/stream`. `prepareTurn()` → `resolveContext($request,
  $body['context'])` (confirmed call site at `AgentAjaxController.php:1470`) is where that payload
  finally reaches `AgentContextResolver::resolve()` as `$clientContext`.
- **Single entry point today**: the only DOM hook that opens the panel is
  `data-nst3af-agent-open`, used in exactly one template,
  `Resources/Private/Templates/Agent/ToolbarItem.html:8`. The click handler is a **document-level
  delegated listener** registered once in `bindEvents()`
  (`Resources/Public/JavaScript/agent/controller-chrome.js:30-39`): `target.closest('[data-nst3af-agent-open]')`
  → `this.open(target.closest(...))`. `open(opener)` (`controller-chrome.js:511-579`) takes the
  clicked element only to remember where to return focus (`this.lastFocus`, `:521`) — it reads no
  other data from the opener and accepts no payload of any kind. `boot()`
  (`Resources/Public/JavaScript/agent.js:396-400`) runs once per backend page load, and the toolbar
  item's own comment explains why (`Classes/Agent/Backend/ToolbarItems/AgentToolbarItem.php:58`:
  "agent.js + boot() are registered once per backend page... Loading the module again from the
  toolbar races TYPO3 core imports").
- Panel shape (confirmed, staying as-is): fixed, right edge, default `800px`
  (`Resources/Public/Css/module/agent.css:55-59,125`, responsive bands at `:128-141`), full
  backdrop scrim (`:43-46`).
- Access gate: `AgentAvailabilityService::shouldRenderUi()`
  (`Classes/Agent/Service/AgentAvailabilityService.php:69-72`) = group permission
  (`nst3af:agent_enabled`, `:31,35-47`) AND not user-hidden (`:49-57`). **No licence/credits check
  anywhere in this class or in `AgentToolbarItem::checkAccess()`
  (`Classes/Agent/Backend/ToolbarItems/AgentToolbarItem.php:47-50`).** Confirms there is nothing
  licence-shaped on this surface to accidentally extend.

## Design

### The real additive hook is not `data-nst3af-agent-open` by itself

`document.addEventListener('click', ...)` in `controller-chrome.js:30` is bound against
whichever `document` `agent.js` executes in. The toolbar item renders in the backend's top-level
chrome; TYPO3 backend modules (where `ns_t3ai`'s scan/report/fix tabs will live) render inside
`#typo3-contentIframe` — a **different `document`**. Click events do not cross an iframe boundary,
so a `data-nst3af-agent-open` button rendered by `ns_t3ai` inside its own module would **not**
reach this listener at all. See "Open risks" below — this is the first thing Phase 2 must solve,
and it changes the shape of the API from "just add a data attribute" to "cross-frame message".

### Proposed shape (additive, two layers)

1. **A tiny, additive JS bridge on the top backend document**, exported from `agent.js` instead of
   kept module-local: e.g. `window.top.NsT3afAgent = { openWithContext(payload) {...} }`, built
   next to (not replacing) today's `controller.open(opener)`. `ns_t3ai`'s Fix button (running
   inside the content iframe) calls `window.top.NsT3afAgent?.openWithContext({ chips, prompt,
   context })` directly — same-origin backend iframes already support this (`resolveModuleRoute()`
   does the same `window.top` reach-through today, `context.js:23`). No TYPO3 Signal/Slot, no
   `ns_t3af` dependency on `ns_t3ai` — `ns_t3af` only publishes a function signature and consumes
   whatever shape of payload it is given defensively. A `postMessage` fallback can be layered in
   later for genuinely cross-origin cases, but same-origin `window.top` is enough for a TYPO3
   backend.
2. **A new DTO, `AgentOpenContext`** (new class, e.g.
   `Classes/Agent/Context/AgentOpenContext.php`): `chips: list<array{key,label,value}>`,
   `promptText: string`, and the same shape of hint fields `AgentContextResolver::resolve()`
   already accepts (`pageId`, `record`, `languageId`, …). This is exactly the untrusted
   `$clientContext` shape AgentContextResolver already takes — no new resolution concept, just a
   named carrier for it plus the two new fields (`chips` override, `promptText`).
3. **`openWithContext(payload)` on the JS side** stores `payload.context` as `this.pendingOpenContext`
   and calls the existing `open(opener)` unchanged; `open()` gains one new, optional step: if
   `this.pendingOpenContext` is set, merge it into the `backendContext` object `restoreSession()`
   already builds (`controller-sessions.js:253`) before the first GET, and pre-fill
   `this.input.value` from `payload.promptText` (same composer field the "starter chip click"
   flow already fills, per `AgentStarterBuilder`'s own docblock,
   `Classes/Agent/Service/AgentStarterBuilder.php:30-31`). Consumed once, then cleared — so a
   plain hotkey/toolbar open right after behaves exactly as today.
4. **Server side merge as an override layer, not a new resolver.** `AgentContextResolver::resolve()`
   already takes one `$clientContext` array (`:45`) built by merging whatever the JS sends. The
   additive change is only in `AgentAjaxController::resolveContext()`
   (`AgentAjaxController.php:1658-1675`): when the incoming body/query carries the new
   `openContext` key, merge it into `$clientContext` with the **highest** precedence (last
   `array_merge()` operand, exactly the existing pattern at `:1663-1672`), so `resolve()` itself
   needs no change at all — "no payload" still produces the identical array it does today, byte
   for byte, because a missing `openContext` key means the merge is a no-op.

### Chips

`AgentContextPresenter::chips()` (`:202-232`) already builds its chip list from the *resolved*
`AgentContext`/`details`, driven by what's present (page/module/language/record/folder/brand/
workspace). The caller-supplied chips (e.g. "Page 42", "German") are not a competing resolver —
they are additional, **informational-only** chips the UI renders alongside the server-resolved
ones (new `chips` array on the open payload, rendered by `controller-chrome.js`'s existing chip
render helper, additively). They never override permission-checked values like `pageId`; the
*prompt text* is where the Fix-specific content actually lives, and that's just what gets written
into `this.input.value`, same text box the editor already types into and can edit before sending.

## Constraints satisfied

- **Additive / non-breaking**: `AgentContextResolver::resolve()` is untouched. The only touched
  method is `AgentAjaxController::resolveContext()`, and only to add one more optional
  `array_merge()` operand gated on a key that is absent today. A plain toolbar click or hotkey
  press sends no `openContext`, so the merge is `array_merge([...], [])` — a no-op — and the
  panel's behaviour (what opens, what starters show, what's pre-filled) is bit-for-bit what it is
  today.
- **Zero `ns_t3ai` dependency**: `ns_t3af` never references `ns_t3ai` classes, routes, or JS
  modules. It exposes a generic `AgentOpenContext` shape and a generic `window.top.NsT3afAgent.openWithContext()`
  function; `ns_t3ai` is the one importing/calling into `ns_t3af`'s surface, same direction every
  other child-extension integration in this codebase already uses (`AiServiceInterface`, MCP tool
  tags).
- **No licence check added**: confirmed above that `AgentAvailabilityService` and
  `AgentToolbarItem::checkAccess()` have none today; the new `openWithContext()` bridge reuses the
  exact same `shouldRenderUi()` gate the toolbar item already uses (if the panel wouldn't render
  for this user via the toolbar, `openWithContext()` must refuse identically) — no new gate is
  introduced on either side.
- **Brand profile untouched/reused**: `BrandContextResolver` is not modified. F1's
  `AgentOpenContext` carries no brand field at all — brand resolution stays exactly where it is,
  inside `AgentContextResolver::resolve()` line 71, keyed off `pageId` only.

## Open risks

1. **Iframe boundary (the big one).** As detailed above, the only existing open-hook
   (`data-nst3af-agent-open` + document click delegation) cannot be reached from inside a backend
   module's content iframe at all. F1 cannot ship as "just add a data attribute to a button" — it
   needs the `window.top` bridge (or `postMessage`) described above. Any Phase 2 implementation
   that skips this and assumes DOM delegation will silently fail for exactly the T3AI use case it
   was built for.
2. **GET-time context is thin by design.** `conversationAction()` deliberately resolves only
   `pageId`/`module` on open (`AgentAjaxController.php:219`, `resolveContext` defaults at
   `:1663-1672`); everything else (record, language, brand override) only takes effect once a turn
   round-trips. If F1's "context chips visible immediately on open" requirement needs the *richer*
   chips (not just the two already sent today) to show before the first turn, `conversationAction()`'s
   call site needs the same optional merge as `resolveContext()`'s private helper — currently it
   calls `resolveContext($request)` with no second argument at all
   (`AgentAjaxController.php:219`), so that call site needs updating too, not just the generic
   helper signature.
3. **One pending-session already exists; a second "pending something" must not collide.**
   `controller-chrome.js` already tracks per-open state (`this.context`, `this.starters`,
   `this.messages`, reset at the top of `open()`, `:533-536`) and session-continuity logic
   (`lastSessionOpenOptions()`, `rememberLastSession()` in `controller-sessions.js`). A new
   `pendingOpenContext` must be cleared *after* the first successful `restoreSession()` call inside
   the same `open()` invocation, not on a timer — otherwise a stale Fix payload could leak into an
   unrelated later re-open (e.g. hotkey) if the editor closes and reopens quickly.
4. **Record focus has no existing wire at all.** Investigation found no automatic "focused tt_content
   record" signal anywhere in `resolveBackendContext()` — `record` only ever arrives via explicit
   attach-search (`recordsAction`) or as part of `this.context` once a turn has run. F1's "Page 42"
   example is easy (page id is already a first-class citizen of the resolver); a hypothetical
   "this exact content element" chip is new ground, not a re-use of an existing signal.
5. **Starter chips vs. Fix prompt are two different fill mechanisms today** — starters fill the
   composer via a dedicated click handler (`controller-chrome.js` starter branch, confirmed
   present at `:119-120` dispatching into `submitTurn`), while F1's pre-fill is proposed as a
   plain `this.input.value` assignment. Both end up as editable composer text, but they are not
   currently the same code path — Phase 2 should double check there's no starter-specific state
   (e.g. `starterAction` threaded through `submitTurn(explicitTool, toolArguments, starterAction)`,
   `controller-turn.js:58`) that a Fix-originated prompt must avoid accidentally triggering.

## Recommendation (implementation order for Phase 2)

1. Add `AgentOpenContext` DTO in `ns_t3af` (pure data, no behaviour) mirroring the
   `$clientContext` shape `AgentContextResolver::resolve()` already accepts, plus `chips` and
   `promptText`.
2. Add the optional merge in `AgentAjaxController::resolveContext()` (and audit the
   `conversationAction()` call site at `:219` to decide whether GET-time context needs the same
   treatment — see Open risk 2).
3. Add `window.top`-exposed `openWithContext(payload)` next to (not replacing) the module-local
   `controller` in `agent.js`; store `pendingOpenContext`, consume-and-clear inside `open()`.
4. Render caller-supplied chips additively in whatever template/helper already renders
   `AgentContextPresenter::chips()`'s output; do not touch that method.
5. Write one functional test proving "toolbar click with no payload" produces an identical
   `AgentContext`/`chips`/`starters` triple before and after the change (the actual non-regression
   proof for "additive"), then one unit test for the new merge precedence.
6. Only after that: let `ns_t3ai` wire a Fix button to call
   `window.top.NsT3afAgent.openWithContext(...)` — this is `ns_t3ai`-side work, out of scope here.
