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

namespace NITSAN\NsT3AF\Agent\Runtime;

use NITSAN\NsT3AF\Agent\Service\AgentAttachTargetRequest;
use NITSAN\NsT3AF\Agent\Service\AgentPlan;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentRequestedFiles;
use NITSAN\NsT3AF\Agent\Service\AgentRunner;
use NITSAN\NsT3AF\Agent\Service\AgentToolSearch;
use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use NITSAN\NsT3AF\Agent\Service\GlossarySaveLanguage;
use NITSAN\NsT3AF\Agent\Service\PageCreateAfter;
use NITSAN\NsT3AF\Agent\Service\PageCreateBefore;
use NITSAN\NsT3AF\Agent\Service\RequestedLanguage;
use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Tool\Agent\AskClarificationTool;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The editor's permitted MCP tools for one agent turn, as a Symfony AI toolbox.
 *
 * The model starts with the core set ({@see \NITSAN\NsT3AF\Agent\Service\AgentCoreToolSet})
 * plus {@see self::FIND_TOOLS}, which searches all tools the editor may use and adds the
 * matches for the next round ({@see GovernedPlatform} re-reads the tool list every round).
 *
 * Execution goes through the tool turn executor, which enforces AI Access, previews writes
 * as drafts and renders the chat message. This class adds the per-turn rules:
 *
 * - only tools of the editor's catalog can run; anything else is answered with "not available",
 * - arguments are checked against the tool's JSON schema first; errors go back to the model,
 * - read and write budgets per turn,
 * - after a message that needs the editor (draft review, blocked, budget), the remaining
 *   calls are skipped and {@see AgentTurnState::pause()} ends the loop.
 *
 * Tools with an unknown severity count as writes.
 *
 * @internal
 */
final class T3afToolbox implements ToolboxInterface
{
    public const FIND_TOOLS = 'find_tools';

    public const ASK_CLARIFICATION = 'ask_clarification';

    public const UPDATE_PLAN = 'update_plan';

    private const PLAN_MAX_STEPS = 10;

    private const PLAN_STATUSES = ['pending', 'in_progress', 'completed'];

    public const MAX_TOOL_SEARCHES = 3;

    /** Refused reads over the budget before the turn pauses; the earlier ones ask the model to use what it has. */
    public const READ_REFUSALS_BEFORE_PAUSE = 3;

    /** Repeated identical reads answered from the first result before the turn stops. */
    public const REPEATED_READS_BEFORE_STOP = 3;

    private const FOUND_TOOLS_LIMIT = 5;

    /** Characters of tool data the model reads back (≈ 1500 tokens). */
    private const MODEL_RESULT_CHARS = 6000;

    /** @var array<string, AiToolDefinition> */
    private array $definitions = [];

    /** @var array<string, array<string, mixed>> the editor's whole executable catalog */
    private array $catalog = [];

    private int $searches = 0;

