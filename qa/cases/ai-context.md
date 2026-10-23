# AI Context (brand profiles) + AI Prompts — cases

**Prefix:** `T3AF-CTX`
**How it works:** `context/features/ai-context.md`
**Main code:** `Classes/Controller/Backend/BrandContextController.php`, `BrandContextAjaxController.php`, `Classes/Service/BrandContext*.php`, `Classes/EventListener/BrandContextPromptInjectionListener.php`, `Classes/Service/AiPromptsService.php`, `AiPromptsBrandContextInfoService.php`, `Classes/Service/PublicUrlValidator.php`, `Resources/Public/JavaScript/ai-context.js`, `Configuration/TCA/tx_nst3af_brand_context_profile.php`, `Configuration/TCA/tx_nst3af_ai_prompt.php`
**Existing tests:** `Tests/Unit/Service/BrandContext*Test.php`, `Tests/Unit/EventListener/BrandContextPromptInjectionListenerTest.php`, `Tests/Unit/Service/PublicUrlValidatorTest.php`, `Tests/Unit/Service/AiPrompts*Test.php`, `Tests/Unit/Domain/Model/BrandContextProfileTest.php`

---

## Not applicable

| Row | Reason |
|---|---|
| 12 Compatibility | `BrandContext*`, `AiPrompts*` and the injection listener contain no TYPO3-version branches. The AI Context tab shell (icon, docheader) on 13.4 vs 14.3 is covered by `T3AF-MOD-033`. |

---

## Positive

### T3AF-CTX-001 Admin creates the first brand profile for a site
P1 · positive · smoke, release · gap
Rows: 1, 8
Test: none
Given an admin on AI Context with a page in site A selected and no profile yet
When they fill brand name, industry, 3 tone tags and click Save (`t3af_dashboard.ai_context.create`)
Then the list shows the profile with the `profile-created` flash · a `tx_nst3af_brand_context_profile` row exists on site A's storage pid with `is_default = 1`

### T3AF-CTX-002 Set default keeps exactly one default per site
P1 · positive · release · gap
Rows: 1, 7
Test: none
Given two profiles on site A, profile 1 is default
When the admin clicks "Set default" on profile 2 (`t3af_dashboard.ai_context.set_default`)
Then flash `profile-default-set` · profile 2 has `is_default = 1`, profile 1 has `is_default = 0` · site B's default is unchanged

### T3AF-CTX-003 Completeness score grows with filled sections
P3 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/BrandContextCompletenessCalculatorTest.php::testIdentityAndVoiceSectionsIncreaseCompleteness`, `::testFullProfileScoresOneHundredPercent`
Given a profile with only identity filled, then with every section filled
When the list card is rendered
Then completeness rises after adding voice · a full profile shows 100 %

### T3AF-CTX-004 Brand placeholders are filled and the brand block is added
P0 · positive · smoke, release · auto
Rows: 1, 11
Test: `Tests/Unit/EventListener/BrandContextPromptInjectionListenerTest.php::testInjectsPlaceholdersAndPrependsBrandBlockToSystemPrompt`, `::testInjectsIntoChatMessagesAndAddsBrandSystemMessage`
Given a default profile on the site and an AI call whose prompt contains `{brand_name}`
When any feature calls `AiService::complete()` or `chat()`
Then `{brand_name}` is replaced by the profile brand name · the brand block is added to the system prompt or as a system message

### T3AF-CTX-005 `[brand_profile]` token replaces the separate brand block
P2 · positive · — · auto
Rows: 1, 11
Test: `Tests/Unit/EventListener/BrandContextPromptInjectionListenerTest.php::testBrandProfileTokenExpandsInlineAndSuppressesSystemPromptBlock`, `::testBrandProfileTokenInMessagesSuppressesBrandSystemMessage`
Given a prompt that contains `[brand_profile]`
When the AI call runs
Then the token expands to the full brand block in place · no second brand block is added to the system prompt or messages

### T3AF-CTX-006 Per-feature profile override wins over the site default
P1 · positive · release · auto
Rows: 1, 8
Test: `Tests/Unit/Service/BrandContextResolverTest.php::testResolveForPageIdUsesExtensionOverrideWhenConfigured`, `Tests/Unit/Service/BrandContextFeatureSettingsServiceTest.php::testPerScopeOverrideWinsOverLegacyValue`
Given site default profile A and `brandContextProfileUid` = profile B saved on the `ns_t3ai` `seo` scope
When an SEO AI call runs for a page of that site
Then profile B is injected · other `ns_t3ai` scopes still use profile A

### T3AF-CTX-007 Override dropdown appears only on AI writing scopes
P2 · positive · — · auto
Rows: 1, 4
Test: `Tests/Unit/Service/BrandContextFeatureSettingsServiceScopeTest.php::testSupportsBrandContextOverrideOnAiFeatureScopes`, `::testDoesNotSupportBrandContextOverrideOnFeatureToggles`, `::testDoesNotSupportBrandContextOverrideOnTokenlessFeatures`
Given the AI Features drawer
When the `ns_t3ai` `seo`, `page` and `content` scopes and a toggle-only scope are opened
Then the brand profile dropdown is shown on `seo`, `page`, `content` · it is not shown on toggle or tokenless scopes

### T3AF-CTX-008 Website research fills the drawer but saves nothing
P1 · positive · release · manual
Rows: 1, 6
Test: none
Given an admin creating a profile, a provider or credits available
When they enter `https://example.com` and click Research (`ajax_nst3af_brand_context_research`)
Then the drawer fields are filled with the AI suggestions and confidence badges · no `tx_nst3af_brand_context_profile` row is written until Save is clicked

