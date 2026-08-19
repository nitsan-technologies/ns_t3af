[![Latest Stable Version](https://img.shields.io/badge/Stable-1.1.4-success)](https://extensions.typo3.org/extension/ns_t3af)
[![AI Foundation Github](https://img.shields.io/badge/AI--Foundation-informational?logo=github)](https://github.com/nitsan-technologies/ns_t3af)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14-important.svg?logo=typo3)](https://get.typo3.org/version/14)
[![TYPO3 13](https://img.shields.io/badge/TYPO3-13-important.svg?logo=typo3)](https://get.typo3.org/version/13)
[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-important.svg?logo=typo3)](https://get.typo3.org/version/12)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![core 13](https://github.com/nitsan-technologies/ns_t3af/actions/workflows/core13.yml/badge.svg)](https://github.com/nitsan-technologies/ns_t3af/actions/workflows/core13.yml)
[![core 14](https://github.com/nitsan-technologies/ns_t3af/actions/workflows/core14.yml/badge.svg)](https://github.com/nitsan-technologies/ns_t3af/actions/workflows/core14.yml)

# AI Foundation for TYPO3 Extension `ns_t3af`

[![AI Foundation for TYPO3](Resources/Public/Images/Readme/readme.jpg)](https://t3planet.de/ai-foundation-fur-typo3)

AI Foundation gives TYPO3 CMS a built-in MCP server, AI tools and multi-LLM management to connect Claude, Cursor, n8n and other AI agents while controlling providers, prompts, permissions and budgets from one backend module. Open source and self-hosted on your own infrastructure with your own keys.

## Installation

```bash
composer require nitsan/ns-t3af
```

Activate `ns_t3af` in the TYPO3 backend, then open **AI Foundation** (Admin Tools) and complete Quick Setup.

## Free and open source, with a free licence key

AI Foundation is GPL-2.0-or-later, on TER and public GitHub. No price, no domain limit,
no time limit.

It does need a **free licence key**. That is not a commercial gate: our licence manager
`ns_license` is shared across the whole AI Universe and carries the AI Credits
entitlement, and we have not separated AI Foundation from it. The key costs nothing and
never expires. The check sends your key and your domain, and nothing else: no content,
no prompts, no images, no IP addresses, no telemetry.

Full detail, including where your AI content goes: [LICENSING.md](LICENSING.md).

It includes these features:

* **MCP Server:** TYPO3 becomes a native MCP endpoint. Claude Desktop, Cursor, n8n, Windsurf and VS Code Copilot can read and write TYPO3 content directly without custom middleware. Supports 7 transport methods, OAuth 2.1 with PKCE, IP allowlisting, MCP Gateway/Proxy mode for multi-site agencies, and a Webhook-to-MCP Bridge for external triggers.

* **MCP Tools:** A full catalog of AI-usable tools across content, pages, SEO, translation, media and more. Generic `write_table` / `table_schema` cover any TCA table; richer per-table CRUD tools need Table Discovery (enable) or EXTCONF registration. Includes a live Playground to test tools and a Developer Kit to build your own.

* **Multi-LLM Management:** Configure OpenAI, Anthropic, Gemini, Ollama and any OpenAI-compatible provider. Set priority and failover ordering per extension. Per-extension monthly budget caps with on/off toggles.

* **AI Context:** Multi-profile brand voice engine. Define business identity, tone, target audience, keywords, forbidden words and compliance notes once. Inject into every AI action across all extensions. Supports multiple client profiles for agencies and auto-research from a URL.

* **Role-based Access & Permissions:** Guided wizard over TYPO3 backend usergroups. Per-group credit limits, module access, fine-grained feature permissions, record-level and page-scope restrictions. Safe AI access for junior staff, clients and freelancers.

* **AI Usage & Logs:** Request-level log with model, tokens, cost, duration and user. 30-day rollup summaries by provider, module, model and user. CSV export, 90-day retention. System-level operational log for sync jobs, errors and scheduler events.

* **Bulk Operations:** Multi-select pages from the page tree, choose an AI action (meta tags, content rewrite, translation, alt text, summary), see a live cost estimate, and run across all selected pages in one step.

* **Scheduler & CLI:** Every AI action is a native Symfony Console command and a TYPO3 Scheduler task. Automate nightly meta generation, translation sync, alt-text generation and more.

* **MCP Connectors:** Connect TYPO3's AI features to external services — Notion, GitHub, Slack, PostgreSQL, Google Drive, Linear, Jira and more, each as a one-click configurable connector.

* **AI Prompts:** Centralized library of 63 editable prompt templates across 9 categories and 7+ extensions. Every AI instruction across SEO, content, translation, media and chat is inspectable and customizable.

|                       | URL                                                                 |
|-----------------------|---------------------------------------------------------------------|
| **Repository:**       | https://github.com/nitsan-technologies/ns_t3af                      |
| **Issues:**           | https://github.com/nitsan-technologies/ns_t3af/issues               |
| **Composer:**         | https://packagist.org/packages/nitsan/ns-t3af                       |
| **TER:**              | https://extensions.typo3.org/extension/ns_t3af                      |
| **Product Page:**     | https://t3planet.de/ai-foundation-fur-typo3                         |
| **Documentation:**    | https://docs.t3planet.de/en/latest/                                 |
| **Support:**          | https://t3planet.de/en/support                                      |
| **Community:**        | https://app.slack.com/client/T024TUMLZ/C0BMHK5NGF7                  |
| **Contribution:**     | [CONTRIBUTING.md](CONTRIBUTING.md)                                  |
| **License:**          | [LICENSE](LICENSE) (GPL-2.0-or-later)                               |
| **Licensing:**        | [LICENSING.md](LICENSING.md)                                        |

## Compatibility

| T3AF Version | TYPO3 Compatibility | PHP Version | Support Level                          |
|--------------|---------------------|-------------|----------------------------------------|
| v1.x         | 12.4 - 14.x         | 8.2 - 8.5   | Features, Bugfixes, Security Updates   |

## Compatible Extensions

T3AF is the required foundation for six AI extensions by T3Planet. Install T3AF once and add the extensions your project needs.

| Extension | Description                          | Link                                              |
|-----------|--------------------------------------|---------------------------------------------------|
| **T3AI**  | AI Content Assistant for TYPO3       | https://t3planet.de/t3ai-typo3-erweiterung        |
| **T3AC**  | AI Chatbot for TYPO3                 | https://t3planet.de/t3ac-typo3-erweiterung        |
| **T3AS**  | AI Search for TYPO3                  | https://t3planet.de/t3as-typo3-erweiterung        |
| **T3AA**  | AI Accessibility for TYPO3           | https://t3planet.de/t3aa-typo3-erweiterung        |
| **T3AL**  | AI XLIFF Localisation for TYPO3      | https://t3planet.de/t3al-typo3-erweiterung        |
| **T3AB**  | AI Extension Builder for TYPO3       | https://t3planet.de/t3ab-typo3-erweiterung        |
