.. include:: ../../Includes.txt

.. _release-notes-1-2-2:

========================
1.2.2  15 September 2026
========================

Here is the list of features and updates introduced in this release:

.. code-block:: none

   15-09-2026 [BUGFIX] Prevent excessive temporary image generation during frontend requests
   15-09-2026 [RELEASE] Release patch version 1.2.2

Upgrade notes
=============

Sites already hit by the disk-full issue should purge leftover FAL temps after
deploying this patch, for example:

.. code-block:: bash

   find var/transient -type f -name 'fal-tempfile-*' -delete
   # or: public/typo3temp/var/transient depending on TYPO3 layout

Then clear processed files / page cache if you want FE to rebuild derivatives
cleanly. The listener no longer creates a writable temp unless IPTC restore or
baked stamp work is required.
