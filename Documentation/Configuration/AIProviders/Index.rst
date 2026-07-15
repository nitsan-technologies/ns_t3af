.. include:: ../../Includes.txt

.. _ai-providers:

============
AI Providers
============

Purpose
-------

Connect TYPO3 to AI models. This is the **most important** AI Foundation screen. Without at least one working provider, no AI feature runs.

**Path:** AI Foundation → AI Providers

`AI Foundation Providers Demo <https://app.supademo.com/embed/cmrbo0w7i0d96qmo57ifnabvz?utm_source=link>`__

Add a provider (step by step)
-----------------------------

1. Click **Add provider**
2. Choose vendor (OpenAI, Anthropic, Gemini, Azure, Mistral, DeepSeek, xAI, custom, Ollama)
3. Paste API key (encrypted on save)
4. Select model
5. Click **Test connection**
6. Enable **Default** on exactly one provider

Supported vendors
-----------------

OpenAI, Anthropic (Claude), Google Gemini, Azure OpenAI, Mistral, DeepSeek, xAI, Custom OpenAI-compatible endpoints, and Ollama (local).

Capabilities
------------

Pick a model that supports what you need. **Test connection** validates your choice.

* **Chat** — Text generation
* **Streaming** — Live response display in the backend
* **Embeddings** — Search and similarity features
* **Vision** — Image analysis
* **Tool use** — MCP agent workflows

Multiple providers — when and why
---------------------------------

**Dev and live** — Two provider rows with different API keys per environment.

**Cost saving** — Cheap model as global default; premium model assigned in :ref:`AI Features <ai-features>` for important tasks.

**EU hosting** — Mistral or Azure in an EU region for data residency requirements.

Troubleshooting
---------------

**Test fails** — Check key, model ID, and firewall (outbound HTTPS must be allowed).

**Rate limit** — Wait or upgrade your vendor plan.

**Vision returns empty** — Use a vision-capable model (for example GPT-4o with vision).

**Module works but child extension fails** — Check :ref:`AI Features <ai-features>` for per-task overrides.

Security
--------

* Rotate keys every 90 days
* Use one key per environment (dev, staging, live)
* Restrict access via :ref:`AI Permissions <ai-permissions>`
* Never commit API keys to Git

Where to get API keys
---------------------

.. note::

   * OpenAI: https://platform.openai.com/api-keys
   * Anthropic: https://console.anthropic.com/
   * Google Gemini: https://aistudio.google.com/apikey
   * Mistral: https://console.mistral.ai/
   * Azure OpenAI: https://portal.azure.com/

More links: :ref:`Helpful Links <helpful-links>`
