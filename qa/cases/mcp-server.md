# MCP server + core tools — cases

**Prefix:** `T3AF-MCP`
**How it works:** `context/features/mcp-server.md`, `context/mcp-2.1-deferred-checklist.md`, `Documentation/McpServer/Testing.rst`
**Main code:** `Classes/Mcp/Middleware/{McpServerMiddleware,OAuthMiddleware,McpSecurityMiddleware}.php`, `Classes/Mcp/OAuth/`, `Classes/Mcp/Authentication/BackendUserBootstrap.php`, `Classes/Mcp/Server/{McpServerFactory,ErrorHandlingProxy,BufferedStdioTransport}.php`, `Classes/Mcp/Tool/*`, `Classes/Mcp/Service/{DataHandlerService,FileService,FileUploadService,McpSlugRedirectGuard,McpModeResolver,McpModeOverride,McpToolSchemaAugmenter,McpInvocationContext}.php`, `Classes/Mcp/Service/RecordsApply/`, `Classes/Mcp/Service/Backend/McpPlaygroundService.php`, `Classes/Mcp/Http/FileUploadEndpoint.php`, `Classes/Mcp/Command/{McpServeCommand,CleanupCommand}.php`, `Classes/Mcp/Controller/Backend/{McpServerController,McpToolsController}.php`, `Classes/Mcp/Logging/AuditLogger.php`
**Existing tests:** `Tests/Unit/Mcp/`, `Tests/Functional/Mcp/` (`OAuth/`, `DataHandler/`, `RecordsApply/`, `File/`)

Endpoints (default `mcpBasePath` = `/mcp`): MCP `/mcp`, URL token `/mcp/r/{64 hex}`, upload `/mcp_upload`, OAuth `/mcp/oauth/{authorize,token,register,revoke}`, metadata `/.well-known/oauth-authorization-server/mcp`, `/.well-known/oauth-protected-resource/mcp`.

---

## Not applicable

| Row | Reason |
|---|---|
| 11 AI output | Core MCP tools generate no AI text. The MCP client (Cursor, Claude, …) is the model; the server only reads and writes records. AI text from child tools (`t3ai_*`, `t3aa_*`) is tested in the child `mcp-tools` cases. Provenance of MCP writes (AI Label) is in T3AF-MCP-015. |

---

## Positive

### T3AF-MCP-001 OAuth code + PKCE flow issues hashed tokens that open the server
P0 · positive · smoke, release · auto
Rows: 1, 10
Test: `Tests/Functional/Mcp/OAuth/OAuthAuthorizationFlowFunctionalTest.php::fullAuthorizationCodeFlowIssuesHashedTokensAndSupportsRefreshAndRevoke`
Given a client registered at `/mcp/oauth/register` and an active backend user
When the client gets a code with an S256 `code_challenge` and exchanges it at `/mcp/oauth/token`
Then an access and refresh token are returned · `tx_nst3af_oauth_token` holds only SHA-256 hashes, never the plain token · the code row is `revoked = 1` (single use) · `validateAccessToken()` returns that backend user

### T3AF-MCP-002 Streamable HTTP initialize and tools/list work with a bearer token
P1 · positive · smoke, release · manual
Rows: 1, 12
Test: none
Given `enableMcpServer = 1` and a valid access token
When the client POSTs `initialize` then `tools/list` to `/mcp` with `Authorization: Bearer <token>`
Then HTTP 200 with an `Mcp-Session-Id` header · serverInfo is "AI Foundation MCP Server" with the shipped version · the list contains `table_schema`, `pages_get`, `content_list`, `write_table`, `records_apply`, `records_undo`, `file_upload_from_url`

### T3AF-MCP-003 mcp-remote URL token works without a header
P1 · positive · release · manual
Rows: 1
Test: none
Given a token issued in **AI Foundation → MCP Server** (route `nst3af_mcp_mcp_remote_token_issue`)
When the client calls `/mcp/r/<64 hex token>` with no `Authorization` header
Then `initialize` succeeds as that backend user · a path with a non-hex or short token falls through to TYPO3 (no MCP answer)

