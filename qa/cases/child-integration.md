# Child extension integration — cases

**Prefix:** `T3AF-CHILD`
**How it works:** `context/features/child-extensions.md`, `Documentation/DeveloperGuide/ExtensionIntegration/Index.rst`
**Main code:** `Configuration/Services.yaml` (`_instanceof` tags), `Classes/Contract/*ProviderInterface.php`, `Classes/Registry/*Registry.php`, `Classes/DependencyInjection/{AdapterCompilerPass,McpCapabilityCompilerPass}.php`, `Classes/Provider/AdapterRegistry.php`, `Classes/Credits/{CreditsFeatureKeyMapper,LicenseKeyResolver}.php`, `Classes/Service/ProviderLegacyConfigService.php`, `Classes/Mcp/Service/McpToolOwnershipResolver.php`, `Classes/Bootstrap/T3afPharBootstrap.php`; child side: `../ns_t3ai/composer.json`, `../ns_t3ai/ext_emconf.php`, `../ns_t3ai/Classes/Feature/T3AiCreditsFeatureKeyAliasProvider.php`
**Existing tests:** `Tests/Unit/Registry/*Test.php`, `Tests/Unit/Provider/AdapterRegistryTest.php`, `Tests/Unit/DependencyInjection/AdapterCompilerPassTest.php`, `Tests/Unit/Credits/{CreditsFeatureKeyMapper,LicenseKeyResolver,ProxyAiExecutorLegacyUnwrap}Test.php`, `Tests/Unit/Mcp/Service/McpToolOwnershipResolverTest.php`, `Tests/Unit/Mcp/Tool/Custom/McpCustomToolRegistrarTest.php`, `Tests/Unit/Bootstrap/T3afPharIntegrationTest.php`, `Tests/Unit/ExtEmconfAutoloadTest.php`

---

## Not applicable

None. Every checklist row has at least one case below.

---

## Positive

### T3AF-CHILD-001 Installed child extension shows up in every AI Foundation area
P1 · positive · smoke, release · manual
Rows: 1
Test: none
Given `ns_t3af` and `ns_t3ai` installed together
When an admin opens AI Features, AI Prompts, AI Permissions, AI Logs filter and Scheduler & CLI
Then T3AI feature cards, prompt categories, permission entries, log channel and `t3ai:*` commands are listed · the T3AI module is a child of the `t3af` parent menu

### T3AF-CHILD-002 Child AI call is logged under the child extension
P1 · positive · smoke, release · manual
Rows: 1, 13
Test: none
Given `ns_t3ai` with a working provider
When an editor generates a meta description in T3AI
Then a `tx_nst3af_request_log` row exists with `extension_key = ns_t3ai`, its feature key and token or credit numbers · it is visible in AI Usage filtered by T3AI

### T3AF-CHILD-003 Feature cards are sorted and filtered by module access
P2 · positive · — · auto
Rows: 1, 3
Test: `Tests/Unit/Registry/AiFeatureCardProviderRegistryTest.php::testBuildCatalogSortsByPriorityAndFiltersByModuleAccess`
Given two `AiFeatureCardProviderInterface` services with different priorities, one for a module the user cannot access
When the AI Features catalog is built
Then cards are sorted by priority · the card for the inaccessible module is absent

### T3AF-CHILD-004 Child settings scopes are resolved from providers
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Registry/ExtensionSettingsScopeRegistryTest.php::testResolvesCompositePaletteAndFieldFilterScopes`
Given a child `ExtensionSettingsScopeProviderInterface` with palette and field-filter scopes
When the drawer asks for a scope
Then the drawer shows only the fields of that scope

### T3AF-CHILD-005 Field redirect sends a child field to its own handler
P3 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Registry/ExtensionSettingsFieldRedirectRegistryTest.php::testFindRedirectHandlerReturnsMatchingProvider`
Given a child `ExtensionSettingsFieldRedirectProviderInterface` for one field
When that field is looked up
Then the matching child handler is returned

### T3AF-CHILD-006 Child permission catalog overrides module access by key
P2 · positive · — · auto
Rows: 1, 3
Test: `Tests/Unit/Registry/AiAccessCatalogProviderRegistryTest.php::testProviderModuleAccessOverridesByCatalogKey`, `Tests/Unit/Registry/FeatureAccessBindingRegistryTest.php::testResolvesT3AiTabFeature`
Given a child `AiAccessCatalogProviderInterface` and a T3AI tab feature binding
When AI Permissions builds its catalog
Then the child's module access entry replaces the default for that key · the T3AI tab resolves to its feature permission

