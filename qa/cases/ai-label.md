# AI Label (EU AI Act Article 50) — cases

**Prefix:** `T3AF-LABEL`
**How it works:** `context/features/ai-label.md`
**Main code:** `Classes/Controller/Backend/AiLabelController.php`, `Classes/AiLabel/Service/AiLabelBindHelper.php`, `Classes/AiLabel/EventListener/GenerationCaptureListener.php`, `Classes/AiLabel/Service/OriginRecorder.php`, `Classes/AiLabel/Service/ConfirmationService.php`, `Classes/AiLabel/Service/MediaRuleEngine.php`, `Classes/AiLabel/Service/TextRuleEngine.php`, `Classes/AiLabel/Service/AutoConfirmSettingsService.php`, `Classes/AiLabel/Service/AiLabelBulkActionService.php`, `Classes/AiLabel/Service/UndoCacheService.php`, `Classes/AiLabel/Service/AiLabelRecordDrawerService.php`, `Classes/AiLabel/Service/AiLabelSettingsService.php`, `Classes/AiLabel/Service/EvidenceExportService.php`, `Classes/AiLabel/Service/FrontendLabelRenderer.php`, `Classes/AiLabel/Hook/DataHandlerAiLabelHook.php`, `Classes/Command/AiLabelAuditCommand.php`, `Classes/Command/AiLabelExportCommand.php`, `Configuration/Sets/NsT3afLabel/`
**Existing tests:** `Tests/Unit/AiLabel/*`, `Tests/Unit/Mcp/Service/RecordsApply/RecordsApplyAiLabelMarkerTest.php`

---

## Not applicable

| Row | Reason |
|---|---|
| 11 AI output | AI Label creates no AI text or media. It only records and shows labels for content made by other features. |

---

## Positive

### T3AF-LABEL-001 T3AI generation is captured and bound on save
P0 · positive · smoke, release · manual
Rows: 1, 13
Test: none
Given T3AI with a working provider and content element uid 12
When the editor generates text for it with T3AI and saves
Then the record has involvement `ai_generated` and recording source `ns_t3ai` · the matching `tx_nst3af_ailabel_generation` row is gone · `sys_log` has "Origin recorded" (channel `ailabel`) · the record shows in AI Label → Texts

### T3AF-LABEL-002 Confirm a record in the dashboard
P0 · positive · smoke, release · manual
Rows: 1, 13
Test: none
Given an AI-generated image awaiting review in AI Label → Media
When the editor clicks confirm on its row
Then `confirmed_by` is the editor, `confirmed_at` and `version_hash` are set · the row leaves "awaiting review" · `sys_log` has "Label confirmed"

### T3AF-LABEL-003 Confirmed AI image shows the label on the website
P0 · positive · smoke, release · manual
Rows: 1, 8
Test: none
Given site set `nitsan/ns-t3af-label` on the site and a confirmed AI-generated image (date after 2026-08-02) in a `textmedia` element
When a visitor opens the page
Then the image shows the EU icon and the text "AI generated" in the configured corner · only one badge is shown for that image

### T3AF-LABEL-004 Confirmed AI media gets a visible label state
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/AiLabel/FrontendLabelStateFactoryTest.php::testMediaGeneratedConfirmedShowsLabel`
Given a confirmed `ai_generated` file
When the frontend label state is built
Then `showLabel` is true · reason is `rule_default`

### T3AF-LABEL-005 Records written by an MCP client are marked AI-generated
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Mcp/Service/RecordsApply/RecordsApplyAiLabelMarkerTest.php::recordsCreatedByAnMcpClientAreMarkedAiGeneratedWithSourceMcp`
Given an MCP client creates `tt_content` 7, 8 and 9
When the records are marked
Then all three are recorded as `ai_generated` with source `mcp`

### T3AF-LABEL-006 Bulk confirm and undo
P1 · positive · release · manual
Rows: 1, 7
Test: none
Given three unconfirmed AI images selected in AI Label → Media
When the editor runs bulk confirm, then clicks undo within 10 minutes
Then after confirm all three are confirmed · after undo all three are unconfirmed again · a second undo says there is nothing to undo · no other field of the file metadata changed

### T3AF-LABEL-007 Evidence CSV export from the module
P1 · positive · release · manual
Rows: 1
Test: none
Given labelled media and texts
When the editor exports with scope media
Then `ai-label-evidence-media.csv` downloads with columns `table, uid, involvement, decision, reason_code, actor, timestamp, source` · only `sys_file_metadata` rows are in it

