# AI Agent conversations

A conversation belongs to the **page and module where it was started** (its home). Opening the agent opens the latest conversation of the configured scope; every conversation stays reachable from the conversation list (☰ in the window header).

## Settings (AI Agent)

| Key | Default | Effect |
|---|---|---|
| `agentConversationScope` | `page` | Which conversation opens automatically: `page` (same module + page), `module` (same module), `user` (latest anywhere; navigating keeps the conversation and shows "Now working on page …") |
| `agentSessionListEnabled` | `1` | Show the conversation list button |
| `agentSessionListDefaultFilter` | `current` | Initial filter: this page / module, or all pages |
| `agentMaxSessionsPerScope` | `20` | Per user and home; the oldest are moved to trash when a new one is stored (0 = unlimited) |
| `agentMaxSessionsPerUser` | `200` | Per user overall (0 = unlimited) |
| `agentHistoryTokenBudget` | `6000` | Tokens (≈ 4 characters each) of earlier messages replayed to the model per turn; older ones are left out with a note |
| `agentConversationRetentionDays` | `90` | Without activity → trash; `t3af:agent:conversations:cleanup` removes trash after 7 days, plus old `tx_nst3af_agent_turn` / `tx_nst3af_agent_demand` rows |

## Behaviour

- A new conversation is stored with the first message (title = first message, 60 characters). "＋" starts a fresh one; the previous one stays in the list.
- The **AI provider** select is fixed after the first answer (`provider_identifier`). It lists the default plus every enabled provider of the site that can call tools and that the editor's groups may use (provider backend groups, AI Permissions allowlist). Credits mode shows only T3Planet Credits.
- Each user message stores where it was written (`meta.context`: page, module). Reopened on another page, the window says where the conversation was started; older messages written elsewhere are captioned and sent to the model with "[written on page …]".
- **The server is the only writer.** Turns are stored by the turn endpoints; Execute, Decline, the first click on a destructive card and Undo update the stored card (found by its draft id) and append the result (`AgentConversationRecorder`). The window sends `sessionUuid` with these actions and never posts its messages. Unsaved choices on a card (kept fields, picked variants, edits) are sent with Execute and stored then.
- **History replay** (`AgentPromptBuilder::buildHistory`): newest messages first until `agentHistoryTokenBudget` is used; one message is cut after 2000 characters. Cards and results are replayed as short notes ("[Prepared change: … — applied]", "[Read the page] …"); errors and thinking are skipped.
- **Summarize conversation** (Σ in the header, shown after 4 new messages): the conversation's provider writes a short summary (`AgentConversationSummarizer`, feature key `agent.conversation_summary`), stored as a message of type `summary`. Later turns replay the latest summary plus the newer messages only.
- Every query is scoped by `be_user_uid`; sessions of other users are never returned (list, open, rename, delete, save).

## Routes

| Route | Purpose |
|---|---|
| `nst3af_agent_conversation` (GET) | Open: `sessionUuid`, `fresh=1`, or the latest of the scope. Returns messages, context, session, list settings, providers |
| `nst3af_agent_sessions` (GET) | List: `filter=current\|all`, `limit`, `offset` |
| `nst3af_agent_session_rename` / `_delete` (POST) | `sessionUuid` (+ `title`); delete is soft |
| `nst3af_agent_turn` / `_stream` (POST) | `sessionUuid`, `fresh`, `provider`; response includes `session` |
| `nst3af_agent_apply_draft` / `_discard_draft` / `_confirm_destructive` / `_undo_change` (POST) | Card actions; with `sessionUuid` the stored conversation is updated |
| `nst3af_agent_summarize` (POST) | `sessionUuid`; appends the summary message and returns it |
| `nst3af_agent_conversation_save` (POST) | Only `disclosureDismissed`; a `messages` payload (older agent.js) is ignored |

## Upgrade

Run the upgrade wizard **"AI Foundation: AI Agent conversations as sessions"** after the database schema update. It gives existing rows a session id and a title and drops the old unique key on (user, module, page).
