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

namespace NITSAN\NsT3AF\Mcp\Authentication;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Session\UserSession;

/**
 * Backend user for an MCP request.
 *
 * Token auth has no user session. EXT:redirects still calls getModuleData() and
 * pushModuleData() after a slug change. Those methods on the stock user read the
 * session id and write be_users.uc. This user keeps that module data in memory.
 */
class McpBackendUserAuthentication extends BackendUserAuthentication
{
    public function getModuleData(string $module, string $type = ''): mixed
    {
        return $this->uc['moduleData'][$module] ?? null;
    }

    public function pushModuleData(string $module, mixed $data, bool $dontPersistImmediately = false): void
    {
        $this->uc['moduleData'][$module] = $data;
    }

    public function setSessionData($key, $data): void
    {
        $this->memorySession()->set((string) $key, $data);
    }

    public function setAndSaveSessionData($key, $data): void
    {
        $this->setSessionData($key, $data);
    }

    private function memorySession(): UserSession
    {
        if ($this->userSession === null) {
            $this->userSession = UserSession::createNonFixated('mcp-' . bin2hex(random_bytes(8)));
        }

        return $this->userSession;
    }

    public static function discardUpdateSignal(mixed $user): void
    {
        if (!$user instanceof self) {
            return;
        }

        $key = BackendUtility::class . '::getUpdateSignal';
        unset($user->uc['moduleData'][$key], $user->uc['moduleSessionID'][$key]);
    }
}