### T3AF-LABEL-008 Only evidence-relevant records are exported
P2 · positive · — · auto
Rows: 1, 7
Test: `Tests/Unit/AiLabel/EvidenceExportServiceTest.php::testIsEvidenceRelevant`
Given an untouched `not_reviewed` row, a row with a recording source, an `ai_generated` row and a confirmed-only row
When relevance is checked
Then only the untouched row is left out

### T3AF-LABEL-009 Record drawer saves label fields
P1 · positive · release · manual
Rows: 1
Test: none
Given an AI-generated `tt_content` row in AI Label → Texts
When the editor opens the edit drawer, sets public interest and responsible person "Jane Editor", and saves
Then a "record saved" message shows · `human_review = 1` and the person is stored · the content fields (header, bodytext) are unchanged

### T3AF-LABEL-010 Audit command reports coverage
P2 · positive · release · manual
Rows: 9
Test: none
Given some unbound captures and ns_t3al not installed
When `vendor/bin/typo3 t3af:ailabel:audit` runs
Then it prints "Coverage score: N%", "Unbound generations: N" and a "Blind spot: …" line · exit code 0

### T3AF-LABEL-011 Audit command never writes labels
P1 · positive · release · auto
Rows: 7, 9
Test: `Tests/Unit/AiLabel/AiLabelAuditReportOnlyTest.php::testAuditCommandSourceNeverWritesLabelFields`
Given the audit command source
When it is scanned
Then it calls `listUnboundGenerations` · it never calls `recordOrigin`, `confirm(` or `bindGeneration`

### T3AF-LABEL-012 Export command writes an HTML file
P2 · positive · — · manual
Rows: 9
Test: none
Given labelled records
When `t3af:ailabel:export --format=html --scope=texts -o var/ailabel.html` runs
Then the file holds an HTML table of text records only · exit code 0 · `--scope=foo` exports all scopes

---

## Negative

### T3AF-LABEL-013 Alt text only update never marks the file as AI media
P0 · negative · release · auto
Rows: 2, 7
Test: `Tests/Unit/AiLabel/AltTextIsolationTest.php::testAltTextOnlySkipsFileMetadataBind`
Given a pending capture id and file metadata uid 42
When `AiLabelBindHelper::bindFileMetadata(42, altTextOnly: true)` runs (T3AA alt / title / description)
Then nothing is bound · the capture id is still unused afterwards

### T3AF-LABEL-014 Text without public interest never shows a label
P1 · negative · release · auto
Rows: 2
Test: `Tests/Unit/AiLabel/AiLabelRuleMatrixTest.php::testTextWithoutPublicInterestNeverLabels`
Given an `ai_generated` text without public interest
When the text rule runs
Then no label is shown · reason is `not_public_interest`

### T3AF-LABEL-015 Media before the cut-off date is not labelled
P1 · negative · release · auto
Rows: 2
Test: `Tests/Unit/AiLabel/AiLabelRuleMatrixTest.php::testMediaPreCutoffDoesNotLabel`
Given an `ai_generated` image dated 2020-01-01 (before 2026-08-02)
When the media rule runs
Then no label is shown · reason is `pre_cutoff`

### T3AF-LABEL-016 Unconfirmed suggestion media shows no label
P1 · negative · release · auto
Rows: 2
Test: `Tests/Unit/AiLabel/AiLabelSafetyTest.php::testUnconfirmedMediaDecisionDoesNotShowLabel`
Given a media file with involvement `suggestion`
When the media rule runs
Then no label is shown

### T3AF-LABEL-017 Human review hides the text label but never the media label
P0 · negative · release · auto
Rows: 2
Test: `Tests/Unit/AiLabel/AiLabelSafetyTest.php::testHumanReviewSuppressesTextButNotMedia`
Given `ai_generated` text and media, both with human review by "Jane Editor"
When both rules run
Then the media label is shown (`rule_default`) · the text label is hidden (`editorial_control`)

### T3AF-LABEL-018 Human review without a responsible person is refused
P1 · negative · release · manual
Rows: 2
Test: none
Given a content element in the normal record form
When the editor ticks human review but leaves responsible person empty and saves
Then the message "AI Label: responsible person required when human review is enabled" shows · human review stays off · other changes are saved

