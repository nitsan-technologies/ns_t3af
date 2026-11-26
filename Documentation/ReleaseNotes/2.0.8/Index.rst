.. include:: ../../Includes.txt


.. _release-notes-2-0-8:

=======================
2.0.8  26 November 2026
=======================

Patch release on the 2.0 line. Provider gateway no longer sends unsupported
sampling parameters to reasoning models (GPT-5, o-series, and similar), with a
one-shot retry when the API rejects temperature or top_p.

Here is the list of features and updates introduced in this release:

.. code-block:: none

   26-11-2026 [BUGFIX] Provider: ModelSamplingCapability omits temperature/top_p for
              reasoning models; retry when the API rejects sampling (OpenAI-compatible,
              Symfony AI, Credits chat, AiService)
   26-11-2026 [TASK] MCP initialize serverInfo.version reports 2.0.8
   26-11-2026 [RELEASE] Minor release version 2.0.8