### T3AF-CTX-009 Uploaded documents are extracted as text
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/BrandContextDocumentExtractorTest.php::testExtractPlainTextAndMarkdown`
Given a `.txt` and a `.md` file up to 10 MB
When they are uploaded in the drawer (`ajax_nst3af_brand_context_extract_documents`)
Then the response holds their plain text · the temp files are deleted after the request

### T3AF-CTX-010 AI Prompts tab merges prompt catalogs from all extensions
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/AiPromptsServiceTest.php::testGetCatalogForUiMergesProviderCatalogs`, `Tests/Unit/Service/AiPromptsBrandContextInfoServiceTest.php::testBuildByCategoryResolvesProfilePerScope`
Given `ns_t3af` and child extensions that register prompt providers
When the AI Prompts tab opens
Then each extension's prompt categories are listed · each category shows the brand profile that applies to its scope

### T3AF-CTX-011 Admin creates a custom prompt
P2 · positive · — · gap
Rows: 1, 8
Test: none
Given an admin on AI Prompts with a page in site A selected
When they create a prompt with type and scope (`t3af_dashboard.ai_prompts.create`)
Then a `tx_nst3af_ai_prompt` row is created on site A's storage pid · it appears in its category list

### T3AF-CTX-012 Profile uid is stamped on the AI request
P1 · positive · release · auto
Rows: 13
Test: `Tests/Unit/EventListener/BrandContextPromptInjectionListenerTest.php::testStampsBrandContextProfileUidOnOptionsForLineage`, `Tests/Unit/Service/BrandContextLineageTest.php::testStampExtraSetsUid`
Given a resolved profile uid 3
When an AI call runs
Then `AiOptions` extra carries `brandContextProfileUid = 3`

### T3AF-CTX-013 Request log shows profile uid and research attribution
P1 · positive · — · gap
Rows: 13
Test: none
Given one ns_t3ai call with profile 3 and one research call
When AI Usage is opened
Then the ns_t3ai row has `brand_context_profile_uid = 3` · the research row has extension `ns_t3af`, feature `brand_context.research`, source `backend_module` and `brand_context_profile_uid = 0`

---

## Negative

### T3AF-CTX-014 Profile without brand name is rejected
P2 · negative · — · gap
Rows: 2
Test: none
Given the profile drawer
When it is saved with an empty brand name
Then flash `profile-validation-fail` · no row is created or changed

