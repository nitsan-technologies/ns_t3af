# Quick Setup wizard + extension settings + upgrades — cases

**Prefix:** `T3AF-SET`
**How it works:** `context/features/backend-module.md` (Quick Setup), `context/architecture.md` (settings storage); no separate feature file
**Main code:** `Classes/Controller/Backend/WizardController.php`, `Classes/Service/Wizard*.php`, `Classes/Settings/ExtensionSettings*.php`, `Classes/Service/SiteExtensionSettingsResolver.php`, `Classes/Service/SiteStorageContext.php`, `Classes/Service/CredentialCipher.php`, `Classes/Updates/*.php`, `Configuration/Secrets/secrets.php`, `Configuration/Backend/AjaxRoutes.php`
**Existing tests:** `Tests/Unit/Service/Wizard*Test.php`, `Tests/Unit/Service/BrandContextServiceWizardValidationTest.php`, `Tests/Unit/Settings/ExtensionSettings*Test.php`, `Tests/Functional/Settings/ExtensionSettingsRepositoryFunctionalTest.php`, `Tests/Unit/Service/{SiteExtensionSettingsResolver,SiteStorageContext,CredentialCipher}Test.php`

---

## Not applicable

| Row | Reason |
|---|---|
| 11 AI output | The wizard and settings store configuration only. The only AI call (provider connection test) returns a pass/fail status, not text shown to editors. Brand research output is covered in `ai-context.md`. |

---

## Positive

### T3AF-SET-001 Wizard in own-keys mode sets up provider, features and brand profile
P1 · positive · smoke, release · gap
Rows: 1, 8
Test: none
Given an admin, a page in site A selected, wizard not completed
When they finish Quick Setup with mode own-keys, an OpenAI catalog entry, a valid API key, feature toggles and a brand context (`ajax_nst3af_wizard_finalize`)
Then the response is `ok: true, mode: ownkeys, connectionOk: true` · the provider is default on site A's storage pid with `last_status = connected` · a default brand profile exists on site A · `wizard_completed = 1` in `tx_nst3af_runtime_setting` · a `nst3af.wizard` log row says `Quick Setup wizard completed (mode: ownkeys, MCP server …).`

### T3AF-SET-002 Credits mode is saved only when credits are public
P1 · positive · release · gap
Rows: 1, 6
Test: none
Given `CreditModeResolver::isPubliclyAvailable()` returns false
When finalize is posted with `mode=credits`
Then `credit_mode = 0` is saved and the response says `mode: ownkeys` · with credits public the same request saves `credit_mode = 1`

### T3AF-SET-003 Wizard progress is saved and resumed
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/WizardProgressServiceTest.php::testSaveProgressClampsAndEnsuresMaxIsAtLeastLast`
Given the wizard on step 4
When the user closes the modal (`ajax_nst3af_wizard_progress` with `lastStep=4`) and reopens it
Then it resumes on step 4 · `wizard_max_step` is at least `wizard_last_step`

### T3AF-SET-004 Wizard marks completion and last step
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/WizardProgressServiceTest.php::testMarkCompletedSetsFlagAndFinalStep`
Given a successful finalize
When progress is read again
Then `wizard_completed = 1` and the last step is the final step · the wizard no longer auto-opens

### T3AF-SET-005 Wizard reuses its own incomplete provider draft
P2 · positive · — · auto
Rows: 1, 7
Test: `Tests/Unit/Service/WizardProviderCatalogTest.php::ensureProviderUidReusesIncompleteWizardDraft`
Given a provider draft created by an earlier wizard run that has no API key yet
When the user picks the same catalog entry again (`ajax_nst3af_wizard_ensure_provider`)
Then the same provider uid is returned · no second provider row is created

### T3AF-SET-006 Wizard picks the next free identifier when the base is taken
P2 · positive · — · auto
Rows: 7, 8
Test: `Tests/Unit/Service/WizardProviderCatalogTest.php::ensureProviderUidAllocatesNextIdentifierWhenBaseIsTakenAtStoragePid`
Given a configured provider with identifier `openai` on the storage pid
When the wizard ensures a new OpenAI provider there
Then a new row with the next free identifier is created · the existing `openai` provider is unchanged