### T3AF-CHILD-007 MCP Tools tab prefers child card providers
P3 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Registry/McpToolsExtensionCardProviderRegistryTest.php::testBuildExtensionConfigsPrefersProviderOverLegacy`, `::testBuildExtensionConfigsFillsGapsFromLegacyCatalog`
Given a child `McpToolsExtensionCardProviderInterface` and the legacy catalog
When the MCP Tools extension cards are built
Then the child provider's card wins · missing extensions are filled from the legacy catalog

### T3AF-CHILD-008 MCP tool is grouped under its owner extension
P2 · positive · — · auto
Rows: 1, 13
Test: `Tests/Unit/Mcp/Service/McpToolOwnershipResolverTest.php::resolveUsesExplicitOwnerExtensionKeyFirst`, `::resolveInfersOwnerFromNamespace`
Given a child MCP tool with and without an explicit owner extension key
When ownership is resolved
Then the explicit key is used first · otherwise the owner comes from the PHP namespace

### T3AF-CHILD-009 Custom provider adapter appears in Providers
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Provider/AdapterRegistryTest.php::testIterableConstructorAddsAdapters`, `Tests/Unit/DependencyInjection/AdapterCompilerPassTest.php::testDescriptorDefinitionForwardsFactoryAndCatalogClass`
Given a child class implementing `AdapterInterface` (auto-tagged `nst3af.adapter`)
When the container is built
Then the adapter is in `AdapterRegistry` · its descriptor keeps the factory and catalog class

### T3AF-CHILD-010 Child CLI commands are grouped by extension
P3 · positive · — · auto
Rows: 9
Test: `Tests/Unit/Service/SchedulerCliCommandCatalogServiceTest.php::testExtensionGroupsGroupsCommandsByExtension`
Given commands from `ns_t3af` and `ns_t3ai`
When Scheduler & CLI is opened
Then each extension's commands are in their own group

### T3AF-CHILD-011 Child settings in CLI resolve to the right site
P2 · positive · — · auto
Rows: 8, 9
Test: `Tests/Unit/Service/SiteExtensionSettingsResolverTest.php::testResolveScansSitesWhenNoPageContext`
Given `ns_t3ai` settings stored only on site root 42 and a storage probe from `ns_t3ai`
When a CLI job resolves settings without a page id
Then site 42 is used · the job does not fail

---

## Negative

### T3AF-CHILD-012 Unavailable child providers are skipped
P2 · negative · — · auto
Rows: 4, 5
Test: `Tests/Unit/Registry/AiAccessCatalogProviderRegistryTest.php::testSkipsUnavailableProviders`, `::testNullFeatureAccessBindingsAreSkipped`
Given a child provider that reports itself unavailable, or returns no binding
When the permission catalog is built
Then its entries are not added · other entries are unchanged

### T3AF-CHILD-013 Child tab is denied when the feature permission is missing
P1 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Access/FeaturePermissionGateTest.php::testT3AiTabDeniedWhenFeatureMissing`
Given an editor without the T3AI tab feature permission
When they open that T3AI tab
Then access is denied · no tab data is shown

### T3AF-CHILD-014 Uninstalling a child keeps the foundation working
P1 · negative · release · manual
Rows: 4, 7
Test: none
Given `ns_t3ai` was used and then deactivated
When an admin opens every AI Foundation tab and runs a `t3af:*` command
Then nothing errors · T3AI cards, prompts and channels are gone · the `ns_t3ai` row in `tx_nst3af_extension_setting` and old request-log rows are still there

### T3AF-CHILD-015 Drawer for an unloaded child returns an error, not another form
P2 · negative · — · gap
Rows: 2, 4
Test: none
Given `ns_t3ai` not loaded
When `ajax_nst3af_feature_settings_get` is called with `extension=ns_t3ai`
Then HTTP 503 with `ns_t3ai extension is not loaded.` · the `ns_t3af` settings form is not returned and nothing is saved (today an unsupported key falls back to the default extension)

### T3AF-CHILD-016 Child with an incompatible foundation version is not installed
P1 · negative · release · manual
Rows: 12
Test: none
Given `nitsan/ns-t3af` 1.x in the project
When `composer require nitsan/ns-t3ai` is run, or ns_t3ai is activated in Classic mode
Then Composer refuses the install (`nitsan/ns-t3af ^2.0`) and Extension Manager refuses activation (`ns_t3af` `2.0.0-2.99.99`) · no files or DB tables change

### T3AF-CHILD-017 Released child does not allow a dev foundation
P2 · negative · release · manual
Rows: 12
Test: none
Given a tagged release of `ns_t3ai`
When `composer.json` is checked
Then the requirement is a stable range like `^2.0` with no `@dev` (today it is `^2.0 || @dev`)

### T3AF-CHILD-018 MCP tool without `#[McpTool]` fails with a clear message
P2 · negative · — · gap
Rows: 2, 13
Test: none
Given a child class implementing `McpToolHandlerInterface` whose `execute()` has no `#[McpTool]` attribute
When the container is built (`McpCapabilityCompilerPass`)
Then the build fails with `MCP handler "<id>" (<class>) must annotate execute() with …` · no half-built MCP tool list is used

