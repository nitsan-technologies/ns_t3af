# AI providers + adapters — cases

**Prefix:** `T3AF-PROV`
**How it works:** `context/features/providers.md`, `tasks/implement-provider.md`
**Main code:** `Classes/Controller/Backend/ProviderController.php`, `Classes/Service/ProviderFormService.php`, `Classes/Service/CredentialCipher.php`, `Classes/Service/ProviderImportService.php`, `Classes/Service/ProviderLegacyConfigService.php`, `Classes/Domain/Model/Provider.php`, `Classes/Domain/Repository/ProviderRepository.php`, `Classes/Provider/` (`AdapterRegistry`, `Capability`, `ProviderCapabilityGuard`, `OpenAiCompatible/`, `SymfonyAi/`, `Model/`), `Classes/DependencyInjection/AdapterCompilerPass.php`, `Classes/Updates/DropLegacyProviderIdentifierUniqueUpdate.php`, `Classes/Updates/StripUnsupportedImageGenerationCapabilityUpdate.php`, table `tx_nst3af_provider`, AJAX routes `nst3af_provider_*` (`Configuration/Backend/AjaxRoutes.php`), module routes `t3af_dashboard.providers*`
**Existing tests:** `Tests/Unit/Service/ProviderFormServiceTest.php`, `Tests/Unit/Service/CredentialCipherTest.php`, `Tests/Unit/Service/ProviderLegacyConfigServiceTest.php`, `Tests/Unit/Domain/Model/ProviderTest.php`, `Tests/Unit/Domain/Repository/ProviderRepositorySoftDeleteTest.php`, `Tests/Unit/Provider/**`, `Tests/Unit/DependencyInjection/AdapterCompilerPassTest.php`, `Tests/Functional/Controller/Backend/ProviderControllerFunctionalTest.php`

Provider resolution at call time (default / explicit / disabled provider, capability errors) is in `public-api.md`.

---

## Not applicable

| Row | Reason |
|---|---|
| 8 Language / site (translated language, RTL) | Provider rows have no language fields (`rootLevel -1`, not translatable). Only the multi-site part applies (004, 010, 023) |
| 10 Security (SSRF on saved `endpoint_url`) | Saved endpoints are only checked with `FILTER_VALIDATE_URL`. Private hosts are allowed on purpose (Ollama on `host.docker.internal:11434`). Protection is the table modify permission (022). The unsaved draft probe is case 040 |

---

## Positive

### T3AF-PROV-001 New provider is saved with an encrypted API key
P0 · positive · smoke, release · auto
Rows: 1, 10
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testEncryptsPlaintextApiKeyOnInsert`
Given an admin on a site root page in **AI Foundation → Providers**
When they save a new provider: identifier `openai-main`, adapter `symfony.openai`, API key `sk-test-123`, capabilities chat + streaming
Then the save succeeds · `tx_nst3af_provider.api_key` starts with `enc:v1:` · `capabilities` = `chat,streaming` · `is_default` is not written in the insert (default is set separately)

### T3AF-PROV-002 Connection test marks the provider connected
P1 · positive · smoke, release · manual
Rows: 1, 13
Test: none
Given a saved provider with a valid API key
When the admin clicks **Test connection** (AJAX `nst3af_provider_test`)
Then the JSON has `ok: true`, `models` and `latencyMs` · the row has `last_status = connected`, `last_status_at` set, `last_status_message` filled · the list badge shows connected

### T3AF-PROV-003 Default checkbox makes the provider the site default
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testCallsSetDefaultWhenIsDefaultChecked`
Given a new provider form with **Default** checked on storage pid 1
When it is saved
Then `ProviderRepository::setDefault(<uid>, 1)` is called once after the insert

