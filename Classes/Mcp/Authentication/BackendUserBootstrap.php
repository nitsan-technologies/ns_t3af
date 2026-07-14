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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Authentication;

use Doctrine\DBAL\ParameterType;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class BackendUserBootstrap
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function bootstrap(int $beUserUid, int $workspaceId = 0): BackendUserAuthentication
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, int|string|null>|false $userRow */
        $userRow = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if ($userRow === false) {
            throw new \RuntimeException('Backend user not found', 1712000010);
        }

        if ((int) ($userRow['deleted'] ?? 0) === 1 || (int) ($userRow['disable'] ?? 0) === 1) {
            throw new \RuntimeException('Backend user not found', 1712000010);
        }

        $backendUser = new BackendUserAuthentication();
        $backendUser->user = $userRow;
        $backendUser->fetchGroupData();

        $GLOBALS['BE_USER'] = $backendUser;

        if (AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            $backendUser->setWorkspace($workspaceId);
        }

        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUser);

        return $backendUser;
    }
}
