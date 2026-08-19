.. include:: ../../Includes.txt

.. _ai-label:

========
AI Label
========

Purpose
-------

**AI Label** helps your team **record, confirm, and disclose** AI-generated or
AI-modified content for **EU AI Act Article 50** workflows.

**Path:** :guilabel:`AI Foundation > AI Label`

AI Foundation provides the **technical tooling** — coverage scores, review
lists, visitor-facing badges, and evidence export. It does **not** guarantee
legal compliance. A high coverage number means internal checks pass, not that
your site is compliant.

Who uses it
-----------

* **Editors** — review AI-touched pages, content elements, and media; confirm
  or adjust involvement; assign a named reviewer where required.
* **Administrators** — configure label appearance, auto-confirm rules, and
  which extension tables participate.
* **Compliance / legal** — export evidence reports with machine-readable reason
  codes per record.

Module layout
-------------

Overview tab
~~~~~~~~~~~~

* **Scoreboard** — four KPI cards: Confirmed, Awaiting review, Labelled in
  frontend, Not labelled.
* **AI Label coverage** — checklist score with collapsible details (what counts,
  blind spots). Export CSV report from here.
* **Media / Texts cards** — domain progress rings and quick links.
* **Where records came from** — counts by recording source (AI Universe, upload
  detection, editor, other extensions).
* **System status** — EU icon pack, ImageMagick/EXIF, scheduled audit task.

Media tab
~~~~~~~~~

* **Folder tree** (fileadmin) — browse FAL folders; badge shows open items.
* **Filters** — search, involvement, label state, file type, confirmed-by, dates,
  recording source, reason code.
* **Table** — one row per file metadata record.
* **Row actions:**

  * **Edit (open icon)** — slide-in drawer to edit AI Label fields and preview
    visitor label.
  * **Confirm** — mark as confirmed by the current backend user (this content
    version only).
  * **Clear** — remove confirmation (undo snapshot kept for 10 minutes via bulk
    undo when applicable).

Texts tab
~~~~~~~~~

Same filter/action pattern for **pages**, **content elements**, and optional
extension tables configured in Settings.

Highlights **“reviewed, nobody named”** rows — a human-review tick without a
responsible person name documents nothing; use bulk **Assign responsible person**
or the drawer.

Settings tab
~~~~~~~~~~~~

Settings are saved in **AI Label → Settings** and stored in
``tx_nst3af_extension_setting`` (extension key ``ns_t3af``). Defaults live in
``Configuration/ExtensionSettings/fields.typoscript``.

Frontend label appearance
^^^^^^^^^^^^^^^^^^^^^^^^^

..  list-table::
   :header-rows: 1
   :widths: 22 28 50

   * - Setting
     - Options
     - Effect
   * - **Position on media**
     - Bottom right (default), bottom left, top right, top left
     - **Frontend (image overlay only).** Positions the badge inside the image
       wrapper when **Mark the image file** is set to **Overlay on the image**.
       Does not move text badges on content elements — those stay inline after
       the element body.
   * - **Size**
     - Medium (default), small, large
     - **Frontend.** CSS classes ``nst3af-ailabel--size-*``; icon height 32px
       (medium), 24px (small), 48px (large). Applies to content-element badges
       and image overlays.
   * - **Wording beside the icon**
     - Show in site language (default), icon only
     - **Frontend.** **Icon only** hides the visible text span but keeps
       ``alt`` / ``aria-label`` for accessibility. **Show in site language**
       displays the short label next to the EU icon in the current site
       language (``locallang.xlf`` overlays, for example ``de.locallang.xlf``).
   * - **Mark the image file itself**
     - Content element only (default), overlay on the image, written into the
       image file
     - **Frontend (partial).** **Content element only** — badge after the
       content element only (no overlay on ``<img>``). **Overlay on the image**
       — badge on each confirmed AI-generated file in Fluid Styled Content
       image rendering. **Written into the image file** — stamps the EU icon
       onto **processed** image copies when ImageMagick is available. Originals
       are not rewritten. Without ImageMagick, use overlay instead.

Machine-readable marking and rules
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

..  list-table::
   :header-rows: 1
   :widths: 22 28 50

   * - Setting
     - Options
     - Effect
   * - **Machine-readable marking**
     - IPTC digital source type (default), IPTC plus JSON-LD, off
     - **Frontend / files.** **IPTC** writes Digital Source Type
       (``trainedAlgorithmicMedia``) onto confirmed media when ImageMagick is
       available. **IPTC plus JSON-LD** also injects page JSON-LD when text
       rules show a label. **Off** skips both.
   * - **Label media of unknown origin**
     - No (default), yes
     - **Frontend.** When yes, confirmed media with involvement
       ``origin_unknown`` shows a visitor badge (reason ``unknown_origin``).
   * - **Second information layer**
     - Off (default), on
     - **Frontend.** When on, the badge is wrapped in ``<details>`` with a
       second line (involvement + reason code).
   * - **Also record on these tables**
     - Comma-separated table names (empty by default)
     - **Backend.** Extra tables are merged with the defaults ``pages``,
       ``tt_content`` and ``sys_file_metadata`` (they are never replaced). After
       save, run **Maintenance → Analyze Database Structure** so the AI Label
       columns exist on those extra tables.