### T3AF-PROV-004 Set default from the list keeps one default per site
P1 · positive · release · manual
Rows: 1, 8
Test: none
Given site A with providers `a1` (default) and `a2`, and site B with default provider `b1`
When the admin sets `a2` as default in site A (AJAX `nst3af_provider_set_default`)
Then the JSON is `{"ok":true,"identifier":"a2"}` · site A has exactly one row with `is_default = 1` (`a2`) · `b1` is still default in site B

### T3AF-PROV-005 Unchecked Enabled toggle is saved as disabled
P1 · positive · release · auto
Rows: 4
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testEditSavePersistsUncheckedIsEnabled`
Given an existing enabled provider
When it is saved with the **Enabled** checkbox unchecked (field missing from POST)
Then `is_enabled = 0` is written

### T3AF-PROV-006 Governance fields are saved with the provider
P2 · positive · — · auto
Rows: 1, 3
Test: `Tests/Functional/Controller/Backend/ProviderControllerFunctionalTest.php::governanceFieldsPersistThroughFormService`
Given a provider form with `be_groups` = one backend group, `privacy_level = reduced`, `no_rerouting = 1`
When it is saved through `ProviderFormService`
Then the stored provider has that group uid in `beGroups`, `privacyLevel = reduced`, `noRerouting = true`

### T3AF-PROV-007 Only installed Symfony AI bridges appear as adapters
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Provider/SymfonyAi/SymfonyAiPlatformDiscoveryTest.php::testDiscoversOnlyMatchingPackages`
Given some `symfony/ai-*-platform` packages are installed
When adapters are discovered
Then one adapter type exists per installed bridge package · packages that are not installed give no adapter

### T3AF-PROV-008 Custom adapter from a child extension is registered
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Provider/AdapterRegistryTest.php::testIterableConstructorAddsAdapters`
Given a class implementing `AdapterInterface`, tagged `nst3af.adapter` in the child extension's `Services.yaml`
When the container builds `AdapterRegistry`
Then `AdapterRegistry::get('<type>')` returns that adapter

### T3AF-PROV-009 Legacy config bridge gives the default provider to `ns_t3cs`
P1 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/ProviderLegacyConfigServiceTest.php::testBuildLegacyConfigMapsDefaultProviderAndNonProviderKeys`
Given a default `symfony.openai` provider with model `gpt-4o`
When `ProviderLegacyConfigService::buildLegacyConfig()` runs
Then the array has `openai_api_key` (decrypted, in memory only), `openai_model = gpt-4o`, `defaultModel = openai`

### T3AF-PROV-010 Import copies providers from another site
P1 · positive · release · gap
Rows: 1, 7, 8
Test: none
Given site B with providers `p1` (default) and `p2`, and site A with no providers
When the admin imports `p1` and `p2` into site A (AJAX `nst3af_provider_import_execute`)
Then the JSON has `imported: 2` · two new rows with `pid` = site A storage, same `api_key` ciphertext, `last_used_at = 0` · the `p1` copy is default in A · site B rows are unchanged

### T3AF-PROV-011 Providers tab works on TYPO3 13.4 and 14.3
P1 · positive · release · manual
Rows: 12
Test: none
Given the same provider data on a 13.4 and a 14.3 site
When the admin opens Providers, saves a provider in the drawer and runs **Test connection**
Then on both versions the module icon shows (14.3 `ModuleV14.svg`, 13.4 `ns-t3af-module13`) · validation errors re-render inside the open drawer · the status badge updates

### T3AF-PROV-012 Save, delete and default change are written to the activity log
P3 · positive · — · manual
Rows: 13
Test: none
Given an admin
When they save a provider, set it default, and delete it
Then the AI Foundation activity log shows one entry per action with identifier and title · no entry contains the API key

---

## Negative

