# AI Agent routing

Structure in PHP; meaning in the LLM. No NL keyword/regex workflows.

## Paths

| Input | Handler | Notes |
|---|---|---|
| `/tool_name …`, UI tool chips, `@table:uid` | `AgentTurnRouter` → `AgentToolTurnProcessor` | Structural only |
| Free-text NL | `AgentRunner` (Symfony AI Agent) | Core tool set + `find_tools` + tool-calling |

## Tool selection

The model starts every turn with the same small set (`AgentCoreToolSet`), sorted by name so the provider can cache the prompt prefix:

- core: `ask_clarification`, `explain_capabilities`, `pages_get`, `pages_tree`, `pages_search`, `content_get`, `content_list`, `content_search`, `record_search`, `site_languages_list`,
- tools of the current backend module: first those declaring it in `#[McpToolIntent(modules: …)]`, then by category (max. 20),
- tools used in the last messages (max. 4).

Everything else is found with **`find_tools(query)`** (handled inside `T3afToolbox`, not an MCP tool). `AgentToolSearch` ranks the editor's permitted catalog with provider embeddings (`symfony/ai-store` index, when an embedding provider exists) and BM25 over name, editor label, description, intent summary/examples and `searchTerms` from `Configuration/McpToolMetadata.yaml`, merged by reciprocal rank. Found tools are offered from the next model round (`GovernedPlatform` re-reads the toolbox every round).

Keep `examples` / `searchTerms` in **English and German**; the keyword search matches the words they contain.

Before a tool runs, `AgentToolArgumentValidator` checks the arguments against the tool's JSON schema (`opis/json-schema`), after fixing harmless mismatches (`"49"` → 49, object → JSON string for string parameters). Errors go back to the model as the tool result.

Warm the embedding index: `vendor/bin/typo3 t3af:agent:index-tools`. Setting `agentEmbeddingSource`: `auto` | `provider` | `none` (keyword search only). Local transformers models were removed.

## Per-group tool access

AI Permissions → Groups → Limits → **AI Agent tools**: *Read-only agent* and *Blocked tools* (names, `*` wildcard). Strictest across the editor's groups; admins are not restricted. Blocked tools appear as locked (`lockKind: group`) and are never offered or found.

## Router contract

- Slash / starters emit MCP tool names (`t3ai_generate_all_seo`, …), not legacy action ids.
- DualMode Write + Previewable → preview path (see `PreviewApply.md`).
- DualMode Read → native execute, no suggestions card.
- DualMode Write without Previewable stays agent-hidden (MCP still visible).

## Related

- Preview → apply: `Documentation/Agent/PreviewApply.md`
- Eval fixtures: `Documentation/Agent/Eval.md`
- Verify suite: `Documentation/Agent/VerifySuite.md`
