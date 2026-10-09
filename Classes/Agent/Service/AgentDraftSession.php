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
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Session storage for pending agent drafts and applied changes (undo).
 *
 * @internal
 */
final class AgentDraftSession
{
    private const DRAFTS_KEY = 'nst3af_agent_drafts';
    private const CHANGES_KEY = 'nst3af_agent_changes';
    private const REGISTRY_NAMESPACE = 'tx_nst3af_agent_change';

    /**
     * @param array<string, mixed> $payload
     */
    public function storeDraft(string $draftId, array $payload, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $drafts = $this->readDrafts($user);
        $drafts[$draftId] = $payload;
        $user->setAndSaveSessionData(self::DRAFTS_KEY, $drafts);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDraft(string $draftId, ?BackendUserAuthentication $user = null): ?array
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return null;
        }

        $drafts = $this->readDrafts($user);

        return is_array($drafts[$draftId] ?? null) ? $drafts[$draftId] : null;
    }

    public function removeDraft(string $draftId, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $drafts = $this->readDrafts($user);
        unset($drafts[$draftId]);
        $user->setAndSaveSessionData(self::DRAFTS_KEY, $drafts);
    }

    public function setDestructiveArmed(string $draftId, bool $armed, ?BackendUserAuthentication $user = null): void
    {
        $draft = $this->getDraft($draftId, $user);
        if ($draft === null) {
            return;
        }

        $draft['destructiveArmed'] = $armed;
        $this->storeDraft($draftId, $draft, $user);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function storeChange(string $changeId, array $payload, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $changes = $this->readChanges($user);
        $changes[$changeId] = $payload;
        $user->setAndSaveSessionData(self::CHANGES_KEY, $changes);

        // The session copy is lost when the backend session is replaced (new login, switched language,
        // two requests saving the session at once); Undo must still find the change then.
        try {
            GeneralUtility::makeInstance(Registry::class)->set(
                self::REGISTRY_NAMESPACE,
                $changeId,
                ['userUid' => (int) ($user->user['uid'] ?? 0), 'payload' => $payload],
            );
        } catch (\Throwable) {
            // Registry not reachable (unit tests, broken database): the session copy still works.
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getChange(string $changeId, ?BackendUserAuthentication $user = null): ?array
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return null;
        }

        $changes = $this->readChanges($user);
        if (is_array($changes[$changeId] ?? null)) {
            return $changes[$changeId];
        }

        try {
            $stored = GeneralUtility::makeInstance(Registry::class)->get(self::REGISTRY_NAMESPACE, $changeId);
        } catch (\Throwable) {
            return null;
        }
        if (is_array($stored)
            && (int) ($stored['userUid'] ?? 0) === (int) ($user->user['uid'] ?? -1)
            && is_array($stored['payload'] ?? null)
        ) {
            return $stored['payload'];
        }

        return null;
    }

    public function removeChange(string $changeId, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $changes = $this->readChanges($user);
        unset($changes[$changeId]);
        $user->setAndSaveSessionData(self::CHANGES_KEY, $changes);

        try {
            GeneralUtility::makeInstance(Registry::class)->remove(self::REGISTRY_NAMESPACE, $changeId);
        } catch (\Throwable) {
            // See storeChange().
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readDrafts(BackendUserAuthentication $user): array
    {
        $drafts = $user->getSessionData(self::DRAFTS_KEY);

        return is_array($drafts) ? $drafts : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readChanges(BackendUserAuthentication $user): array
    {
        $changes = $user->getSessionData(self::CHANGES_KEY);

        return is_array($changes) ? $changes : [];
    }

    private function resolveBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
