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
use NITSAN\NsT3AF\Domain\Repository\AgentConversationRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Conversation rows as the agent window shows them (session list, active session, "started on" notice).
 *
 * @internal
 */
final readonly class AgentSessionPresenter
{
    public function __construct(
        private AgentConversationRepository $repository,
        private AgentSettingsService $settings,
        private AgentContextPresenter $contextPresenter,
        private AgentProviderOptions $providerOptions,
    ) {}

    /**
     * @param array<string, mixed> $context current context
     * @return list<array<string, mixed>>
     */
    public function list(BackendUserAuthentication $user, string $filter, array $context, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->repository->listForUser(
            (int) ($user->user['uid'] ?? 0),
            $filter !== 'all',
            $this->settings->getConversationScope(),
            trim((string) ($context['module'] ?? '')),
            (int) ($context['pageId'] ?? 0),
            $limit,
            $offset,
        );

        return array_map(fn(array $row): array => $this->summary($row, $context, $user), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $context current context
     * @return array<string, mixed>
     */
    public function summary(array $row, array $context, BackendUserAuthentication $user): array
    {
        $pageId = (int) ($row['page_id'] ?? 0);
        $module = (string) ($row['module_route'] ?? '');
        $scope = $this->settings->getConversationScope();
        $sameModule = $module === trim((string) ($context['module'] ?? ''));
        $samePage = $pageId === (int) ($context['pageId'] ?? 0);
        $provider = (string) ($row['provider_identifier'] ?? '');

        return [
            'uuid' => (string) ($row['session_uuid'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'pageId' => $pageId,
            'pageTitle' => $pageId > 0 ? $this->pageTitle($pageId, $user) : '',
            'moduleRoute' => $module,
            'moduleLabel' => $this->contextPresenter->moduleLabel($module, $user),
            'messageCount' => (int) ($row['message_count'] ?? 0),
            'lastActivity' => (int) ($row['last_activity'] ?? 0),
            'provider' => $provider,
            'providerLabel' => $this->providerOptions->label($provider, $pageId),
            'isCurrentScope' => match ($scope) {
                AgentConversationRepository::SCOPE_USER => true,
                AgentConversationRepository::SCOPE_MODULE => $sameModule,
                default => $sameModule && $samePage,
            },
            'isHere' => $sameModule && $samePage,
        ];
    }

    /**
     * Settings the window needs for the session list.
     *
     * @return array{enabled: bool, defaultFilter: string, scope: string, retentionDays: int}
     */
    public function listSettings(): array
    {
        return [
            'enabled' => $this->settings->isSessionListEnabled(),
            'defaultFilter' => $this->settings->getSessionListDefaultFilter(),
            'scope' => $this->settings->getConversationScope(),
            'retentionDays' => $this->settings->getConversationRetentionDays(),
        ];
    }

    private function pageTitle(int $pageId, BackendUserAuthentication $user): string
    {
        if (!$user->isAdmin() && BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) === false) {
            return 'Page ' . $pageId;
        }
        $row = BackendUtility::getRecord('pages', $pageId, 'title');
        $title = trim((string) ($row['title'] ?? ''));

        return $title !== '' ? $title : 'Page ' . $pageId;
    }
}
