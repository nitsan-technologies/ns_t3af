# Public API (`AiServiceInterface`) — cases

**Prefix:** `T3AF-API`
**How it works:** `context/features/public-api.md`, `context/architecture.md` § Request flow (`Documentation/Api/PublicApi.rst` is referenced but not present in this package)
**Main code:** `Classes/Api/` (`AiServiceInterface`, `AiOptions`, `AiResponse`, `EmbeddingResponse`, `StreamSummary`, `AiToolCallingServiceInterface`, `ImageGenerationServiceInterface`, `TtsServiceInterface`, `QualityScore`, `CreditsEstimate`, `AiLabelRecorderInterface`), `Classes/Service/AiService.php`, `Classes/Service/AiToolCallingService.php`, `Classes/Service/ImageGenerationService.php`, `Classes/Service/TtsService.php`, `Classes/Event/`, `Classes/Service/RequestTelemetryService.php`, `Classes/AiLabel/EventListener/GenerationCaptureListener.php`, table `tx_nst3af_request_log`
**Existing tests:** `Tests/Unit/Service/AiServiceTest.php`, `Tests/Unit/Service/AiToolCallingServiceTest.php`, `Tests/Unit/Service/ImageGenerationServiceTest.php`, `Tests/Unit/Service/TtsServiceTest.php`, `Tests/Unit/Service/RequestQualityResolverTest.php`, `Tests/Unit/Service/RequestTelemetryService*Test.php`, `Tests/Unit/Api/*`, `Tests/Unit/Event/BeforeProviderRequestEventTest.php`, `Tests/Unit/Governance/*`, `Tests/Functional/Governance/*`, `Tests/Architecture/ArchitectureTest.php`

Credits routing of the same calls (`T3PlanetCreditAiService` → `ProxyAiExecutor`) is in `credits.md`. Key redaction in provider errors is `T3AF-PROV-039`.

---

## Not applicable

| Row | Reason |
|---|---|
| 7 Data safety (preview, undo, duplicates) | The API writes no content records, only `tx_nst3af_request_log` and AI Label capture rows. The partial-write risk is the stream abort (037) |
| 8 Language / site (translated language, RTL) | `AiOptions` has no language field; the language is in the caller's prompt. Only the site part applies (013) |
| 10 Security (XSS in AI output) | The API returns raw model text. Escaping is the caller's job (`backend-module.md`, `ai-agent.md`, child cases) |
| 12 Compatibility | No TYPO3 version branch in `Classes/Api/`, `AiService`, `AiToolCallingService`, `ImageGenerationService` or `TtsService`. Release mode runs the auto cases on 13.4 and 14.3 |

---

## Positive

### T3AF-API-001 `complete()` returns content and fires before and after events
P0 · positive · smoke, release · auto
Rows: 1
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteHappyPathDispatchesBothEventsAndReturnsContent`
Given an enabled default provider `openai-prod` with model `gpt-4o`
When a child calls `complete('hello')`
Then `AiResponse::$content` is the provider text · `modelId = gpt-4o` · `providerIdentifier = openai-prod` · exactly `BeforeProviderRequestEvent` then `AfterProviderResponseEvent` are dispatched

### T3AF-API-002 Per-call model overrides the provider model
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteOptionModelIdOverridesProvider`
Given a provider with model `gpt-4o`
When `complete('hi', new AiOptions(modelId: 'gpt-5'))` is called
Then the platform receives model `gpt-5`

