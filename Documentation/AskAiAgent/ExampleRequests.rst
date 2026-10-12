.. include:: ../Includes.txt


.. _ask-ai-agent-examples:

================
Example requests
================

The following requests use only tools that ship with AI Foundation. Open the
AI agent on the page you are editing; "this page" means the page shown in the
context line.

.. list-table::
   :header-rows: 1
   :widths: 45 25 30

   *  - Request
      - Tool
      - Result
   *  - ``What can you do on this page?``
      - ``explain_capabilities``
      - Lists the actions available to you on the current page.
   *  - ``Show me the details of this page.``
      - ``pages_get``
      - Shows the properties of the current page.
   *  - ``List the content elements on this page.``
      - ``content_list``
      - Lists the content elements of the page.
   *  - ``Find all pages with "contact" in the title.``
      - ``pages_search``
      - Lists matching pages with their IDs.
   *  - ``Show the page tree below this page.``
      - ``pages_tree``
      - Shows the subpages as a tree.
   *  - ``Add a text element with a short welcome text to this page.``
      - ``write_table``
      - Prepares a change card. Nothing is saved until you click
        :guilabel:`Apply`.

Two input helpers let you be more precise:

*  Type ``/`` to pick a tool directly, for example ``/pages_tree``.
*  Type ``@`` to point at a record, for example ``@pages:12`` or
   ``@tt_content:198``.

Both helpers also work when the AI provider cannot call tools.