### T3AF-CHILD-019 One failing custom MCP tool does not break the others
P1 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Mcp/Tool/Custom/McpCustomToolRegistrarTest.php::registerCollectedSkipsFailingToolWithoutBreakingOthers`, `::collectPhpToolsSkipsMissingClassAndLogsWarning`
Given two custom tools, one throwing on register and one pointing to a missing class
When MCP tools are registered
Then the good tool is registered · the missing class is logged as a warning · no exception reaches the MCP client

### T3AF-CHILD-020 A faulty feature card provider does not break AI Features
P2 · negative · — · gap
Rows: 5
Test: none
Given a child `AiFeatureCardProviderInterface` that throws an exception
When the AI Features tab opens
Then the other cards render · the error is logged (today `AiFeatureCardProviderRegistry` has no try/catch)

### T3AF-CHILD-021 Unknown adapter type is reported
P2 · negative · — · auto
Rows: 2, 5
Test: `Tests/Unit/Provider/AdapterRegistryTest.php::testGetUnknownThrows`
Given a provider row with an adapter type no extension registers
When the adapter is requested
Then an exception is thrown · no other adapter is used in its place

---

## Edge

### T3AF-CHILD-022 Child feature keys map to the right credit keys
P0 · edge · release · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsFeatureKeyMapperTest.php::testMapsT3AiTranslationKeys`, `::testMapsMediaTtsToTextToSpeechForT3ai`
Given T3AI translation and media TTS calls in credits mode
When the credit key is resolved
Then translation keys map to their translation credit key · `media.tts` maps to `text_to_speech`

### T3AF-CHILD-023 EXTCONF aliases from a child are applied
P1 · edge · — · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsFeatureKeyMapperTest.php::testExtensionExtConfAliasesAreApplied`
Given `$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases']['my_extension']['custom.writer']`
When `my_extension` charges `custom.writer`
Then the configured credit key is used

