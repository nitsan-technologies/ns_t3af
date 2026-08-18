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
  blind spots). Export HTML report from here.
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

* Label position, size, and wording on the frontend
* Whether to mark image files at content-element level only
* Machine-readable metadata (IPTC) preservation mode
* Auto-confirm rules (own generations, detected uploads, hold list)
* Applicable extension tables (comma-separated)
* Per-folder defaults (media)

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

* **CSV** — all labelled records with reason codes and confirmation metadata
* **HTML** — formatted report attachment

Use for internal audits; reason codes are machine-readable (for example
``rule_default``, ``editorial_control``, ``unreviewed``).

How records get AI metadata
---------------------------

1. **AI Foundation capture** — every provider response stores a generation
   correlation id.
2. **Bind on save** — connected extensions (for example **AI Assistant /
   ns_t3ai**) attach that generation to the saved page, content element, or
   file when content is persisted.
3. **Upload detection** — optional signals on file import (suggestions only until
   a person confirms).
4. **Manual** — editors set fields in the record drawer or TCA **AI Label** tab
   on pages, content, and file metadata.

**ns_t3ai** content, page, and media generation is covered via automatic bind.

**ns_t3al** (dedicated translation extension) is a separate product; translation
workflows there are not yet wired into AI Label.

Frontend labels
---------------

Visitor badges render on **content elements** after the TypoScript in
``EXT:ns_t3af/Configuration/TypoScript/setup.typoscript`` is included.

**Site Set (TYPO3 v13.4+):** add ``nitsan/ns-t3af-label`` to your own set's
``dependencies`` **and** ``@import`` that setup file. A dependency listing
alone does not load TypoScript.

**Classic templates:** include static “AI Foundation labels”
(:guilabel:`Web > Template`).

When rules pass and a record is **confirmed**, visitors may see:

* EU AI label icon (bundled SVG set)
* Short text (for example “AI generated”, “AI modified”)

Media and text follow **different rules** — media labels are not suppressed by
a named human reviewer; text labels require public interest and review rules.

Custom templates can use ``<ail:label record="{data}" />``,
``<ail:label file="{image}" />``, ``<ail:recordState>``, ``<ail:fileState>``,
or the ``nst3af-label`` DataProcessor.

Fluid Styled Content image rendering is overridden so a confirmed
AI-generated **file** shows the badge on the image itself
(``Partials/FluidStyledContent/Media/Rendering/Image.html``).

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
