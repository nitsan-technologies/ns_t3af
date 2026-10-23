# AI Access & Roles — cases

**Prefix:** `T3AF-ACL`
**How it works:** `context/features/ai-access-roles.md`, `tasks/record-access-enforcement.md`, `Documentation/DeveloperGuide/CustomAccessCatalogs/Index.rst`
**Main code:** `Classes/Access/*`, `Classes/Controller/Backend/AccessRolesController.php`, `Classes/Controller/Backend/AccessRolesAjaxController.php`, `Classes/Service/BeGroupAccessService.php`, `Classes/Service/PermissionMatrixService.php`, `Classes/Registry/AiAccessCatalogProviderRegistry.php`, `Classes/Registry/FeatureAccessBindingRegistry.php`, `Classes/Bootstrap/AiAccessCustomOptionsBootstrap.php`, `Resources/Public/JavaScript/access-roles.js`
**Existing tests:** `Tests/Unit/Access/*`, `Tests/Unit/Registry/AiAccessCatalogProviderRegistryTest.php`, `Tests/Unit/Registry/FeatureAccessBindingRegistryTest.php`, `Tests/Unit/Utility/ModuleTabUtilityTest.php`, `Tests/Unit/Service/BeGroupScopeResolverTest.php`

---

## Not applicable

| Row | Reason |
|---|---|
| 5 Provider failure | The wizard, matrix and gates never call an AI provider. Provider-side denials are in `governance.md`. |
| 9 Queue / CLI | No queue, scheduler task or CLI command. Config is only written through the `nst3af_access_roles_apply` AJAX route. |
| 11 AI output | No AI-generated content is produced or shown. |

---

## Positive

### T3AF-ACL-001 Wizard apply writes merged ACL and group limits
P1 · positive · smoke, release · manual
Rows: 1
Test: none
Given an admin, `ns_t3ai` loaded, and a backend group "Editors" with `groupMods = web_layout` and no AI permissions
When the admin opens **AI Foundation → AI Permissions**, picks preset **AI Editor**, walks Modules → Features → Records → Limits → Review and clicks Apply
Then `be_groups.groupMods` contains `web_layout`, `t3af`, `t3af_dashboard` · `custom_options` contains `nst3af_tab:ai_usage` and `nst3af_tab:ai_prompts` · `tables_modify` contains `tx_nst3af_ai_prompt` and `tx_nst3af_brand_context_profile` · `tx_nst3af_group_settings` has a row for the group with `configured = 1` and `credit_cap_monthly = 10000` · the group list shows the group as configured

### T3AF-ACL-002 Preset templates serialize the expected bits
P1 · positive · release · auto
Rows: 1
Test: `Tests/Unit/Access/GroupConfigSerializerTest.php::consumerPresetWritesExpectedBits`
Given preset **AI Consumer**
When the config is normalized and serialized
Then `groupMods` has `nitsan_nst3ai_dashboard` · `customOptions` has `nst3af_tab:ai_prompts` and `T3Ai:Content` · `tablesSelect` has `tx_nst3af_ai_prompt` · `tablesModify` does not have `tx_nst3af_ai_prompt`

### T3AF-ACL-003 Merge keeps unrelated permissions
P0 · positive · smoke, release · auto
Rows: 1, 7
Test: `Tests/Unit/Access/BeGroupPermissionMergerTest.php::mergePreservesUnrelatedPermissions`
Given a group with `web_list`, `pages`, `other_ext:foo`, `nst3af:capability_chat` and `pages` in `tables_modify`
When the consumer preset is merged into it
Then `web_list`, `pages` (module), `other_ext:foo` and `pages` (tables_modify) are still present · legacy `tx_t3ai_content:content` is replaced by `T3Ai:Content`

### T3AF-ACL-004 Re-apply replaces previously managed AI bits
P1 · positive · release · auto
Rows: 1, 7
Test: `Tests/Unit/Access/BeGroupPermissionMergerTest.php::mergeReplacesPreviouslyManagedAiPermissions`
Given a group that already has `nst3af_tab:providers`, `T3Ai:SEO`, `nitsan_nst3aa_dashboard` and prompt table write access
When a narrower config (T3AI content + AI Prompts only) is applied
Then the old managed bits are gone · `groupMods = nitsan_nst3ai_dashboard,t3af,t3af_dashboard` · `tables_modify` is empty

### T3AF-ACL-005 Review preview shows the merge and writes nothing
P1 · positive · release · manual
Rows: 1, 7
Test: none
Given a configured group and the wizard on step 5 (Review)
When the browser calls `nst3af_access_roles_preview` with `{ groupUid, config }`
Then the response lists merged `groupMods`, `customOptions`, `tablesSelect`, `tablesModify` matching what Apply later writes · the `be_groups` row and `tx_nst3af_group_settings` row are unchanged until Apply is clicked

