.. include:: ../../Includes.txt

.. _ai-label-integration:

=====================
AI Label integration
=====================

Integrate your TYPO3 extension with AI Foundation **AI Label** so AI-generated
content is recorded, reviewable in the backend module, and labelled on the
frontend when rules require it.

Prerequisites
-------------

* AI Foundation (``ns_t3af``) installed
* Your extension persists content on applicable tables (``pages``,
  ``tt_content``, ``sys_file_metadata``, or tables registered in AI Label
  settings)
* AI requests go through AI Foundation (``AiServiceInterface``) so capture
  correlation ids are available

Overview
--------

Three integration points:

1. **Capture** (automatic) — AI Foundation stores each generation in
   ``tx_nst3af_ailabel_generation``.
2. **Bind** (your code) — when you save a record, link the generation to that
   row.
3. **Optional direct origin** — report involvement without a prior capture.

Applicable tables
-----------------

Built-in:

* ``pages``
* ``tt_content``
* ``sys_file_metadata``

Additional tables: add via **AI Label → Settings → Applicable tables**,
``$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelApplicableTables']``,
or listen to ``CollectApplicableTablesEvent``:

..  code-block:: php

    use NITSAN\NsT3AF\AiLabel\Event\CollectApplicableTablesEvent;
    use TYPO3\CMS\Core\Attribute\AsEventListener;

    #[AsEventListener]
    final class RegisterNewsTableForAiLabel
    {
        public function __invoke(CollectApplicableTablesEvent $event): void
        {
            $event->addTable('tx_news_domain_model_news');
        }
    }

Schema columns for extra tables are added by ``ApplicableTableSchemaListener``.

Bind after save (recommended)
-----------------------------

Use ``AiLabelBindHelper`` at the point where your extension knows the final
record uid (after DataHandler or repository save):

..  code-block:: php

    if (class_exists(\NITSAN\NsT3AF\AiLabel\Service\AiLabelBindHelper::class)) {
        \NITSAN\NsT3AF\AiLabel\Service\AiLabelBindHelper::bindContentRecord($uid);
        // or bindPageRecord($uid), bindFileMetadata($uid)
    }

Parameters:

* ``$uid`` — live record uid
* ``Involvement`` (optional) — default ``Involvement::AiGenerated`` for pages and
  content; ``Involvement::Suggestion`` for file metadata from accessibility tools
* ``$source`` (optional) — default ``ns_t3ai`` (pages/content) or ``ns_t3aa`` (files)

If no capture correlation id is in the current request (for example T3AI image
save is a second HTTP request), the helper writes origin with ``recordOrigin()``
instead of ``bindGeneration()``. Visitor badges still require confirmation.

**Reference:** ``packages/ns_t3ai/Classes/Domain/Repository/PageRepository.php``,
``packages/ns_t3ai/Classes/Service/FileService.php``.

File metadata: pass ``$altTextOnly = true`` to skip bind for alt-text-only
updates (accessibility metadata is not treated as generative media).

Direct origin reporting
-------------------------

When capture correlation is unavailable:

..  code-block:: php

    use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
    use NITSAN\NsT3AF\Api\AiLabelRecorderInterface;
    use TYPO3\CMS\Core\Utility\GeneralUtility;

    $recorder = GeneralUtility::makeInstance(AiLabelRecorderInterface::class);
    $recorder->recordOrigin(
        'tt_content',
        $uid,
        Involvement::AiGenerated,
        'my_extension',
        aiSystem: 'gpt-4',
        aiVendor: 'openai',
    );

Public API interface: ``Classes/Api/AiLabelRecorderInterface.php``.

Convenience methods (also reset confirmation):

..  code-block:: php

    $recorder->markGenerated('tt_content', $uid, 'my_extension');
    $recorder->markModified('tt_content', $uid, 'my_extension');
    $recorder->clearInvolvement('tt_content', $uid, 'my_extension');

These throw ``\InvalidArgumentException`` when ``$table`` is not an applicable
table.

Involvement values
------------------

..  list-table::
   :header-rows: 1
   :widths: 30 70

   * - Value
     - Use when
   * - ``not_reviewed``
     - Default; no editor decision
   * - ``no_ai``
     - Editor asserts no AI involvement
   * - ``ai_generated``
     - Content substantially created by AI
   * - ``ai_modified``
     - AI changed existing content
   * - ``origin_unknown``
     - Role of AI unclear
   * - ``suggestion``
     - System detected/suggested; awaiting person

Frontend rendering
------------------

Include the TypoScript that hooks into ``fluid_styled_content`` (visitor badges
on content elements). Listing a Site Set as a dependency does **not** load its
TypoScript automatically — import the file as well.

**Site Set (TYPO3 v13.4+):** add ``nitsan/ns-t3af-label`` to your sitepackage
set's ``dependencies``, **and** import the setup:

..  code-block:: yaml

    dependencies:
      - nitsan/ns-t3af-label

..  code-block:: typoscript

    @import 'EXT:ns_t3af/Configuration/TypoScript/setup.typoscript'

**Classic TypoScript templates:** include static template
“AI Foundation labels”, or ``@import`` the same file.

Fluid badge (namespace ``ail`` is registered by AI Foundation):

..  code-block:: html

    <html xmlns:ail="http://typo3.org/ns/NITSAN/NsT3AF/AiLabel/ViewHelpers"
          data-namespace-typo3-fluid="true">
        <ail:label record="{data}" table="tt_content" />
        <ail:label file="{file}" />
    </html>

Fluid Styled Content ships an image partial override that already calls
``<ail:label file="{file}" />`` on each rendered file.

Assign state without rendering the badge:

..  code-block:: html

    <ail:recordState record="{data}" table="tt_content" as="labelState" />
    <f:if condition="{labelState.showLabel}">…</f:if>

    <ail:fileState file="{image}" as="labelState" />

DataProcessor alias ``nst3af-label`` (variable ``labelState`` by default):

..  code-block:: typoscript

    tt_content {
        dataProcessing {
            1550 = nst3af-label
            1550 {
                as = labelState
            }
        }
    }

Or inject ``FrontendLabelRenderer`` / ``FrontendLabelStateFactory`` in PHP.

Auto-confirm
------------

Administrators can allow auto-confirm for trusted sources via EXTCONF
``ailabelAutoConfirmSources`` (for example ``ns_t3ai``). Hold rules in
``AutoConfirmSettingsService`` may still block public-interest text until manual
review.

Check ``AiLabelRuleMatrixTest`` and ``AutoConfirmSettingsService`` before relying
on auto-confirm in production.

Deep links
----------

File list module: ``ProcessFileListActionsListener`` adds **AI Label** link to
the media tab with folder pre-selected.

Extension coverage matrix
-------------------------

..  list-table::
   :header-rows: 1
   :widths: 25 75

   * - Extension
     - AI Label status
   * - ``ns_t3ai``
     - Binds pages, content, file metadata on save
   * - ``ns_t3al``
     - Not integrated; translation binds planned separately
   * - Custom
     - Use ``AiLabelBindHelper`` or ``AiLabelRecorderInterface``

Maintainer references
---------------------

* Agent summary: ``context/features/ai-label.md``
* Deep spec: ``context/specs/FEATURE_AiLabel.md``
* Unit tests: ``Tests/Unit/AiLabel/``

Run tests:

..  code-block:: bash

    cd packages/ns_t3af
    composer test -- --filter AiLabel
