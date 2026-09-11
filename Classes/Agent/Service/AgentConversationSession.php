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

use NITSAN\NsT3AF\Domain\Repository\AgentConversationRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Module-scoped conversation storage for the AI Agent (DB persistence).
 *
 * @internal
 */
final class AgentConversationSession
{
    private const SESSION_DISCLOSURE_KEY = 'nst3af_agent_disclosure_dismissed';

    private string $moduleRoute = '';

    private int $pageId = 0;

    public function __construct(
        private readonly AgentConversationRepository $conversationRepository,
    ) {}

    public function setScope(string $moduleRoute, int $pageId): void
    {
        $this->moduleRoute = trim($moduleRoute);
        $this->pageId = max(0, $pageId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMessages(?BackendUserAuthentication $user = null): array
    {
        $payload = $this->readPayload($user);
        $messages = $payload['messages'] ?? [];

        return is_array($messages) ? array_values($messages) : [];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function saveMessages(array $messages, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $payload = $this->readPayload($user);
        $payload['messages'] = array_values($messages);
        $this->persistPayload($user, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(?BackendUserAuthentication $user = null): array
    {
        $payload = $this->readPayload($user);
        $context = $payload['context'] ?? [];

        return is_array($context) ? $context : [];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function saveContext(array $context, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $payload = $this->readPayload($user);
        $payload['context'] = $context;
        $this->persistPayload($user, $payload);
    }

    public function clear(?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $this->persistPayload($user, [
            'messages' => [],
            'context' => [],
        ]);
    }

    public function isDisclosureDismissed(?BackendUserAuthentication $user = null): bool
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return false;
        }

        return (bool) ($user->getSessionData(self::SESSION_DISCLOSURE_KEY) ?? false);
    }

    public function setDisclosureDismissed(bool $dismissed, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $user->setAndSaveSessionData(self::SESSION_DISCLOSURE_KEY, $dismissed ? 1 : 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(?BackendUserAuthentication $user): array
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return [];
        }

        $row = $this->conversationRepository->findByScope(
            (int) ($user->user['uid'] ?? 0),
            $this->moduleRoute,
            $this->pageId,
        );
        if ($row === null) {
            return [];
        }

        return $this->hydratePayload($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistPayload(BackendUserAuthentication $user, array $payload): void
    {
        $this->conversationRepository->save(
            (int) ($user->user['uid'] ?? 0),
            $this->moduleRoute,
            $this->pageId,
            [
                'messages' => $this->normalizeMessages(is_array($payload['messages'] ?? null) ? $payload['messages'] : []),
                'context' => is_array($payload['context'] ?? null) ? $payload['context'] : [],
            ],
        );
    }

    /**
     * @param array<int|string, mixed> $messages
     * @return list<array<string, mixed>>
     */
    private function normalizeMessages(array $messages): array
    {
        $normalized = [];
        foreach (array_values($messages) as $message) {
            if (is_array($message)) {
                $normalized[] = $message;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePayload(array $row): array
    {
        $messages = json_decode((string) ($row['messages'] ?? ''), true);
        $context = json_decode((string) ($row['context'] ?? ''), true);

        return [
            'messages' => is_array($messages) ? $messages : [],
            'context' => is_array($context) ? $context : [],
        ];
    }

    private function resolveBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
