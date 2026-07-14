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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use NITSAN\NsT3AF\Utility\SysLogWriterUtility;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AiLogService
{
    private array $extConf;
    private Context $context;
    protected LoggerInterface $logger;
    private string $extensionKey;

    /**
     * @param string $extensionKey Extension key to read configuration from
     */
    public function __construct(string $extensionKey = 'ns_t3af')
    {
        $this->extensionKey = $extensionKey;
        $this->extConf = AiUniverseUtilityHelper::getExtensionConf($this->extensionKey);
        $this->context = GeneralUtility::makeInstance(Context::class);
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function writeLog(
        string $logMessage,
        string $logLevel,
        string $channel = 'ns_t3af',
        array $extraData = [],
        ?string $aiEngine = null,
    ): void {
        $catalog = GeneralUtility::makeInstance(AiLogChannelCatalog::class);
        $normalizedChannel = $catalog->normalizeWriteChannel(
            $channel,
            $this->extensionKey,
            array_merge($extraData, ['message' => $logMessage]),
        );

        SysLogWriterUtility::insert($logMessage, $logLevel, $normalizedChannel, $extraData, $this->getBackendUser());

        // Email only for quota / API-key errors; all error-level rows stay in sys_log.
        if ($logLevel === 'error') {
            GeneralUtility::makeInstance(AiApiAlertNotificationService::class)
                ->notifyIfApplicable($logMessage, $normalizedChannel, $aiEngine);
        }
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