### T3AF-SET-007 Master feature toggle applies to all child fields
P2 · positive · — · auto
Rows: 1, 4
Test: `Tests/Unit/Service/WizardFeatureToggleServiceTest.php::testExpandTogglesAppliesMasterValueToChildFields`
Given an extension card with a master toggle and child toggles
When the master toggle is switched off in the wizard
Then every child field is saved as off

### T3AF-SET-008 Wizard lists only eligible extension cards
P3 · positive · — · auto
Rows: 1, 4
Test: `Tests/Unit/Service/WizardExtensionCatalogServiceTest.php::testBuildCatalogIncludesWizardEligibleFeatureCards`, `::testHasEligibleExtensionsIsFalseWithoutWizardCards`
Given installed child extensions, some with wizard-eligible feature cards
When the extension step is built
Then only wizard-eligible cards are listed · the step is skipped when no card is eligible

### T3AF-SET-009 Stored settings override schema defaults
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Settings/ExtensionSettingsServiceTest.php::testGetAllMergesDynamicDefaultsBeforeStoredValues`
Given schema defaults and a stored value for one field
When settings are read
Then the stored value wins for that field · other fields have their defaults

### T3AF-SET-010 First save on a site initialises all managed extensions
P2 · positive · — · auto
Rows: 1, 8
Test: `Tests/Unit/Settings/ExtensionSettingsServiceTest.php::testInitializeSiteSettingsPersistsAllManagedExtensionsOnFirstSave`
Given a new site with no `tx_nst3af_extension_setting` rows
When settings are saved for the first time
Then a row is written for every managed extension on that site's pid

### T3AF-SET-011 Settings JSON round-trips through the database
P2 · positive · — · auto
Rows: 1, 7
Test: `Tests/Functional/Settings/ExtensionSettingsRepositoryFunctionalTest.php::insertUpdateAndFindSettingsJsonRoundTrip`
Given an empty `tx_nst3af_extension_setting` table
When a row is inserted, updated and read back
Then `settings_json` matches the last saved value exactly

### T3AF-SET-012 Global merge writes pid 0 and existing site rows
P2 · positive · — · auto
Rows: 8
Test: `Tests/Unit/Settings/ExtensionSettingsServiceTest.php::testMergeGlobalPersistsToPidZeroAndExistingSiteRows`
Given settings rows on pid 0 and on two sites
When a global value is merged
Then pid 0 and the existing site rows get the value · the call returns true

---

## Negative

### T3AF-SET-013 Invalid wizard step is rejected
P3 · negative · — · gap
Rows: 2
Test: none
Given the wizard
When `ajax_nst3af_wizard_progress` is posted with `lastStep=0` or `lastStep=9`
Then HTTP 400 with `Invalid wizard step progress.` · stored progress is unchanged

### T3AF-SET-014 Brand step rejects missing required fields
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Service/BrandContextServiceWizardValidationTest.php::validateWizardPayloadRejectsMissingBrandName`, `::validateWizardPayloadRejectsMissingIndustry`, `::validateWizardPayloadRejectsInvalidToneTagCount`
Given the wizard brand step
When it is submitted without brand name, without industry, or with 2 tone tags
Then the message is `Brand name is required.`, `Industry is required.` or `Pick 3–5 tone tags.` · no brand profile is created

### T3AF-SET-015 Failed finalize leaves no half-done setup
P1 · negative · release · gap
Rows: 2, 7
Test: none
Given an admin on a page outside any site, or a valid site and an empty brand name
When finalize is posted with `mode=credits` and feature toggles
Then the response is HTTP 400 · `credit_mode`, provider default, feature toggles and `wizard_completed` keep their previous values (today `credit_mode` is saved before the site check and toggles before brand validation)

### T3AF-SET-016 Finalize without a site root is rejected
P2 · negative · — · gap
Rows: 2, 8
Test: none
Given no page selected and a referer without a page id, on an install with no configured site
When `ajax_nst3af_wizard_finalize` or `ajax_nst3af_wizard_ensure_provider` is posted
Then HTTP 400 with `No valid site root found for setup.` · no provider or brand profile is created

