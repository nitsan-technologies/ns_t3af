# Feature — AI Context (Brand Context Profiles)

**Status:** Done (profiles CRUD, runtime injection, dashboard bar, wizard step 6, per-feature overrides, auto-research, document upload)  
**Route:** `t3af_dashboard.ai_context` (`Configuration/Backend/Modules.php`)  
**Deep plan (historical):** `Documentation/Architecture/AiContextImplementationPlan.md`

---

## What it does

- **Brand personality layer** for AI-generated content: per-site **Brand Context Profiles** define identity, voice, audience, rules, keywords, compliance, and optional document extract.
- **AI Context tab** — create/edit/delete profiles; set one **site default**; completeness ring + section checklist on profile cards.
- **Runtime injection** — `BeforeProviderRequestEvent` listener replaces `{brand_context}`, `{brand_name}`, `{target_persona}`, etc. in prompts and prepends assembled brand block to system prompt when empty.
- **Dashboard summary bar** — horizontal widget (identity + completeness ring + section pills + **Manage Contexts**), not a warning callout.
- **Quick Setup wizard — Step 6 of 8** — optional Brand Name, Industry, Tone Tags, Voice Description → creates a starter default profile.
- **Per-feature profile override** — dropdown in selected **AI Features** drawers only (not global Feature Toggles).

---

## Key paths

| Area | Path |
|---|---|
| Module controller | `Classes/Controller/Backend/BrandContextController.php` |
| AJAX (research, documents) | `Classes/Controller/Backend/BrandContextAjaxController.php` |
| CRUD / list DTOs | `Classes/Service/BrandContextService.php` |
| Table / model | `tx_nst3af_brand_context_profile`, `Classes/Domain/Model/BrandContextProfile.php` |
| Repository | `Classes/Domain/Repository/BrandContextProfileRepository.php` |
| Completeness (7 sections) | `Classes/Service/BrandContextCompletenessCalculator.php` |
| Placeholders + `{brand_context}` map | `Classes/Service/BrandContextPlaceholderService.php` |
| Full `{brand_context}` block | `Classes/Service/BrandContextAssembler.php` |
| Runtime resolve (default + override) | `Classes/Service/BrandContextResolver.php` |
| Prompt injection | `Classes/EventListener/BrandContextPromptInjectionListener.php` |
| Auto-research | `Classes/Service/BrandContextResearchService.php` |
| Document extract | `Classes/Service/BrandContextDocumentExtractor.php` |
| Dashboard bar presenter | `Classes/Service/BrandContextDashboardPresenter.php` |
| AI Foundation setup checklist + module health | `Classes/Service/SetupChecklistService.php`, `DashboardModuleHealthService.php` |
| AI Features override UI | `Classes/Service/BrandContextFeatureSettingsService.php` |
| Feature settings AJAX gate | `Classes/Controller/Backend/FeatureSettingsController.php` |
| Wizard create | `Classes/Controller/Backend/WizardController.php` → `BrandContextService::createWizardProfile()` |
| Templates / partials | `Resources/Private/Templates/Module/AiContext.html`, `Resources/Private/Partials/AiContext/` |
| Dashboard partial | `Resources/Private/Partials/Module/Dashboard/AiContextOverview.html` |
| JS / CSS | `Resources/Public/JavaScript/ai-context.js`, `Resources/Public/Css/module/ai-context.css`, `dashboard.css` |
| Wizard JS | `Resources/Public/JavaScript/setup-wizard.js` (step 6) |

---

## Site scoping

Same pattern as AI Providers / AI Prompts / AI Features:

- Profiles stored at site root **`pid`** via `SiteStorageContext`.
- Page-tree **`id`** required in module URLs and drawer saves.
- One profile marked **`is_default = 1`** per site storage folder.

---

## Profile data model

JSON columns on `tx_nst3af_brand_context_profile`:

| Field | Notes |
|---|---|
| `tone_tags` | Max 5 from `BrandContextProfile::TONE_TAGS` |
| `personas` | Max 3 objects: `name`, `level` (Beginner \| Intermediate \| Expert), optional `role`, `painPoints`, `caresAbout` |
| `content_rules` | Max 10: `{direction: always\|never, text}` |
| `forbidden_words` | Max 20 strings |
| `keywords` | Max 15 strings |
| `competitors` | Max 5 strings |
| `document_extract` | Merged text from uploaded docs (max 3 files, 10 MB each) |

