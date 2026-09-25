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
use NITSAN\NsT3AF\Updates\AgentConversationSessionsUpdate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The active AI Agent conversation of one request.
 *
 * {@see self::resolve()} picks it: the requested session (if the user owns it), otherwise
 * the latest conversation of the configured scope (agentConversationScope: page, module
 * or user). A new conversation is only stored when the first message is saved, so opening
 * the agent on a page does not create empty rows.
 *
 * @internal
 */
final class AgentConversationSession
{
    private const SESSION_DISCLOSURE_KEY = 'nst3af_agent_disclosure_dismissed';

    /** @var array<string, mixed>|null */
    private ?array $row = null;

    public function __construct(
        private readonly AgentConversationRepository $repository,
        private readonly AgentSettingsService $settings,
    ) {}

    /**
     * @param array<string, mixed> $context resolved context (module, pageId)
     * @param bool $fresh true = do not open an existing conversation (e.g. "New conversation")
     * @return array<string, mixed>|null the active row, null while nothing is stored yet
     */
    public function resolve(BackendUserAuthentication $user, array $context, string $requestedUuid = '', bool $fresh = false): ?array
    {
        $userUid = $this->userUid($user);
        $this->row = null;
        if ($requestedUuid !== '') {
            $this->row = $this->repository->findBySessionForUser($requestedUuid, $userUid);
        }
        if ($this->row === null && !$fresh && $requestedUuid === '') {
            $this->row = $this->repository->findLatestForScope(
                $userUid,
                $this->settings->getConversationScope(),
                $this->module($context),
                $this->pageId($context),
            );
        }

        return $this->row;
    }

    /**
     * Starts an empty conversation on the current page / module and enforces the limits.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function startNew(BackendUserAuthentication $user, array $context, string $providerIdentifier = ''): array
    {
        $userUid = $this->userUid($user);
        $this->row = $this->repository->create($userUid, $this->module($context), $this->pageId($context), '', $providerIdentifier);
        $this->repository->enforceLimits(
            $userUid,
            $this->settings->getConversationScope(),
            $this->module($context),
            $this->pageId($context),
            $this->settings->getMaxSessionsPerScope(),
            $this->settings->getMaxSessionsPerUser(),
        );

        return $this->row;
    }

    public function sessionUuid(): string
    {
        return (string) ($this->row['session_uuid'] ?? '');
    }

    /**
     * Provider chosen for the active conversation ('' = not locked yet).
     */
    public function providerIdentifier(): string
    {
        return (string) ($this->row['provider_identifier'] ?? '');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return $this->row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMessages(): array
    {
        $messages = json_decode((string) ($this->row['messages'] ?? ''), true);

        return is_array($messages) ? array_values(array_filter($messages, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        $context = json_decode((string) ($this->row['context'] ?? ''), true);

        return is_array($context) ? $context : [];
    }

    /**
     * Stores the messages of the active conversation; creates it on the first message.
     * The title comes from the first user message; the provider is locked by the first
     * answer (it can only be set while the conversation has no provider yet).
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $context
     */
    public function save(BackendUserAuthentication $user, array $messages, array $context, string $providerIdentifier = ''): void
    {
        if ($messages === [] && $this->row === null) {
            return;
        }
        if ($this->row === null) {
            $this->startNew($user, $context);
        }
        $row = $this->row ?? [];

        $title = trim((string) ($row['title'] ?? '')) === ''
            ? AgentConversationSessionsUpdate::titleFromMessages($messages, (int) ($row['page_id'] ?? 0))
            : null;
        $provider = (string) ($row['provider_identifier'] ?? '') === '' && $providerIdentifier !== '' ? $providerIdentifier : null;

        $this->repository->saveMessages((int) ($row['uid'] ?? 0), $this->userUid($user), $messages, $context, $title, $provider);

        $row['messages'] = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $row['context'] = json_encode($context, JSON_UNESCAPED_UNICODE);
        $row['message_count'] = count($messages);
        if ($title !== null) {
            $row['title'] = $title;
        }
        if ($provider !== null) {
            $row['provider_identifier'] = $provider;
        }
        $this->row = $row;
    }

    public function isDisclosureDismissed(BackendUserAuthentication $user): bool
    {
        return (bool) ($user->getSessionData(self::SESSION_DISCLOSURE_KEY) ?? false);
    }

    public function setDisclosureDismissed(bool $dismissed, BackendUserAuthentication $user): void
    {
        $user->setAndSaveSessionData(self::SESSION_DISCLOSURE_KEY, $dismissed ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function module(array $context): string
    {
        return trim((string) ($context['module'] ?? ''));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function pageId(array $context): int
    {
        return max(0, (int) ($context['pageId'] ?? 0));
    }

    private function userUid(BackendUserAuthentication $user): int
    {
        return (int) ($user->user['uid'] ?? 0);
    }
}