Automatic confirmation
^^^^^^^^^^^^^^^^^^^^^^

..  list-table::
   :header-rows: 1
   :widths: 22 28 50

   * - Setting
     - Options
     - Effect
   * - **Confirm when OUR extension recorded it**
     - Off (default), on for media, on for text, on for media and text
     - **Backend.** Auto-confirms bind from ``ns_t3ai`` / ``ns_t3aa`` /
       ``ns_t3af`` for the chosen domain (media, text, or both). EXTCONF
       ``ailabelAutoConfirmSources`` is still an extra allow-list.
   * - **Confirm when DETECTED on upload**
     - Off (default), on
     - **Backend.** When on, recording source ``detected_upload`` (files whose
       IPTC Digital Source Type already signals AI) is auto-confirmed. Hold
       rules still apply.
   * - **When auto-confirm is on, still hold for a person**
     - Public-interest texts (default)
     - **Backend.** Settings ``autoConfirmHold`` plus EXTCONF
       ``ailabelHoldList`` / ``AutoConfirmSettingsService`` block auto-confirm
       for public-interest text even when a source is allow-listed.
   * - **Automatically record human review and responsible person**
     - (disabled checkbox)
     - **Never available** by design — responsible person stays manual.

Per-folder media defaults are configured via EXTCONF ``ailabelFolderDefaults``
(not on the Settings form).

Record drawer fields
--------------------

Each edit drawer shows:

* **AI involvement** — not reviewed, no AI, AI generated, AI modified, origin
  unknown, suggestion
* **Created on** (read-only) — content creation date vs legal cutoff
* **Matter of public interest** — text records only; editor decision
* **Reviewed by a named person** — required when human review applies
* **Labelling** — automatic / always / do not label
* **Reason for not labelling** — exemption vocabulary (compliance-controlled)
* **Detected on upload** (read-only) — detection hints for media
* **What a visitor will see** — live preview of label decision

Saving the drawer updates the record; it does not auto-confirm unless you also
click **Confirm**.

Bulk actions
------------

Select rows with checkboxes, then:

* Confirm
* No AI involved
* Do not label
* Set public interest
* Assign responsible person
* Clear confirmation
* Undo last bulk action (10-minute window)

Evidence export
---------------

From the action bar or Overview coverage card:

* **CSV** — labelled records with reason codes and confirmation metadata

Use for internal audits; reason codes are machine-readable (for example
``rule_default``, ``editorial_control``, ``unreviewed``).

How records get AI metadata
---------------------------

1. **AI Foundation capture** — every provider response stores a generation
   correlation id.
2. **Bind on save** — integrating extensions call ``AiLabelBindHelper`` (or
   ``AiLabelRecorderInterface``) when a page, content element, file, or custom
   table row is persisted after AI generation.
3. **Upload detection** — optional signals on file import (suggestions only until
   a person confirms).
4. **Manual** — editors set fields in the record drawer or TCA **AI Label** tab
   on pages, content, and file metadata.

See :ref:`AI Label integration <ai-label-integration>` for third-party bind
patterns.

Frontend labels
---------------

Visitor badges render after the TypoScript in
``EXT:ns_t3af/Configuration/TypoScript/setup.typoscript`` is included.

**Site Set (TYPO3 v13.4+):** add ``nitsan/ns-t3af-label`` to your own set's
``dependencies`` **and** ``@import`` that setup file. A dependency listing
alone does not load TypoScript.

**Classic templates:** include static “AI Foundation labels”
(:guilabel:`Web > Template`).

When rules pass and a record is **confirmed**, visitors may see:

* EU AI label icon (bundled SVG set)
* Short text (for example “AI generated”, “AI modified”) — unless **Wording**
  is **Icon only** in Settings
* Size and (for image overlays) position from Settings — see tables above

**Two badge locations:**

* **Content element** — drop-in partial after the element body (always when
  labelling rules pass for text/media on that CE).
* **Image overlay** — only when **Mark the image file** is **Overlay on the
  image** and the file is confirmed AI-generated; position applies here.

Media and text follow **different rules** — media labels are not suppressed by
a named human reviewer; text labels require public interest and review rules.

Custom templates can use ``<ail:label record="{data}" />``,
``<ail:label file="{image}" />``, ``<ail:recordState>``, ``<ail:fileState>``,
or the ``nst3af-label`` DataProcessor.

Fluid Styled Content ships an image partial override
(``Resources/Private/Partials/FluidStyledContent/Media/Rendering/Image.html``)
that wraps the badge when overlay mode is enabled.

TCA integration
---------------

Editors can also use the **AI Label** tab on:

* Page records
* Content elements
* File metadata

Same fields as the module drawer.

Security & permissions
----------------------

Uses standard backend login and module access. Confirmation always records the
current backend user id. Bulk undo is scoped per user session cache.

Related documentation
---------------------

* Developer integration: :ref:`AI Label integration <ai-label-integration>`
* Maintainer agent context: ``context/features/ai-label.md``
* Deep spec: ``context/specs/FEATURE_AiLabel.md``
