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

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Service\SchedulerCliCommandCatalogService;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Offers Scheduler handoff after successful agent writes (T19).
 *
 * @internal
 */
final readonly class AgentSchedulerHandoff
{
    public function __construct(
        private AgentGovernanceGuard $governanceGuard,
        private ModuleTabUtility $moduleTabUtility,
        private UriBuilder $uriBuilder,
        private SchedulerCliCommandCatalogService $commandCatalog,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public function buildHandoffMeta(
        array $tool,
        array $arguments,
        BackendUserAuthentication $user,
        bool $success,
    ): ?array {
        if (!$success) {
            return null;
        }

        $severity = ToolSeverity::tryFromString((string) ($tool['severity'] ?? ''));
        if ($severity !== ToolSeverity::Write) {
            return null;
        }

        return $this->buildRichHandoff(
            (string) ($tool['name'] ?? ''),
            $arguments,
            $user,
            null,
        );
    }

    /**
     * @param array<string, mixed> $applyResult
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public function buildHandoffForApplyResult(
        array $applyResult,
        array $arguments,
        BackendUserAuthentication $user,
        ?string $flow = null,
    ): ?array {
        $toolName = trim((string) ($applyResult['tool'] ?? ''));
        if ($toolName === '') {
            return null;
        }

        return $this->buildRichHandoff($toolName, $arguments, $user, $flow);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    private function buildRichHandoff(
        string $toolName,
        array $arguments,
        BackendUserAuthentication $user,
        ?string $flow = null,
    ): ?array {
        if (!class_exists('TYPO3\\CMS\\Scheduler\\Domain\\Repository\\SchedulerTaskRepository')) {
            return null;
        }

        $cliCommand = $this->resolveSchedulableCommand($toolName);
        if ($cliCommand !== null && $this->commandCatalog->findByCommand($cliCommand) === null) {
            $cliCommand = null;
        }

        $batchLimit = $this->governanceGuard->resolveSchedulerBatchLimit($user);
        $route = $this->moduleTabUtility->routeFor('schedulerCli') ?? 't3af_dashboard.scheduler_cli';
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
        $schedulerRoute = $typo3Version->getMajorVersion() >= 14 ? 'scheduler' : 'scheduler_manage';

        $scheduleParams = ['mode' => 'library'];
        if ($cliCommand !== null) {
            $scheduleParams['command'] = $cliCommand;
        }

        $isSeoFlow = $flow === 'generate_seo_metadata' || str_contains(strtolower($toolName), 'seo');
        $pageCount = $isSeoFlow ? $this->countSitePages() : 0;
        $bodyKey = $isSeoFlow && $pageCount > 1
            ? 'agent.scheduler.handoffBodySeo'
            : 'agent.scheduler.handoffBody';
        $body = $isSeoFlow && $pageCount > 1
            ? $this->translate($bodyKey, [$pageCount])
            : $this->translate($bodyKey);

        return [
            'available' => true,
            'route' => $route,
            'href' => (string) $this->uriBuilder->buildUriFromRoute($route),
            'scheduleHref' => (string) $this->uriBuilder->buildUriFromRoute($route, $scheduleParams),
            'schedulerHref' => (string) $this->uriBuilder->buildUriFromRoute($schedulerRoute),
            'label' => $this->translate('agent.scheduler.handoffLabel'),
            'title' => $this->translate('agent.scheduler.handoffTitle'),
            'body' => $body,
            'dismissLabel' => $this->translate('agent.scheduler.handoffDismiss'),
            'note' => $this->translate('agent.scheduler.handoffNote'),
            'batchLimit' => $batchLimit,
            'tool' => $toolName,
            'cliCommand' => $cliCommand,
            'argumentCount' => count($arguments),
        ];
    }

    private function resolveSchedulableCommand(string $toolName): ?string
    {
        $needle = strtolower($toolName);
        if (str_contains($needle, 'translate') || str_contains($needle, 'translation')) {
            return 't3af:bulk:translate';
        }
        if (str_contains($needle, 'seo') || $needle === 'write_table') {
            return 't3af:bulk:seo-optimize';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildTranslateHandoff(int $pageId, BackendUserAuthentication $user): ?array
    {
        if ($pageId <= 0) {
            return null;
        }

        return $this->buildRichHandoff(
            'translate_page',
            ['uid' => $pageId, 'pageId' => $pageId],
            $user,
            'translate',
        );
    }

    private function countSitePages(): int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable('pages')->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key;
        $value = $languageService instanceof LanguageService
            ? (string) $languageService->sL($label)
            : $key;

        if ($arguments !== [] && $value !== '') {
            return vsprintf($value, $arguments);
        }

        return $value !== '' ? $value : $key;
    }
}
