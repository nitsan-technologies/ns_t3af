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

namespace NITSAN\NsT3AF\Access;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;

/**
 * Central entry for record-level write guards (tables_modify / catalog rows).
 */
final class RecordAccessEnforcer
{
    public function __construct(
        private readonly RecordAccessGate $recordAccessGate = new RecordAccessGate(),
    ) {}

    public function canModifyTable(?BackendUserAuthentication $user, string $table): bool
    {
        return $this->recordAccessGate->canModifyTable($user, $table);
    }

    public function canModifyCatalogId(?BackendUserAuthentication $user, string $catalogId): bool
    {
        return $this->recordAccessGate->canModifyCatalogRow($user, $catalogId);
    }

    public function denyUnlessCanModifyTable(?BackendUserAuthentication $user, string $table): ?JsonResponse
    {
        if ($this->recordAccessGate->canModifyTable($user, $table)) {
            return null;
        }

        return $this->jsonDenied($table);
    }

    public function denyUnlessCanModifyCatalogId(?BackendUserAuthentication $user, string $catalogId): ?JsonResponse
    {
        if ($this->recordAccessGate->canModifyCatalogRow($user, $catalogId)) {
            return null;
        }

        return $this->jsonDenied(catalogId: $catalogId);
    }

    public function denyUnlessCanModifyTableRedirect(
        ?BackendUserAuthentication $user,
        string $table,
        string $redirectUri,
    ): ?ResponseInterface {
        if ($this->recordAccessGate->canModifyTable($user, $table)) {
            return null;
        }

        return new RedirectResponse($redirectUri);
    }

    public function assertCanModifyTable(?BackendUserAuthentication $user, string $table): void
    {
        $this->recordAccessGate->assertCanModifyTable($user, $table);
    }

    public function assertCanModifyCatalogId(?BackendUserAuthentication $user, string $catalogId): void
    {
        $this->recordAccessGate->assertCanModifyCatalogRow($user, $catalogId);
    }

    private function jsonDenied(string $table = '', string $catalogId = ''): JsonResponse
    {
        $message = 'Record modification is not permitted for your backend user group.';
        if ($catalogId !== '') {
            $message .= ' (catalog: ' . $catalogId . ')';
        } elseif ($table !== '') {
            $message .= ' (table: ' . $table . ')';
        }

        return new JsonResponse(['ok' => false, 'message' => $message], 403);
    }
}