### T3AF-MCP-004 stdio server answers initialize for a named backend user
P1 · positive · release · manual
Rows: 1, 9
Test: none
Given an active backend user `editor`
When `printf '<initialize JSON>\n' | typo3 t3af:mcp:serve -u editor -w 0 --no-startup-message`
Then one JSON-RPC result is printed on stdout · nothing but JSON-RPC is on stdout · diagnostics (if any) go to stderr only

### T3AF-MCP-005 pages_get returns only the requested fields
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Mcp/Tool/Pages/PagesGetToolTest.php::selectFieldsLimitsRequestedColumns`
Given a page the backend user may read
When `pages_get` is called with `uid` and `selectFields`
Then only those columns are in the answer

### T3AF-MCP-006 write_table create appends after existing elements and labels the record as AI
P1 · positive · release · auto
Rows: 1, 7
Test: `Tests/Functional/Mcp/DataHandler/WriteTableToolFunctionalTest.php::createAppendsAfterTheExistingElementsAndLabelsTheRecordAsAi`
Given a page with existing `tt_content` elements
When `write_table` runs `action=create` on that page
Then the new element sorts after the last existing one · it carries the AI Label fields (AI-generated)

### T3AF-MCP-007 records_apply creates a page and its elements in one call, in the order sent
P1 · positive · release · auto
Rows: 1
Test: `Tests/Functional/Mcp/RecordsApply/RecordsApplyServiceFunctionalTest.php::aPageAndItsElementsAreCreatedInOneCallInTheOrderSent`
Given a `records_apply` payload with a new page (`NEW` id) and elements using that id as pid
When the tool runs
Then the page and all elements exist · the elements are on the new page in request order · every `sys_history` row carries the batch id

### T3AF-MCP-008 records_undo takes a whole batch back
P1 · positive · release · auto
Rows: 1, 7
Test: `Tests/Functional/Mcp/RecordsApply/RecordsUndoFunctionalTest.php::aBatchThatCreatedAPageAndItsElementsIsTakenBackCompletely`
Given a committed `records_apply` batch that created a page and elements
When `records_undo` runs with that `batchId`
Then the page and its elements are deleted again · other records are untouched

### T3AF-MCP-009 Each enabled discovered table gets nine dynamic tools
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Mcp/Service/McpToolsRegistryServiceTest.php::buildDynamicToolNamesReturnsNineCrudToolsForPrefix`
Given a table enabled in **MCP Tools** (`tx_nst3af_discovered_table`)
When the dynamic tool names are built for its prefix
Then there are nine tools: `<prefix>_list`, `_get`, `_create`, `_update`, `_delete`, `_move`, `_update_batch`, `_delete_batch`, `_move_batch`

### T3AF-MCP-010 A child extension's custom tool is registered with its reflected schema
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Mcp/Tool/Custom/McpCustomToolRegistrarTest.php::registerCollectedAddsToolToBuilderWithReflectedSchema`
Given a class with `#[McpTool]` tagged `mcp.tool` in a child `Services.yaml`
When the server is built
Then the tool is in `tools/list` with an input schema built from its `execute()` parameters

### T3AF-MCP-011 file_upload_from_url stores a public file in FAL
P1 · positive · release · manual
Rows: 1
Test: none
Given a writable folder in the user's file mount and a direct public image URL
When `file_upload_from_url` runs
Then a `sys_file` row exists in that folder with the right name and mime type · the answer has the file uid · a second identical call in the same folder reuses the file instead of adding a copy

### T3AF-MCP-012 A slug change through records_apply creates a redirect
P1 · positive · release · auto
Rows: 1, 8
Test: `Tests/Functional/Mcp/RecordsApply/RecordsApplySlugRedirectsFunctionalTest.php::slugChangeViaRecordsApplySucceedsAndCanCreateARedirect`
Given a page on a site, EXT:redirects loaded
When `records_apply` changes the page `slug`
Then the slug is saved · a `sys_redirect` row from the old slug is created (`McpSlugRedirectGuard`)