### T3AF-API-003 Token usage is read from provider metadata
P2 · positive · — · auto
Rows: 1, 13
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteExtractsTokensFromDeferredMetadataUsage`
Given a provider result with token usage in its metadata
When `complete()` returns
Then `AiResponse::$tokensInput` and `$tokensOutput` match the metadata values

### T3AF-API-004 `stream()` yields chunks in order and fires the after event
P1 · positive · smoke, release · auto
Rows: 1
Test: `Tests/Unit/Service/AiServiceTest.php::testStreamYieldsChunksAndDispatchesAfterEvent`
Given a provider that streams `a`, `b`, `c`
When a child iterates `stream()`
Then the chunks arrive as `['a', 'b', 'c']` · before and after events are dispatched · the after event carries content `abc`

### T3AF-API-005 `embed()` uses the configured embedding model
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Service/AiServiceTest.php::testEmbedUsesEmbeddingModelIdWhenConfigured`
Given a provider with chat model `gpt-4o` and `embedding_model_id = text-embedding-3-small`
When `embed('text')` is called
Then the platform receives `text-embedding-3-small` · `EmbeddingResponse::$modelId = text-embedding-3-small`

### T3AF-API-006 Embedding usage counts against budgets
P2 · positive · — · auto
Rows: 3, 13
Test: `Tests/Unit/Service/AiServiceTest.php::testEmbedDispatchesAfterProviderResponseEventForBudgetTracking`
Given budget listeners on `AfterProviderResponseEvent`
When `embed()` succeeds
Then one `AfterProviderResponseEvent` is dispatched for the embed call

### T3AF-API-007 Tool calling returns tool calls and usage
P1 · positive · release · auto
Rows: 1, 13
Test: `Tests/Unit/Service/AiToolCallingServiceTest.php::testSuccessfulRoundDispatchesAfterResponseEventWithUsage`
Given a tool-capable provider that answers with a `pages_get` tool call
When `completeWithTools()` runs
Then `AiToolCallingResponse::$toolCalls` has one call named `pages_get` · one after event with `tokensInput = 120`, `tokensOutput = 7`

### T3AF-API-008 Image generation uses the image model, not the chat model
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Service/ImageGenerationServiceTest.php::testGenerateUsesTheOpenAiImageModelInsteadOfTheChatModel`
Given an OpenAI provider with chat model `gpt-4o` and no image model set
When `ImageGenerationServiceInterface::generate()` is called
Then the request uses `gpt-image-1` · `ImageGenerationResponse::$modelId = gpt-image-1`

### T3AF-API-009 TTS returns audio and fires the TTS events
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Service/TtsServiceTest.php::testSpeakCallsPlatformSpeechAndReturnsResponse`
Given a provider `openai-prod` with the `tts` capability
When `TtsServiceInterface::speak('Hello')` is called
Then `TtsResponse` has the audio bytes and `mimeType = audio/mpeg` · `BeforeProviderRequestEvent`, `BeforeTtsRequestEvent`, `AfterTtsResponseEvent` and `AfterProviderResponseEvent` are dispatched

