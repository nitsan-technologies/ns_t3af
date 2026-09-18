# AI Agent routing

Structure in PHP; meaning in the LLM. No NL keyword/regex workflows.

## Paths

| Input | Handler | Notes |
|---|---|---|
| `/tool_name …`, UI tool chips, `@table:uid` | `AgentTurnRouter` → `AgentToolTurnProcessor` | Structural only |
| Free-text NL | `AgentTurnOrchestrator` | Shortlist + tool-calling |

## Shortlist

`AgentToolShortlistService` ranks permitted tools via embeddings (`symfony/ai-store` + TYPO3 cache), then category/module fallbacks.

`routingSource` values: `embeddings` | `category_pick` | `module_default`.

Warm the index: `vendor/bin/typo3 t3af:agent:index-tools`.

## Router contract

- Slash / starters emit MCP tool names (`t3ai_generate_all_seo`, …), not legacy action ids.
- DualMode Write + Previewable → preview path (see `PreviewApply.md`).
- DualMode Read → native execute, no suggestions card.
- DualMode Write without Previewable stays agent-hidden (MCP still visible).

## Related

- Preview → apply: `Documentation/Agent/PreviewApply.md`
- Eval fixtures: `Documentation/Agent/Eval.md`
- Verify suite: `Documentation/Agent/VerifySuite.md`