### T3AF-CTX-015 Editor without profile write access cannot change profiles
P1 · negative · release · gap
Rows: 3, 7
Test: none
Given an editor without write access to `tx_nst3af_brand_context_profile` (catalog `brandProfiles`)
When they POST `ai_context.create`, `.update`, `.delete`, `.set_default` or `.set_enabled`
Then flash `profile-access-denied` · no profile row is created, changed or deleted

### T3AF-CTX-016 Profile from another site cannot be changed from this site
P1 · negative · release · gap
Rows: 7, 8
Test: none
Given profile uid 9 on site B
When an admin on site A posts `ai_context.update` or `.delete` with `uid=9`
Then flash `profile-update-fail` or `profile-delete-fail` · profile 9 is unchanged

### T3AF-CTX-017 Override pointing to another site's profile is dropped
P1 · negative · — · gap
Rows: 7, 8
Test: none
Given site B profile uid 9
When the AI Features drawer on site A saves `brandContextProfileUid=9`
Then `brandContextProfileUid` is saved as `0` · site A calls use site A's default profile

### T3AF-CTX-018 Missing override profile falls back to the default
P2 · negative · — · auto
Rows: 5, 8
Test: `Tests/Unit/Service/BrandContextResolverTest.php::testResolveForPageIdFallsBackToDefaultWhenOverrideMissing`
Given an override uid whose profile was deleted
When an AI call runs for that scope
Then the site default profile is injected · the call does not fail

### T3AF-CTX-019 No profile: brand tokens are removed, not sent
P1 · negative · release · auto
Rows: 5, 11
Test: `Tests/Unit/EventListener/BrandContextPromptInjectionListenerTest.php::testStripsBrandTokensWhenNoProfileResolves`
Given a site with no profile and a prompt containing `{brand_name}` and `[brand_profile]`
When the AI call runs
Then neither token text is sent to the provider · no brand block is added

### T3AF-CTX-020 Calls flagged `skipBrandContext` get no brand data
P2 · negative · — · auto
Rows: 4, 11
Test: `Tests/Unit/EventListener/BrandContextPromptInjectionListenerTest.php::testSkipsWhenSkipBrandContextExtraFlagIsSet`
Given `AiOptions` extra `skipBrandContext = true` (used by brand research itself)
When the call runs
Then the prompt and messages are sent unchanged

### T3AF-CTX-021 Disabled default profile is not injected
P1 · negative · — · manual
Rows: 4
Test: none
Given site A's only default profile is disabled (`ai_context.set_enabled` with enabled off, `hidden = 1`)
When an AI writing feature runs on site A
Then no brand block is in the request (check the AI Logs prompt) · the profile row is still in the list as disabled

### T3AF-CTX-022 Research without URL makes no AI call
P2 · negative · — · gap
Rows: 2, 6
Test: none
Given the research AJAX route
When it is posted with an empty `websiteUrl`
Then HTTP 400 `Website URL is required.` · no provider call · no request-log row · no credits used

### T3AF-CTX-023 Provider failure during research shows an error
P1 · negative · release · gap
Rows: 5, 13
Test: none
Given the provider returns a 500 or times out
When research runs
Then HTTP 500 with `Brand research failed: …` · the drawer keeps what the user typed · a failed `tx_nst3af_request_log` row with feature `brand_context.research` exists

### T3AF-CTX-024 Empty or non-JSON AI reply is rejected
P2 · negative · — · gap
Rows: 5, 11
Test: none
Given the provider replies with an empty string, then with plain prose
When research runs
Then the error is `AI returned an empty response.`, then `AI response was not valid JSON: …` · no drawer field is filled

### T3AF-CTX-025 More than 3 documents or unsupported type is refused
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Service/BrandContextDocumentExtractorTest.php::testSkipsUnsupportedFileType`
Given the document upload
When 4 files are sent, or a `.exe` file
Then 4 files return HTTP 400 `Maximum 3 documents allowed.` · the `.exe` is skipped · no text from it is returned

### T3AF-CTX-026 Prompt without type or scope is rejected
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Service/AiPromptsServiceTest.php::testValidateGlobalPromptPayloadRequiresTypeAndScope`
Given the AI Prompts create form
When it is saved without type or scope
Then validation error `invalid_prompt_type` · no `tx_nst3af_ai_prompt` row is created