### T3AF-ACL-006 Permission matrix builds foundation and child scope tabs
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Access/MatrixScopeCatalogTest.php::testBuildsFoundationAndChildScopes`
Given AI Foundation plus registered child access catalog providers
When the matrix scopes are built
Then there is one **AI Foundation** scope plus one scope per child module

### T3AF-ACL-007 Child access provider appears in wizard, matrix and native custom options
P1 · positive · release · manual
Rows: 1, 4
Test: none
Given a third-party extension with a class implementing `AiAccessCatalogProviderInterface`, tagged `t3af.ai_access_catalog_provider`, `isAvailable()` true
When caches are flushed and the admin opens the wizard, the matrix and **Backend user groups → Access Lists → Custom module options**
Then its module card shows in step 1, its features in step 2, its record rows in step 3, a scope tab in the matrix, and its `T3Ai:*` items under the **T3Ai** header

### T3AF-ACL-008 AI Providers UI follows tables_modify on tx_nst3af_provider
P1 · positive · release · manual
Rows: 1, 3
Test: none
Given credits mode off · editor group A with `tables_modify` on `tx_nst3af_provider` · editor group B with select only
When each editor opens **AI Foundation → Providers**
Then A sees **+ New Provider**, Import, Edit, Delete, Set default · B sees the list and Test only · a direct POST to the provider save / delete route by B is refused and no provider row changes

---

## Negative

### T3AF-ACL-009 Apply with an invalid payload is rejected
P2 · negative · — · gap
Rows: 2
Test: none
Given an admin
When `nst3af_access_roles_apply` is POSTed with `groupUid = 0`, or with `config` that is not an object or not valid JSON
Then HTTP 400 `{ ok: false, message: "Invalid payload" }` · no `be_groups` row and no `tx_nst3af_group_settings` row changes

### T3AF-ACL-010 Group detail for a missing uid returns 404
P3 · negative · — · gap
Rows: 2
Test: none
Given an admin
When `nst3af_access_roles_group?uid=999999` is called
Then HTTP 404 `{ ok: false, message: "Group not found" }` · nothing is written

### T3AF-ACL-011 Apply to a deleted or unknown group writes nothing
P2 · negative · — · manual
Rows: 2, 7
Test: none
Given a `groupUid` that does not exist in `be_groups`
When the admin POSTs a valid config to `nst3af_access_roles_apply`
Then the response carries `group: null` · no `tx_nst3af_group_settings` row is created for that uid · no other group changes

### T3AF-ACL-012 FormData "false" strings do not enable modules
P1 · negative · release · auto
Rows: 2
Test: `Tests/Unit/Access/GroupConfigFromArrayTest.php::fromArrayDoesNotTreatFalseStringAsEnabledModule`
Given a payload where module values are the strings `"false"` / `"0"`
When `GroupConfig::fromArray()` parses it
Then those modules are disabled · no matching `groupMods` or `nst3af_tab:*` bits are serialized

### T3AF-ACL-013 Read-only editor gets 403 JSON on a catalog write
P0 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Access/RecordAccessEnforcerTest.php::testReadOnlyUserGets403Json`
Given a non-admin with `tables_select` but no `tables_modify` on the catalog table
When a mutating route calls `RecordAccessEnforcer::denyUnlessCanModifyCatalogId()`
Then HTTP 403 `{ ok: false, message: "Record modification is not permitted for your backend user group. (catalog: …)" }` · the route writes nothing

### T3AF-ACL-014 Direct POST by a read-only editor is refused on every enforced surface
P0 · negative · release · manual
Rows: 3, 7
Test: none
Given an editor group with Read only on `brandProfiles`, `aiPromptStorage`, `extensionSettings`, `usageRequestLog`, `oauthClients`, `mcpCustomTools`
When the editor POSTs directly (devtools / curl with the route token) to Brand Context save, AI Prompts create, AI Features save, AI Usage delete, MCP OAuth issue and MCP custom tool save
Then each returns 403 JSON or redirects with an access-denied flash · no row in the matching `tx_nst3af_*` table is created, changed or deleted