### T3AF-MCP-013 MCP playground runs a tool as the logged-in backend user
P2 · positive · — · manual
Rows: 1, 13
Test: none
Given an admin in **AI Foundation → MCP Tools → Playground**
When they invoke `pages_get` with a page uid (route `nst3af_mcp_tools_playground_invoke`)
Then the result JSON is shown with `success: true` · one row is added to `tx_nst3af_mcp_tool_log` for `pages_get`

### T3AF-MCP-014 A successful tool call is logged once as success
P1 · positive · release · auto
Rows: 13
Test: `Tests/Unit/Mcp/Server/ErrorHandlingProxyTest.php::aNormalAnswerIsLoggedAsSuccess`
Given any tool that returns a normal answer
When it runs through `ErrorHandlingProxy`
Then `AuditLogger::logSuccess` (sys_log, channel MCP) and `McpToolLogService::logSuccess` (`tx_nst3af_mcp_tool_log`) are each called once · no failure entry

### T3AF-MCP-015 Records created by an MCP client are marked AI-generated with source mcp
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Mcp/Service/RecordsApply/RecordsApplyAiLabelMarkerTest.php::recordsCreatedByAnMcpClientAreMarkedAiGeneratedWithSourceMcp`
Given `mcpMarkWritesAsAi = 1` and a table with AI Label fields
When `records_apply` creates a record
Then the record is marked AI-generated with source `mcp`

### T3AF-MCP-016 AI calls made inside a tool are logged with requestSource mcp
P1 · positive · release · auto
Rows: 13
Test: `Tests/Unit/Mcp/Service/McpInvocationContextTest.php::enrichRequestSourceOnlySetsMcpWhileToolIsActive`
Given a tool that calls `AiServiceInterface` during its run
When the request options are built
Then `requestSource` is `mcp` while the tool runs · outside a tool call the caller's own `requestSource` stays

### T3AF-MCP-017 In credits mode the aiProvider choice offers T3Planet credits
P1 · positive · release · auto
Rows: 6
Test: `Tests/Unit/Mcp/Service/McpConnectedProviderEnumResolverTest.php::resolveEnumReturnsCreditsIdentifierWhenCreditsModeIsActive`
Given T3Planet Credits mode is on
When the `aiProvider` enum for AI tools is built
Then it holds the credits identifier, not the own-key providers

---

## Negative

### T3AF-MCP-018 No token is refused with 401 and resource metadata
P0 · negative · smoke, release · auto
Rows: 3, 10
Test: `Tests/Unit/Mcp/Middleware/McpServerMiddlewareTest.php::testProcessReturnsMissingAuthenticationTokenWhenNoTokenProvided`
Given the server is on and the request has no bearer, query or URL token
When the client POSTs to `/mcp`
Then HTTP 401 `{"error":"Missing authentication token"}` with `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource/mcp"` · no tool runs · no `tx_nst3af_mcp_tool_log` row

### T3AF-MCP-019 An expired access token is refused
P0 · negative · release · gap
Rows: 3, 10
Test: none
Given a token row with `access_token_expires` in the past
When the client calls `/mcp` with that token
Then HTTP 401 `{"error":"Authentication failed"}` · `last_used` is not updated · no tool runs

### T3AF-MCP-020 A revoked access token no longer validates
P0 · negative · release · auto
Rows: 3, 10
Test: `Tests/Functional/Mcp/OAuth/OAuthAuthorizationFlowFunctionalTest.php::fullAuthorizationCodeFlowIssuesHashedTokensAndSupportsRefreshAndRevoke`
Given a valid pair revoked through `/mcp/oauth/revoke` (or **Revoke** in the MCP Server tab)
When the access token is validated
Then validation throws (HTTP layer answers 401 "Authentication failed") · the row stays `revoked = 1`

### T3AF-MCP-021 A token of a disabled or deleted backend user is refused
P0 · negative · release · gap
Rows: 3, 10
Test: none
Given a valid token whose `be_users` row is now `disable = 1` or `deleted = 1`
When the client calls `/mcp`
Then HTTP 401 "Authentication failed" (`BackendUserBootstrap` "Backend user not found") · no tool runs · no record written