### T3AF-API-010 A before-event listener can rewrite the prompt
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/ImageGenerationServiceTest.php::testGenerateUsesMutatedPromptFromBeforeEvent`
Given a listener that changes the prompt in the before event
When `generate()` runs
Then the provider receives the changed prompt

### T3AF-API-011 Quality score from the response is kept for the log
P2 · positive · — · auto
Rows: 1, 13
Test: `Tests/Unit/Service/RequestQualityResolverTest.php::testResolveFromResponseQuality`
Given an `AiResponse` with `QualityScore(88, 90, 85, 80, 82)`
When the request quality is resolved
Then `quality_score = 88` · `quality_dimensions` is not empty

### T3AF-API-012 Public API classes have no internal dependencies
P1 · positive · release · auto
Rows: 1
Test: `Tests/Architecture/ArchitectureTest.php::testPublicApiHasNoInternalDependencies`
Given the `NITSAN\NsT3AF\Api\` namespace
When `composer stan` runs the phpat rules
Then no class in `Api\` imports services, adapters or controllers

### T3AF-API-013 A call with `pageId` uses that site's provider
P1 · positive · release · manual
Rows: 1, 8
Test: none
Given site A default provider `a-openai` and site B default provider `b-mistral`
When a child calls `complete('x', new AiOptions(pageId: <page on site B>))`
Then `AiResponse::$providerIdentifier = b-mistral` · the request-log row has `provider_identifier = b-mistral`

### T3AF-API-014 AI Label correlation id is added to the response
P2 · positive · — · gap
Rows: 1, 13
Test: none
Given AI Label capture is active
When `complete()` succeeds
Then `AiResponse::$raw['ailabelCorrelationId']` is a 32-character hex id · one capture row exists for that id · the id can be passed to `AiLabelRecorderInterface::bindGeneration()`

### T3AF-API-015 Successful call writes one attributed request-log row
P1 · positive · release · gap
Rows: 13
Test: none
Given `complete('x', new AiOptions(extensionKey: 'ns_t3ai', featureKey: 'seo.meta_description', requestSource: 'backend_page'))`
When the call succeeds
Then exactly one `tx_nst3af_request_log` row: `extension_key = ns_t3ai`, `feature_key = seo.meta_description`, `request_source = backend_page`, `request_type = complete`, `success = 1`, model and token counts set · without `requestSource` the value is `unknown`

### T3AF-API-016 TTS log keeps credits and token counts
P2 · positive · — · auto
Rows: 6, 13
Test: `Tests/Unit/Service/RequestTelemetryServiceTtsTest.php::testLogTtsPersistsCreditsAndTokensFromResponse`
Given a `TtsResponse` with credits usage and token counts
When the TTS call is logged
Then the request-log row stores the credits used and the token counts from the response

---

## Negative

### T3AF-API-017 No default provider gives a clear error
P1 · negative · release · auto
Rows: 5
Test: `Tests/Unit/Service/AiServiceTest.php::testProviderNoDefaultThrows`
Given no provider with `is_default = 1`
When `provider()` is called without an identifier
Then `UnknownAdapterException` is thrown (`No default AI provider is configured.`) · no request is sent

### T3AF-API-018 Unknown provider identifier gives a clear error
P2 · negative · — · auto
Rows: 2, 5
Test: `Tests/Unit/Service/AiServiceTest.php::testProviderUnknownIdentifierThrows`
Given no provider `missing`
When `provider('missing')` is called
Then `UnknownAdapterException` is thrown · no other provider is used

### T3AF-API-019 Disabled provider is refused, with no silent fallback
P1 · negative · release · gap
Rows: 4, 5
Test: none
Given the default provider has `is_enabled = 0`
When `complete('x')` is called
Then `UnknownAdapterException` with `AI provider "<title>" is disabled. Enable it under AI Foundation → AI Providers.` · no other provider is called · no request-log row with `success = 1`

### T3AF-API-020 Provider with an unregistered adapter is refused
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteUnknownAdapterTypeThrows`
Given a provider whose `adapter_type` is not registered
When `complete('hi')` is called
Then `UnknownAdapterException` is thrown

### T3AF-API-021 Provider failure becomes a typed exception and a failed event
P1 · negative · release · auto
Rows: 5, 13
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteFailureDispatchesFailureEventAndWrapsThrowable`
Given a platform that throws `LogicException('boom')`
When `complete('x')` is called
Then `AdapterRuntimeException('boom')` is thrown · `BeforeProviderRequestEvent` and `ProviderRequestFailedEvent` are dispatched

### T3AF-API-022 HTTP 429 is reported as rate limit
P1 · negative · release · auto
Rows: 5
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteRateLimitErrorIsClassifiedWithStatusCode`
Given a platform that fails with `HTTP/2 429`
When `complete('hello')` is called
Then `AdapterRuntimeException` with code 429 and message starting `Rate limit or quota exceeded at provider.`

### T3AF-API-023 401, 5xx and timeout get readable messages
P2 · negative · — · gap
Rows: 5
Test: none
Given a platform that fails with `HTTP/1.1 401`, `HTTP/1.1 503`, and a network timeout
When `complete()` is called for each
Then 401 → `Provider authentication/authorization failed.` · 503 → `Provider temporary server error.` · the timeout gives a readable message · the API key is in no message