### T3AF-SET-017 Wrong API key is not stored
P1 · negative · release · gap
Rows: 5, 10
Test: none
Given own-keys mode and an API key the provider rejects
When finalize is posted
Then the response has `connectionOk: false` · the provider row has no new `api_key` and `last_status` is not `connected`

### T3AF-SET-018 Unknown provider catalog entry is rejected
P2 · negative · — · gap
Rows: 2
Test: none
Given the wizard provider step
When `ajax_nst3af_wizard_ensure_provider` is posted with an empty `catalogId`, then with `catalogId=unknown`
Then HTTP 400 `Catalog id is required.`, then HTTP 404 `Unknown or unavailable provider catalog entry.` · no provider row is created

### T3AF-SET-019 Retired models are not offered; static list is the fallback
P3 · negative · — · auto
Rows: 2, 5
Test: `Tests/Unit/Service/WizardProviderCatalogTest.php::listForWizardExcludesRetiredCatalogModels`, `::listForWizardFallsBackToStaticModelsWhenCatalogEmpty`
Given a model catalog with retired models, or an empty catalog
When the wizard provider list is built
Then retired models are absent · an empty catalog falls back to the static model list

### T3AF-SET-020 Invalid storage pid returns no provider
P2 · negative · — · auto
Rows: 2, 8
Test: `Tests/Unit/Service/WizardProviderCatalogTest.php::ensureProviderUidReturnsNullWhenStoragePidIsInvalid`
Given a storage pid of 0
When the wizard ensures a provider
Then `null` is returned · no provider row is created

### T3AF-SET-021 Missing encryption key blocks API key save with a message
P1 · negative · release · gap
Rows: 5, 10
Test: none
Given `SYS.encryptionKey` is empty
When finalize is posted with an API key
Then HTTP 400 with the `CipherException` message · no plain key is written to `tx_nst3af_provider.api_key`

### T3AF-SET-022 Unmanaged extension is not written by global merge
P2 · negative · — · auto
Rows: 2, 7
Test: `Tests/Unit/Settings/ExtensionSettingsServiceTest.php::testMergeGlobalReturnsFalseForUnmanagedExtension`
Given an extension key with no settings schema
When a global merge is called for it
Then the call returns false · no `tx_nst3af_extension_setting` row is written

### T3AF-SET-023 Missing settings table falls back to schema defaults
P2 · negative · — · auto
Rows: 5, 12
Test: `Tests/Unit/Settings/ExtensionSettingsServiceTest.php::testGetAllIgnorePidReturnsSchemaDefaultsWhenTableMissing`
Given `tx_nst3af_extension_setting` does not exist yet (before Analyze Database)
When settings are read
Then schema defaults are returned · no exception

### T3AF-SET-024 Fields outside the schema are not saved
P2 · negative · — · gap
Rows: 2, 7
Test: none
Given the AI Features drawer for `ns_t3af`
When the save request adds `settings[notInSchema]=x`
Then `notInSchema` is not in `settings_json` · valid fields are saved as normal

---

## Edge

### T3AF-SET-025 Wizard completion is global across sites
P3 · edge · — · manual
Rows: 8
Test: none
Given the wizard was completed from site A
When an admin opens AI Foundation on site B (no provider, no brand profile)
Then the wizard does not auto-open · site B setup must be done in the Providers and AI Context tabs

### T3AF-SET-026 Page id wins over site scan when resolving settings
P2 · edge · — · auto
Rows: 8
Test: `Tests/Unit/Service/SiteExtensionSettingsResolverTest.php::testResolveUsesPageIdBeforeSiteScan`
Given two sites with different values
When settings are resolved with a page id from site B
Then site B values are used · site A values are not read

### T3AF-SET-027 CLI without page context scans sites
P2 · edge · — · auto
Rows: 8, 9
Test: `Tests/Unit/Service/SiteExtensionSettingsResolverTest.php::testResolveScansSitesWhenNoPageContext`
Given a CLI command with no page id
When settings are resolved
Then the first site with a matching settings row is used · the command does not fail

