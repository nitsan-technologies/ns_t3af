# Feature — AI Label (EU AI Act Article 50)

**Status:** Done (backend module UI, rule engines, origin capture, frontend renderer, evidence export)  
**Routes:** `Configuration/Backend/Modules.php` → `t3af_dashboard.ai_label.*`  
**Deep spec:** `context/specs/FEATURE_AiLabel.md`  
**User doc:** `Documentation/Configuration/AiLabel/Index.rst`  
**Developer doc:** `Documentation/DeveloperGuide/AiLabelIntegration/Index.rst`

---

## What it does

Records, confirms, and discloses AI-generated or AI-modified **media** and **text** for EU AI Act Article 50 compliance workflows. The backend module is a review dashboard — not a legal compliance guarantee.

| Layer | Responsibility |
|---|---|
| **Capture** | Every AI Foundation provider response gets a correlation id (`GenerationCaptureListener` → `OriginRecorder::capture`) |
| **Bind** | Child extensions attach captures to persisted records at save time (`AiLabelBindHelper`) |
| **Rules** | `MediaRuleEngine` / `TextRuleEngine` decide whether a visitor-facing label is shown |
| **Confirm** | A named backend user confirms a record version (`ConfirmationService` + version hash) |
| **Frontend** | `FrontendLabelRenderer` outputs EU icon + text badge on rendered media |
| **Dashboard** | Overview / Media / Texts / Settings tabs with filters, bulk actions, record drawer, evidence export |

**Coverage score** (`CoverageScoreService`) measures an internal checklist (EU icons, unbound generations). It is deliberately **not** called a compliance score.

---

## Backend module tabs

**Path:** `AI Foundation > AI Label`

| Tab | Purpose |
|---|---|
| **Overview** | Coverage card (collapsible details), media/text domain cards, awaiting-review callout, origin breakdown, system status |
| **Media** | FAL folder tree sidebar, filtered file list, row actions (edit drawer / confirm / clear) |
| **Texts** | Pages + `tt_content` + configurable tables, filters, row actions |
| **Settings** | Label position/size/wording, auto-confirm rules, applicable tables, folder defaults |

Shared chrome: scoreboard (4 KPI cards), sub-navigation (`btn-group`).

---

## Data model

TCA fields on `pages`, `tt_content`, `sys_file_metadata` (prefix `tx_nst3af_ailabel_*`):

| Field | Role |
|---|---|
| `involvement` | `not_reviewed`, `no_ai`, `ai_generated`, `ai_modified`, `origin_unknown`, `suggestion` |
| `labelling_mode` | `automatic`, `always`, `never` |
| `public_interest` | Text-only gate (Art. 50 public-interest topics) |
| `human_review` + `responsible_person` | Named reviewer (text suppression when complete) |
| `confirmed_by` / `confirmed_at` / `version_hash` | Person confirmation bound to content version |
| `recording_source` | e.g. `ns_t3ai`, `detected_upload`, `editor` |
| `exemption_reason` | Why labelling was skipped |
| `ai_system` / `ai_vendor` | Optional provenance metadata |

Extra tables:

- `tx_nst3af_ailabel_generation` — capture queue (correlation → bind)
- Cache `nst3af_ailabel_undo` — 10-minute bulk/single undo snapshots

Configurable extension tables via `EXTCONF['ns_t3af']['ailabelApplicableTables']`, `CollectApplicableTablesEvent`, and `ApplicableTableSchemaListener` DDL.

---

## Rule engines (summary)

**Media (`MediaRuleEngine`):** Human review does **not** suppress labels (Art. 50(4)). Respects pre-cutoff date (`ComplianceStringsService::applicationDate()`), labelling mode, confirmation.

**Text (`TextRuleEngine`):** Requires public interest. Named human reviewer suppresses label (`editorial_control`). Unnamed review → `editorial_control_incomplete`.

Every decision stores a `ReasonCode` enum value (machine-readable, exported in evidence).

---

## Child extension integration

| Extension | Integration status |
|---|---|
| **ns_t3ai** | **Covered** — pages/content/files via `AiLabelBindHelper` (always `ai_generated`); stock libraries skipped |
| **ns_t3aa** | **Covered** — generated media via `bindFileMetadata` (`ai_generated`); alt-text-only updates skipped |
| **ns_t3al** | **Not integrated** — blind spot in coverage when ext not loaded; dedicated translation workflows need future hooks |
| **Third party** | `AiLabelBindHelper` at save time (always `ai_generated`) or `AiLabelRecorderInterface` |

