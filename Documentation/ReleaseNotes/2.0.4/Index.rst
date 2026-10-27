.. include:: ../../Includes.txt

.. _release-notes-2-0-4:

======================
2.0.4  27 October 2026
======================

Feature release on the 2.0 line. Symfony AI runtime moves from 0.13 to
0.14, and MCP moves to mcp/sdk ^0.9, with AI Agent, Credits, and MCP
hardening.

Here is the list of features and updates introduced in this release:

.. code-block:: none

   27-10-2026 [FEATURE] Symfony AI 0.14: platform, store, agent, and bundled bridges require 0.14 (agent ~0.14.0, platform 0.14.1)
   27-10-2026 [FEATURE] MCP SDK 0.9: raise mcp/sdk to ^0.9 and align MCP transport, CORS, and docs
   27-10-2026 [FEATURE] Optional per-call request timeout on individual AI provider calls
   27-10-2026 [TASK] Document Credits buy, claim-code, and AI Providers usage flows
   27-10-2026 [BUGFIX] AI Agent alt-text: write every named image, continue after Keep, approve-all via the approve tool, list missing-alt instead of deleting
   27-10-2026 [BUGFIX] AI Agent: treat accessibility check/summarize as reads, confirm before scans, stop mistaking file or page UIDs for content elements
   27-10-2026 [BUGFIX] AI Agent: retry empty turns, skip Create Text progress for chat-only replies, keyword fallback when vector tool search is empty
   27-10-2026 [BUGFIX] Credits: validate API payloads, roll back failed claims, align trial pool totals with lifetime usage
   27-10-2026 [BUGFIX] Providers: only allow models the installed Symfony catalog can run; Test connection checks saved chat and embedding models
   27-10-2026 [BUGFIX] Log token usage for streamed answers from OpenAI-compatible and Symfony AI bridges
   27-10-2026 [BUGFIX] Defer AI Agent tool-index rebuild until after provider save
   27-10-2026 [BUGFIX] MCP writes: validate records_apply/colPos/file refs, sync FAL counters, refuse invalid workspace/stage choices, slim search/read schemas
   27-10-2026 [BUGFIX] MCP uploads and Connect: short UTF-8 text uploads, safer OAuth redirects, portable schemas, DDEV CLI snippets, clearer connect guides
   27-10-2026 [BUGFIX] MCP: mask unexpected provider errors; keep missing-record, session, and usage failures clear
   27-10-2026 [TASK] MCP initialize serverInfo.version reports 2.0.4
   27-10-2026 [RELEASE] Feature release version 2.0.4
