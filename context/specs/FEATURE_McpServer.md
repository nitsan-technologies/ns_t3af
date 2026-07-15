> **Agent entry:** `context/features/mcp-server.md`

# Feature — MCP Server (Feature 3)

Status: **Implemented (v1 + dynamic tools + MCP Tools tab)**
Owner: ns_t3af maintainers
Target version: ns_t3af 1.x (shipped with MCP v1)

## Goals

Expose a TYPO3-native MCP server (OAuth 2.1 + PKCE, Streamable HTTP, stdio CLI, mcp-remote URL tokens) inside `ns_t3af`, with backend configuration UI in the AI Foundation **MCP Server** / **MCP Tools** module tabs.

## Locked decisions

| # | Decision |
|---|---|
| 1 | Full port into `ns_t3af`; conflicts with `marekskopal/typo3-mcp-server` and `hn/typo3-mcp-server` |
| 2 | SDK: `mcp/sdk ^0.5` |
| 3 | Transports: HTTP/OAuth, `/mcp/r/{token}` URL bridge, stdio `nst3af:mcp:serve` |
| 4 | Per-token `workspace_id` (default live); `typo3/cms-workspaces` hard dependency |
| 5 | Core tools: `table_schema`, `pages_get`, `content_list`, `write_table`; dynamic per-table tools (9 each); child ext tools via `mcp.tool` |
| 6 | Advanced settings in collapsible disclosure; stored via `ExtensionConfiguration` |

## Tables

- `tx_nst3af_oauth_client`
- `tx_nst3af_oauth_token`
- `tx_nst3af_oauth_code`
- `tx_nst3af_mcp_session`
- `tx_nst3af_oauth_rate_limit`

## Verification

See implementation plan §Verification (16 steps).

## Custom MCP tools (any extension)

Implement `NITSAN\NsT3AF\Mcp\Contract\McpToolHandlerInterface` (or `#[AsMcpTool]`) in your extension, annotate `execute()` with `#[McpTool]`, and register the class in your `Configuration/Services.yaml`. `McpCapabilityCompilerPass` tags it `mcp.tool` globally — no ns_t3af edits required.

Documentation: `Documentation/Developer/CustomMcpTools.rst`, `Documentation/McpServer/`.