**Completeness sections (7):** identity, voice, audience, rules, language, keywords, sample.

---

## UI — AI Context tab

- **Profile grid** — `card-size-small`, 4 columns ≥1200px; default profile highlighted; footer actions: Edit \| Set Default \| Delete.
- **Profile drawer** — slide-in form with completeness ring, auto-research from website URL, document upload zone.
- **Target Audience** — collapsible persona cards:
  - **Collapsed:** icon, name (or “Unnamed persona”), level label, chevron, remove.
  - **Expanded:** Persona Label, Role / Title, Expertise Level, Key Pain Points, What They Care About.
  - New personas default level **Intermediate**; max 3 personas.
- **Placeholder bar** — lists tokens available in AI Prompts.

---

## UI — Dashboard summary bar

Partial: `Module/Dashboard/AiContextOverview.html`

Horizontal card when site is resolved:

1. **Identity** — “AI CONTEXT” kicker, default brand name, industry.
2. **Completeness** — ring + “X of 7 sections”.
3. **Section pills** — Business Identity, Brand Voice, Target Audience, … (green when complete).
4. **Manage Contexts** — link to AI Context tab.

Presenter: `BrandContextDashboardPresenter::build()`.

---

## Runtime placeholders

Declared in `BrandContextService::PLACEHOLDERS`:

| Token | Source |
|---|---|
| `{brand_context}` | Full assembled block (`BrandContextAssembler`) |
| `{brand_name}` | Profile brand name |
| `{brand_voice}` | Tone tags + voice notes |
| `{target_audience}` | All personas (`Name (Level)`; role appended when set) |
| `{target_persona}` | **First** persona only |
| `{content_rules}` | Always/Never rule lines |
| `{keywords}` | Comma-separated |
| `{forbidden_words}` | Comma-separated |
| `{language}` | Localized language label |
| `{competitors}` | Comma-separated |
| `{compliance_notes}` | Plain text |

**Skip injection:** pass `extra: ['skipBrandContext' => true]` on `AiOptions` (used by brand research itself).

**Resolution order** (`BrandContextResolver::resolveForPageId($pageId, $extensionKey, $scope)`):

1. If `extensionKey` + `scope` set → per-feature override `brandContextProfileUid_<scope>` from that extension's site `settings_json`.
2. Else (or per-scope unset) → legacy extension-wide `brandContextProfileUid` (the pre-per-feature single value).
3. Else → site **default** profile.

