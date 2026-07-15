# Deep implementation specs

Long-form feature specifications (~400–900 lines). **Do not load by default** — use the matching `context/features/<area>.md` summary first.

| Spec | Agent entry |
|---|---|
| `FEATURE_AiProviderManagement.md` | `context/features/providers.md` |
| `FEATURE_McpServer.md` | `context/features/mcp-server.md` |
| `FEATURE_T3PlanetCredits_Client.md` | `context/features/credits.md` |
| `FEATURE_T3PlanetCredits_Server.md` | `context/features/credits.md` (external server) |
| `FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md` | `context/features/credits.md` |
| `FEATURE_T3PlanetCreditsPlan.md` | `context/features/credits.md` (superseded) |

Implementation plan (AI Context, implemented): `Documentation/Architecture/AiContextImplementationPlan.md` → agent entry `context/features/ai-context.md`.

AI Permissions (implemented): `context/features/ai-access-roles.md`; UI prototype reference `AI Permissions — Feature Documentation.md` (monorepo root).

Load a spec only when implementing or debugging that feature in depth.