### T3AF-PROV-013 Missing identifier, title or adapter is rejected
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testRequiresIdentifierTitleAndAdapterType`
Given an empty provider form
When it is saved
Then errors are shown for `identifier`, `title` and `adapter_type` · no row is written

### T3AF-PROV-014 Duplicate identifier on the same site is rejected
P1 · negative · release · auto
Rows: 2, 7
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testRejectsDuplicateIdentifierForDifferentUid`
Given provider `taken` exists on storage pid 1
When a new provider with identifier `taken` is saved on pid 1
Then the error is `Identifier "taken" is already in use.` · no second row is written · the existing row is unchanged

### T3AF-PROV-015 Unregistered adapter type is rejected
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testRejectsUnregisteredAdapterType`
Given a POST with an `adapter_type` that is not in `AdapterRegistry`
When it is saved
Then the error is `Adapter type "<type>" is not registered.` · no row is written

### T3AF-PROV-016 All capabilities unchecked is rejected
P1 · negative · release · auto
Rows: 2, 7
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testRejectsMissingCapabilitiesAsNoneSelected`
Given a provider form for a chat adapter with every capability box unchecked (`capabilities[]` missing from POST)
When it is saved
Then the error is `Select at least one capability.` · repository `save()` is never called · the stored capability CSV is not kept silently

### T3AF-PROV-017 Invalid endpoint URL for Custom / Other is rejected
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testOpenAiCompatibleRejectsInvalidEndpointUrl`
Given adapter `nst3af.openai_compatible` with an endpoint that is not a URL
When it is saved
Then `errors.endpoint_url` is set · no row is written

### T3AF-PROV-018 New provider without API key is rejected
P2 · negative · — · gap
Rows: 2
Test: none
Given a new `symfony.openai` provider with an empty API key
When it is saved
Then the error is `API key is required.` · no row is written

### T3AF-PROV-019 Missing encryption key blocks save with a readable error
P1 · negative · release · auto
Rows: 2, 10
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testSaveReturnsApiKeyErrorWhenEncryptionKeyMissing`
Given `SYS/encryptionKey` is empty
When a provider with an API key is saved
Then `errors.api_key` mentions the encryption key · no row is written · no plaintext key is stored

### T3AF-PROV-020 Failed connection test does not store a newly typed key
P0 · negative · release · gap
Rows: 5, 7, 10
Test: none
Given a saved provider with key A, and key B typed into the drawer but not saved
When **Test connection** fails (`ok: false`)
Then `api_key` is still the ciphertext of key A · `last_status*` is not written for this draft test · no other field changes

### T3AF-PROV-021 Wrong key shows a readable disconnected status
P1 · negative · release · manual
Rows: 5, 13
Test: none
Given a saved provider with an invalid API key
When the admin clicks **Test connection**
Then the drawer shows a readable message (for example authentication failed) · the row has `last_status = disconnected` and a `last_status_message` without the key · no other field changes

### T3AF-PROV-022 Read-only user cannot change providers
P1 · negative · release · manual
Rows: 3
Test: none
Given a backend user with module access but no modify permission on `tx_nst3af_provider`
When they open `providers.new` / `providers.edit`, POST `providers.save` / `providers.delete`, and POST AJAX test / set-default / import-execute
Then module routes redirect with `flash=access-denied` · AJAX returns 403 `{"ok":false,"message":"access-denied"}` · the list shows read-only · no provider row changes

### T3AF-PROV-023 Provider of another site cannot be edited or deleted here
P1 · negative · release · manual
Rows: 3, 8
Test: none
Given provider uid 7 belongs to site B, and the admin has site A selected in the page tree
When they open `providers.edit?uid=7` and POST `providers.delete` with `uid=7`
Then edit redirects with `flash=not-found` · row 7 keeps `deleted = 0` · no "provider deleted" activity log entry is written

### T3AF-PROV-024 Connection test with an unregistered adapter returns failure
P2 · negative · — · auto
Rows: 5
Test: `Tests/Functional/Controller/Backend/ProviderControllerFunctionalTest.php::testActionReturnsFailureWhenAdapterIsNotRegistered`
Given a provider row whose `adapter_type` has no registered adapter
When `nst3af_provider_test` is called for it
Then HTTP 200 with `"ok":false` · no exception page