`scope` is supplied by the caller via `AiOptions->extra['brandContextScope']` (ns_t3ai's `T3AiCompletionGateway` maps `featureKey` → `T3AiFeatureArea::drawerScope()`, only for `seo`/`page`/`content`). Unsupported scopes normalise away and fall back to the legacy value / site default.

**Two injection mechanisms** (both in `BrandContextPromptInjectionListener`):

1. **System block (always).** The assembled `=== BRAND CONTEXT ===` block (`BrandContextAssembler`) is injected as a `system` message into `AiOptions->extra['messages']` — or, when no chat messages are present, prepended to `systemPrompt`. This is the canonical path and runs for every brand-eligible request.
2. **Inline tokens (only where present).** `{brand_*}` / `{brand_context}` tokens are substituted in place wherever a prompt template contains them — both in the plain prompt string and inside `extra['messages']` contents.

> **Known redundancy (cleanup deferred):** several ns_t3ai `PromptContractRegistry` templates embed inline brand tokens (e.g. `Brand: {brand_name}. Voice: {brand_voice}. …`) in the user prompt. Because mechanism 1 already injects the full block as a system message, brand details appear **twice** per request (system block + inline). This is functionally harmless — the model receives consistent data — but costs a few extra input tokens and can leave cosmetic fragments from empty fields (e.g. `Avoid: .` when `{forbidden_words}` is empty; the assembled block skips empty fields, the inline copy does not). **Planned cleanup:** strip the inline brand tokens from the ns_t3ai templates and rely solely on the system block. Deferred — not yet done.

---

## AI Features — profile override (scope-gated)

**Do not** show the Brand Context dropdown on global cards (e.g. **Feature Toggles**, T3AA toggles, T3CS scopes).

**Show only** on these **ns_t3ai** AI Features drawer scopes (only features whose prompts use `{brand_*}` tokens):

| AI Features card | `settingsScope` |
|---|---|
| AI SEO | `seo` |
| AI Pages | `page` |
| AI Content | `content` |

**Excluded:** AI Translation and AI Media — their prompts contain no AI Context variables. Translation requests additionally set `extra['skipBrandContext']` in `T3AiCompletionGateway` so no brand block leaks into translations; media uses the image/TTS services (not the text gateway).

Gate: `BrandContextFeatureSettingsService::supportsScopeOverride($extensionKey, $scope)`.

- **Load:** `FeatureSettingsController::getAction()` prepends `renderOverrideSelect($storagePid, $extensionKey, $scope)` HTML only when scope is allowed; the dropdown shows the effective uid for that scope.
- **Save:** `brandContextProfileUid` in POST body + `scope` → `settings_json` key `brandContextProfileUid_<scope>` for **`ns_t3ai`** at current site storage pid.
- **Value `0`** — use site default profile at runtime.

Setting is **per feature scope** (`seo`, `page`, `content`). The legacy extension-wide `brandContextProfileUid` (written before per-feature support) still acts as the fallback for any scope without its own value, preserving older configurations.

**Profile cards** show a **"Used by"** row listing the AI Features linked to each profile, built from `BrandContextFeatureSettingsService::resolveAllScopeLinks()` + `getScopeLabels()` via `BrandContextService::buildListViewData()`.

> **AI Prompts drawer:** the brand-token badge list was removed — the drawer now shows an info note (`module.aiPrompts.drawer.brandContextInfo`) plus the **Manage in AI Context** link, since tokens are injected automatically at runtime.

---

## Routes

| Route | Method | Purpose |
|---|---|---|
| `ai_context` | GET | Profile list |
| `ai_context.create` | POST | Create profile |
| `ai_context.update` | POST | Update profile |
| `ai_context.delete` | POST | Delete profile |
| `ai_context.set_default` | POST | Set default flag |
| `nst3af_brand_context_research` | AJAX POST | Auto-research from URL |
| `nst3af_brand_context_extract_documents` | AJAX POST | Upload + extract documents |

Feature settings (includes override when scope allowed):

| Route | Purpose |
|---|---|
| `nst3af_feature_settings_get` | Load drawer HTML |
| `nst3af_feature_settings_save` | Save ext_conf + optional `brandContextProfileUid` |

---

## Quick Setup wizard (step 6)

- Fields: Brand Name, Industry, Tone Tags (3–5), Voice Description (optional).
- Skip allowed → no profile created.
- On finalize: `WizardController` → `BrandContextService::createWizardProfile()` (marks default when first profile on site).

---

## Do / Don't

**Do:**

- Extend persona JSON with optional fields backward-compatibly (decode preserves unknown keys).
- Use `SiteStorageContext` for all profile reads/writes.
- Keep dashboard bar + checklist + module health in sync when adding completeness sections.
- Add new override scopes only via `T3AI_BRAND_CONTEXT_SCOPES` + `supportsScopeOverride()`.

**Don't:**

- Put Brand Context profile picker on **Feature Toggles** or non-T3AI extension scopes.
- Remove T3AI Persona tab (separate product surface).
- Inject brand context when `skipBrandContext` is set on `AiOptions`.
- Store profiles outside site root pid.

---

## Verification

```bash
cd packages/ns_t3af
composer test && composer stan
ddev exec vendor/bin/typo3 cache:flush
```

Manual:

1. AI Foundation → **AI Context** → create profile, set default, confirm completeness ring updates.
2. **Target Audience** → collapse/expand persona cards; level shows Beginner / Intermediate / Expert (default Intermediate).
3. **Dashboard** → summary bar with pills + **Manage Contexts** (not yellow callout only).
4. **AI Features** → AI SEO / Pages / Content / Translation / Media → Brand Context dropdown present; **Feature Toggles** → dropdown absent.
5. Run AI generation with `{brand_name}` in prompt → placeholder replaced when default profile exists.
6. Override profile in AI Content settings → T3AI requests use override uid (when `extensionKey: ns_t3ai` on `AiOptions`).

---

## Related context

- Backend module tabs / AI Features drawer: `context/features/backend-module.md`
- TYPO3 backend UI patterns: `context/Typo3CoreBackendDesign.md`
- Architecture / event hook: `context/architecture.md` § AI Context
- AI Prompts (placeholder usage in prompts): `context/architecture.md` § AI Prompts