### T3AF-ACL-015 Gated AI Foundation tab needs its nst3af_tab bit
P1 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Access/ModuleTabAccessServiceTest.php::testGatedTabRequiresMatchingPermission`
Given an editor whose groups carry `nst3af_tab:ai_prompts` but not `nst3af_tab:providers`
When tab visibility is resolved
Then AI Prompts is visible · Providers is hidden

### T3AF-ACL-016 Child tab is hidden when its feature bit is missing
P1 · negative · — · auto
Rows: 3
Test: `Tests/Unit/Access/FeaturePermissionGateTest.php::testT3AiTabDeniedWhenFeatureMissing`
Given an editor with T3AI module access but without the tab's `T3Ai:*` bit
When `FeaturePermissionGate::grantsModuleTab()` / `grantsModuleCard()` run
Then the tab and its card are denied

### T3AF-ACL-017 Use level does not grant Manage
P1 · negative · — · gap
Rows: 3
Test: none
Given an editor with `T3Ai:Translation` only (no `T3Ai:Translation.Manage`)
When `T3AiPermissionResolver::hasFeature($user, 'Translation.Manage')` runs
Then it returns false · the base check `hasFeature($user, 'Translation')` still returns true

### T3AF-ACL-018 Apply failure returns a readable error and leaves no partial write
P2 · negative · — · manual
Rows: 7, 13
Test: none
Given the `tx_nst3af_group_settings` table is missing or not writable (schema not updated)
When the admin clicks Apply
Then the response is HTTP 500 `{ ok: false, message }` with a readable message and no DB credentials · the wizard shows the message · `be_groups` is not left updated without a matching settings row (if it is, report as bug)

---

## Edge

### T3AF-ACL-019 Disabling a module clears its orphan features and records
P1 · edge · release · auto
Rows: 7
Test: `Tests/Unit/Access/GroupConfigNormalizerTest.php::disablingModuleClearsOrphanFeaturesAndRecords`
Given a config with T3AI off but `content = manage` and `aiPromptStorage = readwrite`, and T3CS on with `t3csChat = use`
When `GroupConfigNormalizer::normalize()` runs before apply
Then `content` is disabled and `aiPromptStorage` is `none` · `t3csChat` stays `use`

### T3AF-ACL-020 No nst3af_tab bits means legacy permissive navigation
P2 · edge · — · auto
Rows: 3, 4
Test: `Tests/Unit/Access/ModuleTabAccessServiceTest.php::testPermissiveWhenNoTabPermissionsConfigured`
Given an editor whose groups have no `nst3af_tab:*` custom option at all
When tab visibility is resolved
Then Providers and AI Prompts are visible (Access Roles stays admin-only)

### T3AF-ACL-021 Unavailable child provider is skipped everywhere
P1 · edge · release · auto
Rows: 4
Test: `Tests/Unit/Registry/AiAccessCatalogProviderRegistryTest.php::testSkipsUnavailableProviders`
Given a registered access catalog provider whose `isAvailable()` returns false (extension not loaded)
When catalogs are merged
Then its module, features and record rows are not returned

### T3AF-ACL-022 Consumer preset takes over tt_content write when T3AI manages it
P1 · edge · release · auto
Rows: 7
Test: `Tests/Unit/Access/BeGroupPermissionMergerTest.php::mergePreservesUnrelatedPermissions`
Given `ns_t3ai` loaded (its record row manages `tt_content`) and a group with `tables_modify = tt_content,pages`
When the consumer preset (page content Read) is merged
Then `tables_modify = pages` · `tt_content` stays in `tables_select`

### T3AF-ACL-023 Native nst3af_tab:ai_label grant survives a wizard apply
P2 · edge · — · manual
Rows: 7
Test: none
Given a group with `nst3af_tab:ai_label` set in native Custom module options (the wizard has no AI Label toggle)
When the admin applies any wizard config to that group
Then `nst3af_tab:ai_label` is still in `custom_options` and the editor still sees the AI Label tab

### T3AF-ACL-024 Leftover grants from a disabled child extension are visible
P2 · edge · — · manual
Rows: 4, 7
Test: none
Given a group configured with T3AA enabled, then `ns_t3aa` deactivated and caches flushed
When the admin re-applies the wizard for that group
Then the Review preview still lists the old T3AA tables / module (no longer managed) so the admin can see them · all other unrelated permissions are unchanged

### T3AF-ACL-025 Credit and daily caps are stored only when enabled
P1 · edge · release · gap
Rows: 6
Test: none
Given the Limits step with credit cap switched off but value 2000, and daily request cap switched on at 40
When the admin applies
Then `tx_nst3af_group_settings.credit_cap_monthly = 0` · `daily_request_cap = 40` · `limits_json` keeps the raw values

### T3AF-ACL-026 Credits mode makes AI Providers read-only for editors
P2 · edge · — · manual
Rows: 4, 6
Test: none
Given credits mode on and an editor with `tables_modify` on `tx_nst3af_provider`
When the editor opens **Providers**
Then New / Import / Edit / Delete / Set default are hidden and the server refuses those actions · no provider row changes

### T3AF-ACL-027 Apply leaves mounts, languages and title untouched
P1 · edge · release · manual
Rows: 7, 8
Test: none
Given a group with title, `db_mountpoints` on site A, `file_mountpoints`, `allowed_languages = 0,1` and TSconfig
When the admin applies a wizard config
Then only `groupMods`, `custom_options`, `tables_select`, `tables_modify` change · title, mounts, languages and TSconfig are unchanged · group detail shows the same scope

### T3AF-ACL-028 Editor in a draft workspace gets the same tabs and gates
P2 · edge · — · manual
Rows: 3
Test: none
Given an editor with a wizard config and access to a draft workspace
When the editor switches to the workspace and opens AI Foundation
Then the same tabs are visible as in Live · enforced write routes still return 403 for read-only catalogs

### T3AF-ACL-029 Backend permission check uses the boolean result
P1 · edge · release · auto
Rows: 12
Test: `Tests/Unit/Access/BackendPermissionCheckTest.php::testIsGrantedUsesBooleanCheckResult`
Given `BackendUserAuthentication::check()` returning bool (TYPO3 13.4 / 14.3)
When `BackendPermissionCheck::isGranted()` is called
Then `tables_modify` on `tx_nst3af_provider` is granted and on `tx_other` is denied

### T3AF-ACL-030 T3Ai custom options appear on both TYPO3 versions
P1 · edge · release · manual
Rows: 4, 12
Test: none
Given `ns_t3ai`, `ns_t3aa`, `ns_t3cs` active on TYPO3 13.4 and on 14.3
When caches are flushed and **Custom module options** of a group is opened
Then the **T3Ai** header is present with items from each provider · with no child provider active the header is still present with no items

---

## Security

### T3AF-ACL-031 Non-admin cannot read or change roles through AJAX
P0 · security · smoke, release · gap
Rows: 3, 10
Test: none
Given a logged-in non-admin editor with AI Foundation module access
When the editor calls `nst3af_access_roles_groups`, `_group`, `_matrix`, `_preview` and POSTs `_apply` with their own `groupUid` and a full admin config
Then every call returns HTTP 403 `{ ok: false, message: "Forbidden" }` · no `be_groups` field and no `tx_nst3af_group_settings` row changes · the editor's rights are unchanged after re-login

### T3AF-ACL-032 Access Roles tab is admin only
P0 · security · release · auto
Rows: 3
Test: `Tests/Unit/Access/ModuleTabAccessServiceTest.php::testAccessRolesAdminOnly`
Given a non-admin and an admin
When tab visibility for `aiAccessRoles` is resolved
Then it is hidden for the non-admin and visible for the admin

### T3AF-ACL-033 Payload cannot grant tables outside the record catalog
P0 · security · release · gap
Rows: 2, 10
Test: none
Given an admin session
When `_apply` is POSTed with `records: { "be_users": "readwrite", "aiPromptStorage": "superuser" }`
Then `be_users` is not added to `tables_select` / `tables_modify` · the unknown level is treated as `none` · only rows from `RecordPermissionCatalog` produce table grants

### T3AF-ACL-034 Two groups: rights are the union, wizard touches only the target group
P0 · security · release · manual
Rows: 3, 7
Test: none
Given an editor in group A (wizard: AI Prompts Read) and group B (native: `tables_modify` on `tx_nst3af_ai_prompt`)
When the admin re-applies the wizard on A, and the editor then edits a prompt
Then group B's row is unchanged · the editor can write prompts (TYPO3 union of groups) · the matrix row for A still shows Read only

### T3AF-ACL-035 Unconfigured second group does not reopen gated tabs
P0 · security · release · manual
Rows: 3
Test: none
Given an editor in group A (wizard: only `nst3af_tab:ai_prompts`) and group B (no `nst3af_tab:*` bits)
When the editor opens AI Foundation
Then only Dashboard and AI Prompts are visible · Providers, MCP, AI Usage and AI Logs stay hidden

### T3AF-ACL-036 Admin bypasses record gates
P1 · security · — · auto
Rows: 3
Test: `Tests/Unit/Access/RecordAccessGateTest.php::testAdminCanAlwaysModify`
Given an admin without any `tables_modify` entries
When `RecordAccessGate::canModifyTable()` runs
Then it returns true

### T3AF-ACL-037 Group titles with HTML render as text
P1 · security · — · manual
Rows: 10
Test: none
Given a `be_groups` title `<img src=x onerror=alert(1)></script>`
When the admin opens the wizard group list and the Permission Matrix
Then the title shows as literal text · no script runs · the bootstrap `<script type="application/json">` block is not broken

### T3AF-ACL-038 AJAX routes refuse requests without a valid route token
P1 · security · — · manual
Rows: 10
Test: none
Given an admin session
When `_apply` is POSTed from another origin or with a missing / wrong route token
Then TYPO3 rejects the request · no `be_groups` field changes
