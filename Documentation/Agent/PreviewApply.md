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

## After apply / decline

- The apply response carries `result.readback[*].recordLabel` + `fieldLabels` and `links` (per record: Open page, Edit, View on website; at most 3 records, only readable tables).
- With `agentContinueAfterConfirm` on, a confirm or decline on a card the runner produced sends one `continuation` turn (`{outcome: applied|declined, label, result}`). The server stores it as a hidden user message and tells the model what happened, so a multi-step request continues without the editor typing "continue". **Execute all** merges the confirmed cards into one continuation.

## What the editor may change

- The agent checks the editor's own rights before it prepares a card: table, page, field (`exclude` fields need the grant) and file. A request that is not allowed is refused up front, with the reason in plain words, and no card is shown.
- A field the editor may not change is left out of the card. If nothing is left, there is no card.
- Every refused, hidden or failed request (including a change that could not be prepared or applied) is written to the AI logs with `success = 0`.
- A new content element gets `colPos` 0 when the plan leaves the column out. A new page can be placed before or after a named page; "after A named U" uses page A.
- The word "header" as a field of a requested element ("a text element with the header X") is not a second Create Header step, whatever the value contains.

## Array parameters

Tool parameters typed `array` get a JSON-schema `items` (or `type: object` for `array<string, …>`) from the `@param` docblock (`AgentToolDefinitionMapper::arrayShape`). OpenAI rejects array parameters without `items`. Put the docblock above the `#[McpTool]` attribute.

## Related

- Routing / tool selection: `Documentation/Agent/Routing.md`
- Low-risk matrix tests: `Tests/Unit/Agent/AgentLowRiskFieldMatrixTest.php`