### T3AF-CTX-027 Prompt writes are skipped when no prompt table exists
P3 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiPromptsServiceTest.php::testMutatingOperationsAreNoOpsWhenNoPromptTableRegistered`
Given no prompt provider registers a table
When create, update or delete is called
Then nothing is written · no exception

### T3AF-CTX-028 Prompt from another site cannot be changed
P1 · negative · — · gap
Rows: 7, 8
Test: none
Given prompt uid 4 on site B
When an admin on site A posts `ai_prompts.update` or `.delete` with `uid=4`
Then the action fails · prompt 4 is unchanged

---

## Edge

### T3AF-CTX-029 Research is charged once and not charged on failure
P0 · edge · release · gap
Rows: 6
Test: none
Given credits mode on with a known balance
When one research call succeeds, then one fails at the provider
Then the balance drops once for the success (feature `brand_context.research`, billed as `content_generate`) · the failed call does not reduce the balance

### T3AF-CTX-030 Deleted profile is soft-deleted
P2 · edge · — · manual
Rows: 7
Test: none
Given a non-default profile
When it is deleted (`ai_context.delete`)
Then flash `profile-deleted` · the row has `deleted = 1` · old `tx_nst3af_request_log` rows keep their `brand_context_profile_uid`

### T3AF-CTX-031 Field limits are enforced on save
P3 · edge · — · gap
Rows: 2
Test: none
Given a profile with a 70-character brand name, 6 tone tags, 4 personas and 12 content rules
When it is saved
Then brand name is cut to 60 characters, tone tags to 5, personas to 3, rules to 10 · no other field is changed

### T3AF-CTX-032 Long multibyte text is cut without broken characters
P2 · edge · — · gap
Rows: 2, 8
Test: none
Given a brand name of 60 German or Japanese characters plus more
When the profile is saved
Then the stored brand name is valid UTF-8 with no broken last character (today `substr` cuts bytes)

### T3AF-CTX-033 Each site uses its own default profile
P1 · edge · release · auto
Rows: 8
Test: `Tests/Unit/Service/BrandContextResolverTest.php::testResolveDefaultForPageIdUsesSiteDefaultProfile`
Given site A and site B, each with its own default profile
When an AI call runs for a page of site B
Then site B's profile is injected · site A's profile is not

### T3AF-CTX-034 Call without page id uses the first site root
P2 · edge · — · auto
Rows: 8, 9
Test: `Tests/Unit/Service/BrandContextResolverTest.php::testResolveForNullPageIdFallsBackToSiteRootLookupWithoutFailing`
Given a CLI or MCP call with no page id
When brand context is resolved
Then the first site root's default profile is used · the call does not fail

---

## Security

### T3AF-CTX-035 Research refuses private and non-HTTP URLs
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Service/PublicUrlValidatorTest.php::testRejectsPrivateReservedAndInvalidUrls`, `Tests/Unit/Service/BrandContextWebsiteFetcherTest.php::testFetchTextRejectsNonPublicUrlWithoutSendingRequest`
Given research with `http://127.0.0.1`, `http://169.254.169.254`, `http://10.0.0.1`, `file:///etc/passwd` or `ftp://example.com`
When research runs
Then no HTTP request is sent to that address · the AI gets no page text from it

### T3AF-CTX-036 Research does not follow a redirect to a private address
P0 · security · release · gap
Rows: 10
Test: none
Given a public URL that answers `302 Location: http://169.254.169.254/latest/meta-data/`
When research runs
Then the redirect target is not fetched · its content is not sent to the AI (today the HTTP client follows redirects)

### T3AF-CTX-037 Research and document upload need profile write access
P1 · security · release · gap
Rows: 3, 6, 10
Test: none
Given an editor with AI Foundation access but without write access to `tx_nst3af_brand_context_profile`
When they POST `ajax_nst3af_brand_context_research` or `_extract_documents`
Then HTTP 403 · no provider call · no credits used

### T3AF-CTX-038 AI research text in tone and keyword chips is escaped
P1 · security · release · gap
Rows: 10, 11
Test: none
Given research returns a keyword `<img src=x onerror=alert(1)>`
When the drawer renders the chips in `ai-context.js`
Then the text is shown literally · no script runs

