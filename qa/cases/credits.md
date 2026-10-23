# T3Planet Credits — cases

**Prefix:** `T3AF-CRED`
**How it works:** `context/features/credits.md`, `context/features/credits-api-base-url.md`, `context/features/child-extensions-credits-api-migration.md`
**Main code:** `Classes/Credits/**` (`Service/CreditModeResolver`, `CreditsReleaseGate`, `T3PlanetCredit{Ai,Tts,Image}Service`, `Proxy{Ai,Tts,Image}Executor`, `CreditsChargeRecorder`, `LocalReceiptCache`, `TokenResolver`, `LicenseKeyResolver`, `CreditsRetryPolicy`, `CreditsRateLimitBackoff`, `CreditsFeatureKeyMapper`, `CreditsCheckoutUrlValidator`, `CreditsCheckoutUrlBuilder`, `CreditsReturnUrlBuilder`, `CreditsApiBaseUrlResolver`, `RuntimeSettingsService`, `CreditsDomainResolver`, `CreditsHistorySyncService`; `Http/T3PlanetHttpClient`, `T3PlanetSseStreamParser`, `CreditsApiErrorParser`; `CreditsApiErrorCodes`), `Classes/Service/T3PlanetCreditsChatExecutor.php`, `Classes/Controller/Backend/CreditModeController.php`, `ModuleController::creditsCheckoutAction` (route `t3af_dashboard.credits_checkout`), `Resources/Private/Partials/Provider/ModeToggle.html`, `Resources/Public/JavaScript/credits-mode.js`, tables `tx_nst3af_runtime_setting`, `tx_nst3af_credit_receipt`, AJAX routes `nst3af_credits_*`
**Existing tests:** `Tests/Unit/Credits/*` (47 files), `Tests/Unit/Service/T3PlanetCreditsChatExecutorTest.php`, `Tests/Unit/Api/AiCreditUnitsTest.php`, `Tests/Unit/Api/CreditsPricingTest.php`, `Tests/Unit/Governance/GroupLimitsListenerTest.php`, `Tests/Unit/Service/RequestTelemetryServiceImageTest.php`

Money cases are P0: charged once, nothing charged or recorded on failure, balance exhausted, no license, checkout URL allowlist. "Receipt" means one row in `tx_nst3af_credit_receipt` (unique `request_uuid`). The server owns the balance; the local receipt is the site's record of a charge.

---

## Not applicable

| Row | Reason |
|---|---|
| 7 Data safety (preview, undo) | Credits has no preview or undo. Duplicate and partial-write risks are covered by 040 (receipt upsert) and 041 (abort on disconnect) |
| 8 Language / site (translated content, RTL) | Credits sends the caller's prompt unchanged and stores no translatable records. Only the catalog language (045) and the site domain for CLI (043) apply |
| 12 Compatibility | No TYPO3 version branch in `Classes/Credits/`, `CreditModeController` or `T3PlanetCreditsChatExecutor`. Release mode runs the auto cases on 13.4 and 14.3 |

---

## Positive

### T3AF-CRED-001 Credit mode off sends calls to the site's own provider
P0 · positive · smoke, release · auto
Rows: 1, 6
Test: `Tests/Unit/Credits/CreditModeOffGuardTest.php::testCreditModeOffForwardsToInnerService`
Given credit mode is off
When a child calls `AiServiceInterface::complete()`
Then the inner `AiService` is called exactly once · the T3Planet proxy is never called · no receipt is written

### T3AF-CRED-002 Successful credits call is charged once with one receipt
P0 · positive · smoke, release · auto
Rows: 1, 6
Test: `Tests/Unit/Credits/ProxyAiExecutorLegacyUnwrapTest.php::testCompleteUnwrapsSingleFieldJsonForLegacySeoKey`
Given credit mode is active with a valid bearer token
When `ns_t3ai` calls `complete('Page about TYPO3', featureKey: 'seo.meta_description')`
Then `charge` is called exactly once with catalog key `seo_page_metadata` and `fields = ['meta_description']` · exactly one receipt is inserted for that feature key · the response content is `A concise summary`

