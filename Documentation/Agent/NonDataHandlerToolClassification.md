# Non-DataHandler Tool Classification

Phase 0 prerequisite for the AI Agent write path (amended R30). Each child extension MCP tool is classified by inspecting its `execute()` path and delegated services.

**Legend**

| Classification | Meaning |
| --- | --- |
| **read-only** | Returns data only; no persistence |
| **non-record-write** | Persists data via DBAL, extension repositories, file storage, or external APIs — not via TYPO3 `DataHandler` |
| **record-write (DataHandler)** | Creates or updates core TYPO3 TCA records through `DataHandler` (directly or via delegated services). Out of scope for the read-only vs non-record-write binary; covered by Phase 0b (`McpPlannableToolInterface`) |

**Totals (39 child tools)**

| Extension | Tools | Read-only | Non-record-write | Record-write (DataHandler) |
| --- | ---: | ---: | ---: | ---: |
| `ns_t3ai` | 23 | 2 | 3 | 18 |
| `ns_t3aa` | 6 | 5 | 1 | 0 |
| `ns_t3cs` | 7 | 4 | 3 | 0 |
| `ns_t3as` | 2 | 1 | 1 | 0 |
| `ns_t3ac` | 1 | 0 | 1 | 0 |
| **Total** | **39** | **13** | **8** | **18** |

Dual-mode tools (`get` / `update` operations) are classified by their mutating operation.

---

## ns_t3ai (23 tools)

| MCP tool name | Class | Classification | Write path / notes |
| --- | --- | --- | --- |
| `t3ai_mass_translation_queue_list` | `MassTranslationQueueListTool` | read-only | SELECT on `tx_nst3ai_domain_model_bulktranslation` |
| `t3ai_mass_seo_queue_list` | `MassSeoQueueListTool` | read-only | SELECT on `tx_nst3ai_domain_model_bulkseo` |
| `t3ai_mass_translation_queue_add` | `MassTranslationQueueAddTool` | non-record-write | `McpQueueService` → DBAL INSERT/UPDATE/DELETE on queue table |
| `t3ai_mass_seo_queue_add` | `MassSeoQueueAddTool` | non-record-write | `McpQueueService` → DBAL INSERT on queue table |
| `t3ai_apply_schema_markup` | `ApplySchemaMarkupTool` | non-record-write | `McpSchemaService` → `SchemaRepository` + `ConnectionPool` UPDATE on `pages` (bypasses DataHandler) |
| `t3ai_apply_content_analysis` | `ApplyContentAnalysisTool` | non-record-write | When `updates` empty: log only via `T3AiLogService`. When `updates` provided: **record-write (DataHandler)** on `tt_content` |
| `t3ai_create_news_simple` | `CreateNewsSimpleTool` | record-write (DataHandler) | `McpContentCreationService` → `NewsRepository` |
| `t3ai_create_news_advanced` | `CreateNewsAdvancedTool` | record-write (DataHandler) | Same + content elements via `PageRepository` |
| `t3ai_create_page_structure` | `CreatePageStructureTool` | record-write (DataHandler) | `McpPageGenerationService` → `PageStructureService` |
| `t3ai_create_blog_advanced` | `CreateBlogAdvancedTool` | record-write (DataHandler) | `McpContentCreationService` → `PageRepository` |
| `t3ai_create_blog_simple` | `CreateBlogSimpleTool` | record-write (DataHandler) | Same |
| `t3ai_create_page_advanced` | `CreatePageAdvancedTool` | record-write (DataHandler) | Same |
| `t3ai_create_page_simple` | `CreatePageSimpleTool` | record-write (DataHandler) | Same |
| `t3ai_record_translate` | `TranslateRecordTool` | record-write (DataHandler) | `McpTranslationService` → `TranslationLocalizeService` |
| `t3ai_translate_content` | `TranslateContentTool` | record-write (DataHandler) | Same (batch `tt_content`) |
| `t3ai_translate_news` | `TranslateNewsTool` | record-write (DataHandler) | Same on `tx_news_domain_model_news` |
| `t3ai_generate_seo_batch` | `GenerateSeoBatchTool` | record-write (DataHandler) | `McpSeoService` → `PageRepository::saveField()` |
| `t3ai_generate_og_title` | `GenerateOgTitleTool` | record-write (DataHandler) | Same |
| `t3ai_generate_og_description` | `GenerateOgDescriptionTool` | record-write (DataHandler) | Same |
| `t3ai_generate_meta_description` | `GenerateMetaDescriptionTool` | record-write (DataHandler) | Same |
| `t3ai_generate_keywords` | `GenerateKeywordsTool` | record-write (DataHandler) | Same |
| `t3ai_generate_all_seo` | `GenerateAllSeoTool` | record-write (DataHandler) | Same |
| `t3ai_create_content_element` | `CreateContentElementTool` | record-write (DataHandler) | `McpContentCreationService` → `PageRepository::saveContentElement()` |

