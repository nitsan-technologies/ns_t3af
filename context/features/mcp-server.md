# Feature — MCP Server & Tools

**Status:** Done  
**Deep spec:** [`FEATURE_McpServer.md`](../specs/FEATURE_McpServer.md)  
**User docs:** `Documentation/McpServer/Index.rst`, `Documentation/McpServer/Tools.rst`, `Documentation/Developer/CustomMcpTools.rst`

---

## What it does

- **Transports:** Streamable HTTP at `{basePath}` (OAuth 2.1 + PKCE), mcp-remote URL tokens (`/mcp/r/{token}`), stdio CLI (`nst3af:mcp:serve` / `vendor/bin/typo3 mcp:server`).
- **OAuth:** client registration, authorization code + PKCE, token revocation, rate limiting.
- **Workspace pinning:** each token has `workspace_id` (default `0` = live); `BackendUserBootstrap` applies it.
- **Core tools:** `table_schema`, `pages_get`, `content_list`, `write_table` (create/update/delete via DataHandler).
- **Dynamic tools:** per discovered extension table — 9 tools each (list, get, CRUD, move, batch) via `NsT3afDynamicToolRegistrar`.
- **Custom tools:** any extension via `McpToolHandlerInterface` + `#[McpTool]` + `mcp.tool` DI tag.
- **Backend:** MCP Server tab (connections, OAuth paths, advanced settings) + MCP Tools tab (extension catalog).
- **Audit:** `Classes/Mcp/Logging/AuditLogger.php` → `sys_log`.
- **Cleanup:** `mcp:cleanup` CLI for expired tokens/sessions.

---

## Key paths

| Area | Path |
|---|---|
| MCP root | `Classes/Mcp/` |
| Server factory | `Classes/Mcp/Server/McpServerFactory.php` |
| HTTP middleware | `Classes/Mcp/Middleware/McpServerMiddleware.php`, `OAuthMiddleware.php` |
| OAuth | `Classes/Mcp/OAuth/` |
| Core tools | `Classes/Mcp/Tool/{Schema,Pages,Content,Record}/` |
| Dynamic registrar | `Classes/Mcp/Tool/Dynamic/NsT3afDynamicToolRegistrar.php` |
| Tool catalog UI | `Classes/Mcp/Service/McpToolsRegistryService.php`, `McpToolsController.php` |
| Backend server UI | `Classes/Mcp/Controller/Backend/McpServerController.php` |
| CLI | `Classes/Mcp/Command/McpServeCommand.php`, `CleanupCommand.php` |
| ext_conf | `ext_conf_template.txt` **MCP Server** category |

---

## Tables

- `tx_nst3af_oauth_client`, `tx_nst3af_oauth_token`, `tx_nst3af_oauth_code`
- `tx_nst3af_mcp_session`, `tx_nst3af_oauth_rate_limit`
- `tx_nst3af_discovered_table` (dynamic tool discovery)

---

## ext_conf keys (Extension Configuration — not provider table)

- `mcpBasePath`, `enableMcpServer`, `requireAuth`
- OAuth lifetimes, rate limits, `oauthDefaultClientId`, redirect URIs

---

## Custom MCP tools (child extensions)

Implement `McpToolHandlerInterface`, annotate with `#[McpTool]`, tag `mcp.tool` + `public: true` in **child** `Services.yaml`.

Examples: `ns_t3ai` tools, `ns_t3aa` `GenerateFileMetadataTool`.

---

## Do / Don't

**Do:** Call `table_schema` before `write_table` or dynamic create/update tools.

**Do:** Use `ErrorHandlingProxy` — tools should not wrap everything in try/catch.

**Don't:** Install `marekskopal/typo3-mcp-server` or `hn/typo3-mcp-server` alongside (Composer conflicts).

---

## Verification

- `Documentation/McpServer/Testing.rst`
- Unit tests: `Tests/Unit/Mcp/`
- Backend → AI Foundation → MCP Server + MCP Tools tabs load without errors