### T3AF-CRED-003 Admin activates free credits and sees the balance
P0 · positive · smoke, release · manual
Rows: 1, 4
Test: none
Given an admin with an active `ns_t3af` license, credit mode off, no token
When they open **AI Foundation → AI Providers**, switch to T3Planet Credits and click **Activate** (AJAX `nst3af_credits_activate`)
Then the JSON has `status: true`, `active: true`, a `balance` · `tx_nst3af_runtime_setting.credit_mode = 1` · `token_enc` starts with `enc:v1:` · the dashboard shows the free credit balance · provider edit links are hidden while credits are active

### T3AF-CRED-004 Credit flag is respected when the release gate is open
P1 · positive · release · auto
Rows: 4
Test: `Tests/Unit/Credits/CreditModeResolverTest.php::testReleaseGateOnRespectsEnabledRuntimeFlag`
Given the release gate is open (`CreditsReleaseGate::PUBLICLY_AVAILABLE = true`)
When `credit_mode = 1` and a token is stored
Then `isPubliclyAvailable()`, `isEnabled()` and `isActive()` are all true

### T3AF-CRED-005 Embedding through credits records one receipt
P0 · positive · release · auto
Rows: 1, 6
Test: `Tests/Unit/Credits/ProxyAiExecutorEmbedTest.php::testEmbedSuccessRecordsReceipt`
Given credit mode is active
When `embed('text')` succeeds through the proxy
Then the API is called once · one receipt is inserted for feature key `embedding` · one vector is returned with model `text-embedding-3-small`

### T3AF-CRED-006 Image generation through credits records one receipt
P0 · positive · release · auto
Rows: 1, 6
Test: `Tests/Unit/Credits/ProxyImageExecutorTest.php::testGenerateSuccessRecordsReceipt`
Given credit mode is active
When an image is generated through `ProxyImageExecutor`
Then the API is called once · one receipt is inserted for feature key `image_generate` · one image is returned with model `gpt-image-1`

### T3AF-CRED-007 TTS through credits records one receipt
P0 · positive · release · auto
Rows: 1, 6
Test: `Tests/Unit/Credits/ProxyTtsExecutorTest.php::testSpeakSuccessRecordsReceipt`
Given credit mode is active
When `speak()` succeeds through `ProxyTtsExecutor`
Then the API is called once · one receipt is inserted for feature key `text_to_speech` · the audio bytes and model `tts-1-hd` are returned

### T3AF-CRED-008 Credit mode on routes TTS through the proxy
P1 · positive · release · auto
Rows: 6
Test: `Tests/Unit/Credits/T3PlanetCreditTtsServiceTest.php::testCreditModeOnRoutesThroughProxy`
Given credit mode is active
When `TtsServiceInterface::speak()` is called
Then the proxy executor `speak()` is called exactly once · the site's own TTS service is never called

### T3AF-CRED-009 Tool-calling round through credits records the charge once
P0 · positive · release · auto
Rows: 1, 6
Test: `Tests/Unit/Service/T3PlanetCreditsChatExecutorTest.php::testMapsToolCallsAndRecordsCharge`
Given credit mode is active and the AI Agent sends a tool-calling request
When T3Planet answers with tool calls
Then the tool calls are mapped into the response · the charge recorder is called exactly once

### T3AF-CRED-010 Streaming is charged under the assistant chat key
P1 · positive · release · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsFeatureKeyMapperTest.php::testStreamEndpointAlwaysUsesAssistantChatCatalogKey`
Given any child `featureKey`
When the call goes to the stream endpoint
Then the catalog key sent to T3Planet is `assistant_chat`, whatever the child key was

### T3AF-CRED-011 Child extension aliases map to the right catalog key
P1 · positive · — · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsFeatureKeyMapperTest.php::testExtensionExtConfAliasesAreApplied`
Given extension `my_extension` maps `custom.writer` to `easy_language` in its extension configuration
When it calls with `featureKey: 'custom.writer'`
Then the catalog key is `easy_language`, not the raw child key

