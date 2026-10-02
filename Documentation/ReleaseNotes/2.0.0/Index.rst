.. include:: ../../Includes.txt

.. _release-notes-2-0-0:

========================
2.0.0  30 September 2026
========================

Here is the list of features and updates introduced in this release:

.. code-block:: none

   30-09-2026 [BREAKING] Drop TYPO3 12 support; raise floor to TYPO3 13.4 / 14.3
   30-09-2026 [BREAKING] symfony/ai-agent pinned to ~0.13.0 (experimental, no BC promise)
   30-09-2026 [TASK] Remove TYPO3-12-only dual code paths (StandaloneView Fluid views,
              legacy icon size strings, page-tree v12 component id, TYPO3-11 mailer branch)
   30-09-2026 [RELEASE] Release major version 2.0.0

Upgrade notes
=============

TYPO3 12 is no longer supported. TYPO3 12 is ELTS-only since April 2026, and
this extension's AI Agent feature depends on ``symfony/ai-agent`` (requires
PHP 8.2+ / Symfony 7.3+), which does not fit typical TYPO3-12-era stacks.

- If you run TYPO3 12, stay on the ``1.2.x`` line (``composer require
  "nitsan/ns-t3af:^1.2"``). It keeps receiving bugfixes and security updates.
- If you run TYPO3 13.4 or 14.x, upgrade normally via Composer. No database
  or configuration migration is required — only the supported TYPO3/PHP
  version floor changed.
