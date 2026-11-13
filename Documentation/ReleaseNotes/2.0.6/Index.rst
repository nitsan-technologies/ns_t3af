.. include:: ../../Includes.txt


.. _release-notes-2-0-6:

=======================
2.0.6  13 November 2026
=======================

Patch release on the 2.0 line. AI Agent and AI Label QA fixes, Quick Setup
hardening, MCP write safety, provider/Credits dashboard fixes, plus structured
output and CLI CommandIO.

Here is the list of features and updates introduced in this release:

.. code-block:: none

   13-11-2026 [FEATURE] API: structured output via AiOptions::jsonSchema
   13-11-2026 [FEATURE] CLI: CommandIo helper for ns_t3af commands
   13-11-2026 [FEATURE] AI Agent: open panel from Fix with shared context; legacy card names in slash search
   13-11-2026 [FEATURE] AI Features: info-only drawer when scopes are empty
   13-11-2026 [BUGFIX] AI Agent: news create/MM, multi-fix continue, alt/AI Label tools, schema cards
   13-11-2026 [BUGFIX] AI Agent: transport errors, placeholders, scan Fix apply, workspace publish notice
   13-11-2026 [BUGFIX] AI Agent: credits path answers, DeepL fallback warn, openWithContext prefill
   13-11-2026 [BUGFIX] AI Label: coverage ring, audit badge, human-review confirm, stop default wipe of stamps
   13-11-2026 [BUGFIX] Setup: Quick Setup admin-only; warn when providers are disabled
   13-11-2026 [BUGFIX] Access: gate image generation on Media permission; site-scoped basic auth
   13-11-2026 [BUGFIX] MCP: force 301 on slug auto-redirects; harden Advanced settings and Scheduler CLI
   13-11-2026 [BUGFIX] Providers/Credits: switch-back, model validation, dashboard KPIs
   13-11-2026 [BUGFIX] Scheduler: failing banner/Status; keep chat provider off translate tools
   13-11-2026 [TASK] MCP initialize serverInfo.version reports 2.0.6
   13-11-2026 [RELEASE] Patch release version 2.0.6
