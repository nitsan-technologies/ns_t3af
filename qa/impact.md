# Impact map — ns_t3af

Changed path → case files. `php qa/scope.php change` reads this; agents should not.

Add every matching row. Always add smoke-auto (`php qa/scope.php smoke`). For changes under `Classes/Api/`, `Classes/Credits/`, `Classes/Provider/`, `Classes/Mcp/` or `Classes/Access/`, the script prints child edges — do not load the child `qa/` tree unless the user asked.

---

## Code → cases

| Changed path | Run cases |
|---|---|
| `Classes/Api/*`, `Classes/Service/AiService.php`, `AiToolCallingService.php`, `ImageGenerationService.php`, `TtsService.php`, `Classes/Event/*` | `public-api`, `providers`, `credits` |
| `Classes/Provider/*`, `Classes/Domain/Repository/Provider*`, `Classes/Service/Provider*.php`, `CredentialCipher.php`, `Classes/Controller/Backend/ProviderController.php`, `Classes/Utility/ProviderSlugMapper.php`, `Classes/Feature/T3AfEmbeddingCapabilityProvider.php` | `providers`, `public-api` |
| `Classes/Credits/*`, `Classes/Service/T3PlanetCreditsChatExecutor.php`, `Classes/Controller/Backend/CreditModeController.php` | `credits`, `public-api` |
| `Classes/Mcp/Server/*`, `Classes/Mcp/Http/*`, `Classes/Mcp/Middleware/*`, `Classes/Mcp/OAuth/*`, `Classes/Mcp/Authentication/*`, `Classes/Mcp/Controller/*` | `mcp-server` (all) |
| `Classes/Mcp/Tool/*`, `Classes/Mcp/Service/*`, `Classes/Mcp/Support/*`, `Classes/Mcp/Attribute/*`, `Configuration/McpToolMetadata.yaml` | `mcp-server`, `ai-agent` (agent calls MCP tools) |
| `Classes/Mcp/Service/RecordsApply/*`, `Classes/Mcp/Service/DataHandlerService.php`, `Classes/Mcp/Service/FileUploadService.php`, `Classes/Mcp/Service/McpSlugRedirectGuard.php` | `mcp-server` data-safety and security rows |
| `Classes/Agent/*`, `Resources/Private/Agent/*`, `Classes/Command/EvalAgentCommand.php`, `IndexAgentToolsCommand.php`, `CleanAgentConversationsCommand.php`, `Classes/Domain/Repository/AgentConversationRepository.php` | `ai-agent` + replay (`t3af:agent:eval`) |
| `Classes/Access/*`, `Classes/Controller/Backend/AccessRoles*.php`, `Classes/Service/PermissionMatrixService.php`, `BeGroup*.php`, `Classes/Domain/Repository/BeGroupRepository.php`, `GroupSettingsRepository.php` | `access-roles`, `governance` |
| `Classes/Governance/*`, `Classes/Domain/Repository/UsageBudgetRepository.php`, `Classes/EventListener/RecordBudgetUsageListener.php`, `Classes/Service/RequestTelemetryService.php`, `AiApiAlertNotificationService.php` | `governance` |
| `Classes/Service/AiLog*.php`, `AiUniverseActivityLogService.php`, `AiUsageAnalyticsService.php`, `Classes/Domain/Repository/AiSysLogRepository.php`, `RequestLogRepository.php`, `Classes/Domain/RequestLog/*`, `Classes/Utility/SysLogWriterUtility.php`, `Classes/Command/CleanAiLogsCommand.php`, `Classes/Feature/T3AfAiLogChannelProvider.php` | `ai-logs` |
| `Classes/Service/BrandContext*.php`, `Classes/Controller/Backend/BrandContext*.php`, `Classes/Domain/Repository/BrandContextProfile*.php`, `Classes/EventListener/BrandContextPromptInjectionListener.php`, `Classes/Service/AiPromptsBrandContextInfoService.php` | `ai-context` |
| `Classes/AiLabel/*`, `Classes/Controller/Backend/AiLabelController.php`, `Classes/Command/AiLabel*.php` | `ai-label` |
| `Classes/Controller/Backend/ModuleController.php`, `AbstractAiUniverseModuleController.php`, `FeatureSettingsController.php`, `Classes/Service/Dashboard*.php`, `ModuleStateService.php`, `FeatureExtensionHealthService.php`, `SetupChecklist*.php`, `EnvironmentRequirementService.php`, `Classes/Backend/*`, `Classes/Utility/ModuleTabUtility.php`, `BackendModuleLinkUtility.php`, `Configuration/Backend/*` | `backend-module` |
| `Classes/Controller/Backend/WizardController.php`, `Classes/Service/Wizard*.php`, `Classes/Settings/*`, `Classes/Service/SiteExtensionSettingsResolver.php`, `SiteStorage*.php`, `ExtensionExtConfCategoryService.php`, `Configuration/ExtensionSettings/*` | `wizard-settings` |
| `Classes/Prompt/*`, `Classes/Service/AiPromptsService.php` | `ai-context`, `child-integration` |
| `Classes/Registry/*`, `Classes/Contract/*`, `Classes/DependencyInjection/*`, `Classes/Feature/*` | `child-integration` + child `smoke` |
| `Classes/Utility/LicenseUtility.php` | `backend-module`, `credits`, `access-roles` (license rows) |
| `Classes/Service/Scheduler*.php` | `backend-module`, `ai-agent` (scheduler hand-off) |
| `Classes/Updates/*` | `wizard-settings`, `providers` (upgrade path) on **both** 13.4 and 14.3 |
| `Configuration/TCA/*`, `ext_tables.sql` (package root) | Feature owning the table + `smoke` |
| `Configuration/Sets/*`, `Configuration/TypoScript/*` | `ai-label` (frontend output) + `smoke` |
| `Resources/Private/Language/*` | `smoke` only (label check) |
| `Documentation/*`, `context/*`, `qa/*` | None |

---

## ns_t3af → child edges

Foundation changes can break children with no child file touched. Run these child cases too.

| ns_t3af path | ns_t3ai cases (`../ns_t3ai/qa/cases/`) | Other children |
|---|---|---|
| `Classes/Api/*` | `ai-gateways`, then T3AI `smoke` | ns_t3cs / ns_t3aa `smoke` |
| `Classes/Credits/*` | `ai-gateways` (credits rows), `image-media`, `translation` | Credits rows in each child |
| `Classes/Provider/*` | `ai-gateways`, `translation`, `image-media` | ns_t3cs embeddings |
| `Classes/Mcp/*` | `mcp-tools` (all) | Child MCP tools |
| `Classes/Access/*`, `Classes/Governance/*` | `dashboard-access` + permission rows | Child access catalogs |
| `Classes/Settings/*` | `ai-gateways`, `dashboard-access` | Child settings scopes |
| `Classes/AiLabel/*` | `page-blog`, `content-news`, `image-media` | ns_t3aa (file metadata) |
| `Classes/Prompt/*`, AI Context | `prompts`, `rte` | — |
| Logging / `RequestLog` | `logs` | — |
