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

use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Agent audit rows: correlation id + argument hash, no payloads (T17).
 *
 * @internal
 */
final readonly class AgentAuditLogger
{
    public const CALL_TYPE = 'agent';
    public const CLIENT_LABEL = 'AI Agent';

    public function __construct(private McpToolLogRepository $toolLogRepository) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function logToolInvocation(
        string $correlationId,
        string $toolName,
        array $arguments,
        bool $success,
        int $latencyMs,
        ?string $errorCode = null,
    ): void {
        try {
            $user = $GLOBALS['BE_USER'] ?? null;
            $beUserId = $user instanceof BackendUserAuthentication
                ? (int) ($user->getUserId() ?? 0)
                : 0;

            $this->toolLogRepository->insert([
                'tool_name' => $toolName,
                'handler_name' => 'AgentAjaxController',
                'call_type' => self::CALL_TYPE,
                'token_uid' => 0,
                'client_label' => self::CLIENT_LABEL,
                'be_user' => $beUserId,
                'success' => $success ? 1 : 0,
                'error_message' => $errorCode,
                'latency_ms' => max(0, $latencyMs),
                'correlation_id' => $correlationId,
                'arguments_hash' => $this->hashArguments($arguments),
            ]);
        } catch (\Throwable) {
            // Audit logging must never break agent turns.
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function hashArguments(array $arguments): string
    {
        try {
            $encoded = json_encode($arguments, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $encoded = serialize($arguments);
        }

        return hash('sha256', $encoded);
    }
}