### T3AF-LABEL-019 Drawer rejects invalid values
P2 · negative · — · gap
Rows: 2
Test: none
Given the edit drawer for `tt_content:12`
When a request posts `involvement=foo` (or `labelling_mode=foo`)
Then the drawer shows "Invalid AI involvement value." (or "Invalid labelling mode.") · the record is not changed

### T3AF-LABEL-020 Bulk with malformed refs changes nothing
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/AiLabel/AiLabelBulkActionServiceTest.php::testInvalidRefsAreSkipped`
Given refs `invalid` and `bad:0`
When bulk confirm runs
Then 0 records are processed · no record is updated

### T3AF-LABEL-021 Public-interest text is never auto-confirmed
P1 · negative · release · auto
Rows: 4
Test: `Tests/Unit/AiLabel/AutoConfirmSettingsServiceTest.php::testHoldBlocksPublicInterestText`
Given auto-confirm for own output set to "both"
When T3AI output for `tt_content` is checked
Then auto-confirm is refused for public-interest text · allowed for other text

### T3AF-LABEL-022 Detected uploads auto-confirm only when the setting is on
P2 · negative · — · auto
Rows: 4
Test: `Tests/Unit/AiLabel/AutoConfirmSettingsServiceTest.php::testDetectedUploadRequiresSetting`
Given source `detected_upload` on `sys_file_metadata`
When `ailabelAutoConfirmDetected` is off, then on
Then auto-confirm is refused when off · allowed when on

### T3AF-LABEL-023 MCP marking can be turned off
P2 · negative · — · auto
Rows: 4
Test: `Tests/Unit/Mcp/Service/RecordsApply/RecordsApplyAiLabelMarkerTest.php::theSettingTurnsMarkingOff`
Given `mcpMarkWritesAsAi` is off
When an MCP client creates and edits `tt_content`
Then no record is marked · nothing is recorded as AI

### T3AF-LABEL-024 No site set means no website badge
P2 · negative · — · manual
Rows: 4, 8
Test: none
Given a confirmed AI image on a site without `nitsan/ns-t3af-label`
When a visitor opens the page
Then no badge markup and no AI Label CSS are output · the page renders normally

### T3AF-LABEL-025 A failed provider call marks nothing
P2 · negative · release · manual
Rows: 5
Test: none
Given T3AI with a wrong API key
When the editor tries to generate text and saves the element anyway
Then no new `tx_nst3af_ailabel_generation` row exists · the record involvement is unchanged

### T3AF-LABEL-026 Public API rejects tables without AI Label fields
P2 · negative · — · auto
Rows: 2, 10
Test: `Tests/Unit/AiLabel/OriginRecorderApiTest.php::testMarkGeneratedRejectsUnknownTable`
Given table `tx_not_registered`
When `markGenerated` is called for it
Then an `InvalidArgumentException` names the table · nothing is written

---

## Edge

### T3AF-LABEL-027 Credits-mode generation is captured the same way
P2 · edge · release · manual
Rows: 6
Test: none
Given credits mode on and T3AI image generation through the credits proxy
When the editor generates and saves the image
Then the file is `ai_generated` with source `ns_t3ai` · AI Label adds no extra credit charge

### T3AF-LABEL-028 Label text follows the site language
P1 · edge · release · auto
Rows: 8
Test: `Tests/Unit/AiLabel/FrontendLabelTextServiceTest.php::testResolvesTranslatedLabelFromSiteLanguageOnFrontendRequest`
Given a German site language on a frontend request
When the label text for `ai_generated` is resolved
Then it is "KI-generiert"

### T3AF-LABEL-029 Missing translation falls back to English
P2 · edge · — · auto
Rows: 8
Test: `Tests/Unit/AiLabel/FrontendLabelTextServiceTest.php::testFallsBackToEnglishWhenTranslationMissing`
Given a site language with no AI Label translation
When the label text for `ai_modified` is resolved
Then it is "AI modified"

### T3AF-LABEL-030 Editing confirmed text clears the confirmation
P1 · edge · release · manual
Rows: 7
Test: none
Given a confirmed AI-generated content element
When the editor changes its bodytext in the record form and saves
Then `confirmed_by`, `confirmed_at` and `version_hash` are cleared · it shows as awaiting review again

### T3AF-LABEL-031 Changing involvement in the drawer needs a new confirmation
P2 · edge · — · gap
Rows: 7
Test: none
Given a confirmed `ai_generated` image
When the editor sets involvement to `ai_modified` in the edit drawer
Then the confirmation is cleared, as it is when the same change is made in the record form

### T3AF-LABEL-032 Two generations before one save
P2 · edge · — · manual
Rows: 1, 9
Test: none
Given an editor generates text twice for the same element before saving
When they save once
Then the record is bound once to the last capture · the earlier capture counts as unbound in the audit until it ages out after 7 days

### T3AF-LABEL-033 Image overlay mode skips the element badge for media types
P2 · edge · — · auto
Rows: 1
Test: `Tests/Unit/AiLabel/ContentElementLabelEnabledViewHelperTest.php::testOverlaySkipsMediaCTypes`
Given `ailabelMarkImageFile = overlay`
When `image`, `textmedia`, `textpic` and `text` elements are checked
Then only `text` gets the element badge

### T3AF-LABEL-034 Unknown-origin media is labelled only when enabled
P2 · edge · — · auto
Rows: 4
Test: `Tests/Unit/AiLabel/AiLabelRuleMatrixTest.php::testMediaUnknownOriginLabelsWhenEnabled`
Given confirmed `origin_unknown` media
When "label unknown origin" is on, then off
Then the label shows with reason `unknown_origin` when on · no label when off

### T3AF-LABEL-035 Same behaviour on TYPO3 13.4 and 14.3
P1 · edge · release · manual
Rows: 12
Test: none
Given the same content and labels on a 13.4 and a 14.3 instance
When the four AI Label tabs, the record form and the website are opened
Then all tabs load · the badge looks the same · TypoScript comes from the site set or static template only

### T3AF-LABEL-036 Export command reports a failed write
P2 · edge · — · gap
Rows: 9
Test: none
Given an output path in a folder that does not exist
When `t3af:ailabel:export -o /nonexistent/x.csv` runs
Then an error is printed and the exit code is not 0 · no partial file is left

---

## Security

### T3AF-LABEL-037 Capture queue stores no prompt or content
P0 · security · release · auto
Rows: 7, 10
Test: `Tests/Unit/AiLabel/CaptureQueueSchemaTest.php::testGenerationQueueStoresNoPromptOrContentColumns`
Given `ext_tables.sql`
When the `tx_nst3af_ailabel_generation` table is read
Then it has no `prompt`, `content` or `bodytext` column

### T3AF-LABEL-038 Visitor info layer shows no machine reason codes
P2 · security · — · auto
Rows: 10
Test: `Tests/Unit/AiLabel/FrontendLabelRendererTest.php::testSecondInfoLayerWrapsDetailsWithoutReasonCode`
Given the second info layer is on
When the badge details are rendered
Then the visitor sees "AI generated" · `rule_default` is not in the HTML

### T3AF-LABEL-039 Label actions without the AI Label tab
P0 · security · release · gap
Rows: 3, 10
Test: none
Given an editor whose group lacks `nst3af_tab:ai_label`
When they post to `ai_label.bulk`, `ai_label.record_save` or `ai_label.settings_save`, or open `ai_label.export`
Then access is denied · no label field, setting or file changes

### T3AF-LABEL-040 Editors cannot change labels on records they cannot edit
P1 · security · release · gap
Rows: 3, 10
Test: none
Given an editor with the AI Label tab but no access to page 50 or to a file storage
When they bulk-confirm or drawer-save a record on page 50 or a file in that storage
Then the change is refused · the record keeps its label fields

### T3AF-LABEL-041 Bulk refs to non-label tables are ignored
P1 · security · release · gap
Rows: 2, 10
Test: none
Given a crafted bulk post with ref `be_users:1` and action `set_public_interest`
When it is sent
Then a "nothing processed" message shows · no server error · `be_users` is not touched

### T3AF-LABEL-042 Applicable tables only accept content tables
P1 · security · — · gap
Rows: 10
Test: none
Given AI Label → Settings
When `be_users` is entered under applicable tables and saved
Then it is refused · no AI Label columns are planned for `be_users`

### T3AF-LABEL-043 Label edits respect workspaces
P2 · security · — · gap
Rows: 3
Test: none
Given an editor working in a draft workspace
When they confirm or drawer-save a live record
Then the live record does not change until the workspace is published

### T3AF-LABEL-044 Text in label fields is escaped
P1 · security · release · manual
Rows: 10
Test: none
Given responsible person and exemption reason set to `<img src=x onerror=alert(1)>`
When the dashboard list, the drawer preview and the website badge are shown
Then the text shows as plain text · no script runs