### T3AF-CRED-012 Only `ns_t3af` licenses are sent to T3Planet
P1 · positive · release · auto
Rows: 4
Test: `Tests/Unit/Credits/LicenseKeyResolverTest.php::testBuildLicenseKeysCommaSeparatedIncludesOnlyNsT3afLicenses`
Given license rows for `ns_t3af` and for other NITSAN extensions
When the license key list is built
Then only the `ns_t3af` key is in the list

### T3AF-CRED-013 Checkout opens for T3Planet and Pabbly URLs
P0 · positive · release · auto
Rows: 1, 10
Test: `Tests/Unit/Credits/CreditsCheckoutUrlValidatorTest.php::testAllowsTrustedCheckoutHosts`
Given checkout URLs `https://payments.pabbly.com/checkout/abc`, `https://t3planet.shop/pabbly/starter?token=x`, `https://pabbly.t3planet.de/checkout/pro` and `https://pay.t3planet.de/order/1`
When `CreditsCheckoutUrlValidator::isAllowed()` checks them
Then each one is allowed

### T3AF-CRED-014 Return URL to the backend has no backend token
P1 · positive · release · auto
Rows: 10
Test: `Tests/Unit/Credits/CreditsReturnUrlBuilderTest.php::testFromRouteBuildsAbsoluteUrlWithoutBackendToken`
Given route `t3af_dashboard.providers` whose backend URL carries `?token=backend-route-token`
When the return URL for checkout is built
Then it is the absolute URL `https://<host>/typo3/module/t3af/dashboard/providers` · the `token` parameter is not in it

### T3AF-CRED-015 Checkout `redirectto` is replaced with the absolute return URL
P1 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Credits/CreditsCheckoutUrlBuilderTest.php::testReplacesCfRedirecttoWithAbsoluteReturnUrl`
Given a T3Planet checkout URL with a `cf_redirectto` query parameter
When `CreditsCheckoutUrlBuilder::normalize()` runs
Then `cf_redirectto` holds the absolute return URL of this site

### T3AF-CRED-016 Successful credits call writes an attributed request-log row
P1 · positive · release · gap
Rows: 13
Test: none
Given credit mode is active
When `complete('x', new AiOptions(extensionKey: 'ns_t3ai', featureKey: 'seo.meta_description', requestSource: 'backend_page'))` succeeds
Then exactly one `tx_nst3af_request_log` row: `provider_identifier = t3planet_credits`, `extension_key = ns_t3ai`, `feature_key = seo.meta_description`, `request_source = backend_page`, `success = 1`, credits used set

### T3AF-CRED-017 Image log keeps the credits from the response
P2 · positive · — · auto
Rows: 6, 13
Test: `Tests/Unit/Service/RequestTelemetryServiceImageTest.php::testLogImageGenerationPersistsCreditsFromResponse`
Given an image response with credits usage
When the image call is logged
Then the request-log row stores the credits from the response

### T3AF-CRED-018 Environment variable chooses the API host
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Credits/CreditsApiBaseUrlResolverTest.php::testEnvironmentVariableWinsOverDefault`
Given `T3PLANET_CREDITS_API_BASE_URL` is set
When the API base URL is resolved
Then the environment value is used instead of `https://composer.t3planet.cloud`

---

## Negative

### T3AF-CRED-019 Insufficient credits: nothing recorded, failure logged
P0 · negative · release · gap
Rows: 5, 6, 13
Test: none
Given credit mode is active and the balance is 0
When `complete()` gets HTTP 402 `insufficient_credits`
Then `InsufficientCreditsException` is thrown · no receipt is written · `ProviderRequestFailedEvent` is dispatched with reason `credits.insufficient` · one request-log row with `success = 0` · the error payload has `topup_url` when the server sends one