### T3AF-MCP-022 A token without mcp:write cannot run write tools
P0 · negative · release · gap
Rows: 3, 10
Test: none
Given a token whose `scope` is `mcp:read` only (default scopes: `mcp:read mcp:write mcp:tools`)
When the client calls `write_table` or `records_apply`
Then the call is refused with a scope error · no record written · read tools still work

### T3AF-MCP-023 A wrong PKCE verifier gets no token
P0 · negative · release · auto
Rows: 3, 10
Test: `Tests/Functional/Mcp/OAuth/OAuthAuthorizationFlowFunctionalTest.php::exchangeRejectsInvalidPkceVerifier`
Given a valid authorization code made with an S256 challenge
When the token exchange sends a different `code_verifier`
Then "PKCE verification failed" · no `tx_nst3af_oauth_token` row is issued

### T3AF-MCP-024 Authorize with an unregistered redirect_uri is refused
P0 · negative · release · gap
Rows: 2, 10
Test: none
Given a registered client
When `GET` or `POST /mcp/oauth/authorize` uses a `redirect_uri` not stored for that client
Then HTTP 400 `invalid_request` "Invalid redirect_uri" · no code row · no redirect to the foreign URI

### T3AF-MCP-025 write_table is refused for a user without table rights
P0 · negative · smoke, release · auto
Rows: 3
Test: `Tests/Functional/Mcp/DataHandler/WriteTableToolFunctionalTest.php::writeTableToolDeniesModifyForUserWithoutTableRights`
Given an editor whose groups may not modify the table
When `write_table` tries to create or update a record there
Then an error answer · no row written or changed

### T3AF-MCP-026 pages_get for a page outside the user's mounts is denied without loading it
P0 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Mcp/Tool/Pages/PagesGetToolTest.php::pageOutsideEditorAccessReturnsAccessDeniedWithoutLoadingTheRecord`
Given an editor without read access to page X
When `pages_get` asks for X
Then an access-denied error · no page data in the answer

### T3AF-MCP-027 records_apply strict mode refuses a field the editor may not change
P1 · negative · release · auto
Rows: 3, 7
Test: `Tests/Unit/Mcp/Service/RecordsApply/RecordsApplyPreflightTest.php::strictModeRefusesAFieldTheEditorMayNotChange`
Given an editor without the `non_exclude_fields` grant for a field
When `records_apply` with `strict` sends that field
Then the whole call is refused and names the field · nothing written

### T3AF-MCP-028 cache_clear scope all is refused for an editor without TSconfig
P1 · negative · — · auto
Rows: 3
Test: `Tests/Unit/Mcp/Service/CacheServicePermissionTest.php::editorWithoutTsConfigMayNotClearAll`
Given a non-admin without `options.clearCache.all`
When `cache_clear` runs with `scope=all`
Then a denial reason is returned · no cache is flushed

### T3AF-MCP-029 MCP Server settings actions answer 403 without the MCP Server tab
P1 · negative · release · auto
Rows: 3, 4
Test: `Tests/Unit/Mcp/Controller/McpServerControllerAccessTest.php::aUserWithoutTheModuleIsDenied`
Given a non-admin without `t3af_dashboard` or without the MCP Server tab
When they call a settings route (for example `nst3af_mcp_advanced_save`, `nst3af_mcp_ip_allowlist_add`)
Then HTTP 403 · no setting changed

### T3AF-MCP-030 Server switched off answers 503 on MCP and upload paths
P1 · negative · release · gap
Rows: 4
Test: none
Given `enableMcpServer = 0`
When a client calls `/mcp` (with a valid token) or `/mcp_upload`
Then HTTP 503 `{"error":"MCP server is disabled"}` · no tool runs · no upload stored

### T3AF-MCP-031 Destructive file operations are refused when switched off
P1 · negative · release · gap
Rows: 4, 7
Test: none
Given `mcpAllowDestructiveFileOps = 0`
When `file_delete`, `file_move`, `file_rename`, `directory_move`, `directory_rename` or `directory_delete` runs
Then the error "Destructive file operations are disabled (mcpAllowDestructiveFileOps)." · the file or folder is unchanged

### T3AF-MCP-032 A disconnected provider in aiProvider is refused
P1 · negative · release · auto
Rows: 5
Test: `Tests/Unit/Mcp/Service/McpInvocationContextTest.php::assertProviderIsUsableRejectsDisconnectedProvider`
Given a provider record that is not connected
When an AI tool is called with `aiProvider` set to it
Then the call is refused with a clear message · no AI request is sent · no credits charged

### T3AF-MCP-033 Broken JSON in records_apply is a tool error
P1 · negative · release · auto
Rows: 2
Test: `Tests/Unit/Mcp/Tool/Record/RecordsApplyToolTest.php::brokenJsonIsAToolError`
Given a `records_apply` call whose `data` is not valid JSON
When the tool runs
Then a tool error (`isError`) · the service is not called · nothing written

### T3AF-MCP-034 A records_apply request over the byte cap is refused before decoding
P1 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Mcp/Tool/Record/RecordsApplyToolTest.php::aRequestOverTheByteCapIsRefusedBeforeItIsDecoded`
Given `data` + `cmd` + `bulk` larger than the inline byte cap
When the tool runs
Then a tool error naming the cap · nothing decoded or written

