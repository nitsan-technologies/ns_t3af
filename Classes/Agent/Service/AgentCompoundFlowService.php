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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Ordered compound agent flows (translate + SEO, optional page inspect).
 *
 * @internal
 */
final readonly class AgentCompoundFlowService
{
    public function __construct(
        private AgentSeoMetadataFlow $seoMetadataFlow,
        private AgentSchedulerHandoff $schedulerHandoff,
    ) {}

    /**
     * @param list<string> $steps Ordered step ids from {@see AgentNlIntentResolver::resolveCompoundSteps()}
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    public function execute(
        array $steps,
        int $pageId,
        string $correlationId,
        BackendUserAuthentication $user,
    ): array {
        if ($pageId <= 0) {
            return [[
                'role' => 'assistant',
                'content' => $this->translate('agent.starter.generateSeoNeedsPage'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId, 'flow' => 'compound'],
            ]];
        }

        $messages = [];
        $pageInspected = false;

        foreach ($steps as $step) {
            if ($step === AgentNlIntentResolver::STEP_PAGE_INSPECT) {
                $inspectMessages = $this->seoMetadataFlow->execute($pageId, $correlationId, includePageRead: true, draftOnly: true);
                if ($this->isFailureMessages($inspectMessages)) {
                    return $inspectMessages;
                }
                $messages = array_merge($messages, $inspectMessages);
                $pageInspected = true;
                continue;
            }

            if ($step === AgentNlIntentResolver::STEP_TRANSLATE) {
                $handoff = $this->schedulerHandoff->buildTranslateHandoff($pageId, $user);
                $meta = [
                    'type' => 'info',
                    'correlationId' => $correlationId,
                    'flow' => 'compound_translate',
                    'compoundStep' => AgentNlIntentResolver::STEP_TRANSLATE,
                ];
                if ($handoff !== null) {
                    $meta['schedulerHandoff'] = $handoff;
                }
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $this->translate('agent.compound.translateHandoff', [$pageId]),
                    'meta' => $meta,
                ];
                continue;
            }

            if ($step === AgentNlIntentResolver::STEP_SEO_OPTIMIZE) {
                $seoMessages = $this->seoMetadataFlow->execute(
                    $pageId,
                    $correlationId,
                    includePageRead: !$pageInspected,
                    draftOnly: false,
                );
                if ($this->isFailureMessages($seoMessages) && $messages === []) {
                    return $seoMessages;
                }
                foreach ($seoMessages as $seoMessage) {
                    if (is_array($seoMessage['meta'] ?? null)) {
                        $seoMessage['meta']['compoundStep'] = AgentNlIntentResolver::STEP_SEO_OPTIMIZE;
                        $seoMessage['meta']['flow'] = 'compound_seo';
                    }
                    $messages[] = $seoMessage;
                }
            }
        }

        return $messages;
    }

    /**
     * @param list<array{role: string, content: string, meta: array<string, mixed>}> $messages
     */
    private function isFailureMessages(array $messages): bool
    {
        foreach ($messages as $message) {
            $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
            if (($meta['success'] ?? true) === false || ($meta['type'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        $value = $languageService->sL('LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key);
        if ($arguments === []) {
            return $value;
        }

        return sprintf($value, ...array_map(static fn(int|string $argument): string => (string) $argument, $arguments));
    }
}