### T3AF-API-024 Provider error in the body is shown, not an empty answer
P1 · negative · release · auto
Rows: 5, 11
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteSurfacesProviderErrorInsteadOfEmptyResponse`
Given a raw completion body that contains a provider `error` object and no text
When `complete()` reads it
Then `AdapterRuntimeException` is thrown with the provider error text · no empty `AiResponse` is returned

### T3AF-API-025 `embed()` without the embeddings capability is refused
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiServiceTest.php::testEmbedRejectsProviderWithoutEmbeddingsCapability`
Given a provider without `embeddings`
When `embed('x')` is called
Then `AdapterRuntimeException` contains `does not have the "embeddings" capability enabled` · no request is sent

### T3AF-API-026 Image message without the vision capability is refused
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteRejectsVisionPayloadWithoutVisionCapability`
Given a provider without `vision`
When `complete()` is called with an image part in `extra['messages']`
Then `AdapterRuntimeException` contains `does not have the "vision" capability enabled` · no request is sent

### T3AF-API-027 `stream()` without the streaming capability is refused
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiServiceTest.php::testStreamRejectsProviderWithoutStreamingCapability`
Given a provider without `streaming`
When `stream()` is iterated
Then `AdapterRuntimeException` is thrown before any chunk · no request is sent

### T3AF-API-028 TTS without the tts capability is refused
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/TtsServiceTest.php::testSpeakThrowsWhenProviderLacksTtsCapability`
Given a provider without `tts`
When `speak('Hello')` is called
Then `AdapterRuntimeException` mentioning `tts` is thrown · no audio is returned

### T3AF-API-029 Image call fails before the request when no image model exists
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/ImageGenerationServiceTest.php::testChatModelWithoutAnImageDefaultFailsBeforeTheRequest`
Given a provider whose adapter has no image default and no image model is set
When `generate()` is called
Then the error contains `No image model configured for provider "OpenAI"` · no image request is sent

### T3AF-API-030 Tool calling on a non-capable adapter fails loudly
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiToolCallingServiceTest.php::testCompleteWithToolsFailsLoudlyForNonCapableAdapter`
Given a provider whose adapter does not implement tool calling
When `completeWithTools()` is called
Then `AdapterRuntimeException` is thrown · no plain completion is returned instead

### T3AF-API-031 Empty embedding input is refused before the provider call
P2 · negative · — · gap
Rows: 2
Test: none
Given input `''`, `'   '` or `['', ' ']`
When `embed()` is called
Then `AdapterRuntimeException` `Empty embedding input: …` · no provider request · no request-log row

### T3AF-API-032 Governance cancel returns an empty response
P1 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteCancelsWhenListenerSetsReason`
Given a before-event listener that cancels with reason `blocked by ACL`
When `complete('hi')` is called
Then `AiResponse::$content = ''` · `raw = ['cancelled' => 'blocked by ACL']` · no exception