### T3AF-MCP-035 write_table rejects an unknown table
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Mcp/Tool/Record/WriteTableToolTest.php::rejectsUnknownTable`
Given a table name not in TCA
When `write_table` is called with it
Then an error answer · nothing written

### T3AF-MCP-036 Creating on a page that does not exist is refused with a clear message
P1 · negative · release · auto
Rows: 2, 7
Test: `Tests/Functional/Mcp/DataHandler/WriteTableToolFunctionalTest.php::createOnAPageThatDoesNotExistIsRefusedWithAClearMessage`
Given pid 999999 does not exist
When `write_table` creates a record there
Then the error contains "Page 999999 was not found" · no row written

### T3AF-MCP-037 Tool schemas refuse unknown arguments
P2 · negative · — · auto
Rows: 2
Test: `Tests/Unit/Mcp/Service/McpToolSchemaAugmenterTest.php::generateForClassHandlerIsUsedForTaggedTools`
Given any tagged tool
When its input schema is generated by `McpToolSchemaAugmenter`
Then the schema has `additionalProperties: false`

### T3AF-MCP-038 A POST body over mcpMaxBodyBytes is refused
P2 · negative · — · gap
Rows: 2
Test: none
Given `mcpMaxBodyBytes` set to a small value (SDK floor 4 MiB)
When a client POSTs a larger JSON-RPC body to `/mcp`
Then an error status from the transport · no tool runs · no record written

### T3AF-MCP-039 A soft error answer becomes isError with a numeric code and one failure log
P1 · negative · release · auto
Rows: 2, 13
Test: `Tests/Unit/Mcp/Server/ErrorHandlingProxyTest.php::aToolAnswerWithAnErrorIsLoggedAsFailureAndThrown`
Given a tool that returns `{"error":"…"}`
When it runs through `ErrorHandlingProxy`
Then a `ToolCallException` with JSON `{"error":"…","code":1790700001}` · one failure log (audit + tool log) · no success log

---

## Edge

### T3AF-MCP-040 A dry run writes nothing but reports what would happen
P0 · edge · release · auto
Rows: 7
Test: `Tests/Functional/Mcp/RecordsApply/RecordsApplyServiceFunctionalTest.php::aDryRunWritesNothingButReportsWhatWouldHappen`
Given a valid `records_apply` payload with `dryRun: true`
When the tool runs
Then the answer lists the planned writes · no record, `sys_history` row or AI Label is written

### T3AF-MCP-041 One refused record rolls back the whole batch
P0 · edge · release · auto
Rows: 7
Test: `Tests/Functional/Mcp/RecordsApply/RecordsApplyServiceFunctionalTest.php::oneRefusedRecordRollsBackTheValidOnesBeforeIt`
Given a batch where a later record is refused by DataHandler
When `records_apply` runs
Then none of the earlier valid records stay in the database · the audit entry for the refusal remains

### T3AF-MCP-042 The same requestId with the same payload is applied once
P1 · edge · release · auto
Rows: 7, 9
Test: `Tests/Functional/Mcp/RecordsApply/RecordsApplyIdempotencyFunctionalTest.php::theSameRequestIdWithTheSamePayloadIsAppliedOnce`
Given a `records_apply` call with `requestId` that already succeeded
When the client retries the exact call
Then the stored answer is replayed (marked replayed) · no second record is created

### T3AF-MCP-043 Undo is refused when a record changed after the batch
P0 · edge · release · auto
Rows: 7
Test: `Tests/Functional/Mcp/RecordsApply/RecordsUndoFunctionalTest.php::aBatchIsRefusedWhenARecordWasChangedAfterIt`
Given a batch, then a later manual edit of one of its records
When `records_undo` runs for that batch
Then the undo is refused · the later edit is kept · no other record of the batch is reverted

### T3AF-MCP-044 In a workspace the batch creates workspace records and nothing live
P0 · edge · release · auto
Rows: 3, 7
Test: `Tests/Functional/Mcp/RecordsApply/RecordsApplyServiceFunctionalTest.php::inAWorkspaceTheBatchCreatesWorkspaceRecordsAndNothingLive`
Given the token user works in draft workspace N (MCP Server › Workspace selection)
When `records_apply` creates records
Then the records exist only as versions in workspace N · the live table has no new live row

### T3AF-MCP-045 The workspace switch is temporary and never stored on the user
P0 · edge · release · auto
Rows: 3, 7
Test: `Tests/Unit/Mcp/Authentication/BackendUserBootstrapTest.php::testApplyWorkspaceSwitchesTemporarilyAndNeverPersists`
Given a backend user whose own backend session is in Live
When an MCP call runs in workspace N
Then the call uses N · `be_users.workspace_id` is unchanged · the editor's backend stays in Live

### T3AF-MCP-046 A workspace the user may not use falls back to Live
P1 · edge · release · auto
Rows: 3
Test: `Tests/Unit/Mcp/Authentication/BackendUserBootstrapTest.php::testApplyWorkspaceFallsBackToLiveWhenWorkspaceIsNotAllowed`
Given a token pinned to workspace N the user has no access to
When the user is bootstrapped
Then the call runs in Live (0) · not in N

### T3AF-MCP-047 A playground preview clears the invocation context even when it fails
P1 · edge · — · auto
Rows: 7
Test: `Tests/Unit/Mcp/Service/Backend/McpPlaygroundServicePreviewClearTest.php::previewClearsInvocationContextOnFailure`
Given a previewable (DualMode) tool whose preview throws
When `McpPlaygroundService::preview` runs it
Then the invocation context (provider, workspace, `requestSource`) is cleared · the next call is not affected · nothing written

### T3AF-MCP-048 The mode override never changes the stored mcpMode
P1 · edge · — · auto
Rows: 7
Test: `Tests/Unit/Mcp/Service/McpModeOverrideTest.php::overrideNeverMutatesStoredExtensionSetting`
Given `mcpMode = context` in Extension Configuration
When `McpModeOverride` runs a callback in `native` mode
Then `McpModeResolver::getMode()` is `native` inside the callback and `context` after · the stored setting is unchanged

### T3AF-MCP-049 site_languages_list without a page lists languages per site
P2 · edge · — · auto
Rows: 8
Test: `Tests/Unit/Mcp/Service/SiteLanguagesListServiceTest.php::withoutAPageEachSiteListsItsOwnLanguages`
Given two sites with different language sets
When `site_languages_list` is called without a page
Then the answer groups languages under `sites[]` with `siteIdentifier` and `rootPageId`

### T3AF-MCP-050 A second site domain passes the DNS-rebinding check
P1 · edge · release · manual
Rows: 8, 10
Test: none
Given two sites on different hosts (both in `trustedHostsPattern`)
When a client calls `/mcp` on the second host with a valid token
Then `initialize` succeeds · a request with a `Host` that matches no site and is not localhost is refused by the transport

### T3AF-MCP-051 A core write tool charges no credits
P1 · edge · release · manual
Rows: 6
Test: none
Given T3Planet Credits mode is on and the balance is known
When `write_table` creates a record over MCP
Then the balance is unchanged · no credits receipt row for the call

### T3AF-MCP-052 t3af:mcp:cleanup removes expired OAuth rows and stale sessions
P2 · edge · — · gap
Rows: 9
Test: none
Given expired rows in `tx_nst3af_oauth_token`, `tx_nst3af_oauth_code`, `tx_nst3af_mcp_session` and a valid active token
When `typo3 t3af:mcp:cleanup` runs
Then exit code 0 and "Cleanup completed." · expired rows are gone · the active token still works

### T3AF-MCP-053 t3af:mcp:serve with an unknown user exits 1
P2 · edge · — · gap
Rows: 9
Test: none
Given no backend user `nobody` (or a disabled one)
When `typo3 t3af:mcp:serve -u nobody`
Then exit code 1 · stderr says `Backend user "nobody" not found or disabled.` · nothing on stdout

### T3AF-MCP-054 Scheduler tools work on TYPO3 14.3
P1 · edge · release · manual
Rows: 12
Test: none
Versions: 13.4, 14.3
Given at least one scheduler task on each version
When `scheduler_list` and `scheduler_get` run
Then both answer with the task and its `tasktype` · no SQL error on 14.3 (the tools read `serialized_task_object`)

### T3AF-MCP-055 The full HTTP flow passes on 13.4 and 14.3
P1 · edge · release · manual
Rows: 12
Test: none
Versions: 13.4, 14.3
Given a fresh install of each version
When T3AF-MCP-001, 002, 007 and 044 are run by hand with an MCP client
Then each case passes on both versions

---

## Security

### T3AF-MCP-056 file_upload_from_url refuses private IP addresses before any HTTP call
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Mcp/Service/FileUploadServiceSsrfTest.php::downloadFromUrlRejectsPrivateHostsBeforeHttp`
Given URLs on 127.0.0.1, 10.0.0.5, 169.254.169.254, 100.64.0.1
When `downloadFromUrl()` runs
Then "private or reserved network address" error · no HTTP request is sent · no file stored