### T3AF-CRED-020 Charge with `status: false` writes no receipt
P0 · negative · release · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsChargeRecorderTest.php::testRecordSkipsWhenStatusFalse`
Given a charge payload with `status: false`
When `CreditsChargeRecorder::record()` runs
Then `LocalReceiptCache` is not called · no receipt exists for that `request_uuid`

### T3AF-CRED-021 Removed content writes no receipt
P0 · negative · release · auto
Rows: 6, 7
Test: `Tests/Unit/Credits/ProxyAiExecutorLegacyUnwrapTest.php::testCompleteThrowsContentRemovedOnRedactedReplayWithoutReceipt`
Given T3Planet replays a request whose content was removed under retention
When `complete()` reads the answer
Then `CreditsApiException` with `content_removed` and warning `content_redacted_retention` · no receipt is inserted

### T3AF-CRED-022 Removed image content writes no receipt
P0 · negative · — · auto
Rows: 6
Test: `Tests/Unit/Credits/ProxyImageExecutorTest.php::testGenerateThrowsContentRemovedWithoutReceipt`
Given T3Planet answers an image request with `content_removed`
When `generate()` runs through the proxy
Then `CreditsContentRemovedException` is thrown · no receipt is inserted, even though the payload has `status: true` and a `charged` amount

### T3AF-CRED-023 HTTP 402 on a stream is reported as insufficient credits
P0 · negative · release · auto
Rows: 5, 6
Test: `Tests/Unit/Credits/T3PlanetHttpClientStreamTest.php::testStreamMaps402JsonToInsufficientCreditsException`
Given the stream endpoint answers 402 with a JSON error body
When the stream starts
Then `InsufficientCreditsException` is thrown · no chunks are returned as content

### T3AF-CRED-024 Plan expired and daily cap count as exhausted
P1 · negative · — · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsVisitorMessageResolverTest.php::testPlanExpiredAndDailyCapAreExhausted`
Given an error with `plan_expired` or `daily_cap_exceeded`
When the visitor message is resolved
Then `CreditsVisitorMessageResolver::isCreditsExhausted()` is true for both, as for `insufficient_credits`

### T3AF-CRED-025 Message shown to site visitors is neutral
P1 · negative · — · auto
Rows: 6, 10
Test: `Tests/Unit/Credits/CreditsVisitorMessageResolverTest.php::testVisitorMessageIsNeutral`
Given credits are exhausted during a frontend AI feature
When the visitor message is built
Then the message says the feature is temporarily unavailable · it does not contain the word `credit`

### T3AF-CRED-026 Credit mode on without a token is not active
P0 · negative · release · gap
Rows: 4, 6
Test: none
Given `credit_mode = 1` and `token_enc` empty, no token in extension configuration
When `CreditModeResolver::isActive()` is checked and a child calls `complete()`
Then `isActive()` is false · the call goes to the site's own provider · nothing is sent to T3Planet · no receipt is written

### T3AF-CRED-027 Closed release gate refuses the toggle
P1 · negative · — · gap
Rows: 4
Test: none
Given `CreditsReleaseGateInterface::isPubliclyAvailable()` returns false
When `nst3af_credits_toggle` is posted with `enabled=1`
Then HTTP 403 with `error_code = credits_unavailable` · `credit_mode` is unchanged · no trial token is requested

### T3AF-CRED-028 Activation without license or contact asks for name and email
P0 · negative · release · manual
Rows: 2, 4
Test: none
Given no `ns_t3af` license, no token, credit mode off
When the admin posts `nst3af_credits_activate` with an empty name or an invalid email
Then HTTP 400 with `error_code = contact_required` and `Name and a valid email are required to activate free credits.` · `credit_mode` stays 0 · no token is stored · nothing is sent to T3Planet

### T3AF-CRED-029 Failed activation stores no token
P1 · negative · — · manual
Rows: 4, 5
Test: none
Given the T3Planet API returns an error (for example `license_invalid`) during activation
When the admin clicks **Activate**
Then the JSON has `status: false`, the error code and a readable `userMessage`, with the HTTP status from `CreditsApiErrorCodes::httpStatus` · `token_enc` stays empty · `isActive()` stays false · AI calls keep using the site's own provider