### T3AF-SET-028 No configured site gives no storage pid
P3 · edge · — · auto
Rows: 8
Test: `Tests/Unit/Service/SiteStorageContextTest.php::testResolveFirstRootStoragePidReturnsNullWithoutConfiguredSites`, `::testExtractPageIdFromReferer`
Given no site configuration
When the storage pid is resolved
Then the result is `null` · a page id in the referer is used when present

### T3AF-SET-029 Upgrade wizards are idempotent
P1 · edge · release · manual
Rows: 7, 9, 12
Test: none
Given an install upgraded from 1.x
When `vendor/bin/typo3 upgrade:run` runs `nst3afAgentConversationSessions`, `nst3afDropLegacyProviderIdentifierUnique`, `nst3afDropMcpPromptTemplateCategory`, `nst3afStripUnsupportedImageGenerationCapability`, then `upgrade:list` is run again
Then all four are marked done and are not offered again · a second run changes no row

### T3AF-SET-030 Image capability strip touches only unsupported providers
P1 · edge · — · gap
Rows: 7
Test: none
Given one provider whose adapter cannot generate images with `image_generation` in its capabilities, and one that can
When `nst3afStripUnsupportedImageGenerationCapability` runs
Then `image_generation` is removed only from the first provider · the second provider's capabilities and API key are unchanged

### T3AF-SET-031 Drop-index upgrade on a non-MySQL database
P2 · edge · — · gap
Rows: 12
Test: none
Given TYPO3 on PostgreSQL or SQLite
When `nst3afDropLegacyProviderIdentifierUnique` runs
Then it finishes without an SQL error (it uses MySQL `ALTER TABLE … DROP INDEX`) · provider rows are unchanged

### T3AF-SET-032 Wizard and settings work on 13.4 and 14.3
P1 · edge · release · manual
Rows: 12
Test: none
Given the same install on TYPO3 13.4 and 14.3
When Quick Setup runs and the AI Features drawer saves a value
Then both versions finish with `ok: true` and the same saved rows

---

## Security

### T3AF-SET-033 Non-admin cannot finalize the wizard
P0 · security · release · gap
Rows: 3, 10
Test: none
Given an editor with AI Foundation access but no Providers or settings write permission
When they POST `ajax_nst3af_wizard_finalize` with `mode=credits` and an API key
Then the request is refused · `credit_mode`, providers, feature settings and brand profiles are unchanged

### T3AF-SET-034 Wizard cannot move another site's provider
P0 · security · release · gap
Rows: 7, 8, 10
Test: none
Given provider uid 5 on site B
When finalize is posted from site A with `providerUid=5`
Then the request is refused · provider 5 keeps its `pid` and default flag on site B

### T3AF-SET-035 Settings cannot be written for a site outside the user's web mounts
P1 · security · release · gap
Rows: 3, 8, 10
Test: none
Given an editor whose web mounts cover only site A
When they POST `ajax_nst3af_feature_settings_save` with `id` set to a page in site B
Then the request is refused · site B `tx_nst3af_extension_setting` rows are unchanged

### T3AF-SET-036 Secret settings are stored encrypted
P0 · security · smoke, release · auto
Rows: 10
Test: `Tests/Unit/Settings/ExtensionSettingsSecretServiceTest.php::testEncryptAndDecryptRoundTrip`
Given a value for a secret listed in `Configuration/Secrets/secrets.php` (for example `deepl_api_key`)
When it is saved
Then `settings_json` holds an `enc:v1:` value, not the plain key · reading it back returns the original key

### T3AF-SET-037 Empty or masked submit keeps the stored secret
P0 · security · release · auto
Rows: 7, 10
Test: `Tests/Unit/Settings/ExtensionSettingsSecretServiceTest.php::testMergeForSavePreservesSecretWhenSubmittedEmpty`, `::testMergeForSaveIgnoresMaskedPlaceholderWithPrefix`
Given a stored secret
When the form is saved with the secret field empty or with the mask placeholder
Then the stored ciphertext is unchanged

### T3AF-SET-038 Undecryptable secret is shown as not configured
P1 · security · — · gap
Rows: 5, 10, 13
Test: none
Given a stored `enc:v1:` secret after the TYPO3 encryption key was changed
When settings are read
Then the secret is treated as empty and the UI shows it as not configured · the ciphertext is not shown · a warning is logged (today the failure is silent)

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