### T3AF-MCP-057 A host name that resolves to a private address is refused
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Mcp/Service/FileUploadServiceSsrfTest.php::hostNameOfAPrivateAddressIsStillRefused`
Given a host name resolving to a private IP
When `file_upload_from_url` downloads it
Then the private-address error · no HTTP request · no file stored

### T3AF-MCP-058 A redirect to a private address is refused
P0 · security · release · gap
Rows: 10
Test: none
Given a public URL that answers 302 with `Location: http://169.254.169.254/…`
When `file_upload_from_url` follows it
Then the private-address error on that hop · no request to the private host · no file stored

### T3AF-MCP-059 A pinned download never combines stream with curl options
P1 · security · — · auto
Rows: 10
Test: `Tests/Unit/Mcp/Service/FileUploadServiceDownloadOptionsTest.php::pinnedDnsOptionsNeverCombineStreamWithCurl`
Given a host name resolved once to a public IP and no proxy
When the download options are built
Then `curl` holds `CURLOPT_RESOLVE` to that IP · `stream` is not set

### T3AF-MCP-060 Dangerous upload file names are refused
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Mcp/Service/FileUploadServiceAssertFileNameTest.php::assertFileNameIsAllowedRejectsDangerousNames`
Given names `shell.php`, `drop.phtml`, `page.html`, `page.htm`, `.htaccess`, `image.php.jpg`
When `assertFileNameIsAllowed()` checks them
Then each is refused · no file stored

### T3AF-MCP-061 A woff2 font with a PHP payload is refused
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Mcp/Service/FileUploadServiceFontMagicTest.php::polyglotWoff2HeaderPlusPhpIsRefused`
Given a file named `.woff2` with a woff2 header followed by PHP code
When the font magic-byte check runs
Then the file is refused · not stored