### T3AF-PROV-025 Import rejects the same site or an empty selection
P2 · negative · — · gap
Rows: 2
Test: none
Given site A is selected
When `nst3af_provider_import_execute` is called with `sourceStoragePid` = site A, or with no `uids`
Then HTTP 400 `Select at least one provider to import.` · no row is written

---

## Edge

### T3AF-PROV-026 Blank API key on edit keeps the stored key
P1 · edge · release · auto
Rows: 7
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testKeepsExistingApiKeyWhenInputBlankOnEdit`
Given a saved provider with an encrypted key
When it is edited with the API key field left empty
Then `api_key` is not part of the update · the stored ciphertext is unchanged

### T3AF-PROV-027 Changing the adapter clears model ids
P2 · edge · — · auto
Rows: 7
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testEditClearsStaleModelsWhenAdapterTypeChanges`
Given a provider on `symfony.openai` with `model_id = gpt-4o`
When it is saved with a different adapter type
Then `model_id` and `embedding_model_id` are saved as `''`

### T3AF-PROV-028 Changing the API key resets the connection status
P2 · edge · — · auto
Rows: 7, 13
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testEditResetsLastStatusWhenApiKeyChanges`
Given a provider with `last_status = connected`
When a different API key is saved
Then `last_status = unknown`, `last_status_message = unknown`, `last_status_at = 0`

### T3AF-PROV-029 Embedding model forces the embeddings capability
P2 · edge · — · auto
Rows: 7
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testEmbeddingModelIdAutoAddsEmbeddingsCapability`
Given a provider form with `embedding_model_id` set and embeddings unchecked
When it is saved
Then `embeddings` is added to `capabilities`

### T3AF-PROV-030 Empty embedding model uses the vendor default, not the chat model
P1 · edge · release · auto
Rows: 5
Test: `Tests/Unit/Domain/Model/ProviderTest.php::testEffectiveEmbeddingModelUsesVendorDefaultInsteadOfChatModel`
Given a vendor provider (OpenAI / Mistral / Gemini / Hugging Face) with empty `embedding_model_id`
When `Provider::effectiveEmbeddingModel()` is read
Then it returns the entry from `Provider::DEFAULT_EMBEDDING_MODELS` · never the chat `model_id`

### T3AF-PROV-031 Translate-only adapters save with no capabilities
P2 · edge · — · auto
Rows: 2
Test: `Tests/Unit/Service/ProviderFormServiceTest.php::testAllowsEmptyCapabilitiesForTranslateOnlyAdapters`
Given an adapter whose `getDefaultCapabilities()` is `[]` (DeepL / Google Translate from `ns_t3ai`)
When it is saved with no capability checked
Then the save succeeds

### T3AF-PROV-032 Image generation is dropped on chat-only adapters
P2 · edge · — · auto
Rows: 7
Test: `Tests/Unit/Domain/Model/ProviderTest.php::testFromRowDropsImageGenerationOnChatOnlyAdapters`
Given a stored row on a chat-only adapter with `image_generation` in `capabilities`
When the row is loaded as a `Provider`
Then `image_generation` is not in `Provider::$capabilities`

### T3AF-PROV-033 Soft delete frees the identifier and clears default
P1 · edge · release · auto
Rows: 7
Test: `Tests/Unit/Domain/Repository/ProviderRepositorySoftDeleteTest.php::testSoftDeleteTombstonesIdentifierAndClearsDefault`
Given a default provider uid 5
When it is deleted
Then the row has `deleted = 1`, `is_default = 0`, `identifier = deleted-5` · a new provider can reuse the old identifier

### T3AF-PROV-034 Providers are read-only while credits mode is active
P2 · edge · — · manual
Rows: 6
Test: none
Given T3Planet Credits mode is active (enabled + token)
When the admin opens the Providers tab
Then the provider list is shown read-only (no add / edit actions) · existing rows are unchanged · AI calls go to `t3planet_credits`, not to these rows

