.. include:: ../Includes.txt


.. _ask-ai-agent-how:

============
How it works
============

The AI agent works on **the page you are editing**. When you open it, it
takes that page as its context. It reads the page and its content when
needed, and every change it prepares applies to that page. Every request
follows the same four steps. The AI agent never skips step 3 for a change.

.. figure:: ../Images/ask-ai-agent-be-result.png
   :alt: AI agent window with the conversation list, the context line, a request, completed read steps, the Changes applied card with Undo, suggestions, and the message field highlighted
   :class: with-border with-shadow
   :zoom: lightbox

   A completed request in the AI agent window.

The numbers in the screenshot mark:

#. :guilabel:`Conversations`, filtered by :guilabel:`This page` or
   :guilabel:`All pages`.
#. The **context line**: the page the AI agent works on, with its site,
   language, and workspace.
#. Your request.
#. Read steps the AI agent ran, each marked :guilabel:`completed`.
#. The applied change with :guilabel:`Open page`, :guilabel:`Edit`,
   :guilabel:`View on website`, and :guilabel:`Undo`.
#. :guilabel:`What I can do`: suggested next requests for this page.
#. The **message field** and the AI provider used for the conversation.
   Choose the provider in the dropdown below the message field (for example
   :guilabel:`Default: OpenAI`) before you send the first message. See
   :ref:`ask-ai-agent-where-provider`.

The four steps of every request:

#. **Open on a page** — Select the page you are editing, then click
   :guilabel:`Ask AI Agent`. The context line shows that page with its
   language and workspace. "This page" in your request always means the page
   shown there.
#. **Ask** — Type your request and press :kbd:`Enter`. The AI agent selects
   the tools it needs. **Read tools** (for example ``pages_get``) run
   immediately and return an answer.
#. **Review and approve** — If the request needs a change, the AI agent
   prepares a **change card** for the page, marked :guilabel:`Preview — nothing changed yet`.
   Click :guilabel:`Apply` to write it, or :guilabel:`Cancel` to discard it.
#. **Check the result** — After :guilabel:`Apply`, the card shows
   :guilabel:`Changes applied` with links to the record and an
   :guilabel:`Undo` button.

.. important::

   The AI agent works with **your** backend permissions. It cannot read or
   change anything that your backend account cannot access.