---

## ns_t3aa (6 tools)

| MCP tool name | Class | Classification | Write path / notes |
| --- | --- | --- | --- |
| `t3aa_get_page_speed` | `GetPageSpeedTool` | read-only | External Google PageSpeed API via `PageMonitorService` |
| `t3aa_list_files_missing_alt_text` | `ListFilesMissingAltTextTool` | read-only | SELECT join `sys_file_metadata` + `sys_file` |
| `t3aa_get_file_metadata` | `GetFileMetadataTool` | read-only | `FileMetaService::getSystemFileMetaData()` |
| `t3aa_get_file_for_metadata` | `GetFileForMetadataTool` | read-only | `FileResolver` + metadata lookup; hints update tool |
| `t3aa_summarize_content` | `SummarizeContentTool` | read-only | Reads `tt_content`; optional AI; does not persist |
| `t3aa_update_file_metadata` | `UpdateFileMetadataTool` | non-record-write | `FileMetaService` → DBAL INSERT/UPDATE on `sys_file_metadata` |

---

## ns_t3cs (7 tools)

| MCP tool name | Class | Classification | Write path / notes |
| --- | --- | --- | --- |
| `t3cs_list_datasources` | `ListDatasourcesTool` | read-only | SELECT on datasource table |
| `t3cs_list_queue_items` | `ListQueueItemsTool` | read-only | SELECT on training queue |
| `t3cs_training_summary` | `TrainingSummaryTool` | read-only | Queue/embedding counts |
| `t3cs_usage_analytics_summary` | `UsageAnalyticsSummaryTool` | read-only | Aggregated SELECTs |
| `t3cs_save_datasource` | `SaveDatasourceTool` | non-record-write | `DatasourceRepository` DBAL; may download PDF to fileadmin |
| `t3cs_sync_datasource` | `SyncDatasourceTool` | non-record-write | Sync flags (DBAL) or `DatasourceSyncService` queue/embed writes |
| `t3cs_reset_failed_queue_item` | `ResetFailedQueueItemTool` | non-record-write | `DatasourceQueueRepository` DBAL UPDATE |

---

## ns_t3as (2 tools)

| MCP tool name | Class | Classification | Write path / notes |
| --- | --- | --- | --- |
| `t3as_list_predefined_questions` | `ListPredefinedQuestionsTool` | read-only | SELECT on predefined questions |
| `t3as_search_settings` | `SearchSettingsTool` | non-record-write | `update` operation → `SettingsRepository::createOrUpdate()` (DBAL). `get` is read-only |

---

## ns_t3ac (1 tool)

| MCP tool name | Class | Classification | Write path / notes |
| --- | --- | --- | --- |
| `t3ac_chatbot_settings` | `ChatbotSettingsTool` | non-record-write | `update` operation → `ChatbotRepository::createOrUpdateChatbot()` (DBAL). `get` is read-only |

---

## Defects / follow-ups

- **`t3ai_apply_content_analysis`**: mixed mode — log-only path is non-record-write; `updates` path uses DataHandler. Agent must treat severity per operation.
- **`McpContentCreationService`** injects `DataHandlerService` but delegates to repositories that instantiate `DataHandler` directly — no agent impact, but worth noting for 0b retrofit.
- Any tool writing TCA records **without** DataHandler is a defect in the owning child extension and must be raised as a separate ticket (per handoff Q-I).
