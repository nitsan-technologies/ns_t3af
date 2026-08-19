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

Schema columns for extra tables are added by ``ApplicableTableSchemaListener``
as ``CREATE TABLE`` fragments. After changing extra tables, run
**Maintenance → Analyze Database Structure** so the columns exist.

Bind after save (recommended)
-----------------------------

Use ``AiLabelBindHelper`` at the point where your extension knows the final
record uid (after DataHandler or repository save):

..  code-block:: php

    use NITSAN\NsT3AF\AiLabel\Service\AiLabelBindHelper;

    // After DataHandler / repository save — pass your extension key as $source
    AiLabelBindHelper::bindContentRecord($uid, 'my_extension');
    AiLabelBindHelper::bindPageRecord($uid, 'my_extension');
    AiLabelBindHelper::bindFileMetadata($metaUid, 'my_extension');
    AiLabelBindHelper::bindRecord('tx_news_domain_model_news', $uid, 'my_extension');

Every child bind stores involvement ``ai_generated``. Editors may change that
later in the AI Label module. Visitor badges still require confirmation.

Parameters:

* ``$uid`` — live record uid (file metadata uid for ``bindFileMetadata``)
* ``$source`` — short extension identifier stored as recording source (use your
  extension key, for example ``my_extension``)
* ``$altTextOnly`` (``bindFileMetadata`` only) — skip bind when only alt text
  was updated

If no capture correlation id is in the current request (for example a follow-up
HTTP request after async file processing), the helper writes origin with
``recordOrigin()`` instead of ``bindGeneration()``.

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

Settings tab drives auto-confirm via ``AutoConfirmSettingsService``:

* **Confirm when OUR extension recorded it** — ``ns_t3ai``, ``ns_t3aa``,
  ``ns_t3af`` for media, text, or both.
* **Confirm when DETECTED on upload** — recording source ``detected_upload``
  (IPTC Digital Source Type already signals AI).
* **Hold** — public-interest text stays manual (also EXTCONF
  ``ailabelHoldList``).

EXTCONF ``ailabelAutoConfirmSources`` remains an extra allow-list (used even
when the Settings own-toggle is off). Auto-confirm never fills the responsible
person field.

Check ``AutoConfirmSettingsServiceTest`` and ``AiLabelRuleMatrixTest`` before
relying on auto-confirm in production.

Module settings consumed on the frontend
----------------------------------------

``AiLabelSettingsService`` drives visitor badge appearance:

* ``labelSize``, ``labelWording`` — passed to ``FrontendLabelRenderer``
* ``markImageFile === overlay`` — enables image partial wrapper and overlay badge
* ``markImageFile === written_in`` — stamps processed image copies (ImageMagick)
* ``labelPosition`` — overlay CSS class and processed-file stamp corner
* ``machineReadable`` — ``iptc`` / ``iptc_jsonld`` / ``off``
* ``labelUnknownOrigin`` — visitor badge for confirmed unknown-origin media
* ``secondInfoLayer`` — expandable ``<details>`` on the badge

Deep links
----------

File list module: ``ProcessFileListActionsListener`` adds **AI Label** link to
the media tab with folder pre-selected.

Maintainer references
---------------------

* Agent summary: ``context/features/ai-label.md``
* Deep spec: ``context/specs/FEATURE_AiLabel.md``
* Unit tests: ``Tests/Unit/AiLabel/``

Run tests:

..  code-block:: bash

    cd packages/ns_t3af
    composer test -- --filter AiLabel
