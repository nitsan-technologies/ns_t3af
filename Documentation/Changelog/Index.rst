.. include:: ../Includes.txt


.. _changelog:
.. _changelog-1-0-0:

=========
Changelog
=========

All notable changes to **AI Foundation** (``EXT:ns_t3af``) are documented here.

The format follows Keep a Changelog conventions. The project adheres to
`Semantic Versioning <https://semver.org/spec/v2.0.0.html>`__.

Releases are listed in reverse chronological order (latest first).

Version 1.0.0 (2026-07-14)
==========================

Initial stable release of AI Foundation for TYPO3: shared AI provider
management, public AI service API, backend module, governance and access
controls, T3Planet Credits client, MCP server tooling, and related
documentation.

New Features
------------

* Shared AI Foundation platform for centralized provider, prompt, MCP, and
  governance management
* Top-level **AI Foundation** backend module for setup, configuration, and
  day-to-day operations
* Multi-provider AI runtime with streaming, embeddings, encrypted API key
  storage, and model discovery
* **AI Context** (brand context profiles) with per-site brand voice, personas,
  and runtime prompt injection
* Centralized **AI Prompt** management across the AI suite
* **MCP Server** integration with OAuth 2.1, Streamable HTTP transport,
  multiple connection methods, and an MCP Tools tab
* **AI Access & Roles** wizard with a permission matrix
* **Quick Setup** wizard with guided configuration steps and a setup checklist
* ``AiServiceInterface`` and a Developers module tab for reusable integration
  guidance
* AI usage logging, analytics dashboards, request telemetry, and administrator
  alerts for invalid API keys and quota limits
* Public AI service API and T3Planet Credits client

Improvements
------------

* TYPO3 v12 / v13 / v14 compatibility with PHP 8.2+

For the dated entry list of this release, see
:ref:`Release Notes 1.0.0 <release-notes-1-0-0>`.