Capture is automatic for all AI Foundation provider calls; bind must happen when content is saved.

---

## Key paths

| Area | Path |
|---|---|
| Module controller | `Classes/Controller/Backend/AiLabelController.php` |
| Routes | `Configuration/Backend/Modules.php` (`ai_label`, `.media`, `.texts`, `.settings`, `.bulk`, `.undo`, `.record_edit`, `.record_save`, `.export`) |
| List/statistics services | `Classes/AiLabel/Service/AiLabel*Service.php` |
| Rule engines | `Classes/AiLabel/Service/MediaRuleEngine.php`, `TextRuleEngine.php` |
| Origin / capture | `Classes/AiLabel/Service/OriginRecorder.php`, `GenerationCaptureListener.php` |
| Bind helper (children) | `Classes/AiLabel/Service/AiLabelBindHelper.php` |
| Confirmation / undo | `Classes/AiLabel/Service/ConfirmationService.php`, `UndoCacheService.php` |
| Record drawer | `Classes/AiLabel/Service/AiLabelRecordDrawerService.php` |
| Frontend render | `Classes/AiLabel/Service/FrontendLabelRenderer.php`, `ViewHelpers/LabelViewHelper.php`, `RecordStateViewHelper.php`, `FileStateViewHelper.php` |
| Image overlay | `Resources/Private/Partials/FluidStyledContent/Media/Rendering/Image.html`, `Resources/Public/Css/frontend/ai-label.css` |
| Frontend state | `Classes/AiLabel/Dto/FrontendLabelState.php`, `FrontendLabelStateFactory.php`, `DataProcessing/RecordStateProcessor.php` (`nst3af-label`) |
| TypoScript | `Configuration/TypoScript/setup.typoscript`, static template + site set `nitsan/ns-t3af-label` |
| DataHandler hook | `Classes/AiLabel/Hook/DataHandlerAiLabelHook.php` |
| Compliance copy | `Configuration/BuildInputs/compliance-strings.json` → `ComplianceStringsService` |
| Templates | `Resources/Private/Templates/AiLabel/`, `Partials/AiLabel/` |
| CSS / JS | `Resources/Public/Css/module/ai-label.css`, `Resources/Public/JavaScript/ai-label.js` |
| TCA | `Configuration/TCA/Overrides/tx_tt_content_ailabel.php` (+ pages, sys_file_metadata) |
| Public API | `Classes/Api/AiLabelRecorderInterface.php` |
| Tests | `Tests/Unit/AiLabel/` |

---

## POST routes (bulk / drawer)

| Route | Method | Action |
|---|---|---|
| `ai_label.bulk` | POST | confirm, clear, no_ai, do_not_label, set_public_interest, assign_responsible |
| `ai_label.undo` | POST | Restore last bulk batch from cache |
| `ai_label.record_edit` | GET | Drawer HTML (AJAX) |
| `ai_label.record_save` | POST | Persist drawer fields |
| `ai_label.export` | GET | CSV/HTML evidence export |

Undo cache keys use underscores (`bulk_1`, `sys_file_metadata_43`) — TYPO3 cache identifiers must not contain `:`.

---

## Do / Don't

**Do:** Bind generations at every child-extension persistence point. Use `ComplianceStringsService` for user-facing compliance copy. Confirm labels in the module before treating records as reviewed.

**Don't:** Present coverage % as legal compliance. Auto-confirm without checking `AutoConfirmSettingsService` hold rules. Tag alt-text-only file metadata binds (`bindFileMetadata` skips when `$altTextOnly`).

---

## Verification

1. Backend → AI Foundation → AI Label → all four tabs load.
2. Generate content via ns_t3ai → record appears in Texts/Media with `recording_source` = `ns_t3ai`.
3. Confirm row → `confirmed_by` / `confirmed_at` set; frontend label appears when rules pass.
4. Edit drawer opens via pencil action; save persists involvement fields.
5. `composer test` — `Tests/Unit/AiLabel/` passes.