### T3AF-API-033 Cancelled tool-calling request never reaches the provider
P1 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Service/AiToolCallingServiceTest.php::testCancelledRequestNeverReachesTheProvider`
Given the group daily request limit is reached
When `completeWithTools()` is called
Then the platform is called 0 times · `toolCalls = []` · `raw['cancelled'] = 'Daily AI request limit reached for your backend user group.'`

### T3AF-API-034 Over-budget user is stopped before the request
P1 · negative · release · auto
Rows: 3
Test: `Tests/Functional/Governance/AccessControlListenerFunctionalTest.php::overBudgetUserTSconfigCancelsBeforeProviderRequest`
Given a backend user whose UserTSconfig budget is used up
When an AI call is made
Then the before event is cancelled · nothing is sent to the provider

### T3AF-API-035 Provider limited to other backend groups is denied
P1 · negative · — · auto
Rows: 3
Test: `Tests/Unit/Governance/AccessControlListenerTest.php::testDeniesWhenProviderRestrictedToOtherBeGroups`
Given a provider with `be_groups` that do not include the user's groups
When a non-admin user makes an AI call with it
Then the before event is cancelled with a reason · the provider is not called

### T3AF-API-036 Failed call writes a readable failure row
P1 · negative · release · gap
Rows: 13
Test: none
Given a provider that fails with HTTP 500
When `complete()` is called
Then one request-log row with `success = 0`, `error_code = 500`, `error_class` set, `raw_meta.message` readable · no `success = 1` row for the same call

---

## Edge

### T3AF-API-037 Client disconnect mid-stream writes nothing
P1 · edge · release · gap
Rows: 7, 13
Test: none
Given a running `stream()` call
When the client disconnects (`connection_aborted()` is true)
Then no `AfterProviderResponseEvent` is dispatched · no request-log row is written · `last_used_at` is not updated · no budget usage is recorded

### T3AF-API-038 `stream()` return value is the same with credits on or off
P2 · edge · — · gap
Rows: 1, 6
Test: none
Given a child that reads `$generator->getReturn()` after streaming
When the call runs once with credits mode off and once on
Then both return a `StreamSummary` with the full content · with credits on, `StreamSummary::$credits` is set

### T3AF-API-039 Calls from CLI or scheduler without a backend user are allowed
P2 · edge · — · auto
Rows: 9
Test: `Tests/Unit/Governance/AccessControlListenerTest.php::testPassesThroughWhenNoBackendUser`
Given no backend user (CLI command or scheduler task)
When an AI call is made
Then the access control listener does not cancel the call

### T3AF-API-040 Empty capability list on a legacy row still allows chat
P2 · edge · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteAllowsEmptyCapabilities`
Given an old provider row with an empty `capabilities` value
When `complete('hello')` is called
Then the call is sent and returns `ok` · no capability error

### T3AF-API-041 Batch embed input is accepted
P2 · edge · — · auto
Rows: 2
Test: `Tests/Unit/Service/AiServiceTest.php::testEmbedInvokeAcceptsBatchInput`
Given `embed(['first', 'second'])` on a platform without an `embed()` method
When the call runs through `invoke()`
Then the batch is passed as a list · one vector per text is returned

---

## Security

### T3AF-API-042 Request log keeps a prompt fingerprint, not the prompt
P1 · security · release · gap
Rows: 10, 13
Test: none
Given `complete('secret customer text')` succeeds and another call fails
When the two `tx_nst3af_request_log` rows are read
Then `prompt_fingerprint` is the sha256 of the prompt · the prompt text and the response content are in no column, including `raw_meta`

### T3AF-API-043 Privacy level controls what is logged
P1 · security · — · gap
Rows: 10, 13
Test: none
Given provider A with `privacy_level = none` and provider B with `privacy_level = reduced`
When one call is made with each
Then provider A writes no request-log row · provider B writes a row with `prompt_fingerprint = ''` and `raw_meta = '{}'`

---

## AI output

### T3AF-API-044 Thinking parts are skipped and non-Latin text is kept
P1 · ai · release · auto
Rows: 11
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteReadsTextPartsWhenConverterRejectsArrayContent`
Given a raw completion with a `thinking` part and a text part `翻訳されたタイトル`
When `complete()` reads it after the converter rejects the array content
Then `AiResponse::$content = '翻訳されたタイトル'` · the thinking text is not in the content

### T3AF-API-045 Plain text answer is preferred over array content
P2 · ai · — · auto
Rows: 11
Test: `Tests/Unit/Service/AiServiceTest.php::testCompletePrefersAsTextOverArrayGetContent`
Given a result that offers both `asText()` and an array `getContent()`
When `complete()` reads it
Then `AiResponse::$content` is the `asText()` string

### T3AF-API-046 Live answer meets the shared output checks
P2 · ai · release · manual
Rows: 11
Test: none
Given an approved live run on a test site (see `../ai-checks.md` § Live run rules)
When a child feature calls `complete()` through a real provider
Then `LIVE-LANG`, `LIVE-PLAIN` and `LIVE-LEAK` pass for that provider + model · results are recorded with provider, model and date · a failure creates an issue, not a merge block

---

Field reference and rules: `_template.md`.
