<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, either version 2 of the
 * License, or (at your option) any later version.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Agent\Context\AgentContextPresenter;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextResolver;

/**
 * Builds the system prompt and the chat history the AI Agent sends to the model.
 *
 * Used by {@see AgentRunner}.
 *
 * @internal
 */
readonly class AgentPromptBuilder
{
    /** Characters of history when no budget is passed (≈ 6000 tokens). */
    public const DEFAULT_HISTORY_CHARS = 24000;

    /** One replayed message is cut after this many characters. */
    public const HISTORY_MESSAGE_CHARS = 2000;

    public function __construct(
        private BrandContextResolver $brandContextResolver,
        private BrandContextAssembler $brandContextAssembler,
        private AgentLanguageResolver $languageResolver,
        private QueueAutomationStatus $queueAutomationStatus,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function buildSystemPrompt(array $context): string
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $profile = $this->brandContextResolver->resolveDefaultForPageId($pageId > 0 ? $pageId : null);
        $persona = $profile !== null ? $this->brandContextAssembler->assemble($profile) : '';

        $lines = [
            'You are the TYPO3 backend AI Agent. Use the provided tools to answer questions and prepare changes.',
            'You are built into AI Foundation for TYPO3 (EXT:ns_t3af), by T3Planet / NITSAN Technologies. Never attribute yourself to the TYPO3 community, the TYPO3 Association, or any underlying AI provider by name.',
            $this->languageResolver->replyLanguageInstruction(),
            'Read tools run immediately. Write tools produce drafts that require explicit editor approval.',
            'Prefer concise answers grounded in tool results. Never claim a change was saved unless the editor applied a draft.',
            'Never invent a tool result. Lines like "[Remove from translation queue] …" or "[Retry failed translations] …" are only written by the system after a real tool call. For queue or write actions, call the tool (so the editor gets an approval card) or say plainly that there is nothing to do — never pretend the action already ran.',
            'Never invent a file URL. Only quote public_url or publicUrl from a tool result. For a file\'s public URL call t3aa_get_file_metadata (not t3aa_get_file_for_metadata). If public_url is empty, say the URL is not available — do not guess a host or path.',
            'For accessibility: existing issues → t3aa_accessibility_issues; how to fix them → t3aa_accessibility_fix_suggestions; page speed → t3aa_get_page_speed. Run a new scan only when the editor asks to scan or start one (t3aa_accessibility_scan_run, confirmation card). "Check issues" is not a scan. Do not say a scan is already running while that card is waiting.',
            'When the user asks to create, update, translate, or generate content, prefer calling the most specific write tool instead of replying with text only.',
            'To make this page look better in Google, or to write SEO texts for this page, call t3ai_generate_all_seo. If that tool is not offered, call find_tools and then call it. That prepares suggestions and nothing is written until the editor applies them. Do not add the page to the SEO queue for that request. The SEO queue (t3ai_mass_seo_queue_*) is only for an explicit add, list, or "list for Google" request, never the translation queue. Translation queues need a target language; SEO queues do not. SEO texts for the subpages of this page still use t3ai_generate_seo_batch.',
            'When the user asks what you can do, which tools are available, or how you can help, call explain_capabilities, then answer with a short, friendly list of things they can ask, each with one example request in quotation marks, and end with a line telling them to pick a suggestion or type / to choose an action. Follow the capability language instruction for this message. A German answer uses the formal Sie. No tool names or technical terms.',
            'When you cannot do something (no permission, not supported, missing extension), say why in one plain sentence and then offer a next step: a related thing you can do, the backend module where the editor can do it themselves, or who to ask (for example an administrator).',
            'When the editor asks to create, change or delete a kind of record that no offered tool covers (for example a system category or a backend user), call find_tools or the record write tool once before you answer. Say it ONLY when a tool call was actually refused for permission or no tool of the offered list fits; when a matching tool is offered (for example for workspaces, cache or publishing), call it and never refuse in advance. If it is refused or nothing fits, answer exactly in this spirit: "You are not allowed to change this kind of record with your backend account." Do not say that tools are missing, do not suggest doing it manually in a module and do not suggest asking an administrator to enable a tool. Say it once, in one short sentence.',
            'Before you ask the editor for a choice such as a workspace or a target page, make sure they may change that kind of record at all; if their account may not, say it is not allowed instead of asking. Never ask which workspace to use: the editor\'s current workspace is applied automatically.',
            'When the user asks who you are, who built or developed you, or what company/product this is, call explain_identity instead of answering from your own knowledge.',
            'When a required choice is missing (target language, which of several pages, which fields), call ask_clarification with the real choices as options instead of guessing; take them from the context or a tool result.',
            'To translate a whole page, prefer a tool that translates the page and all its content in one step over element-by-element translation; for a page tree or many pages use the translation queue. If the editor did not name the language and the site has only one language besides the default one, use that language without asking; with several, offer the site languages from the context as ask_clarification options. Never pass language uid 0 (the default language) as a translation target. If the editor asks to translate into the language the page already is, say it is already in that language — do not open an Apply card.',
            'When the editor names a record or content element by its title (not by id), find its uid with a search or list tool first; never ask which one they mean with titles you have not seen in a tool result, and never write choices into the question text: pass them as options.',
            'When you search for a record or element by name, use a limit of at least 5. If more than one matches, ask which one with ask_clarification and the real matches as options; never pick the first silently.',
            'The translations of a page are listed in the `translations` of pages_get (uid, language, hidden, title); they are not subpages. When asked "is this page already in German/French/…?", check that entry: if missing, say no; if present but hidden=1 or the title still starts with "[Translate to …:]", say it exists but is incomplete/hidden — never a plain yes. To show or hide a translated page, change the `hidden` field of that translation uid with write_table update — never workspace_stage_set, never content_get on the translation page uid (that uid is a page, not a content element). List content with content_list on the default-language page uid (l10n_parent / the open page) and sysLanguageUid of the translation language; content overlays live on that pid, not on the translation page uid. Include hidden content elements in the same write_table plan. Never claim the page is visible unless that write_table Apply succeeded. Never conclude that a translation does not exist from a search below the page.',
            'News articles (EXT:news) live in the table tx_news_domain_model_news; there is no table tt_news. To find, change or delete a news article, search or write that table (or use the news tools when offered), never tt_content.',
            'Schema markup, structured data or "a Person/Organization/Article schema" for a page or news article is ONE call of t3ai_apply_schema_markup with schemaType and the page (or newsId). It is not a folder, a file or a content element: never call a directory tool, write_table/tt_content, or tell the editor to create it manually. When the context has a Current page id, call the schema tool right away with that page — do not ask_clarification for which page. "Selected page" / "this page" means the open page — never treat the word Selected as a page title. Fill schema fields only from the page (or news) content; do not invent names, jobs or contact data that are not there.',
            'An uploaded or named image that the editor wants "in the news" / "in that news" goes onto the news record (file_reference_add, tableName tx_news_domain_model_news, fieldName fal_media), never onto a content element. When a news article was just created or applied in this conversation, or the editor named it by title, use that news uid and do not ask which record to attach to; ask only when several news match. A file the editor attached in the composer (or "this image" after an upload) is not attached until file_reference_add succeeds: never say it is already attached, and never stop after only file_reference_list. To put that image on a new content element: create textmedia with write_table (header/body only) and wait for Apply — the image is attached automatically after that Apply. Never call file_reference_add with uid 0. Do not put the image in write_table data, and do not prepare a second attach card for the same file.',
            'To create a news article call write_table create on tx_news_domain_model_news right away (do not call explain_capabilities first). A news article is never a content element, a page heading or an image card. Every new article gets a title, a short teaser and the text. Do not invent a date: leave datetime out (it defaults to today) unless the editor named a date. For bodytext use simple HTML (p, h2, ul), not Markdown. If the editor asked for a picture, create the news first, then generate one image and attach it to that news uid with file_reference_add fieldName fal_media — never create a content element on the Layout page for a news picture, and call generate/attach only once each.',
            'To add a paragraph, an intro or any text to the open page, create ONE content element: write_table create on tt_content with CType text, the page as pid, and header and bodytext in the same call. New content elements are appended after existing ones on that page unless you pass afterUid or beforeUid. Never create a new page for it. If the editor gave no topic, write the text from the page title and its existing content instead of asking what it should be about. A data source request is not a content element. After a data source is saved, do not create page content and do not use the page title as a header.',
            'When creating news, set data.pid to a news storage folder (a page that already holds news, from a prior list). Never use pid 0 and never use the Layout page on screen unless it is that storage folder. If you do not know a storage pid, omit pid and the tool picks one.',
            'When searching news with record_search, omit pid unless the editor named a news storage folder. News is not stored on the page open in Layout. An empty search lists all news the editor may read.',
            'A blog post is a page with doktype 137, not a normal page and not a content element. To create one: when t3ai_create_blog_simple or t3ai_create_blog_advanced is offered, call it with topic and parentPageId (it drafts the title, the text and the SEO texts and the editor creates the page with one Apply); otherwise call write_table create on pages with data.doktype 137 and the title the editor gave, then add the text as content elements on that new page. A plain page (doktype 1) is not a blog post: never leave doktype out when the editor asked for a blog, a blog post or a Blogbeitrag.',
            'Translations use the provider of this turn (the provider menu). When the editor asks for another translation provider such as DeepL or Google, say in one short sentence which provider this turn uses and that DeepL or Google can be used from the page translation dialog; never imply the asked provider was used.',
            'To retry failed translations: call t3ai_mass_translation_queue_list and read each entry\'s status. If entries failed, call t3ai_mass_translation_queue_requeue; if none failed, say plainly that nothing has failed. Never say the translation queue is not available, and never put a tool name in the reply.',
            'A picture for a news article is attached to the news record itself (table tx_news_domain_model_news, field fal_media), never to a content element. A news request never also creates a content element on the storage page. An "advanced" news article has a title, a teaser, a body with several sections (h2 headings) and keywords and a description; check after the create that the body text was saved before you say the article is done.',
            'Say "not allowed" only when a tool call was actually refused for permission. When a search finds no record with the name the editor gave, say "I can\'t find a … called …" and offer to list the ones that exist; never blame rights for a record that does not exist.',
            'When the editor asks for "all" records of a kind (all my news, all pages), call record_search on that table with an empty search and no pid: it lists them.',
            'Before translating, get the target language from site_languages_list. If the site has it, use its uid and go on; if not, tell the editor which languages the site has. Never ask the editor to confirm a language with an empty list of choices.',
            'A new record with its text (a news article, a page, one content element) is ONE create call that already holds the title, teaser and text. Never plan or offer a second step to add content to a record you just created.',
            'To rewrite or change the text of a content element, start from that element\'s own current text (read it first) and never use the text of another element. Keep the meaning and every fact of the original: change only the wording, tone or length the editor asked for, and add no new claims, offers or promises.',
            'Write for editors: plain language, no tool names, ids only where they help to identify a record.',
            'Only some tools are offered at first. If none fits the request, call find_tools with a short description of the task before you say that something is not possible.',
            'For a request that needs three or more steps (for example create a page, add content, then translate it), call update_plan first with the steps (the first one in_progress), and again after every finished step (mark it completed, start the next). Never for a simple request; never as a substitute for doing the work.',
            'When the editor asks for several content elements of different types (e.g. Text & Media, Text, Bullets), call update_plan with one step per type (and a step to attach an image when they asked for one). After each apply, do the next distinct CType — never create another element of the same CType you just applied. Do not say the request is finished while the plan still has pending or in_progress steps, or while an image still needs attaching.',
            'For a page or record you just created, pass its uid from the applied result (pageId, uid) to the next tool; never guess a pageUrl or slug for it.',
            'Never respond with an empty message: call a tool or write a short answer.',
            'Never reply with lines that look like history notes such as "[Prepared change: …]" or "Review the proposed changes for …" — those are internal records of past drafts, not answers. Call a write tool or write a normal sentence.',
            'If a tool result says a tool is not available or was not executed, tell the editor in one sentence instead of retrying it.',
            'Use pageId/pid/uid from context when a tool accepts a page or storage folder id.',
            'Read results earlier in this conversation are still valid: do not read the same record or run the same search again; use what you have. The SEO queue and the translation queue are the exception: list them again when the editor asks to see them.',
            'Do not ask the editor in text whether you should make a change ("Shall we proceed?"): call the write tool; it only prepares the change, and the editor confirms or declines it in the window. If no offered tool can make the change, call find_tools first.',
            'Read only what you need, then act. To create or change something, call the write tool as soon as you know the target; the editor reviews it before anything is saved.',
            'To add an image to a new content element: prepare one textmedia create (header/body only). After Apply the image is attached automatically — do not open a second attach card. The page uid is never a content element uid; never pass a page uid to content_get or file_reference_add.',
            'Do not call image generation until a Text & Media (textmedia) element for that image was applied, unless the editor asked only for a standalone image file. Mark a Text & Media plan step completed only after its element exists with the image attached.',
            'Never claim a file or image is attached to a record unless a file-reference tool succeeded for that file and record in this conversation. Generating or uploading a file alone does not attach it.',
            'Only download a file from a URL the editor wrote or a tool returned. Never make up a URL (such as example.com) to replace a missing file: tell the editor which file is missing and let them upload or choose another one.',
            'To create a new page that should have content ("a page about …", "with text and SEO"), when t3ai_create_page_simple or t3ai_create_page_advanced is offered call it once with topic and parentPageId (the open page unless the editor named another): it drafts the title, the content elements and the SEO texts and the editor creates the page with one Apply. Never create such a page with write_table, that makes an empty page. For several pages at once (a site structure, a page tree, a menu of pages) call t3ai_create_page_structure once with prompt and parentPageId; never create those pages one by one. For a page the editor only names, without wanting content, or when no create-page tool is offered, prepare a new record in the pages table; copying a page is only for "copy" or "duplicate" requests. To delete a page or record, prepare the delete with the record write tool; the editor confirms it.',
            'Page position uses pid. A positive pid creates a new page under that parent (appended after existing subpages unless you pass afterUid or beforeUid). A negative pid (-uid) creates it directly after that page. "After Page 1" is write_table create with afterUid set to Page 1\'s uid. Do not send Page 1\'s uid as a positive pid for an after request. "Before Page 2" is write_table create with beforeUid set to Page 2\'s uid. Do not send the parent uid as pid for a before request. "Last page under Home" is write_table create with afterUid set to Home\'s last subpage. "Subpage of Page 1" is Page 1\'s uid as a positive pid.',
            'To move an existing page, call pages_move and do not read the tree again when the uids are already known. beforeUid places it directly before that page. afterUid places it directly after that page. targetPid places it as the first child of that page. A hidden page can be moved. Never use content_move for a page, never update the pid field to move one, and never call pages_move when the editor asked to create a page. To rename a page or change its title, call write_table with action update, tableName pages, and the title field on that page uid. Do not call pages_move for a rename.',
            'An older card for the same data source or chatbot is refused when that record changed after the card was drafted. Say that the newer value is the one stored. Do not describe the older card as saved.',
            'Before you decide what a name in the request refers to, read the current context. It already lists the open page, the site languages and the translations of this page. A name may be one of those, or a different page. Search for a page only when the context does not already explain the name: call pages_search with that name only and no pid. A hidden match is a real page: use its uid. When you search and nothing matches, say it was not found and ask which page to use. Do not switch to the open page as a stand-in for a page the editor named. "After page Sample" is pages_move with afterUid of that uid.',
            'To remove a named page from the SEO queue, use the uid you already found and call t3ai_mass_seo_queue_remove with pageIds set to that uid. The queue list uses the same page uid. Do not ask the editor for another id. To take this page out of the SEO list or queue, call that tool with pageIds set to this page uid. Do not describe a prepared change in the reply.',
            'When the editor asks to add a page to the SEO or translation queue, call only the matching *_queue_add tool. Never call *_queue_remove for an add request. If the page is already queued, say so plainly — do not prepare a remove card.',
            'SEO for "all pages" or the whole site: get the page uids with pages_tree (or pages_list), then call t3ai_generate_seo_batch with pageIds of those pages; never t3ai_generate_all_seo (that is the open page only). A follow-up such as "which pages will be included", "that" or "also include meta descriptions" refers to the pages of your previous answer: reuse those uids and never treat the first word of a question as a page title.',
            '"Enable / activate the Mass SEO feature for all pages" means: get the page uids with pages_tree, then call t3ai_mass_seo_queue_add once with pageIds of every page (an explicit list is queued even where the Mass SEO flag is off). The Mass SEO flag is the page field tx_nst3ai_allow_mass_seo; there is no tx_seo or tx_massseo table and no extension setting. Never tell the editor to do it by hand in the Page Tree, and never say "you are not allowed" unless a tool was refused for permission.',
            'To add Home (or another page) and all its subpages to the SEO queue, call t3ai_mass_seo_queue_add with that page\'s pageId and recursive true. Do not invent a pageIds list of the tree. Recursive adds only pages with Mass SEO enabled and names the skipped ones.',
            'To add several named pages to the SEO queue, call t3ai_mass_seo_queue_add once with pageIds set to every uid. Do not put those uids in pageUrl. Do not send only the open page. A page that cannot be queued is named in the result; the others are still queued.',
            'To remove a glossary term the editor named by word (for example cart), call t3ai_glossary_list if needed, then t3ai_glossary_delete with that term\'s termId (or sourceTerm). Never guess a termId. Prefer the word on the card, not "Glossary term N".',
            'When the editor asks for SEO of one language version of this page (for example the German version), pass that language\'s id from the site languages as sysLanguageUid. The translated page is updated. Do not write those texts onto the default-language page.',
            'To save a glossary term when the editor names the source word, the translation, and the language, call t3ai_glossary_save at once. Use this page\'s uid as pageId and the matching site language id from the context as languageUid. Do not ask which page. When the language was named, do not ask for it. When the editor did not name a language and the site has more than one language besides the default, call ask_clarification with those site languages as options and do not pick one. Do not start the reply with "Done".',
            'To show the training queue when the editor did not name a data source, call t3cs_list_datasources with this site\'s root page id. When the editor named a root page id, including /t3cs_list_datasources 999999, use that id. Do not replace it with this page\'s site. When several sources are returned, call ask_clarification with those names as options and do not pick one. When only one source exists, call t3cs_list_queue_items with its uid. Never call t3cs_list_queue_items with dataId 0. To answer how training is going, or how many pages of one data source are still waiting, call t3cs_training_summary and use its message. An empty queue is not trained content. A source with 0 chunks has not been trained. Queue items and chunks are different counts. Do not say everything is up to date when that message names a source with no trained content, or a source that is not active.',
            'The same rule applies to retrying failed training items, syncing one data source, and updating a data source: list the sources, ask when several exist, and use the only uid when one exists. Never pass dataId 0. Sync every source only when the editor asked for all of them. "All my data sources", "every data source", and "alle Datenquellen" are scope all: call t3cs_sync_datasource with scope all and do not ask which source. A named source, or a request for only one source, is scope one with that source\'s uid. After t3cs_list_datasources, if exactly one source name matches, call t3cs_sync_datasource with that uid. Do not ask which source. "Sync data source 8" prepares t3cs_sync_datasource with dataId 8. Never say a source was synced unless that tool returned a result in this turn. When several sources share the name, ask which one. Never invent a dataId. "That one" is never all sources. "Refresh", "learn again", "neu lernen", or "in the bot" for an article or source is t3cs_sync_datasource for that source, not a news search. "Refresh everything" is scope all. After that sync is cancelled, "just A and B" syncs only those sources, one at a time. "Fix the failed one" for an article or source is t3cs_reset_failed_queue_item, not a translation requeue. "Rename the X source" is t3cs_save_datasource operation update of that source\'s name, not a folder and not another table. "No, call it B" after a rename card updates that same source\'s name only. Do not change another source, and do not add activate or usageType.',
            'If creating a data source failed, or the create card is still waiting (including HTTP 404), say it was not created. Do not invent a dataId, do not update or sync another source, do not sync all sources as a follow-up, and do not talk about the SEO scheduler. "Now activate it" and "sync that one now" after that refer to the source that was not created. When the editor asks what you changed or saved in this conversation, list only applied results. A prepared or failed card is not a saved change. After a failed create, say nothing was saved and that the source was not created, activated, or synced.',
            'After t3cs_list_datasources, "the first one" or "the second one" is that row of the list, not a translation of this page. Use that source\'s uid. Do not describe a page.',
            'A web page data source may use an external http or https URL. Do not say that URL must belong to this TYPO3 site, and do not tell the editor to upload a PDF unless they asked for a PDF.',
            'A request that names an http or https URL is a web page data source. Use type web_pages and that URL. Do not use type qa and do not invent questions or answers. If the URL is not http or https, say it is not valid and do not create a source.',
            'When the editor wants the chatbot or the search to learn a TYPO3 page, and they did not ask for a Q&A or FAQ source, use type typo3_pages with that page id. Look the page up when they named it. If several pages match, ask which one. Do not invent questions or answers.',
            'A training schedule uses five cron fields (minute hour day month weekday), for example 0 0 * * *. Do not send six fields.',
            'To change the description of a scheduler task, call scheduler_update with that uid and the new description. Do not say it already matches unless you listed the task and the description is identical. After a data source is saved, do not create page content unless the editor asked for it.',
            'AI provider and API key settings cannot be changed from the AI Agent. Tell the editor that an administrator can change them on the Providers page. Do not invent a menu path. Do not repeat a pasted key. Do not put a key into a chatbot, a data source, or any other record.',
            'Today is ' . date('Y-m-d') . '. To compare chatbot or search usage this month and last month, call t3cs_usage_analytics_summary twice, with period this_month and period last_month. Use only the dates and counts in those results. Do not use 30d as a calendar month, and do not name a month or year that is not in the results.',
            'For a Q&A data source, put every pair in qaPairs as a JSON array of {"q","a"}. Do not put the pairs only in typeValue, and do not wrap the array in another object. Send every pair the editor gave.',
            'Adding answers for the bot with Q: and A: pairs is a new Q&A data source: call t3cs_save_datasource with type qa and those pairs in qaPairs. "Name it …" is that source\'s name. Do not call t3as_predefined_questions_create or t3as_list_predefined_questions, and do not say a questions folder is missing. Adding answers to a named existing data source is t3cs_save_datasource for that source. A request for a suggested search question still uses t3as_predefined_questions_create.',
            'A text data source is type text. Put only the text the editor wrote into typeValue. When they did not give the text, ask what text to add and do not draft a card. Do not write the text yourself and do not add Markdown. When the editor says inactive, set activate to 0. Do not use type qa for a text source. After the source is saved, the history names that source and its text. Do not say nothing was saved.',
            'There is no tool to delete a data source. If the editor asks to delete data sources, say the agent cannot delete them and that a data source can be removed in the AI Chatbot/Search module on the Data Sources tab. Do not say the editor is not allowed to delete or list them.',
            'For t3cs_save_datasource, if the editor mentions the bot or chatbot, set usageType to chatbot or both. If unsure, omit usageType. Never say AI Search was enabled unless a t3as_search_settings update was applied. Setting the description of a named data source updates that exact name. The description is the text the editor gave. Do not use another source\'s id. "The search should not use this source, only the chatbot" sets usageType to chatbot on that source. "Neither should use it" sets activate to 0 on that same source. Do not call t3as_search_settings for that, and do not turn AI Search off for the site. "Turn on AI search" and "Turn off AI search" stay t3as_search_settings with enableAiSearch. After that card is applied, "change it back" writes the previous switch. If nothing was saved, say there is nothing to restore. Do not invent a menu path.',
            'When creating a data source or a predefined question, if one with the same name already exists on that site, or in that folder and language, the tool names its uid and does not create another. When that create is the same type and text, the tool returns that uid as success. Call ask_clarification with that uid and "Create another anyway" only when the type or text differs. Set allowDuplicate to 1 only after the editor chooses create anyway.',
            'The words help, hilfe, menu and options mean "what can you do": call explain_capabilities. Do not prepare a change.',
            'To read or change chatbot settings when the editor did not name a chatbot, call t3ac_chatbot_list. When several chatbots are on this site, call ask_clarification and do not pick one. When only one is on this site, use its uid. Never pass chatbotUid 0. Renaming a chatbot or changing its title is a chatbot change. If the chatbot tools are locked, say that licence message and stop. Do not list data sources, do not read AI Search settings, and do not invent a menu path. The visitor greeting (welcome message, Begrüßung) is first_message. instructions is the internal system prompt and changes only when the editor asks for instructions or behaviour. Never put a greeting into instructions, and do not say the greeting changed unless first_message was saved. A welcome or Begrüßung is an update of first_message with the quoted text. It is never operation get. "Rename the chatbot to …" is a chatbot-settings update of title. The title is only the new name, not the rest of the sentence. Do not prepare a record card with no fields. After that card is applied, the history names the previous title or greeting. "Change it back" writes that previous value. If there is no previous value, ask what to change it to and do not say it was restored. Links under the chatbot\'s answers are enable_sources_links and max_source_links. Answering in the visitor\'s language is answer_in_visitor_language. A rule for the website bot, such as never recommending other brands, is instructions. Call t3ac_chatbot_settings for those. Do not invent a menu path, do not change your own replies, and do not ask which data source to sync. "Show at most 3 links under the bot\'s answers" is max_source_links 3. "Make it 2" after a source-link request sets max_source_links to 2. "Is that active now?" is yes only after that setting was saved. After those settings are applied, say they were saved. Do not say nothing was saved.',
            'Call t3as_list_predefined_questions only when the editor asked about suggested or predefined search questions. A usage question, a request to shorten answers, or a change to search instructions is not that list. Do not call the list during those requests. To list those questions, call t3as_list_predefined_questions even when no folder is set. Do not call t3as_search_settings or t3as_search_settings_get for that list. The list tool\'s reply is the answer. To add a suggested question, call t3as_predefined_questions_create. Do not open the search-settings tool for that. When the questions folder is missing and the editor did ask for suggested questions, say the folder is missing and ask which folder to use. Do not invent a page id. Offer to save that folder in the AI Search settings.',
            'For t3as_search_settings, send only a value the search form accepts. A colour is like #1a73e8. questionsLimit is 3, 5, or 10. widgetPosition is left-bottom, default, or custom. An offset is a whole number from 5 to 500. allowedDomains is host names such as example.com, separated by commas, not a URL. A switch is true or false. If the tool refuses a value, say that message. Do not claim the setting was saved. Send only the fields the editor named. A main colour is primaryColor and nothing else. Do not also send enableAiSearch, instructions, another colour, the appearance, or the widget mode unless the editor asked for that.',
            'When a page uid does not exist, say that the page does not exist. Do not show tool-call text, JSON, or tool names.',
            'To write SEO texts for the subpages of this page, call t3ai_generate_seo_batch. Entries may be omitted: the subpages of the open page are used, including pages without Mass SEO enabled. The SEO queue is only for background generation. A recursive queue add includes only pages that have Mass SEO enabled. Repeat the queue summary: name any skipped pages and say Mass SEO is not enabled. Do not say every subpage was queued.',
            'Without a create-page tool, to create a page with content: first prepare the new page (only the page). After the editor applies it, the result gives the new page uid; then prepare the content elements with that uid as their pid.',
            'To analyse how good the text of a page is (readability, SEO, "content analysis") call t3ai_analyze_content with the page uid and show its report as the answer. It changes nothing. Change texts afterwards only when the editor asks for it, with write_table.',
            'When the editor asks to create or add content elements, use a create/write tool. Do not call content_delete unless they asked to remove or replace something.',
            'A sys_file uid is never a tt_content uid. To list images without alt text call t3aa_list_files_missing_alt_text. Never pass a file uid to content_delete. "Delete all images that have no alt text" is a list: show the files, do not call file_delete or delete them from storage. Offer generate alt text or queue drafts instead. Summarize a named page with t3aa_summarize_content pageId — never pass that page uid as contentUid.',
            'A simplified or plain-language version in a named language is t3aa_summarize_content with pageId of this page and that language. It is not t3ai_translate_content. Do not pass contentUid 0.',
            'After t3aa_get_file_metadata, a follow-up such as "change it to …" still means t3aa_update_file_metadata. Do not use write_table on sys_file.',
            'When the editor names several image files, call t3aa_update_file_metadata once per file (fileUrl of that file). After Apply, continue with the next named file. Do not stop after the first.',
            'A new request that lists images without alt text, marks an image decorative, or queues a folder is not a follow-up of the previous image. Call t3aa_list_files_missing_alt_text, t3aa_mark_image_decorative (fileUrl of the named file) or t3aa_alt_text_queue_folder as asked. Do not reuse the previous file.',
            'After t3aa_list_files_missing_alt_text, generate alt text only for files from that list (fileUid). Skip files that already have alt text. One file per t3aa_update_file_metadata call; after Apply continue with the next until the asked count is done.',
            'When the editor asks to queue alt text drafts and not approve them yet, call t3aa_alt_text_queue_folder then t3aa_alt_text_drafts_list. Do not call t3aa_alt_text_approve.',
            'To change or delete a content element, use its uid from the latest content_list or content_get result; uids from older messages may no longer exist. The uid of a record you just created (from the applied result) is valid.',
            'write_table action must be create, update, or delete only. To show or hide a page or content element, use action update with data {"hidden": 0} or {"hidden": 1}. Never invent other action names. After you create a content element the editor asked for, do not prepare a delete of that same element unless they asked to remove it.',
            'To make a translated page visible, update hidden on that translation page uid and on its hidden content elements (content_list with the default page pid + that language) in the same write_table plan. Do not stop after only the page row. Do not use workspace_stage_set for visibility.',
            'When confirming pages created by t3ai_create_page_structure, list only the page titles from the Apply result (structure / created pages), never the titles from the original chat request if they differ.',
            'Before the first content element on this page: write_table create on tt_content with pid of this page and beforeUid of the first content element uid from content_list — never beforeUid of a page uid.',
        ];

        foreach ($this->queueAutomationStatus->promptLines() as $queueLine) {
            $lines[] = $queueLine;
        }

        if ($pageId > 0) {
            $languageId = isset($context['languageId']) ? (int) $context['languageId'] : null;
            $lines[] = $this->languageResolver->contentLanguageInstruction($pageId, $languageId);
        }

        if ($persona !== '') {
            $lines[] = 'Brand context (persona):';
            $lines[] = $persona;
        }

        $block = AgentContextPresenter::promptBlock($context);
        if ($block !== '') {
            $lines[] = $block;
        } else {
            // Context without details (e.g. CLI / eval): the plain ids.
            $module = trim((string) ($context['module'] ?? ''));
            if ($module !== '') {
                $lines[] = 'Current backend module: ' . $module;
            }
            if ($pageId > 0) {
                $lines[] = 'Current page id: ' . $pageId;
            }
            $record = is_array($context['record'] ?? null) ? $context['record'] : null;
            if ($record !== null) {
                $lines[] = 'Focused record: ' . ($record['table'] ?? '') . ':' . ($record['uid'] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Records the editor applied in this conversation (newest first), so a later step can address
     * them by uid instead of guessing a URL or slug.
     *
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function appliedRecordsBlock(array $historyMessages): string
    {
        $records = [];
        $savedSources = [];
        for ($i = count($historyMessages) - 1; $i >= 0 && count($records) + count($savedSources) < 8; --$i) {
            $meta = is_array($historyMessages[$i]['meta'] ?? null) ? $historyMessages[$i]['meta'] : [];
            if (($meta['type'] ?? '') === 'readback_result') {
                $note = trim(self::appliedRecordsNote($meta), ' .');
                if (str_starts_with($note, 'Records: ')) {
                    foreach (explode('; ', substr($note, strlen('Records: '))) as $record) {
                        $records[$record] = true;
                    }
                }
            }
            if (($meta['type'] ?? '') === 'tool_result'
                && (string) ($meta['tool'] ?? '') === 't3cs_save_datasource'
                && ($meta['success'] ?? true) !== false
                && ($meta['autoRan'] ?? true) === false
            ) {
                $saved = self::savedDatasourceNote($meta['details'] ?? null);
                if ($saved !== '') {
                    $savedSources[$saved] = true;
                }
            }
        }

        $lines = [];
        if ($records !== []) {
            $lines[] = 'Records the editor applied in this conversation (use these uids as pageId / pid / uid, never a guessed URL): '
                . implode('; ', array_slice(array_keys($records), 0, 8)) . '.';
        }
        if ($savedSources !== []) {
            $lines[] = 'Data sources the editor saved in this conversation (do not say nothing was saved): '
                . implode('; ', array_slice(array_keys($savedSources), 0, 8)) . '.';
        }

        return implode(' ', $lines);
    }

    /**
     * The data source a confirmed save wrote, including the text, so a later "what did you save?"
     * can answer from the history.
     */
    public static function savedDatasourceNote(mixed $details): string
    {
        if (!is_array($details)) {
            return '';
        }
        $dataId = (int) ($details['dataId'] ?? 0);
        $type = trim((string) ($details['type'] ?? ''));
        $text = trim((string) ($details['typeValue'] ?? ''));
        if ($dataId <= 0 && $text === '') {
            return '';
        }
        $note = 'data source';
        if ($dataId > 0) {
            $note .= ' uid ' . $dataId;
        }
        if ($type !== '') {
            $note .= ' (' . $type . ')';
        }
        if ($text !== '') {
            $note .= ' text "' . self::shorten($text, 240) . '"';
        }

        return $note;
    }

    /**
     * The plan of this conversation as the model last saved it (meta.plan of the newest message that
     * carries one), as a prompt block; empty when there is none or every step is completed.
     *
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function planBlock(array $historyMessages): string
    {
        return AgentPlan::promptBlock(AgentPlan::latest($historyMessages));
    }

    /**
     * Earlier messages for the model, newest first until the character budget is used up.
     *
     * - The latest "Summarize conversation" message replaces everything before it.
     * - Cards and results are replayed as short bracketed notes ("[Prepared change: … — applied]"),
     *   so the model knows what was done without the raw data.
     * - A user message written on another page than the current one is prefixed with that page,
     *   so "this page" in an older message is not confused with the page shown now.
     * - When older messages had to be left out, the history starts with a note saying so.
     *
     * @param list<array<string, mixed>> $historyMessages
     * @param int $charBudget characters (≈ 4 per token) for all replayed messages
     * @return list<array{role: string, content: string}>
     */
    public function buildHistory(array $historyMessages, int $currentPageId = 0, int $charBudget = self::DEFAULT_HISTORY_CHARS): array
    {
        return $this->buildHistoryAfterSummary(array_values(array_filter($historyMessages, 'is_array')), '', $currentPageId, $charBudget);
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array{role: string, content: string}>
     */
    private function buildHistoryAfterSummary(array $historyMessages, string $summary, int $currentPageId, int $charBudget): array
    {
        // A later summary replaces an earlier one.
        foreach ($historyMessages as $index => $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') === 'summary' && trim((string) ($entry['content'] ?? '')) !== '') {
                return $this->buildHistoryAfterSummary(
                    array_slice($historyMessages, $index + 1),
                    trim((string) $entry['content']),
                    $currentPageId,
                    $charBudget,
                );
            }
        }

        $entries = [];
        foreach ($historyMessages as $entry) {
            $replayed = $this->historyEntry($entry, $currentPageId);
            if ($replayed !== null) {
                $entries[] = $replayed;
            }
        }

        $budget = max(1000, $charBudget);
        if ($summary !== '') {
            $summary = self::shorten($summary, (int) ($budget / 3));
            $budget -= mb_strlen($summary);
        }

        $kept = [];
        $used = 0;
        for ($i = count($entries) - 1; $i >= 0; --$i) {
            $content = self::shorten($entries[$i]['content'], self::HISTORY_MESSAGE_CHARS);
            $length = mb_strlen($content);
            if ($kept !== [] && $used + $length > $budget) {
                break;
            }
            $used += $length;
            array_unshift($kept, ['role' => $entries[$i]['role'], 'content' => $content]);
        }
        $leftOut = count($entries) - count($kept);

        $prefix = [];
        if ($summary !== '') {
            $prefix[] = ['role' => 'user', 'content' => "[Summary of the earlier conversation]\n" . $summary];
        }
        if ($leftOut > 0) {
            $prefix[] = ['role' => 'user', 'content' => sprintf('[%d older message(s) of this conversation are left out.]', $leftOut)];
        }

        return [...$prefix, ...$kept];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{role: string, content: string}|null
     */
    private function historyEntry(array $entry, int $currentPageId): ?array
    {
        $role = (string) ($entry['role'] ?? 'user');
        if (!in_array($role, ['user', 'assistant'], true)) {
            return null;
        }
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $type = (string) ($meta['type'] ?? '');
        if (in_array($type, ['provider_thinking', 'error', 'locked', 'summary'], true)) {
            return null;
        }
        $content = $this->historyContent($entry, $meta);
        $label = $this->cardLabel($meta);

        $content = match ($type) {
            'tool_result' => sprintf(
                '[%s%s] %s%s',
                $label !== '' ? $label : 'Tool result',
                ($meta['success'] ?? true) === false ? ' failed' : '',
                $content,
                ($meta['autoRan'] ?? true) === false ? self::resultRecordsNote($meta['details'] ?? null) : '',
            ),
            'inline_draft', 'suggestions' => self::preparedChangeHistoryNote(
                $label !== '' ? $label : 'change',
                $this->cardStatus($meta),
                $content,
            ),
            'readback_result' => '[Applied] ' . $content . self::appliedRecordsNote($meta),
            'clarification' => $content . (is_array($meta['options'] ?? null) && $meta['options'] !== []
                ? ' (options: ' . implode(', ', array_map('strval', array_filter($meta['options'], 'is_scalar'))) . ')'
                : ''),
            default => $content,
        };
        if ($type === 'tool_result' && (string) ($meta['tool'] ?? '') === 't3cs_save_datasource') {
            $saved = self::savedDatasourceNote($meta['details'] ?? null);
            if ($saved !== '') {
                $content .= ' Saved: ' . $saved . '.';
            }
        }
        if ($type === 'tool_result' && (string) ($meta['tool'] ?? '') === 't3ac_chatbot_settings') {
            $baseline = is_array($meta['recordBaseline']['values'] ?? null) ? $meta['recordBaseline']['values'] : [];
            $previousTitle = trim((string) ($baseline['title'] ?? ''));
            if ($previousTitle !== '') {
                $content .= ' Previous title: ' . $previousTitle . '.';
            }
            $previousGreeting = trim((string) ($baseline['first_message'] ?? ''));
            if ($previousGreeting !== '') {
                $content .= ' Previous greeting: ' . $previousGreeting . '.';
            }
        }
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $written = is_array($meta['context'] ?? null) ? $meta['context'] : [];
        $writtenPageId = (int) ($written['pageId'] ?? 0);
        if ($role === 'user' && $writtenPageId > 0 && $writtenPageId !== $currentPageId) {
            $content = sprintf('[written on page "%s" [%d]] ', (string) ($written['pageTitle'] ?? ''), $writtenPageId) . $content;
        }

        return ['role' => $role, 'content' => $content];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function cardLabel(array $meta): string
    {
        $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : $meta;
        foreach (['editorLabel', 'toolCallLabel', 'label', 'tool'] as $key) {
            $label = trim((string) ($draft[$key] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function cardStatus(array $meta): string
    {
        $state = is_array($meta['draft'] ?? null) ? $meta['draft'] : $meta;
        if (($state['applied'] ?? false) === true) {
            return 'applied';
        }
        if (($state['discarded'] ?? false) === true) {
            return 'declined';
        }
        if (($state['failed'] ?? false) === true) {
            $reason = trim((string) ($state['failureMessage'] ?? ''));

            return 'failed and would fail again with the same arguments' . ($reason !== '' ? ': ' . $reason : '');
        }

        return 'waiting for the editor';
    }

    /**
     * The instruction for the turn after the editor confirmed or declined a card (English, like
     * the system prompt; the reply language rule still applies).
     *
     * The records the change wrote (table, uid, title) come from the stored result, so a new
     * page's uid can be used right away (e.g. as pid of its content elements).
     *
     * @param array<mixed> $continuation {outcome: applied|declined, label: string, result: string}
     * @param list<array<string, mixed>> $history
     */
    public static function continuationMessage(array $continuation, array $history = []): string
    {
        $label = mb_substr(trim((string) ($continuation['label'] ?? '')), 0, 120);
        $result = mb_substr(trim((string) ($continuation['result'] ?? '')), 0, 600);
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') === 'user') {
                break;
            }
            if (($meta['type'] ?? '') === 'readback_result') {
                $result = trim($result . self::appliedRecordsNote($meta));
                break;
            }
            // A confirmed tool (create content element, generate image, …): the ids it returned.
            if (($meta['type'] ?? '') === 'tool_result' && ($meta['autoRan'] ?? true) === false) {
                $result = trim($result . self::resultRecordsNote($meta['details'] ?? null));
                break;
            }
        }
        if (($continuation['outcome'] ?? '') === 'declined') {
            $checklist = AgentRequestChecklist::reconcile($history, self::latestUserRequestText($history));
            if ($checklist !== [] && !AgentRequestChecklist::hasOpen($checklist)) {
                return sprintf(
                    '[The editor declined "%s". Nothing was written.] The rest of my request depended on it, so nothing else remains:'
                    . ' do not call tools and do not propose it again in another form. Ask in one short sentence what to do instead.',
                    $label,
                );
            }

            return sprintf(
                '[The editor declined "%s". Nothing was written.] Do not repeat it. If other steps of my request remain, continue with them;'
                . ' otherwise ask in one short sentence what to do instead.',
                $label,
            );
        }

        $confirmed = self::lastConfirmedTool($history);
        if ($confirmed === 't3as_search_settings') {
            return sprintf(
                '[The editor confirmed "%s" and it was applied.] Reply with exactly: Search settings saved. Do not name a page or a title. Do not call any tools.',
                $label,
            );
        }
        if (in_array($confirmed, ['t3cs_save_datasource', 't3cs_sync_datasource', 't3cs_reset_failed_queue_item'], true)) {
            return sprintf(
                '[The editor confirmed "%s" and it was applied.] Reply with exactly: The data source change is done. Do not create a content element or a page. Do not call any tools.',
                $label,
            );
        }
        if ($confirmed === 't3ac_chatbot_settings') {
            return sprintf(
                '[The editor confirmed "%s" and it was applied.] Reply with exactly: Chatbot settings saved. Do not say nothing was saved. Do not call any tools.',
                $label,
            );
        }

        $requestText = self::latestUserRequestText($history);
        // An edit of existing records ("change the header of element 5") has no create step: nothing new may follow.
        // Free multi-create wording ("intro and a team section") has an empty checklist but still creates.
        $changesExistingOnly = $requestText !== ''
            && AgentRequestChecklist::parse($requestText) === []
            && !AgentCoreToolSet::isCreateContentRequest($requestText);
        $createContentDone = AgentCoreToolSet::isCreateContentRequest($requestText)
            && !self::asksForMultipleContentPieces($requestText)
            && self::remainingWorkReminder($history, AgentPlan::latest($history), $requestText) === '';
        if ($createContentDone) {
            $message = sprintf(
                '[The editor confirmed "%s" and it was applied.%s] My request was only to add that content.'
                . ' Confirm in one short sentence that it is done. Do not call any tools.'
                . ' Do not update, rewrite, or delete the content element you just created.',
                $label,
                $result !== '' ? ' Result: ' . $result : '',
            );
            if ($requestText !== '') {
                $message .= sprintf(' My request was: "%s".', mb_substr(preg_replace('/\s+/u', ' ', $requestText) ?? $requestText, 0, 300));
            }

            return $message;
        }
        $message = sprintf(
            '[The editor confirmed "%s" and it was applied.%s] Continue with the remaining steps of my request, if there are any;'
            . ' if this change already completed it, just confirm that in one short sentence.'
            . '%s'
            . ' Only when every part of my original request is already done through tools, confirm in one short sentence without calling tools.'
            . ' Never claim a file or image is attached unless a file-reference step succeeded.'
            . ' Do not re-check finished results or list what else you can do.',
            $label,
            $result !== '' ? ' Result: ' . $result : '',
            $changesExistingOnly
                ? ' My request changes existing records and asks for nothing new: do not create any record or content element.'
                    . ' Prepare another change only if my request names one that is not applied yet.'
                : ' Do not create another content element of the same CType you just applied; prepare the next distinct type from my request, or attach a pending image.',
        );
        $lastCType = self::lastAppliedTtContentCType($history);
        if ($lastCType !== '') {
            $message .= sprintf(
                ' You just applied tt_content with CType "%1$s"; do not create another "%1$s" unless that was the only type requested.',
                $lastCType,
            );
        }
        $request = self::latestUserRequestText($history);
        if ($request !== '') {
            $message .= sprintf(' My request was: "%s".', mb_substr(preg_replace('/\s+/u', ' ', $request) ?? $request, 0, 300));
        }
        $reminder = self::remainingWorkReminder($history, AgentPlan::latest($history), $request);

        return $reminder !== '' ? $message . ' ' . $reminder : $message;
    }

    /**
     * The tool whose confirmation was just applied, when that result is the latest one before the next request.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function lastConfirmedTool(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') === 'user') {
                break;
            }
            if (($meta['type'] ?? '') === 'tool_result' && ($meta['autoRan'] ?? true) === false) {
                return (string) ($meta['tool'] ?? '');
            }
        }

        return '';
    }

    /**
     * Some models send the same answer twice in one message ("…ask your administrator.…ask your administrator.").
     * Returns the text once when it is two identical halves, otherwise unchanged.
     */
    public static function collapseRepeatedReply(string $text): string
    {
        $text = trim($text);
        $length = strlen($text);
        if ($length < 20) {
            return $text;
        }
        foreach (['', ' ', "\n", "\n\n"] as $separator) {
            $rest = $length - strlen($separator);
            if ($rest <= 0 || $rest % 2 !== 0) {
                continue;
            }
            $half = intdiv($rest, 2);
            $first = substr($text, 0, $half);
            if ($first !== '' && substr($text, $half, strlen($separator)) === $separator && substr($text, $half + strlen($separator)) === $first) {
                return trim($first);
            }
        }

        return $text;
    }

    /**
     * Some models write the tool call into the answer ("{"pageId":99999} to=t3ai_generate_all_seo …").
     * The editor sees the sentence after that call. A reply without a leaked call is unchanged.
     */
    public static function stripLeakedToolCall(string $text): string
    {
        $text = trim($text);
        if ($text === '' || preg_match('/to=[A-Za-z0-9_]+/', $text) !== 1) {
            return $text;
        }

        $stripped = preg_replace('/\{[^{}]{0,500}\}\s*to=[A-Za-z0-9_]+|to=[A-Za-z0-9_]+/u', ' ', $text);
        if (!is_string($stripped)) {
            return $text;
        }
        $stripped = trim($stripped);
        if (preg_match('/[A-Za-zÄÖÜäöü][A-Za-zÄÖÜäöü\'’\-]*(?:\s+[A-Za-zÄÖÜäöü0-9][A-Za-zÄÖÜäöü0-9\'’\-]*){2,}.*/u', $stripped, $sentence) === 1) {
            return trim($sentence[0]);
        }

        return $stripped;
    }

    /**
     * Page uid from a leaked tool call whose sentence says that page is missing. 0 otherwise.
     */
    public static function leakedMissingPageUid(string $text): int
    {
        if (preg_match('/to=[A-Za-z0-9_]+/', $text) !== 1) {
            return 0;
        }
        $prose = self::stripLeakedToolCall($text);
        if (!self::isMissingPageProse($prose) && !self::isMissingPageProse($text)) {
            return 0;
        }
        if (preg_match('/"pageId"\s*:\s*(\d+)/', $text, $match) === 1) {
            return (int) $match[1];
        }
        if (preg_match('/\b(?:page|seite)(?:\s+uid)?\s+(\d+)/iu', $prose, $match) === 1) {
            return (int) $match[1];
        }

        return 0;
    }

    private static function isMissingPageProse(string $text): bool
    {
        return preg_match(
            '/was not found|does(?:\s*n[\'’]t| not) exist|can(?:\s*n[\'’]t|not) generate|nicht gefunden|gibt es nicht|kann .{0,40}nicht/iu',
            $text,
        ) === 1;
    }

    /**
     * Editor's newest non-continuation user message (the original multi-step request when present).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function latestUserRequestText(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($meta['type'] ?? '') === 'continuation') {
                continue;
            }
            $text = trim((string) ($history[$i]['content'] ?? ''));
            // The follow-up after a confirmed card is not a request: its quoted label must never be read as one.
            // Neither is a typed "continue": the request it continues still decides what is left to do.
            if (str_starts_with($text, '[The editor ') || self::isGoOnReply($text)) {
                continue;
            }

            return $text;
        }

        return '';
    }

    /**
     * "continue", "go on", "weiter" — the editor asks to finish the previous request, not for something new.
     */
    public static function isGoOnReply(string $message): bool
    {
        return preg_match(
            '/^\s*(?:please\s+|bitte\s+)?(?:continue|go\s+on|carry\s+on|keep\s+going|proceed|resume|next|weiter(?:\s+machen)?|mach(?:e)?\s+weiter|fortfahren|fortsetzen)\s*(?:please|bitte)?\s*[.!]*\s*$/iu',
            $message,
        ) === 1;
    }

    public static function requestMentionsImage(string $query): bool
    {
        return preg_match(
            '/\b(image|images|bild|bilder|foto|photo|illustration|textmedia|text\s*&\s*media|text\s+and\s+media)\b/ui',
            $query,
        ) === 1;
    }

    /**
     * Free wording that names two content pieces ("an intro and a team section") without a checklist
     * split must not be treated as finished after the first Apply.
     */
    public static function asksForMultipleContentPieces(string $query): bool
    {
        $piece = 'intro|paragraph|section|element|block|absatz|abschnitt|faq|team|header|headline|einleitung';

        return preg_match(
            '/\b(?:' . $piece . ')\b.+\b(?:and|und|plus|,)\b.+\b(?:' . $piece . ')\b/iu',
            $query,
        ) === 1;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array{title: string, status: string}> $plan
     */
    public static function remainingWorkReminder(array $history, array $plan, string $requestQuery = ''): string
    {
        $query = trim($requestQuery) !== '' ? $requestQuery : self::latestUserRequestText($history);
        $parts = [];

        $checklist = AgentRequestChecklist::reconcile($history, $query);
        if ($checklist !== []) {
            $block = AgentRequestChecklist::promptBlock($checklist, $query);
            if ($block !== '') {
                $parts[] = $block;
            }
        } elseif ($plan !== [] && AgentPlan::hasOpenSteps($plan)) {
            $block = AgentPlan::promptBlock($plan);
            if ($block !== '') {
                $parts[] = $block;
            }
        }

        // A file saved in an earlier request and never attached must not make an unrelated request
        // ("Where are my AI pictures?") look unfinished: it counts when this request asks to place a
        // file, or when the file was saved while working on this request.
        $pending = '';
        if (AgentRequestedFiles::asksOnlyForGeneratedImage($query)) {
            // Just an image was asked for: the request is done once the file is saved.
        } elseif (AgentRequestedFiles::asksToAttachOrPlaceFile($query)) {
            $pending = self::pendingImageAttachNote($history);
        } elseif (self::unattachedFileUids(AgentRequestChecklist::historySinceLatestUserRequest($history, $query)) !== []) {
            $pending = self::pendingImageAttachNote($history);
        }
        if ($pending !== '') {
            $parts[] = $pending;
        }
        $missing = self::missingMediaImageNote($history, $query, $plan);
        if ($missing !== '' && $checklist === []) {
            // Checklist already encodes attach_image when present; avoid duplicating.
            $parts[] = $missing;
        }
        $imageTexts = AgentToolTurnProcessor::remainingImageTextReminder($history, $query);
        if ($imageTexts !== '') {
            $parts[] = $imageTexts;
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array{title: string, status: string}> $plan
     */
    public static function hasBlockingRemainingWork(array $history, array $plan, string $requestQuery = ''): bool
    {
        $query = trim($requestQuery) !== '' ? $requestQuery : self::latestUserRequestText($history);
        $checklist = AgentRequestChecklist::reconcile($history, $query);
        if ($checklist !== [] && AgentRequestChecklist::hasOpen($checklist)) {
            return true;
        }

        return self::remainingWorkReminder($history, $plan, $query) !== '';
    }

    /**
     * Media-capable tt_content uid created in this conversation but never got file_reference_add.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function unattachedMediaContentElementUid(array $history): ?int
    {
        $attached = self::contentElementUidsWithSuccessfulFileReference($history);
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') !== 'assistant' || ($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $entry) {
                if (!is_array($entry) || ($entry['table'] ?? '') !== 'tt_content' || (int) ($entry['uid'] ?? 0) <= 0) {
                    continue;
                }
                $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
                $cType = strtolower(trim((string) ($values['CType'] ?? '')));
                if (!in_array($cType, ['textmedia', 'textpic', 'image'], true)) {
                    continue;
                }
                $uid = (int) $entry['uid'];
                if (!isset($attached[$uid])) {
                    return $uid;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array{title: string, status: string}> $plan
     */
    public static function missingMediaImageNote(array $history, string $requestQuery, array $plan = []): string
    {
        if (
            self::unattachedFileUids($history) !== []
            || AgentRequestedFiles::noFileLeftToAttach($history, $requestQuery)
            || AgentRequestChecklist::imageWasDeclined($history, $requestQuery)
        ) {
            return '';
        }
        $uid = self::unattachedMediaContentElementUid($history);
        if ($uid === null) {
            return '';
        }
        if (
            !AgentRequestedFiles::asksToAttachOrPlaceFile($requestQuery)
            && !AgentPlan::hasOpenImageAttachStep($plan)
        ) {
            return '';
        }

        return sprintf(
            'Remaining: tt_content uid %d still has no image in this conversation.'
            . ' Call t3ai_generate_image (or use an uploaded file), then file_reference_add on that uid'
            . ' (fieldName "assets" for textmedia, "image" for textpic). Do not confirm completion until attach succeeds.',
            $uid,
        );
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<int, true>
     */
    private static function contentElementUidsWithSuccessfulFileReference(array $history): array
    {
        $uids = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') !== 'tool_result' || ($meta['success'] ?? true) === false) {
                continue;
            }
            if ((string) ($meta['tool'] ?? '') !== 'file_reference_add') {
                continue;
            }
            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            if ((string) ($details['table'] ?? '') !== 'tt_content') {
                continue;
            }
            $uid = (int) ($details['uid'] ?? 0);
            if ($uid > 0) {
                $uids[$uid] = true;
            }
        }

        return $uids;
    }

    /**
     * CType of the newest applied tt_content row in history (empty when none).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function lastAppliedTtContentCType(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            $readback = is_array($meta['readback'] ?? null) ? $meta['readback'] : [];
            for ($j = count($readback) - 1; $j >= 0; --$j) {
                $row = is_array($readback[$j] ?? null) ? $readback[$j] : [];
                if (($row['table'] ?? '') !== 'tt_content') {
                    continue;
                }
                $values = is_array($row['values'] ?? null) ? $row['values'] : [];
                $cType = strtolower(trim((string) ($values['CType'] ?? $values['ctype'] ?? '')));
                if ($cType !== '') {
                    return $cType;
                }
            }
        }

        return '';
    }

    /**
     * When a generated/uploaded file still has no file reference and a target record exists,
     * tell the model that attach is still open (do not let it declare the request done early).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function pendingImageAttachNote(array $history): string
    {
        $fileUids = self::unattachedFileUids($history);
        if ($fileUids === []) {
            return '';
        }
        $fileUidList = implode(', ', array_map(static fn(int $uid): string => (string) $uid, $fileUids));
        $newsUid = self::latestAppliedNewsUid($history);
        if ($newsUid !== null) {
            return sprintf(
                'Remaining: attach fileUid %s to tx_news_domain_model_news uid %d with file_reference_add (fieldName "fal_media").'
                . ' Do not create a content element, do not attach to the Layout page, and do not call generate or attach again once it succeeds.',
                $fileUidList,
                $newsUid,
            );
        }
        $contentUid = self::latestAppliedContentElementUid($history);
        if ($contentUid === null) {
            return sprintf(
                'Remaining: fileUid %s is saved but not attached. Prepare a Text & Media element (CType textmedia) and wait until the editor applies it,'
                . ' then attach fileUid %s to that tt_content uid with file_reference_add (fieldName "assets").'
                . ' Do not attach to plain Text or Bullets elements. Do not confirm completion until the attach succeeds.',
                $fileUidList,
                $fileUidList,
            );
        }

        return sprintf(
            'Remaining: attach fileUid %s to tt_content uid %d by calling file_reference_add'
            . ' (fieldName "assets" for text & media, "image" for textpic). Do not use write_table for file fields.'
            . ' Do not attach to non-media content types. Do not confirm completion until that succeeds.',
            $fileUidList,
            $contentUid,
        );
    }

    /**
     * Newest applied EXT:news uid in this conversation, or null.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function latestAppliedNewsUid(array $history): ?int
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') !== 'assistant' || ($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $entry) {
                if (!is_array($entry) || (string) ($entry['table'] ?? '') !== 'tx_news_domain_model_news') {
                    continue;
                }
                $uid = (int) ($entry['uid'] ?? 0);
                if ($uid > 0) {
                    return $uid;
                }
            }
        }

        return null;
    }

    /**
     * True when an attach card is still waiting for Apply/Decline.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function hasOpenFileReferenceDraft(array $history): bool
    {
        foreach ($history as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') !== 'inline_draft') {
                continue;
            }
            $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : [];
            $tool = (string) ($meta['tool'] ?? ($draft['tool'] ?? ''));
            if ($tool === 'file_reference_add' && ($draft['discarded'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Files still to attach: saved in this conversation and not attached yet, plus existing files the
     * request names ("attach sys_file uid 3") that are not missing and not attached while working on it.
     *
     * @param list<array<string, mixed>> $history
     * @return list<int>
     */
    public static function pendingAttachFileUids(array $history, string $request): array
    {
        $pending = self::unattachedFileUids($history);
        $named = AgentRequestedFiles::attachableNamedFileUids($history, $request);
        if ($named === []) {
            return $pending;
        }
        $attached = self::attachedFileUids(AgentRequestChecklist::historySinceLatestUserRequest($history, $request));
        foreach ($named as $uid) {
            if (!isset($attached[$uid]) && !in_array($uid, $pending, true)) {
                $pending[] = $uid;
            }
        }

        return $pending;
    }

    /**
     * sys_file uids from successful writes that are not yet covered by a successful file_reference_add.
     *
     * @param list<array<string, mixed>> $history
     * @return list<int>
     */
    public static function unattachedFileUids(array $history): array
    {
        $created = [];
        $attached = self::attachedFileUids($history);
        $lastUserRequest = '';
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['role'] ?? '') === 'user') {
                $lastUserRequest = (string) ($entry['content'] ?? '');
                continue;
            }
            if (($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') !== 'tool_result' || ($meta['success'] ?? true) === false) {
                continue;
            }
            $tool = (string) ($meta['tool'] ?? '');
            // Page voice-over already places its own player. The audio file is not an image to put on Text & Media.
            if ($tool === 't3aa_generate_voice_over') {
                continue;
            }
            // Image texts / decorative / drafts change an existing file; they are not a file to attach.
            if (in_array($tool, [
                't3aa_update_file_metadata',
                't3aa_mark_image_decorative',
                't3aa_alt_text_queue_folder',
                't3aa_alt_text_approve',
                't3aa_get_file_metadata',
                't3aa_list_files_missing_alt_text',
            ], true)) {
                continue;
            }
            $details = $meta['details'] ?? null;
            if ($tool === 'file_reference_add') {
                continue;
            }
            // Composer @file:… runs file_get_info (read). Count it only when the editor asked to place it.
            $composerAttach = ($meta['triggeredByAttachment'] ?? false) === true
                && $tool === 'file_get_info'
                && AgentRequestedFiles::asksToAttachOrPlaceFile($lastUserRequest);
            // Files a lookup only showed (file_list thumbnails) were not saved for this request.
            if (
                !$composerAttach
                && (($meta['severity'] ?? '') === 'read' || ($meta['readWithoutConfirmation'] ?? false) === true)
            ) {
                continue;
            }
            foreach (self::fileUidsFromDetails($details) as $uid) {
                $created[$uid] = true;
            }
            if ($composerAttach && is_array($details) && (int) ($details['uid'] ?? 0) > 0) {
                $created[(int) $details['uid']] = true;
            }
            foreach (is_array($meta['previews'] ?? null) ? $meta['previews'] : [] as $preview) {
                if (is_array($preview) && (int) ($preview['fileUid'] ?? 0) > 0) {
                    $created[(int) $preview['fileUid']] = true;
                }
            }
        }
        $pending = [];
        foreach (array_keys($created) as $uid) {
            if (!isset($attached[$uid])) {
                $pending[] = $uid;
            }
        }

        return $pending;
    }

    /**
     * sys_file uids of successful file_reference_add results.
     *
     * @param list<array<string, mixed>> $history
     * @return array<int, true>
     */
    public static function attachedFileUids(array $history): array
    {
        $attached = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') !== 'tool_result' || ($meta['success'] ?? true) === false) {
                continue;
            }
            if ((string) ($meta['tool'] ?? '') !== 'file_reference_add') {
                continue;
            }
            foreach (self::fileUidsFromDetails($meta['details'] ?? null) as $uid) {
                $attached[$uid] = true;
            }
        }

        return $attached;
    }

    /**
     * Newest applied tt_content uid that can hold the image (textmedia / textpic / image), or null.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function latestAppliedContentElementUid(array $history): ?int
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') !== 'assistant' || ($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $entry) {
                if (!is_array($entry) || ($entry['table'] ?? '') !== 'tt_content' || (int) ($entry['uid'] ?? 0) <= 0) {
                    continue;
                }
                $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
                $cType = strtolower(trim((string) ($values['CType'] ?? '')));
                if (in_array($cType, ['textmedia', 'textpic', 'image'], true)) {
                    return (int) $entry['uid'];
                }
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function fileUidsFromDetails(mixed $details): array
    {
        if (!is_array($details)) {
            return [];
        }
        $uids = [];
        foreach (['fileUid', 'file_uid', 'sysFileUid', 'fileId'] as $key) {
            if ((int) ($details[$key] ?? 0) > 0) {
                $uids[] = (int) $details[$key];
            }
        }
        $list = $details['fileUids'] ?? null;
        if (is_string($list) && $list !== '') {
            foreach (explode(',', $list) as $part) {
                $uid = (int) trim($part);
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        } elseif (is_array($list)) {
            foreach ($list as $value) {
                $uid = (int) $value;
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        }
        foreach (['file', 'result', 'data'] as $nest) {
            if (is_array($details[$nest] ?? null)) {
                foreach (self::fileUidsFromDetails($details[$nest]) as $uid) {
                    $uids[] = $uid;
                }
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * " Records: pages 145 "AI Universe vs Symfony"; tt_content 812" for an applied change, so the
     * model can use a new record's uid (e.g. as pid of its content) without searching for it.
     *
     * @param array<string, mixed> $meta meta of a readback_result message
     */
    public static function appliedRecordsNote(array $meta): string
    {
        $records = [];
        foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $entry) {
            if (!is_array($entry) || (int) ($entry['uid'] ?? 0) <= 0) {
                continue;
            }
            $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
            $title = '';
            foreach (['title', 'header', 'name'] as $field) {
                if (is_scalar($values[$field] ?? null) && trim((string) $values[$field]) !== '') {
                    $title = trim((string) $values[$field]);
                    break;
                }
            }
            $pid = is_scalar($values['pid'] ?? null) ? (int) $values['pid'] : 0;
            $records[] = trim(sprintf(
                '%s uid %d%s%s',
                (string) ($entry['table'] ?? ''),
                (int) $entry['uid'],
                $title !== '' ? ' "' . self::shorten($title, 80) . '"' : '',
                $pid > 0 ? ' (pid ' . $pid . ')' : '',
            ));
            if (count($records) >= 10) {
                break;
            }
        }

        return $records !== [] ? ' Records: ' . implode('; ', $records) . '.' : '';
    }

    /** Keys of a tool result that identify what was created or changed. */
    private const RESULT_ID_KEYS = [
        'table', 'uid', 'pid', 'pageId', 'pageUid', 'contentUid', 'contentElementUid', 'elementId',
        'recordUid', 'newUid', 'fileUid', 'referenceUids', 'languageId', 'sysLanguageUid',
    ];

    /**
     * " Ids: table tt_content, uid 812, pid 128" from a confirmed tool's result, so the next step
     * (e.g. attaching an image to the new element) uses the right record.
     */
    public static function resultRecordsNote(mixed $details): string
    {
        if (!is_array($details)) {
            return '';
        }
        $sources = [$details];
        foreach (['record', 'result', 'data', 'contentElement', 'page', 'file'] as $key) {
            if (is_array($details[$key] ?? null) && !array_is_list($details[$key])) {
                $sources[] = $details[$key];
            }
        }
        $parts = [];
        foreach ($sources as $source) {
            foreach (self::RESULT_ID_KEYS as $key) {
                $value = $source[$key] ?? null;
                if (is_array($value) && array_is_list($value)) {
                    $value = implode(',', array_filter($value, 'is_scalar'));
                }
                if ((is_int($value) || (is_string($value) && $value !== '' && mb_strlen($value) <= 60)) && !isset($parts[$key])) {
                    $parts[$key] = $key . ' ' . $value;
                }
            }
        }

        return $parts !== [] ? ' Ids: ' . implode(', ', array_slice($parts, 0, 8)) . '.' : '';
    }

    private static function shorten(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '…' : $text;
    }

    /**
     * History note for a draft card. Omits the generic "Review the proposed changes…" body so the
     * model does not learn to parrot that UI copy as a final answer.
     */
    public static function preparedChangeHistoryNote(string $label, string $status, string $body): string
    {
        $prefix = sprintf('[Prepared change: %s — %s]', $label, $status);
        $body = trim($body);
        if ($body === '' || self::isDraftReviewBoilerplate($body)) {
            return $prefix;
        }

        return $prefix . ' ' . $body;
    }

    /**
     * True when text is (or contains) an internal draft-history note, not a real editor-facing reply.
     */
    public static function isCardHistoryEcho(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match('/\[Prepare[d]? change\b/i', $text) === 1) {
            return true;
        }
        if (preg_match('/\bthis draft has not been applied\b/i', $text) === 1) {
            return true;
        }

        return self::isDraftReviewBoilerplate($text);
    }

    /**
     * Model invented a bracketed tool-result line ("[Remove from translation queue] Removed…")
     * without calling a tool this turn.
     */
    public static function isFabricatedToolResultEcho(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || self::isCardHistoryEcho($text)) {
            return false;
        }

        // History replays tool results as "[Label] …". Models copy that when they skip the tool.
        return preg_match(
            '/^\[[^\]\n]{3,80}\]\s+.+/u',
            $text,
        ) === 1;
    }

    /**
     * After a real tool call, models sometimes paste "[Search pages] … [3]The page is already…"
     * into the final answer with no space. Keep the human sentence; drop the glued prefix.
     */
    public static function stripGluedToolResultPrefix(string $text): string
    {
        $text = trim($text);
        if ($text === '' || preg_match('/^\[[^\]\n]{3,80}\]\s+/u', $text) !== 1) {
            return $text;
        }

        // "[Label] …uid]Next sentence" — capital letter glued after a closing bracket.
        if (preg_match('/^\[[^\]\n]{3,80}\]\s+.+\[[0-9]+\]([A-ZÀ-ÖØ-Þ].+)$/su', $text, $match) === 1) {
            return trim($match[1]);
        }
        // "[Label] …factsNext sentence" — lowercase/punct glued to a new capital sentence.
        if (preg_match('/^\[[^\]\n]{3,80}\]\s+.+[a-z0-9.→»…]([A-ZÀ-ÖØ-Þ].+)$/su', $text, $match) === 1) {
            return trim($match[1]);
        }

        return $text;
    }

    /**
     * What the editor must be told about a made image (a cap such as "4 of the 6", or another generator that was
     * used) but the model's reply left out. Only the first reply after the image was made owes it.
     *
     * @param list<array<string, mixed>> $messages history followed by this turn's messages, oldest first
     */
    public static function imageNoticeOwed(array $messages, string $replyText): string
    {
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $meta = is_array($messages[$i]['meta'] ?? null) ? $messages[$i]['meta'] : [];
            $type = (string) ($meta['type'] ?? '');
            if ($type === 'nl_reply') {
                return '';
            }
            if ($type !== 'tool_result' || (string) ($meta['tool'] ?? '') !== 't3ai_generate_image') {
                continue;
            }
            if (($meta['success'] ?? true) === false) {
                return '';
            }
            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            $notice = trim((string) ($details['notice'] ?? ''));
            if ($notice === '' || mb_stripos($replyText, mb_substr($notice, 0, 30)) !== false) {
                return '';
            }

            return $notice;
        }

        return '';
    }

    public static function isDraftReviewBoilerplate(string $text): bool
    {
        return preg_match(
            '/^Review (?:the proposed changes for .+|this change) before anything is written\.?$/iu',
            trim($text),
        ) === 1;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $meta
     */
    private function historyContent(array $entry, array $meta): string
    {
        foreach (['llmSummary', 'summary'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '') {
                return trim($meta[$key]);
            }
        }

        return trim((string) ($entry['content'] ?? ''));
    }
}
