.. include:: ../../Includes.txt

.. _mcp-file-uploads:

================
MCP File Uploads
================

How AI agents get files into FAL and attach them to records through the AI Foundation MCP server.

Ingest paths
------------

Prefer these in order:

1. **URL upload** — ``file_upload_from_url`` for public ``http(s)`` links (and YouTube/Vimeo online media).
2. **Content upload** — ``file_upload`` with ``content`` for small text assets (SVG, CSV, VTT, plain text).
3. **Pre-signed binary** — ``file_upload_prepare`` → client ``PUT`` → then attach (see below).
4. **Tiny base64** — ``file_upload`` with ``base64Content`` only for very small binaries under ``mcpMaxBase64UploadBytes`` (default 16 KiB). Larger payloads are rejected; use prepare instead.

URL upload
----------

Call ``file_upload_from_url`` with a direct file URL (not an HTML page). The response includes a ``sys_file`` ``uid``, ``publicUrl``, ``sha1``, and a ``nextStep`` hint.

YouTube and Vimeo **page** URLs are stored as online media assets instead of downloading video bytes.

Content upload
--------------

Use ``file_upload`` with ``content`` (and a file name that includes an extension) when the agent authors the bytes inline. Dangerous extensions (``.php``, ``.html``, ``.htaccess``, …) are refused.

Pre-signed upload (chat / desktop binaries)
-------------------------------------------

1. Call ``file_upload_prepare`` (optional ``fileName``, ``directoryPath``, ``storageUid``).
2. Receive ``uploadUrl``, ``uploadToken``, ``validUntil``, and curl-style instructions.
3. ``PUT`` (or ``POST``) the raw file body to ``uploadUrl`` with the token (Bearer or query).
4. Read the JSON ``describeFile`` response (``uid``, ``sha1``, ``nextStep``, …).
5. Attach with ``file_reference_add`` **or** ``write_table`` inline refs.

Example attach after upload::

   file_reference_add(table="tt_content", uid=45, fieldName="image", fileUids="88")

   # or, on create/update:
   write_table(action="create", tableName="tt_content", data='{"pid":1,"header":"Hero","image":[{"uid_local":88,"alternative":"Banner"}]}')

On ``write_table`` **update**, a present file-field array **replaces** existing references for that field; ``[]`` clears them.

Base64 policy
-------------

``base64Content`` through the LLM channel silently truncates or corrupts mid-size binaries. Keep it for tiny fixtures only. Prefer prepare + PUT for anything larger than the configured base64 cap.

Integrity checks
----------------

Optional ``expectedSha1`` / ``expectedSize`` apply to **both** ``content`` and ``base64Content``. They validate the payload the client sends **before** FAL storage.

If TYPO3 rewrites the file while storing it (common for SVG sanitization), the upload still succeeds and the response includes ``rewritten: true``, ``inboundSha1`` / ``inboundSize``, plus the stored ``sha1`` / ``size``. Prefer ``file_upload_prepare`` for raster images (JPG/PNG) so bytes never pass through the model.

Create-only storage and destructive ops
---------------------------------------

Uploads are **create-only**: conflicts rename; identical SHA1 content is reused (dedupe). Deletes, renames, and moves of existing FAL objects are gated by ``mcpAllowDestructiveFileOps`` (default on for backward compatibility — turn off on stricter sites).

Related settings (extension / MCP Advanced): ``mcpMaxFileSizeMb``, ``mcpMaxBase64UploadBytes``, ``mcpUploadTokenTtl``, ``mcpAllowDestructiveFileOps``.
``mcpMaxBodyBytes`` only caps Streamable HTTP MCP JSON bodies — large binaries go through the pre-signed PUT path.

Metadata and public URLs
------------------------

Upload, list, search, and ``file_get_info`` responses include an absolute ``publicUrl`` when the storage can serve the file.

Edit title / alternative / description with ``write_table`` on ``sys_file_metadata`` (lookup the row by ``file`` = ``sys_file`` uid). Example::

   write_table(action="update", tableName="sys_file_metadata", uid=<metadataUid>, data='{"title":"Hero","alternative":"Banner"}')

Discover fields first with ``table_schema`` for ``sys_file_metadata``.

Relations, collections, and SEO file fields
--------------------------------------------

``table_schema`` now surfaces:

* **category / MM select** (e.g. ``categories``, ``authors``, ``tags``) with ``writableAs: uid_list`` — write ``"126"`` or ``"8,12"`` via ``write_table``.
* **file fields** (``og_image``, ``image``, ``assets``, …) with ``writableAs: file_references`` — use ``file_reference_add`` or ``[{"uid_local": N}]``; do not set a bare integer. ``file_reference_add`` syncs the parent counter used by SEO generators.
* **Content Blocks collections** (``type: collection``) — do not write the parent field; create child rows in ``foreignTable`` with ``foreignField`` (often ``foreign_table_parent_uid``). Order with ``pid=<page>`` then ``pid=-<previousChildUid>``.

When ``write_table`` ignores a field, the response includes ``ignoredFieldDetails`` with a concrete ``hint``.

Verification checklist (TC)
---------------------------

* **TC1** — ``file_upload_from_url`` with a public image URL → ``uid`` + absolute ``publicUrl``.
* **TC2** — YouTube/Vimeo page URL → online media asset (no binary download).
* **TC3** — Tiny ``base64Content`` under ``mcpMaxBase64UploadBytes`` succeeds.
* **TC4** — Mid-size base64 (~70 KiB) is rejected with a prepare hint.
* **TC5** — ``file_upload_prepare`` → client ``PUT`` → ``201`` JSON with ``uid`` / ``sha1``.
* **TC6** — Attach via ``file_reference_add`` or ``write_table`` ``[{"uid_local":N}]``.
* **TC7** — Private/reserved host URL is refused (SSRF); sandbox/clients still cannot hit private hosts.