### T3AF-CRED-030 Expired license is not sent
P1 · negative · — · gap
Rows: 4
Test: none
Given an `ns_t3af` license row with `expiration_date` in the past and `is_life_time = 0`
When the license key list is built
Then that key is not in the list · a lifetime license and one with `expiration_date = 0` are still included

### T3AF-CRED-031 Context length exceeded is not retried
P0 · negative · — · auto
Rows: 5, 6
Test: `Tests/Unit/Credits/ProxyAiExecutorLegacyUnwrapTest.php::testCompleteDoesNotRetryContextLengthExceeded`
Given T3Planet answers `context_length_exceeded`
When `complete()` runs
Then `charge` is called exactly once · the error is thrown to the caller · no second request

### T3AF-CRED-032 Never-retry error codes are not retried
P1 · negative · — · auto
Rows: 5, 6
Test: `Tests/Unit/Credits/CreditsRetryPolicyTest.php::testNeverRetryCodes`
Given each of `context_length_exceeded`, `tools_unsupported`, `model_unknown`, `model_not_allowed`, `request_too_large`, `unsupported_content`, `insufficient_credits`, `plan_expired`, even with `upstream_status = 503`
When the retry policy is asked
Then `isNeverRetry()` is true and `shouldRetryUpstream()` is false · no second charge request is sent

### T3AF-CRED-033 Token refresh needs active credits
P2 · negative · — · manual
Rows: 4
Test: none
Given credit mode is off
When `nst3af_credits_refresh_token` is posted
Then HTTP 400 with `error_code = not_active` · the stored token is unchanged · nothing is sent to T3Planet

### T3AF-CRED-034 Group monthly credit cap stops the call before the charge
P1 · negative · release · auto
Rows: 3, 6
Test: `Tests/Unit/Governance/GroupLimitsListenerTest.php::testDeniesWhenMonthlyCreditCapReached`
Given a backend group with `credit_cap_monthly = 100` and 100 credits already used this month
When a user in that group makes an AI call
Then the before event is cancelled with a reason containing `Monthly AI credit cap` · the request is not sent, so nothing is charged

