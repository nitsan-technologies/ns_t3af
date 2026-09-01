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

/**
 * Session storage for pending agent drafts and applied changes (undo).
 *
 * @internal
 */
final class AgentDraftSession
{
    private const DRAFTS_KEY = 'nst3af_agent_drafts';
    private const CHANGES_KEY = 'nst3af_agent_changes';

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

        return is_array($changes[$changeId] ?? null) ? $changes[$changeId] : null;
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