### T3AF-PROV-035 Upgrade wizards run once and are idempotent
P2 · edge · release · manual
Rows: 9, 12
Test: none
Given a database with the legacy global unique index on `identifier`, and a chat-only row with `image_generation`
When `typo3 upgrade:run nst3afDropLegacyProviderIdentifierUnique` and `typo3 upgrade:run nst3afStripUnsupportedImageGenerationCapability` run, then `typo3 upgrade:list` runs
Then both exit 0 · the index is gone and `image_generation` is removed from that row · neither wizard is listed as necessary any more · no other provider field changes

---

## Security

### T3AF-PROV-036 Edit drawer shows only a masked key
P0 · security · release · manual
Rows: 10
Test: none
Given a provider with key `sk-very-long-secret`
When the admin opens the edit drawer and inspects page source and the JSON of search / models / test calls
Then only the mask (`sk-` followed by bullets) appears · the full key is not in HTML, JSON or JS

### T3AF-PROV-037 Cipher round-trip returns the original key
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Service/CredentialCipherTest.php::testEncryptDecryptRoundTrip`
Given a valid `SYS/encryptionKey`
When a key is encrypted and then decrypted
Then the ciphertext starts with `enc:v1:` · decrypt returns the exact original key

### T3AF-PROV-038 Tampered ciphertext is rejected
P1 · security · — · auto
Rows: 10
Test: `Tests/Unit/Service/CredentialCipherTest.php::testDecryptTamperedCiphertextThrows`
Given an `enc:v1:` value with changed bytes
When it is decrypted
Then `CipherException` is thrown · no partial plaintext is returned

### T3AF-PROV-039 Provider error that echoes the key is redacted
P0 · security · release · gap
Rows: 5, 10, 13
Test: none
Given a provider whose error text contains the API key (`key-in-error` fixture; Gemini sends the key as `?key=` in the URL)
When `complete()` fails or **Test connection** fails
Then the key is not in the `AdapterRuntimeException` message, `tx_nst3af_request_log.raw_meta`, the `ProviderRequestFailedEvent` error, `last_status_message`, or the test JSON `message`

### T3AF-PROV-040 Model probe on a draft endpoint needs modify permission
P1 · security · release · manual
Rows: 3, 10
Test: none
Given a backend user without modify permission on `tx_nst3af_provider`
When they call `nst3af_provider_models` with `uid=0`, `adapterType=nst3af.openai_compatible`, `endpoint=http://169.254.169.254/`
Then the call is refused (403) · no outbound HTTP request is made · nothing is cached in `nst3af_provider_models`

### T3AF-PROV-041 Save and delete need POST with a valid token
P1 · security · — · manual
Rows: 10
Test: none
Given a logged-in admin
When `providers.save` / `providers.delete` is called with GET, or with POST but without the module route token
Then the request is refused · no provider row changes

### T3AF-PROV-042 Provider fields and probe messages render escaped
P1 · security · — · manual
Rows: 10, 11
Test: none
Given a provider title `<img src=x onerror=alert(1)>` and a provider error message that contains HTML
When the list, drawer and connection badge render
Then the text is shown escaped · no script runs

---

## AI output

### T3AF-PROV-043 Reasoning model answer is read without the thinking part
P1 · ai · release · auto
Rows: 11
Test: `Tests/Unit/Provider/SymfonyAi/SymfonyAiResultReaderTest.php::reasoningModelAnswerIsNotLost`
Given a Symfony AI result with a thinking part and a text part `Hallo! Wie kann ich helfen?`
When `SymfonyAiResultReader::text()` reads it
Then it returns only `Hallo! Wie kann ich helfen?` · the thinking text is only available from `thinking()`

---

Field reference and rules: `_template.md`.