### T3AF-CRED-035 Empty 500 response becomes a readable internal error
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Credits/T3PlanetHttpClientTest.php::testDecodeResponseMapsEmpty500BodyToInternalError`
Given T3Planet answers HTTP 500 with an empty body
When the response is decoded
Then `CreditsApiException` with `internal_error`, HTTP status 500 and a message mentioning `empty body` · no success payload is returned

---

## Edge

### T3AF-CRED-036 Transient upstream error is retried once with a new request id
P0 · edge · release · auto
Rows: 5, 6
Test: `Tests/Unit/Credits/ProxyAiExecutorLegacyUnwrapTest.php::testCompleteRetriesTransientUpstreamErrorOnceWithFreshUuid`
Given the first charge fails with a transient upstream error and the second succeeds
When `complete()` runs
Then `charge` is called exactly twice · the two `request_uuid` values differ · the content is `ok`

### T3AF-CRED-037 Rate limit waits for Retry-After
P1 · edge · — · auto
Rows: 5
Test: `Tests/Unit/Credits/CreditsRateLimitBackoffTest.php::testRetryAfterWinsOverExponential`
Given a `rate_limited` error with a `Retry-After` value
When the backoff delay is calculated
Then the `Retry-After` value is used instead of the exponential delay

### T3AF-CRED-038 Rate-limited embed succeeds on a later attempt
P1 · edge · — · auto
Rows: 5, 6
Test: `Tests/Unit/Credits/ProxyAiExecutorEmbedTest.php::testEmbedRetriesOnRateLimitedThenSucceeds`
Given the embed endpoint answers `rate_limited` twice, then succeeds
When `embed()` runs
Then the API is called 3 times with 2 waits, the first one 1.0 second · vectors are returned

### T3AF-CRED-039 Idempotency conflict is retried once
P0 · edge · — · auto
Rows: 6
Test: `Tests/Unit/Service/T3PlanetCreditsChatExecutorTest.php::testRetriesOnceOnIdempotencyConflict`
Given the first tool-calling request gets `idempotency_conflict`
When the executor retries
Then the platform is called exactly twice (one retry) · the second answer `ok` is returned

### T3AF-CRED-040 Receipt upsert does not create a duplicate
P0 · edge · release · auto
Rows: 6, 7
Test: `Tests/Unit/Credits/LocalReceiptCacheHistoryUpsertTest.php::testHistoryUpsertPreservesExistingClientContext`
Given a receipt already exists for a `request_uuid`
When history sync brings the same `request_uuid` again
Then the insert hits the unique key and one update is made · still one row for that `request_uuid` · the stored client context is kept

### T3AF-CRED-041 Client disconnect during a stream sends Abort once and records nothing
P0 · edge · release · gap
Rows: 6, 7
Test: none
Given credit mode is active and a stream is running
When the HTTP client fails mid-stream (timeout or disconnect)
Then the Abort endpoint is called exactly once for that `request_uuid` · no receipt is written · `ProviderRequestFailedEvent` with reason `credits.timeout` is dispatched

### T3AF-CRED-042 Stream with tokens but no usage event fails
P1 · edge · — · auto
Rows: 5, 6
Test: `Tests/Unit/Credits/T3PlanetSseStreamParserTest.php::testParseFailsWhenTokensReceivedWithoutUsage`
Given an SSE stream that sends text tokens and ends without a usage event
When the stream is parsed
Then `CreditsApiException` with `invalid_response` is thrown · the call is not treated as a finished, charged answer

### T3AF-CRED-043 Scheduler and CLI use the stored site domain
P1 · edge · — · auto
Rows: 9
Test: `Tests/Unit/Credits/CreditsDomainResolverTest.php::testResolveUsesStoredCreditsDomainWhenHttpHostMissing`
Given no HTTP host (CLI command or scheduler task) and a stored credits domain
When the domain is resolved for an API call
Then the stored domain is used

### T3AF-CRED-044 Custom API URL in the database is kept
P1 · edge · — · auto
Rows: 1
Test: `Tests/Unit/Credits/RuntimeSettingsServiceApiBaseUrlTest.php::testCustomUrlIsNotOverwritten`
Given a custom API base URL saved in `tx_nst3af_runtime_setting`
When the base URL sync runs
Then the custom URL is unchanged

### T3AF-CRED-045 Catalog follows the backend user's language
P2 · edge · — · auto
Rows: 8
Test: `Tests/Unit/Credits/CreditsCatalogLanguageResolverTest.php::testResolveMapsGermanFromBeUsersLang`
Given a backend user with language `de`
When the catalog language is resolved
Then German is requested

### T3AF-CRED-046 Unknown feature key falls back to content generation
P1 · edge · — · auto
Rows: 6
Test: `Tests/Unit/Credits/CreditsFeatureKeyMapperTest.php::testUnknownKeyFallsBackToContentGenerate`
Given a child `featureKey` that is not in the catalog
When it is mapped
Then the content generation catalog key is charged

---

## Security

### T3AF-CRED-047 Checkout refuses untrusted URLs
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Credits/CreditsCheckoutUrlValidatorTest.php::testRejectsUntrustedCheckoutHosts`
Given checkout URLs `''`, `http://payments.pabbly.com/checkout`, `https://evil.example/phish` and `javascript:alert(1)`
When `isAllowed()` checks them
Then each one is refused

### T3AF-CRED-048 Checkout route shows an error page for a bad URL
P0 · security · release · manual
Rows: 10
Test: none
Given a logged-in backend user
When they open route `t3af_dashboard.credits_checkout` with `url=https://evil.example/pay`
Then HTTP 400 with `Invalid or missing checkout URL.` · no iframe or redirect to `evil.example` · the page does not echo the URL