### T3AF-MCP-062 Dedupe never reuses a JPEG for a .woff2 upload
P1 · security · — · auto
Rows: 7, 10
Test: `Tests/Unit/Mcp/Service/FileUploadServiceDedupeGuardTest.php::dedupeRefusesExistingJpegWhenUploadNameIsWoff2`
Given an identical-content JPEG already in the folder
When an upload named `font.woff2` would dedupe to it
Then the dedupe is refused · the JPEG is not returned as the font

### T3AF-MCP-063 A pre-signed upload token works only once
P0 · security · release · auto
Rows: 7, 10
Test: `Tests/Functional/Mcp/File/FileUploadTokenFunctionalTest.php::consumeUploadTokenIsSingleUse`
Given a token from `file_upload_prepare`
When the token is consumed twice (as `/mcp_upload` does per upload)
Then the first use returns the target folder · the second returns nothing (refused) · only one file can be stored

### T3AF-MCP-064 A storage outside the user's file mounts is denied
P0 · security · release · auto
Rows: 3, 10
Test: `Tests/Unit/Mcp/Service/FileServiceStorageAccessTest.php::testGetFileInfoDeniesStorageOutsideUserFileMounts`
Given an editor without that storage in `getFileStorages()`
When `file_get_info` asks for a file there
Then "Access denied to storage" · no file data returned

