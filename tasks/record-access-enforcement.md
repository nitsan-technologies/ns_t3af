# Record access enforcement checklist

Use `RecordAccessEnforcer` + catalog IDs from `RecordPermissionCatalog` for every mutating backend/AJAX route.

## Central (`ns_t3af`)

| Class | Role |
|---|---|
| `RecordAccessGate` | `canSelectTable`, `canModifyTable`, `canModifyCatalogRow`, `assert*` |
| `RecordAccessEnforcer` | `denyUnlessCanModifyTable`, `denyUnlessCanModifyCatalogId` → 403 JSON |
| `FeatureAccessBindingRegistry` | Record catalog IDs from `AiAccessCatalogProviderInterface` bindings |
| `AiUniverseRecordMap` | AI Foundation admin catalog ID constants |

Register both gate and enforcer as `public: true` in `Configuration/Services.yaml`.

## T3CS (`ns_t3cs`) — reference implementation

- [x] `T3CsBackendController` — all datasource/source-group/queue/usage-analytics POST actions
- [x] `SearchTabController::updateAction` — `t3csSearchSettings`
- [x] `T3AsLogController::deleteSearchHistoryAction` — `t3csSearchHistory`
- [x] `T3AcChatbotController` — chatbot save/upload/training/delete — `t3csChatbot`; history — `t3csChatbotHistory`
- [x] `McpSaveDatasourceService` — `t3csDatasource`
- [x] Fluid `canModify*` flags in `buildBaseAssign()` + partials (DataSource, TrainingCenter, Search, Chatbot, UsageAnalytics, SourceGroupList)

**Manual check:** Editor with Read on `t3csDatasource` sees list, no add/edit/delete/sync; direct POST returns 403.

## AI Foundation admin

- [x] `ProviderController` — `tx_nst3af_provider` (existing)
- [x] `BrandContextController` — `brandProfiles` + `canModifyBrandProfiles`
- [x] `FeatureSettingsController::saveAction` — `extensionSettings`
- [x] `ModuleController` — usage log delete/export (`usageRequestLog`), prompts CRUD/sync (`aiPromptStorage`)
- [x] `McpToolsController` — discovered tables, custom tools, prompt templates
- [x] `McpServerController` — OAuth token issue/revoke (`oauthClients`)

## T3AI (`ns_t3ai`)

- [x] `T3AiController::savePromptAction` — `aiPromptStorage`
- [x] `T3AiBackendController::saveMassSeoAction` — core `pages` via `tables_modify`
- [x] `T3AiBackendController::addToMassSeoQueueAction` — `bulkSeo`
- [x] `T3AiBackendController::deleteFromMassSeoQueueAction` — `bulkSeo`
- [x] `T3AiBackendController::deleteFromQueueAction` — `bulkTranslation`
- [x] `T3AiBackendController::saveMassTranslationLanguageConfigAction` — `bulkTranslation`
- [x] `T3AiBackendController::buildDashboardModules` — card filtering via `FeaturePermissionGate`
- [x] Content writes — `T3AiController::denyUnlessPageContentWrite()` (feature gate + `tables_modify`)

## T3AA (`ns_t3aa`)

- [x] `ImageController::massMetaGenerateAction` — `t3aaBulkMeta`
- [x] `ImageController::fileMetaSaveAction` — `denyUnlessT3AaFileMetaWrite()` (`sys_file` / metadata)
- [x] `ButtonBarUtility` — `FeaturePermissionGate` for doc-header cards (aligned with dashboard)

## Tests

```bash
cd packages/ns_t3af && .Build/bin/phpunit Tests/Unit/Access/
```