### T3AF-CHILD-024 Unknown child feature key is billed as content generation
P1 · edge · — · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsFeatureKeyMapperTest.php::testUnknownKeyFallsBackToContentGenerate`
Given a child feature key with no mapping or alias
When a credit charge is made
Then it is billed as `content_generate`

### T3AF-CHILD-025 Only ns_t3af licences are sent to the credits API
P0 · edge · release · auto
Rows: 4, 10
Test: `Tests/Unit/Credits/LicenseKeyResolverTest.php::testBuildLicenseKeysCommaSeparatedIncludesOnlyNsT3afLicenses`
Given licences for several T3Planet extensions
When the licence header for the credits API is built
Then only `ns_t3af` licence keys are included

### T3AF-CHILD-026 Legacy child config reads the foundation provider
P1 · edge · — · auto
Rows: 7, 12
Test: `Tests/Unit/Service/ProviderLegacyConfigServiceTest.php::testBuildLegacyConfigMapsDefaultProviderAndNonProviderKeys`, `::testBuildLegacyConfigInheritsUniverseAdminKeyForChildExtensions`
Given a child that still reads its old extension configuration keys
When its legacy config is built
Then the default provider and non-provider keys are mapped · the admin key is inherited from `ns_t3af`

### T3AF-CHILD-027 Second adapter of the same type replaces the first
P3 · edge · — · auto
Rows: 7
Test: `Tests/Unit/Provider/AdapterRegistryTest.php::testAddOverridesSameType`
Given two adapters with the same type
When both are registered
Then the later one is used · there is one entry for that type

### T3AF-CHILD-028 Empty child stub keeps the suite tab map
P3 · edge · — · auto
Rows: 3
Test: `Tests/Unit/Registry/AiAccessCatalogProviderRegistryTest.php::testEmptyChildStubDoesNotOverwriteSuiteTabMap`
Given a child provider that returns an empty tab map
When the access catalog is built
Then the existing suite tab map is unchanged

### T3AF-CHILD-029 Embedding settings need an embedding-capable extension
P3 · edge · — · auto
Rows: 4
Test: `Tests/Unit/Access/ExtensionAvailabilityTest.php::testEmbeddingModelConfigurationRequiresT3CsT3AsOrT3AcWhenOnlyChildProviders`
Given only child extensions without embedding support
When embedding model configuration is checked
Then it is not available until `ns_t3cs`, `ns_t3as` or `ns_t3ac` is installed

### T3AF-CHILD-030 Table write check follows the group setting
P2 · edge · — · auto
Rows: 3
Test: `Tests/Unit/Access/BackendPermissionCheckTest.php::testIsGrantedUsesBooleanCheckResult`
Given a group with `tables_modify` on `tx_nst3af_provider` but not on another table
When `BackendPermissionCheck::isGranted()` is called for both tables
Then it is true for `tx_nst3af_provider` · false for the other table

### T3AF-CHILD-031 Classic mode bundles all bridges
P1 · edge · release · auto
Rows: 12
Test: `Tests/Unit/Bootstrap/T3afPharIntegrationTest.php::testProvidersCommandReportsEveryBridgeAvailable`, `Tests/Unit/ExtEmconfAutoloadTest.php::testExtEmconfDeclaresPsr4MatchingComposerJson`
Given a Classic-mode (non-Composer) install
When the bundled PHAR is loaded
Then every bridge reports available · `ext_emconf.php` PSR-4 matches `composer.json`

### T3AF-CHILD-032 Child works in Composer and Classic mode on 13.4 and 14.3
P1 · edge · release · manual
Rows: 12
Test: none
Given four installs: Composer and Classic, each on TYPO3 13.4 and 14.3, with `ns_t3af` and `ns_t3ai`
When T3AI generates a meta description and the AI Features drawer saves a T3AI value
Then all four succeed with the same request-log and settings rows

---

## Security

### T3AF-CHILD-033 Child secrets are stored encrypted
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Settings/ExtensionSettingsSecretServiceTest.php::testEncryptAndDecryptRoundTrip`
Given a child secret declared through `ExtensionSettingsSecretProviderInterface` (for example `ns_t3ai` `stabilityAiApiKey`)
When it is saved in the drawer
Then `settings_json` holds an `enc:v1:` value · reading it back returns the original key

### T3AF-CHILD-034 Child card and settings labels are escaped
P1 · security · — · manual
Rows: 10
Test: none
Given a child card title and a field label containing `<script>alert(1)</script>`
When AI Features and the drawer render (the drawer HTML is inserted with `innerHTML` in `ai-features.js`)
Then the text is shown literally · no script runs

### T3AF-CHILD-035 Child log channels fit the sys_log column
P2 · security · — · auto
Rows: 13
Test: `Tests/Unit/Service/AiLogChannelCatalogTest.php::canonicalChannelsFitSysLogColumn`
Given all registered AI log channels including child channels
When they are written to `sys_log`
Then no channel name is longer than the column · no log row is cut or lost

---

## AI output

### T3AF-CHILD-036 Single-field JSON reply is unwrapped for legacy SEO keys
P1 · ai · — · auto
Rows: 11
Test: `Tests/Unit/Credits/ProxyAiExecutorLegacyUnwrapTest.php::testCompleteUnwrapsSingleFieldJsonForLegacySeoKey`
Given a credits-mode reply `{"meta_description":"…"}` for a legacy T3AI SEO feature key
When the child receives the result
Then it gets the plain text, not the JSON wrapper

### T3AF-CHILD-037 Child output goes through foundation injection
P2 · ai · — · manual
Rows: 11
Test: none
Given a default brand profile on the site
When T3AI generates page content
Then the AI Logs prompt for that call contains the brand block · the T3AI result is in the page language

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