    /**
     * @param list<AiToolDefinition> $definitions tools offered at the start of the turn
     * @param list<array<string, mixed>> $catalog all executable catalog entries (searchable)
     * @param array<string, string> $severities tool name → ToolSeverity value
     */
    public function __construct(
        array $definitions,
        array $catalog,
        private readonly array $severities,
        private readonly AgentTurnState $state,
        private readonly AgentToolRuntime $runtime,
    ) {
        foreach ($catalog as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name !== '' && $name !== self::FIND_TOOLS) {
                $this->catalog[$name] = $entry;
            }
        }
        foreach ($definitions as $definition) {
            if (isset($this->catalog[$definition->name])) {
                $this->definitions[$definition->name] = $definition;
            }
        }
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->definitions as $name => $definition) {
            $tools[] = $this->tool($name, $definition->description, $definition->parameters);
        }
        if (count($this->catalog) > count($this->definitions)) {
            $tools[] = $this->tool(self::FIND_TOOLS, self::findToolsDescription(), self::findToolsParameters());
        }

        $tools[] = $this->tool(self::UPDATE_PLAN, self::updatePlanDescription(), self::updatePlanParameters());

        return $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $toolCall = $this->answeredImageTextQuestion($this->withoutInventedPageSpeedUrl(self::withoutGuessedLanguageUid($toolCall)));
        $outcome = 'executed';
        $result = $this->run($toolCall, $outcome);
        // For the eval and debugging: every call the model made, also the ones that did not run.
        $this->state->emit('tool_call', [
            'tool' => $toolCall->getName(),
            'arguments' => $toolCall->getArguments(),
            'outcome' => $outcome,
        ]);

        return $result;
    }

    /**
     * The editor clicked an option of the agent's image-text question ("Regenerate all", or one of several
     * files with the same name). That click means the image-text tool for that file, whatever the model called.
     */
    private function answeredImageTextQuestion(ToolCall $toolCall): ToolCall
    {
        if ($toolCall->getName() === self::UPDATE_PLAN) {
            return $toolCall;
        }
        $answer = AgentToolTurnProcessor::imageTextAnswer(
            trim((string) ($this->runtime->body['userMessage'] ?? '')),
            $this->conversationHistory(),
        );
        $tool = (string) ($answer['tool'] ?? '');
        $target = $answer['arguments'] ?? null;
        if ($target === null || !isset($this->catalog[$tool])) {
            return $toolCall;
        }

        $arguments = $toolCall->getName() === $tool ? $toolCall->getArguments() : [];
        unset($arguments['fileUrl'], $arguments['altText'], $arguments['title'], $arguments['description']);
        if (isset($target['languageUid'])) {
            unset($arguments['isoCode']);
        }

        return new ToolCall($toolCall->getId(), $tool, array_merge($arguments, $target));
    }

    /**
     * "Check the page speed of this page": the model fills url with a made-up address ("https://your-site.com")
     * and PageSpeed then scores a stranger's site. A url whose host the editor never wrote is dropped, so the
     * tool checks the current page (pageId).
     */
    private function withoutInventedPageSpeedUrl(ToolCall $toolCall): ToolCall
    {
        $arguments = $toolCall->getArguments();
        $host = strtolower((string) parse_url(trim((string) ($arguments['url'] ?? '')), PHP_URL_HOST));
        if ($toolCall->getName() !== 't3aa_get_page_speed' || $host === '') {
            return $toolCall;
        }
        $written = (string) ($this->runtime->body['requestQuery'] ?? '');
        foreach ($this->conversationHistory() as $entry) {
            if (($entry['role'] ?? '') === 'user') {
                $written .= ' ' . (string) ($entry['content'] ?? '');
            }
        }
        if (str_contains(strtolower($written), $host)) {
            return $toolCall;
        }
        unset($arguments['url']);

        return new ToolCall($toolCall->getId(), $toolCall->getName(), $arguments);
    }

    /**
     * The record the model wants to write is headed "Voice-over" (also inside a uid-keyed map of records).
     * A text element with other content is a normal write, even in a request that also asks for a voice-over.
     */
    private static function writesVoiceOverHeader(mixed $data): bool
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            return false;
        }
        foreach (['header', 'subheader'] as $field) {
            if (is_string($data[$field] ?? null) && preg_match(AgentRunner::VOICE_OVER_REQUEST, $data[$field]) === 1) {
                return true;
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && self::writesVoiceOverHeader($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The write attaches a file to the record (assets, media, image), which is how an audio file ends up on a text element.
     */
    private static function writesMediaField(mixed $data): bool
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            return false;
        }
        foreach ($data as $key => $value) {
            if (in_array((string) $key, ['assets', 'media', 'image'], true) && $value !== '' && $value !== null && $value !== []) {
                return true;
            }
            if (is_array($value) && self::writesMediaField($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The image-text tools do not show languageUid to the model (it picks the language by isoCode),
     * so a languageUid it sends anyway is a guess.
     */
    private static function withoutGuessedLanguageUid(ToolCall $toolCall): ToolCall
    {
        $arguments = $toolCall->getArguments();
        if (!in_array($toolCall->getName(), ['t3aa_get_file_metadata', 't3aa_update_file_metadata'], true) || !array_key_exists('languageUid', $arguments)) {
            return $toolCall;
        }
        unset($arguments['languageUid']);

        return new ToolCall($toolCall->getId(), $toolCall->getName(), $arguments);
    }

    /**
     * @param string $outcome executed | paused | unavailable | invalid | repeated | refused | stopped
     */
    private function run(ToolCall $toolCall, string &$outcome): ToolResult
    {
        $name = $toolCall->getName();

        // A plan update is not a change: it also counts after a step paused the turn for review.
        if ($name === self::UPDATE_PLAN) {
            return new ToolResult($toolCall, $this->updatePlan($toolCall->getArguments()));
        }
        if ($this->state->isPaused()) {
            $outcome = 'paused';

            return new ToolResult($toolCall, 'Not executed: the editor has to review the previous step first.');
        }
        if ($name === self::FIND_TOOLS) {
            return new ToolResult($toolCall, $this->findTools((string) ($toolCall->getArguments()['query'] ?? '')));
        }
        $requestQuery = trim((string) ($this->runtime->body['requestQuery'] ?? ''));
        if ($name === 'pages_move' && (PageCreateBefore::isCreateBefore($requestQuery) || PageCreateAfter::isCreateAfter($requestQuery) || PageCreateAfter::isLastUnder($requestQuery))) {
            $outcome = 'invalid';
            $place = PageCreateAfter::isCreateAfter($requestQuery) || PageCreateAfter::isLastUnder($requestQuery) ? 'afterUid' : 'beforeUid';

            return new ToolResult(
                $toolCall,
                'Not executed: this creates a new page. Call write_table with action create, tableName pages, and ' . $place . ' of the page that sets the place. Do not call pages_move.',
            );
        }
        // Asked for an AI-generated image, the model otherwise uploads a stock photo from a URL it made up.
        if ($name === 'file_upload_from_url' && AgentRequestedFiles::asksForGeneratedImage($requestQuery) && !AgentRequestedFiles::namesUrl($requestQuery)) {
            $outcome = 'invalid';

            return new ToolResult($toolCall, isset($this->catalog[AgentRequestedFiles::GENERATION_TOOL])
                ? 'Not executed: the editor asked for an AI-generated image. Call ' . AgentRequestedFiles::GENERATION_TOOL
                    . ' with a description of the image and a short altText, then file_reference_add with the returned fileUid.'
                    . ' Never use a stock photo or an image URL you found or made up.'
                : 'Not executed: the editor asked for an AI-generated image, and AI image generation is not available to this editor.'
                    . ' Do not upload a web image instead: do the rest of the request and tell the editor in one sentence that the image could not be generated.');
        }
        // The image-text tools find a named file in any folder; browsing for it only costs steps.
        $namedFile = in_array($name, ['file_list', 'file_get_info'], true)
            ? AgentToolTurnProcessor::requestedFileName($requestQuery)
            : null;
        if (
            $namedFile !== null
            && isset($this->catalog['t3aa_update_file_metadata'])
            && !in_array('t3aa_update_file_metadata', AgentRunner::toolsNotAskedFor($this->gateRequest()), true)
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor named the file "' . $namedFile . '". t3aa_get_file_metadata and t3aa_update_file_metadata'
                    . ' take fileUrl "' . $namedFile . '" and find it in any folder. Call the one the request needs with that fileUrl;'
                    . ' for texts in another language add isoCode.',
            );
        }
        $describedFile = $name === AgentRequestedFiles::GENERATION_TOOL
            ? AgentToolTurnProcessor::requestedFileName((string) ($toolCall->getArguments()['prompt'] ?? ''))
            : null;
        $conversationHistory = $this->conversationHistory();
        if ($describedFile !== null && isset($this->catalog['t3aa_update_file_metadata'])) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the prompt is about the existing file "' . $describedFile . '", and this tool draws a new picture.'
                    . ' For its alt text, title or description call t3aa_update_file_metadata with fileUrl "' . $describedFile . '"'
                    . ' (isoCode for another language).',
            );
        }
        if (
            $name === AgentRequestedFiles::GENERATION_TOOL
            && isset($this->catalog['t3aa_update_file_metadata'])
            && AgentRunner::usedRecently('t3aa_update_file_metadata', $conversationHistory)
            && !AgentRequestedFiles::asksForGeneratedImage($requestQuery)
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor is working on the texts of an existing image and did not ask for a new picture.'
                    . ' Call t3aa_update_file_metadata for that file (isoCode for another language).',
            );
        }
        if (
            in_array($name, ['t3ai_generate_all_seo', 't3ai_generate_seo_batch', 't3aa_generate_voice_over'], true)
            && (
                preg_match('/\b(picture|pictures|image|images|photo|photos|illustration|bild\w*|foto\w*|grafik\w*)\b/iu', $requestQuery) === 1
                || AgentPromptBuilder::pendingImageAttachNote($conversationHistory) !== ''
            )
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked for a picture, not SEO or voice-over. Call t3ai_generate_image (then file_reference_add), or wait for the open generate card to be applied.',
            );
        }
        // A voice-over is the voice-over tool. write_table otherwise saves a Text & Media element titled "Voice-over".
        if (
            $name === 'write_table'
            && isset($this->catalog['t3aa_generate_voice_over'])
            && (
                self::writesVoiceOverHeader($toolCall->getArguments()['data'] ?? null)
                || (
                    preg_match(AgentRunner::VOICE_OVER_REQUEST, $requestQuery) === 1
                    && self::writesMediaField($toolCall->getArguments()['data'] ?? null)
                )
            )
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: a voice-over is not a normal content element. Call t3aa_generate_voice_over for this page and, when the editor named a language, pass that languageUid.'
                    . ' The tool stores the audio on the page and places the Voiceover element. Do not create Text & Media, and do not add the voice-over onto an existing text element.',
            );
        }
        // "Add this image to the news X" must attach, not create a duplicate news/CE.
        if (
            $name === 'write_table'
            && strtolower((string) ($toolCall->getArguments()['action'] ?? 'create')) === 'create'
            && AgentRequestedFiles::asksToAttachOrPlaceFile($requestQuery)
            && AgentAttachTargetRequest::parse($requestQuery) !== null
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor named an existing record to attach the image to. Call file_reference_add for that record (news: fal_media, textmedia: assets). Do not create a new record.',
            );
        }
        // EXT:news missing: do not search or write the news table, and do not run news MCP tools.
        if (!\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('news')) {
            $newsTable = strtolower((string) ($toolCall->getArguments()['tableName'] ?? $toolCall->getArguments()['table'] ?? ''));
            $isNewsMcp = str_contains($name, 'news') && str_starts_with($name, 't3ai_');
            if (
                $isNewsMcp
                || $newsTable === 'tx_news_domain_model_news'
                || (
                    in_array($name, ['record_search', 'write_table', 'records_apply'], true)
                    && AgentRunner::isNewsRelatedRequest($requestQuery)
                )
            ) {
                $outcome = 'invalid';

                return new ToolResult(
                    $toolCall,
                    'Not executed: News isn\'t available on this site (EXT:news is not installed).',
                );
            }
        }
        // "Generiere eine Nachricht ..." is a news article. The model otherwise answers it with SEO or schema markup.
        if (
            AgentRunner::isNewsCreateRequest($requestQuery)
            && in_array($name, ['t3ai_generate_all_seo', 't3ai_generate_seo_batch', 't3ai_apply_schema_markup', 't3ai_generate_keywords', 't3ai_generate_meta_description'], true)
            && preg_match('/\b(seo|meta|keywords?|schema|structured\s+data|suchmaschinen\w*|metadaten|og\s*(?:title|desc\w*))\b/iu', $requestQuery) !== 1
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked for a news article, not SEO. Call write_table with action create on tx_news_domain_model_news, with title, teaser and bodytext in the same call.',
            );
        }
        // "Generate SEO for all pages" is a batch over the pages, not the one page that is open.
        if ($name === 't3ai_generate_all_seo' && AgentRunner::asksSeoForAllPages($requestQuery)) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked for SEO for all pages, not for the open page. Call pages_tree (or pages_list) for the page uids, then t3ai_generate_seo_batch with pageIds of those pages. With more than 20 pages, ask first whether to queue them instead (t3ai_mass_seo_queue_add with pageIds).',
            );
        }
        // "Make the German version visible" changes the hidden field. The model sometimes tries a delete first.
        if ($name === 'content_delete' && AgentRunner::isVisibilityRequest($requestQuery)) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked to show or hide something, not to delete it. Change the hidden field with write_table action update (page and its content elements), never delete.',
            );
        }
        // Show/hide (not bare "publish"): use write_table hidden, never a workspace review stage.
        // Bare publish/unpublish may still use workspace_stage_set.
        if (
            $name === 'workspace_stage_set'
            && AgentRunner::isVisibilityRequest($requestQuery)
            && preg_match('/\b(visible|invisible|hidden|unhide|hide|sichtbar|unsichtbar|ausblend\w*|einblend\w*|verstecke\w*|verberg\w*)\b/iu', $requestQuery) === 1
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked to make a page or content visible or hidden, not to change a workspace review stage. Call write_table action update with data {"hidden": 0} (or 1) on the translation page uid and its hidden content elements.',
            );
        }
        // "Translate the first element into French" is one element, not the whole page.
        if ($name === 't3ai_translate_page' && AgentRunner::asksForOneElementTranslation($requestQuery)) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked for one content element, not the whole page. Call t3ai_translate_content with contentUid of that element (content_list on the open page gives the order) and targetLanguageUid of the language named in the request.',
            );
        }
        // "Generate a Person schema" is schema markup for a page. The model otherwise tries to create a folder or a content element.
        if (
            AgentRunner::isSchemaRequest($requestQuery)
            && preg_match('/\b(folders?|director(?:y|ies)|ordner|verzeichnis\w*|content\s+element|inhaltselement|absatz|paragraph)\b/iu', $requestQuery) !== 1
        ) {
            if (str_starts_with($name, 'directory_')) {
                $outcome = 'invalid';

                return new ToolResult(
                    $toolCall,
                    'Not executed: the editor asked for schema markup, not a folder. Call t3ai_apply_schema_markup with schemaType (for example Person) for the open page.',
                );
            }
            if ($name === 't3ai_create_content_element'
                || (
                    $name === 'write_table'
                    && strtolower((string) ($toolCall->getArguments()['action'] ?? 'create')) === 'create'
                    && strtolower((string) ($toolCall->getArguments()['tableName'] ?? $toolCall->getArguments()['table'] ?? '')) === 'tt_content'
                )
            ) {
                $outcome = 'invalid';

                return new ToolResult(
                    $toolCall,
                    'Not executed: the editor asked for schema markup, not a content element. Call t3ai_apply_schema_markup with schemaType (for example Person) for the open page; do not ask which page when one is open.',
                );
            }
        }
        // A news article without a picture request is not answered with an image card.
        if (
            $name === 't3ai_generate_image'
            && AgentRunner::isNewsCreateRequest($requestQuery)
            && preg_match('/\b(pictures?|images?|photos?|illustrations?|bild\w*|foto\w*|grafik\w*)\b/iu', $requestQuery) !== 1
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked for a news article, not a picture. Call write_table with action create on tx_news_domain_model_news, with title, teaser and bodytext in the same call.',
            );
        }
        // A news request answered with a content element or a page (for example on a folder). News lives in its own table.
        if (
            $name === 'write_table'
            && strtolower((string) ($toolCall->getArguments()['action'] ?? 'create')) === 'create'
            && AgentRunner::isNewsCreateRequest($requestQuery)
            && AgentRunner::isNewsRequestAnsweredWithOtherTable(
                (string) ($toolCall->getArguments()['tableName'] ?? $toolCall->getArguments()['table'] ?? ''),
                $requestQuery,
            )
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor asked for a news article, not a content element or a page. Call write_table with action create on tx_news_domain_model_news, with title, teaser and bodytext in the same call. Omit pid; the tool picks the news storage.',
            );
        }
        // "Add an intro paragraph to this page" is a content element. The model otherwise drafts a whole new page.
        if (
            $requestQuery !== ''
            && in_array($name, AgentRunner::PAGE_CREATE_TOOL_NAMES, true)
            && !AgentRunner::asksForNewPage($requestQuery)
        ) {
            $outcome = 'invalid';

            return new ToolResult(
                $toolCall,
                'Not executed: the editor did not ask for a new page. Add the text to the open page with write_table (action create, tableName tt_content, CType text, header and bodytext in the same call).',
            );
        }
        if ($name === 'file_reference_add') {
            $attachUid = (int) ($toolCall->getArguments()['uid'] ?? 0);
            if ($attachUid <= 0) {
                $outcome = 'invalid';

                return new ToolResult(
                    $toolCall,
                    'Not executed: file_reference_add needs the uid of an existing record (tt_content or news). '
                    . 'To put the image on a new Text & Media element, create that element with write_table first '
                    . '(the image attaches after Apply). To attach to an existing element, pass that element\'s uid (> 0).',
                );
            }
            if (AgentPromptBuilder::hasOpenFileReferenceDraft($conversationHistory)) {
                $outcome = 'invalid';

                return new ToolResult(
                    $toolCall,
                    'Not executed: an attach card is already open for the editor. Wait until they Apply or Decline it. Do not prepare another attach.',
                );
            }
            if (AgentPromptBuilder::pendingAttachFileUids($conversationHistory, $this->gateRequest()) === []) {
                $outcome = 'invalid';

                return new ToolResult(
                    $toolCall,
                    'Not executed: there is no file left to attach from this conversation (uploads, generated images, or named sys_file uids). Do not claim the image is already attached — call file_reference_list if you need to check.',
                );
            }
        }
        if (!isset($this->catalog[$name])) {
            $outcome = 'unavailable';

            return new ToolResult($toolCall, sprintf('Tool "%s" is not available to this editor.', $name));
        }
        // A permitted tool the model knows from earlier turns: offer it from now on.
        $definition = $this->definitions[$name] ?? $this->addDefinitions([$this->catalog[$name]])[0] ?? null;
        if ($definition === null) {
            $outcome = 'unavailable';

            return new ToolResult($toolCall, sprintf('Tool "%s" is not available to this editor.', $name));
        }

        $validation = $this->runtime->argumentValidator->validate($definition->parameters, $toolCall->getArguments());
        if ($validation['errors'] !== []) {
            $this->state->trace[] = ['tool' => $name, 'invalidArguments' => $validation['errors']];
            $outcome = 'invalid';

            return new ToolResult($toolCall, sprintf(
                'Not executed: invalid arguments for %s. %s Correct the arguments and call the tool again.',
                $name,
                implode(' ', $validation['errors']),
            ));
        }

        if ($name === self::ASK_CLARIFICATION) {
            return new ToolResult($toolCall, $this->askClarification($this->withoutUnavailableLanguages($validation['arguments'])));
        }

        // The same read with the same arguments: answer from the first call, no budget used.
        $isRead = $this->severityOf($name) === ToolSeverity::Read->value;
        $readKey = $isRead ? self::readKey($name, $validation['arguments']) : '';
        if ($isRead && isset($this->state->readResults[$readKey])) {
            ++$this->state->repeatedReads;
            if ($this->state->repeatedReads > self::REPEATED_READS_BEFORE_STOP) {
                // The model goes in circles: stop and tell the editor instead of running into the loop limit.
                $this->state->addMessage([
                    'role' => 'assistant',
                    'content' => $this->runtime->translator->translate('agent.turn.repeatedReads'),
                    'meta' => ['type' => 'info', 'correlationId' => $this->state->correlationId, 'orchestratorPause' => true],
                ]);
                $this->state->pause('repeated_reads');
                $outcome = 'stopped';

                return new ToolResult($toolCall, 'Not executed: the same reads were repeated too often. The turn ends.');
            }

            $outcome = 'repeated';

            return new ToolResult(
                $toolCall,
                'Already read in this turn, same result: ' . $this->state->readResults[$readKey]
                . ' Do not call it again. You have everything you need: call the write tool that makes the change'
                . ' (if none is offered, call find_tools with what should be done), or answer the editor now.',
            );
        }

        $budgetMessage = $this->consumeBudget($name);
        if ($budgetMessage !== null) {
            // First refusals go back to the model, so it answers or prepares the change with
            // what it already has. Only a model that keeps reading pauses the turn.
            $outcome = 'refused';
            if ($isRead && ++$this->state->budgetRefusals < self::READ_REFUSALS_BEFORE_PAUSE) {
                return new ToolResult(
                    $toolCall,
                    'Not executed: the read limit of this turn is reached. Do not read more.'
                    . ' Use what you already have: call the write tool that prepares the change the editor asked for,'
                    . ' or answer the editor now.',
                );
            }
            $this->state->addMessage($budgetMessage);
            $this->state->pause((string) $budgetMessage['meta']['budgetKind'] . '_budget');

            return new ToolResult($toolCall, 'Not executed: the tool budget of this turn is used up.');
        }

        $this->state->emit('progress', ['status' => 'tool', 'tool' => $name, 'label' => $this->editorLabel($name)]);

        $body = $this->runtime->body;
        $body['arguments'] = $validation['arguments'];
        $body['conversationHistory'] = $this->conversationHistory();
        $body['skipLlmSummary'] = true;
        $message = $this->runtime->executor->execute($name, $this->runtime->context, $body, $this->runtime->user, $this->state->correlationId);

        if (isset($message['meta']['trace']) && is_array($message['meta']['trace'])) {
            $this->state->trace = array_merge($this->state->trace, array_values($message['meta']['trace']));
        }
        $this->state->executedTools[] = $name;
        // Cards from a natural-language turn continue the turn after the editor confirms / declines.
        $message['meta']['fromRunner'] = true;
        if (self::isRepeatedPlanFailure($message, $this->state->messages)) {
            $this->state->pause('plan_invalid');
            $outcome = 'stopped';

            return new ToolResult(
                $toolCall,
                'Not executed: this call already failed for the same reason. Do not call it again. The turn ends.',
            );
        }
        $this->state->addMessage($message);
        // The step that was running failed: the Progress list shows it instead of spinning on.
        if (($message['meta']['type'] ?? '') === 'error' && $this->state->plan !== []) {
            $this->state->setPlan(AgentPlan::failCurrent($this->state->plan));
            $this->state->attachPendingPlan();
        }

        $pauseReason = $this->runtime->pausePolicy->pauseReason($message);
        if ($pauseReason !== null) {
            $this->state->pause($pauseReason);
        }

        $forModel = $this->summaryForModel($message);
        if ($isRead) {
            $this->state->readResults[$readKey] = $forModel;
        }

        return new ToolResult($toolCall, $forModel);
    }

    /**
     * The editor's request this turn works on (the original one during a confirm/decline continuation).
     */
    private function gateRequest(): string
    {
        $request = trim((string) ($this->runtime->body['requestForGate'] ?? ''));

        return $request !== '' ? $request : trim((string) ($this->runtime->body['requestQuery'] ?? ''));
    }

    /**
     * Prior conversation plus messages produced in this turn (readbacks, open drafts, applied tools).
     *
     * @return list<array<string, mixed>>
     */
    private function conversationHistory(): array
    {
        $prior = is_array($this->runtime->body['conversationHistory'] ?? null)
            ? $this->runtime->body['conversationHistory']
            : [];
        $merged = [];
        foreach ([...array_values($prior), ...$this->state->messages] as $entry) {
            if (is_array($entry)) {
                $merged[] = $entry;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function readKey(string $name, array $arguments): string
    {
        ksort($arguments);

        return $name . ':' . json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The same failed prepare was already shown. A later identical failure must not add another message.
     *
     * @param array<string, mixed> $message
     * @param list<array<string, mixed>> $messages
     */
    public static function isRepeatedPlanFailure(array $message, array $messages): bool
    {
        $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
        if (($meta['type'] ?? '') !== 'tool_result' || ($meta['success'] ?? true) !== false) {
            return false;
        }

        $tool = trim((string) ($meta['tool'] ?? ''));
        $error = trim((string) ($meta['error'] ?? ''));
        if ($tool === '' || $error === '') {
            return false;
        }

        foreach ($messages as $existing) {
            $existingMeta = is_array($existing['meta'] ?? null) ? $existing['meta'] : [];
            if (
                ($existingMeta['type'] ?? '') === 'tool_result'
                && trim((string) ($existingMeta['tool'] ?? '')) === $tool
                && trim((string) ($existingMeta['error'] ?? '')) === $error
            ) {
                return true;
            }
        }

        return false;
    }

    public static function findToolsDescription(): string
    {
        return 'Search all TYPO3 backend tools this editor may use, by what should be done (any language).'
            . ' Use it when none of the offered tools fits the request. The found tools can be called in the next step.';
    }

    public static function updatePlanDescription(): string
    {
        return 'Show the editor your plan for a request that needs three or more steps, and keep it up to date.'
            . ' Send the complete list every time: one step in_progress, finished steps completed, the rest pending.'
            . ' Call it first, then again whenever a step is done. Do not use it for simple requests.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function updatePlanParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'steps' => [
                    'type' => 'array',
                    'description' => 'All steps in order, each a short phrase in the editor\'s language (max ' . self::PLAN_MAX_STEPS . ').',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => self::PLAN_STATUSES],
                        ],
                        'required' => ['title', 'status'],
                    ],
                ],
            ],
            'required' => ['steps'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function updatePlan(array $arguments): string
    {
        $raw = $arguments['steps'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $steps = [];
        $active = false;
        foreach (is_array($raw) ? $raw : [] as $step) {
            if (is_string($step)) {
                $step = ['title' => $step];
            }
            if (!is_array($step)) {
                continue;
            }
            $title = trim((string) ($step['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $status = (string) ($step['status'] ?? 'pending');
            $status = in_array($status, self::PLAN_STATUSES, true) ? $status : 'pending';
            // One step at a time is in progress.
            if ($status === 'in_progress') {
                $status = $active ? 'pending' : 'in_progress';
                $active = true;
            }
            $steps[] = ['title' => mb_substr($title, 0, 120), 'status' => $status];
            if (count($steps) >= self::PLAN_MAX_STEPS) {
                break;
            }
        }
        $this->state->setPlan($steps);

        return $steps === []
            ? 'Plan cleared.'
            : 'Plan saved. Carry on with the step that is in progress and update the plan when it is done.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function findToolsParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What the editor wants to do, in a few words, e.g. "translate news record" or "Alt-Texte für Bilder".',
                ],
            ],
            'required' => ['query'],
        ];
    }

    private function findTools(string $query): string
    {
        if (++$this->searches > self::MAX_TOOL_SEARCHES) {
            return 'Not executed: the tool search was used too often in this turn. Answer with the tools you have.';
        }
        $this->state->emit('progress', [
            'status' => 'tool',
            'tool' => self::FIND_TOOLS,
            'label' => $this->runtime->translator->translate('agent.live.findingTools'),
        ]);

        $candidates = array_values(array_diff_key($this->catalog, $this->definitions));
        $result = $this->runtime->toolSearch->search($query, $candidates, self::FOUND_TOOLS_LIMIT);
        $foundTools = $result['tools'];
        // "Make the page visible" ranks page and cache tools; the record writer is what changes a field.
        if (AgentToolSearch::asksForRecordChange($query)) {
            $foundNames = array_map(static fn(array $tool): string => (string) ($tool['name'] ?? ''), $foundTools);
            foreach ($candidates as $candidate) {
                if (($candidate['name'] ?? '') === 'write_table' && !in_array('write_table', $foundNames, true)) {
                    $foundTools[] = $candidate;
                }
            }
        }
        $found = $this->addDefinitions($foundTools);
        $this->state->trace[] = [
            'tool' => self::FIND_TOOLS,
            'query' => $query,
            'method' => $result['method'],
            'found' => array_map(static fn(AiToolDefinition $definition): string => $definition->name, $found),
        ];
        $this->state->foundTools = [...$this->state->foundTools, ...array_map(
            static fn(AiToolDefinition $definition): string => $definition->name,
            $found,
        )];

        // Checked before the found-tools list, not only when it is empty: embeddings/BM25 almost
        // always surface *some* loosely related core tool for a coherent sentence (e.g. "crawl and
        // index for search" matching cache_clear/file_list), so a strict empty-check rarely fires.
        // A premium match is always safe to prefer here — PremiumCatalogProvider::findMatch()
        // already excludes extensions that are loaded, so if it matches, the real tool for this
        // capability cannot be among $found anyway.
        $premiumMatch = $this->runtime->premiumCatalog->findMatch($query);
        if ($premiumMatch !== null) {
            return $this->runtime->entitlementExplanation->buildNotPurchasedMessage($premiumMatch, $this->runtime->user);
        }

        if ($found === []) {
            return 'No matching tool. Tell the editor in one sentence that this is not possible with the AI Agent here.';
        }

        $lines = ['These tools are now available:'];
        foreach ($found as $definition) {
            $lines[] = sprintf(
                '- %s (%s): %s Parameters: %s',
                $definition->name,
                $this->severityOf($definition->name),
                trim($definition->description),
                $this->describeParameters($definition->parameters),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * A language question lists only the languages of the site; a language the editor asked for that the site
     * does not have is said so instead of being offered as a button.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function withoutUnavailableLanguages(array $arguments): array
    {
        $siteLanguages = GlossarySaveLanguage::siteLanguages($this->runtime->context);
        $options = is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        if ($siteLanguages === [] || $options === []) {
            return $arguments;
        }
        $kept = array_values(array_filter(
            $options,
            static fn(mixed $option): bool => !is_string($option) || !RequestedLanguage::isUnavailableName($option, $siteLanguages),
        ));
        if (count($kept) === count($options)) {
            return $arguments;
        }
        $requestQuery = trim((string) ($this->runtime->body['requestQuery'] ?? ''));
        $missing = RequestedLanguage::unavailable($requestQuery, $siteLanguages);
        if ($missing !== null || $kept === []) {
            $missing ??= 'That language';
            $titles = RequestedLanguage::titles($siteLanguages);
            $arguments['question'] = sprintf(
                "%s isn't a language of this site (available: %s). Nothing was changed. Pick one of the site languages.",
                $missing,
                $titles,
            );
            $arguments['options'] = $kept === [] ? array_values(array_filter(array_map(
                static fn(array $language): string => trim((string) ($language['title'] ?? '')),
                array_filter($siteLanguages, static fn(array $language): bool => (int) ($language['id'] ?? 0) > 0),
            ), static fn(string $title): bool => $title !== '')) : $kept;

            return $arguments;
        }
        $arguments['options'] = $kept;

        return $arguments;
    }

    /**
     * Shows the question with its options as buttons and ends the turn; the editor's answer
     * (typed or clicked) is the next message.
     *
     * @param array<string, mixed> $arguments
     */
    private function askClarification(array $arguments): string
    {
        $question = trim((string) ($arguments['question'] ?? ''));
        $options = AskClarificationTool::normalizeOptions(is_array($arguments['options'] ?? null) ? $arguments['options'] : []);
        $this->state->addMessage([
            'role' => 'assistant',
            'content' => $question,
            'meta' => [
                'type' => 'clarification',
                'tool' => self::ASK_CLARIFICATION,
                'options' => $options,
                'correlationId' => $this->state->correlationId,
                'orchestratorPause' => true,
                'llmSummary' => 'Asked the editor: ' . $question . ($options !== [] ? ' Options: ' . implode(' | ', $options) : ''),
            ],
        ]);
        $this->state->executedTools[] = self::ASK_CLARIFICATION;
        $this->state->pause('clarification');

        return 'The question is shown to the editor. Stop here and wait for the answer.';
    }

    private function editorLabel(string $name): string
    {
        $label = trim((string) ($this->catalog[$name]['editorLabel'] ?? ''));

        return $label !== '' ? $label : $name;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<AiToolDefinition>
     */
    private function addDefinitions(array $entries): array
    {
        $added = [];
        foreach ($this->runtime->definitionMapper->mapExecutableTools($entries) as $definition) {
            if (isset($this->catalog[$definition->name])) {
                $this->definitions[$definition->name] = $definition;
                $added[] = $definition;
            }
        }

        return $added;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function describeParameters(array $parameters): string
    {
        $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
        if ($properties === []) {
            return 'none.';
        }
        $required = is_array($parameters['required'] ?? null) ? $parameters['required'] : [];
        $parts = [];
        foreach ($properties as $name => $definition) {
            $type = is_array($definition) ? (string) ($definition['type'] ?? 'string') : 'string';
            $parts[] = $name . ' (' . $type . (in_array($name, $required, true) ? ', required' : '') . ')';
        }

        return implode(', ', $parts) . '.';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function tool(string $name, string $description, array $parameters): Tool
    {
        // The MCP JSON schema travels in the metadata; GovernedPlatform passes it to the provider as-is.
        return new Tool(
            new ExecutionReference(self::class, 'execute'),
            $name,
            $description,
            null,
            ['severity' => $name === self::FIND_TOOLS || $name === self::UPDATE_PLAN ? ToolSeverity::Read->value : $this->severityOf($name), 'parameters' => $parameters],
        );
    }

    private function severityOf(string $name): string
    {
        return $this->severities[$name] ?? ToolSeverity::Write->value;
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function consumeBudget(string $name): ?array
    {
        if ($this->severityOf($name) === ToolSeverity::Read->value) {
            if ($this->state->readCount >= $this->runtime->maxReads) {
                return $this->budgetMessage('read', $this->runtime->maxReads);
            }
            ++$this->state->readCount;

            return null;
        }

        if ($this->state->writeCount >= $this->runtime->maxWrites) {
            return $this->budgetMessage('write', $this->runtime->maxWrites);
        }
        ++$this->state->writeCount;

        return null;
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function budgetMessage(string $kind, int $limit): array
    {
        return [
            'role' => 'assistant',
            'content' => $this->runtime->translator->translate(
                $kind === 'read' ? 'agent.turn.readBudgetExceeded' : 'agent.turn.writeBudgetExceeded',
                [(string) $limit],
            ),
            'meta' => [
                'type' => 'budget_exceeded',
                'correlationId' => $this->state->correlationId,
                'orchestratorPause' => true,
                'budgetKind' => $kind,
            ],
        ];
    }

    /**
     * @param array{role: string, content: string, meta: array<string, mixed>} $message
     */
    /**
     * What the model reads back from a tool: the short summary plus the data (compact JSON,
     * capped), so it can use names and ids without an extra summarizing call.
     *
     * @param array{role: string, content: string, meta: array<string, mixed>} $message
     */
    private function summaryForModel(array $message): string
    {
        $summary = '';
        foreach (['llmSummary', 'summary'] as $key) {
            $value = $message['meta'][$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $summary = trim($value);
                break;
            }
        }
        if ($summary === '') {
            $summary = trim($message['content']);
        }

        $details = $message['meta']['details'] ?? null;
        if (($message['meta']['type'] ?? '') === 'tool_result' && $details !== null && $details !== [] && $details !== '') {
            try {
                $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = '';
            }
            if ($json !== '' && $json !== 'null') {
                if (mb_strlen($json) > self::MODEL_RESULT_CHARS) {
                    $json = mb_substr($json, 0, self::MODEL_RESULT_CHARS) . '… (truncated)';
                }
                $summary .= ($summary !== '' ? "\n" : '') . 'Data: ' . $json;
            }
        }

        return $summary !== '' ? $summary : 'Done.';
    }
}
