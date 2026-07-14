<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

namespace NITSAN\NsT3AF\Mcp\Logging;

use NITSAN\NsT3AF\Service\AiLogChannelCatalog;
use NITSAN\NsT3AF\Utility\SysLogWriterUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

readonly class AuditLogger
{
    /** @param list<mixed> $arguments */
    public function logSuccess(string $handlerName, string $type, array $arguments, int $executionTimeMs): void
    {
        $this->writeLog(
            handlerName: $handlerName,
            type: $type,
            executionTimeMs: $executionTimeMs,
            logLevel: 'info',
            details: sprintf('MCP %s %s: OK (%dms)', $type, $handlerName, $executionTimeMs),
        );
    }

    /** @param list<mixed> $arguments */
    public function logFailure(
        string $handlerName,
        string $type,
        array $arguments,
        int $executionTimeMs,
        string $errorMessage,
    ): void {
        $this->writeLog(
            handlerName: $handlerName,
            type: $type,
            executionTimeMs: $executionTimeMs,
            logLevel: 'error',
            details: sprintf('MCP %s %s failed: %s (%dms)', $type, $handlerName, $errorMessage, $executionTimeMs),
            errorMessage: $errorMessage,
        );
    }

    private function writeLog(
        string $handlerName,
        string $type,
        int $executionTimeMs,
        string $logLevel,
        string $details,
        string $errorMessage = '',
    ): void {
        try {
            $backendUser = $GLOBALS['BE_USER'] ?? null;
            if (!$backendUser instanceof BackendUserAuthentication) {
                return;
            }

            $data = [
                'tool' => $handlerName,
                'type' => $type,
                'executionTimeMs' => $executionTimeMs,
            ];

            if ($errorMessage !== '') {
                $data['error'] = $errorMessage;
            }

            SysLogWriterUtility::insert(
                $details,
                $logLevel,
                AiLogChannelCatalog::CHANNEL_MCP,
                $data,
                $backendUser,
            );
        } catch (\Throwable) {
            // Audit logging must never break tool execution
        }
    }
}