### T3AF-MCP-065 The OAuth authorize POST needs the CSRF cookie
P0 · security · release · gap
Rows: 10
Test: none
Given an authorize form without the `mcp_csrf` cookie, or with a different `csrf_token`
When it is POSTed to `/mcp/oauth/authorize` with valid credentials
Then HTTP 403 "CSRF validation failed" · no code row · no redirect

### T3AF-MCP-066 The authorize page escapes the client name
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Mcp/OAuth/OAuthAuthorizePageRendererTest.php::renderEscapesClientNameAndShowsAuthenticationError`
Given a client registered with `client_name` containing `<script>`
When `/mcp/oauth/authorize` is rendered
Then the name is HTML-escaped · no script runs

### T3AF-MCP-067 The token endpoint answers a generic invalid_grant
P1 · security · release · auto
Rows: 10
Test: `Tests/Functional/Mcp/OAuth/OAuthAuthorizationFlowFunctionalTest.php::tokenEndpointReturnsGenericInvalidGrantWithoutInternalDetail`
Given an invalid code
When `/mcp/oauth/token` is called
Then HTTP 400 `invalid_grant` with the fixed description · no exception text, code or stack in the body

### T3AF-MCP-068 Reusing a rotated refresh token revokes the whole family
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Mcp/OAuth/AuthorizationServiceRefreshTokenTest.php::testRefreshTokenReuseRevokesClientUserFamily`
Given a refresh token already rotated once
When it is presented again
Then refresh fails · every active token of that client + user is revoked

### T3AF-MCP-069 The IP allowlist blocks unknown addresses
P1 · security · release · auto
Rows: 3, 10
Test: `Tests/Unit/Mcp/Service/Backend/McpSecurityServiceTest.php::testDeniesUnknownIpWhenAllowlistConfigured`
Given an enabled IP allowlist without the client IP
When the client calls `/mcp` or an OAuth path
Then the IP is not allowed (middleware answers 403 "Client IP address is not on the MCP allowlist.") · no tool runs

### T3AF-MCP-070 OAuth endpoints are rate limited
P1 · security · — · gap
Rows: 10
Test: none
Given `rateLimitEnabled = 1` and `rateLimitToken = 20` per `rateLimitTokenWindow = 300` s (defaults)
When one IP sends 21 `POST /mcp/oauth/token` requests inside one window
Then the 21st gets HTTP 429 `too_many_requests` with a `Retry-After` header · no token issued for it · a row exists in `tx_nst3af_oauth_rate_limit`

### T3AF-MCP-071 The records_apply audit keeps field values out of the log
P0 · security · release · auto
Rows: 10, 13
Test: `Tests/Unit/Mcp/Service/RecordsApply/RecordsApplyAuditTest.php::quotedValuesAreKeptOutOfTheLog`
Given a refused batch whose DataHandler message quotes field values
When the audit line is written
Then the quoted values are removed · table, uid and reason remain

---

## AI output

None — see **Not applicable** row 11.

---

## Field reference

See `_template.md`. Tags `—` = no tag. `Versions:` is set only where behaviour differs between TYPO3 13.4 and 14.3.
