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

namespace NITSAN\NsT3AF\Agent\Context;

use NITSAN\NsT3AF\Service\BrandContextResolver;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Server-side context resolution for AI Agent turns (T3, R9, R10).
 *
 * @internal
 */
final readonly class AgentContextResolver
{
    public function __construct(
        private BrandContextResolver $brandContextResolver,
    ) {}

    /**
     * @param array<string, mixed> $clientContext Untrusted client payload
     */
    public function resolve(array $clientContext, ?BackendUserAuthentication $user = null): AgentContext
    {
        $pageId = (int) ($clientContext['pageId'] ?? 0);
        if ($pageId > 0 && !$this->userCanReadPage($pageId, $user)) {
            $pageId = 0;
        }

        $focusedRecord = null;
        $record = is_array($clientContext['record'] ?? null) ? $clientContext['record'] : null;
        if ($record !== null) {
            $table = (string) ($record['table'] ?? '');
            $uid = (int) ($record['uid'] ?? 0);
            if ($table !== '' && $uid > 0) {
                if ($table === 'pages' && !$this->userCanReadPage($uid, $user)) {
                    $focusedRecord = null;
                } else {
                    $focusedRecord = ['table' => $table, 'uid' => $uid];
                }
            }
        }

        $brandProfile = $this->brandContextResolver->resolveDefaultForPageId($pageId > 0 ? $pageId : null);

        $workspaceId = (int) ($clientContext['workspaceId'] ?? 0);
        if ($workspaceId <= 0 && $user !== null) {
            $workspaceId = (int) $user->workspace;
        }

        return new AgentContext(
            module: trim((string) ($clientContext['module'] ?? '')),
            pageId: $pageId,
            focusedRecord: $focusedRecord,
            languageId: (int) ($clientContext['languageId'] ?? 0),
            siteIdentifier: trim((string) ($clientContext['siteIdentifier'] ?? '')),
            workspaceId: max(0, $workspaceId),
            brandContextProfileUid: $brandProfile?->uid,
            brandName: $brandProfile !== null ? $brandProfile->brandName : '',
        );
    }

    private function userCanReadPage(int $pageId, ?BackendUserAuthentication $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }
}