### T3AF-CRED-049 Lookalike checkout hosts are refused
P0 · security · — · gap
Rows: 10
Test: none
Given checkout URLs `https://t3planet.shop@evil.example/`, `https://evilt3planet.de/`, `https://t3planet.de.evil.example/` and `https://T3PLANET.SHOP.evil.example/`
When `isAllowed()` checks them
Then each one is refused

### T3AF-CRED-050 Return URL to another host is not passed to the shop
P0 · security · release · gap
Rows: 10
Test: none
Given `nst3af_credits_products` or `nst3af_credits_dashboard` is called with `return=https://evil.example/`
When the return URL is normalised
Then the foreign URL is dropped or replaced by this site's backend URL · `https://evil.example/` never appears in `redirect_to` or `cf_redirectto`

### T3AF-CRED-051 Bearer token is added only to trusted checkout hosts
P0 · security · — · gap
Rows: 10
Test: none
Given a checkout URL with `{token}` or a `cf_credittoken` parameter on a host that is not allowlisted
When `CreditsCheckoutUrlBuilder::normalize()` runs
Then the bearer token is not inserted into that URL

### T3AF-CRED-052 Credits AJAX is admin-only and editors never get the token
P0 · security · release · manual
Rows: 3, 10
Test: none
Given a non-admin editor with access to the AI Foundation module
When they call `nst3af_credits_status`, `nst3af_credits_toggle` and `nst3af_credits_activate`
Then status returns no `creditsBearerToken` value · toggle and activate are refused · `credit_mode` and `token_enc` are unchanged · the provider page shows no token for the editor

### T3AF-CRED-053 Bearer token never appears in logs or error payloads
P0 · security · release · manual
Rows: 10, 13
Test: none
Given credit mode is active with a known bearer token
When calls fail with `token_invalid`, HTTP 500 and a network timeout
Then the token is not in the JSON error payloads (`message`, `userMessage`, extra fields), not in `sys_log`, `var/log/*`, or `tx_nst3af_request_log` (including `raw_meta`), and not in exception messages

### T3AF-CRED-054 Bearer token is encrypted at rest
P0 · security · release · manual
Rows: 10
Test: none
Given credits were activated
When an admin runs `SELECT token_enc FROM tx_nst3af_runtime_setting` and reads the `ns_t3af` extension configuration
Then `token_enc` starts with `enc:v1:` · the plain token is not in the database row · the extension configuration has no plain token (`t3planetApiToken` empty)

### T3AF-CRED-055 Credits toggle refuses cross-site requests
P1 · security · — · manual
Rows: 3, 10
Test: none
Given an admin logged into the backend
When a page on another origin posts to `/typo3/ajax/nst3af/credits/toggle` with `enabled=1` and no valid TYPO3 AJAX token
Then the request is refused · `credit_mode` is unchanged · no trial token is requested

---

## AI output

### T3AF-CRED-056 Legacy SEO answer is unwrapped to plain text
P1 · ai · release · auto
Rows: 11
Test: `Tests/Unit/Credits/ProxyAiExecutorLegacyUnwrapTest.php::testCompleteUnwrapsSingleFieldJsonForLegacySeoKey`
Given T3Planet returns `{"meta_description": "A concise summary"}` for a single-field SEO request
When `complete()` returns it to `ns_t3ai`
Then the content is the plain text `A concise summary`, not JSON

### T3AF-CRED-057 Live credits call is charged as expected
P1 · ai · release · manual
Rows: 6, 11
Test: none
Given an approved live run on a test site with a test T3Planet account (see `../ai-checks.md` § Live run rules)
When one SEO meta description is generated through credits
Then `LIVE-CREDIT` passes: the balance drops by the estimated credits once · one receipt with the canonical feature key · `LIVE-LANG` and `LIVE-PLAIN` pass for the answer · results are recorded with model and date

---

Field reference and rules: `_template.md`.
