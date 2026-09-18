# AI Agent preview → apply

DualMode write tools that implement `McpPreviewableToolInterface` never blind-apply native generation.

## Flow

1. **Preview (native mode override)** — `McpPlaygroundService::preview()` runs `handler->preview()` under `McpModeOverride::run(native)`. Extension Configuration `mcpMode` is not changed; stack pops in `finally`.
2. **Draft** — `AgentToolTurnProcessor` stores `flow: agent_preview` + `PreviewResult` in `AgentDraftSession`. No DataHandler / no tool `invoke`.
3. **Suggestions card** — assistant meta `type: suggestions` with `draftId`, variants, `callCount`, `generationPath`.
4. **Apply (context mode)** — `AgentWriteService::applySuggestions(draftId, selections, edits?)` resolves variant indexes → content params, then `invokeWithMode(..., context)`.

## Selections

- Payload: `fieldKey => variantIndex` (0-based).
- Optional editor overrides: explicit text + `editedByEditor=true`.
- `applyMode=safe` keeps only `AgentLowRiskFieldMatrix` columns (SEO + file metadata aliases included).

## fieldKeys / variants

- Agent schema for `t3ai_generate_all_seo` exposes `fieldKeys` + `variants` (`AgentToolDefinitionMapper`).
- Child tools must put every requested field on every variant (empty string allowed). Incomplete variants skip missing keys on resolve.

## Related

- Routing / shortlist: `Documentation/Agent/Routing.md`
- Low-risk matrix tests: `Tests/Unit/Agent/AgentLowRiskFieldMatrixTest.php`