### T3AF-CTX-039 AI research text in content rules is escaped
P1 · security · — · gap
Rows: 10, 11
Test: none
Given research returns a content rule `"><script>alert(1)</script>`
When the drawer renders the rule inputs
Then the full text is the input value · no script runs · no extra element appears

### T3AF-CTX-040 Brand fields cannot break out of the brand block
P0 · security · release · auto
Rows: 10, 11
Test: `Tests/Unit/Service/BrandContextAssemblerTest.php::testEscapesFenceBreakersAndRoleMarkersInsideFields`, `::testAssembledBlockIsFencedWithUntrustedPreamble`
Given a brand description containing a fence marker and `system:` role text
When the brand block is built
Then the marker and role text are escaped · the block stays inside the untrusted fence with its preamble

---

## AI output

### T3AF-CTX-041 Research reply is mapped to allowed values
P1 · ai · — · auto
Rows: 11
Test: `Tests/Unit/Service/BrandContextResearchServiceTest.php::testMapFieldsNormalizesIndustryToneTagsAndLanguage`, `::testNormalizeConfidenceFiltersInvalidLevels`, `::testParseJsonResponseExtractsObjectFromMarkdownFence`
Given an AI reply wrapped in a markdown fence with an unknown industry, unknown tone tags and confidence `very-high`
When it is parsed
Then the JSON inside the fence is used · industry and tone tags are mapped to allowed values or dropped · invalid confidence levels are removed

### T3AF-CTX-042 Placeholder values are not expanded twice
P1 · ai · — · auto
Rows: 10, 11
Test: `Tests/Unit/Service/BrandContextPlaceholderServiceTest.php::testReplaceIsSinglePassAndDoesNotCascade`
Given a brand name that itself contains `{keywords}`
When placeholders are replaced
Then the brand name text stays literal · `{keywords}` inside it is not expanded

### T3AF-CTX-043 Embedding calls never get the brand block
P1 · ai · — · auto
Rows: 11
Test: `Tests/Unit/EventListener/BrandContextPromptInjectionListenerTest.php::testEmbedNeverGainsBrandSystemBlockInPromptOrMessages`
Given a default profile
When an embedding call runs
Then the input text sent to the provider is unchanged · no brand system block

### T3AF-CTX-044 Document text is added only when enabled and is capped
P2 · ai · — · auto
Rows: 4, 11
Test: `Tests/Unit/Service/BrandContextAssemblerTest.php::testDocumentExtractOmittedByDefaultEvenWhenStored`, `::testDocumentExtractIncludedWhenToggleOnAndCapped`
Given a profile with a stored document extract longer than 2,000 characters
When the brand block is built with the document toggle off, then on
Then off: no document text · on: at most `BrandContextAssembler::MAX_INJECT_DOCUMENT_CHARS` (2,000) characters, outside the untrusted fence

### T3AF-CTX-045 Generated text follows the brand voice
P2 · ai · — · manual
Rows: 11
Test: none
Given a default profile with tone "Formal", a forbidden word "cheap" and a keyword "sustainable"
When an editor generates a meta description with `ns_t3ai` on that site
Then the text is formal, does not contain "cheap" and is in the page language · the AI Logs prompt shows the brand block

---

## Field reference

| Field | Values |
|---|---|
| Priority | P0 · P1 · P2 · P3 (see `../README.md` § Severity) |
| Kind | positive · negative · edge · security · ai |
| Tags | `smoke`, `release` (zero, one or both) |
| Status | `auto` = a test covers it · `manual` = verify in backend / CLI · `gap` = should be automated, no test yet |
| Rows | Checklist rows this case answers (`../checklist.md`) |
| Test | `path::method` when `auto`; `none` otherwise |
| Ticket | Optional ClickUp link when the case came from a bug |

Rules:

- One behaviour per case. Split instead of writing "and also".
- **Then** must be observable: a saved value, a message, a log row, a credit count, an HTTP status.
- Negative **Then** always says what must **not** change.
- Never renumber. Retired cases stay with `~~strikethrough~~` title and a reason.
