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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\McpToolNameResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Writes structured MCP invocation logs for analytics and auditing.
 *
 * @internal
 */
readonly class McpToolLogService
{
    public function __construct(
        private McpToolLogRepository $repository,
        private McpRuntimeContext $runtimeContext,
        private AdvancedSettingsService $settingsService,
        private McpToolNameResolver $toolNameResolver,
    ) {}

    /** @param list<mixed> $arguments */
    public function logSuccess(object $handler, string $callType, array $arguments, int $latencyMs): void
    {
        if (!$this->settingsService->logAllToolCalls()) {
            return;
        }

        $this->write($handler, $callType, true, '', $latencyMs);
    }

    /** @param list<mixed> $arguments */
    public function logFailure(
        object $handler,
        string $callType,
        array $arguments,
        int $latencyMs,
        string $errorMessage,
    ): void {
        if (!$this->settingsService->logAllToolCalls()) {
            return;
        }

        $this->write($handler, $callType, false, $errorMessage, $latencyMs);
    }

    private function write(
        object $handler,
        string $callType,
        bool $success,
        string $errorMessage,
        int $latencyMs,
    ): void {
        try {
            $beUser = $this->runtimeContext->getBeUser();
            if ($beUser <= 0) {
                $backendUser = $GLOBALS['BE_USER'] ?? null;
                if ($backendUser instanceof BackendUserAuthentication) {
                    $beUser = (int) ($backendUser->getUserId() ?? 0);
                }
            }

            $handlerName = (new \ReflectionClass($handler))->getShortName();

            $this->repository->insert([
                'tool_name' => $this->toolNameResolver->resolveFromHandler($handler),
                'handler_name' => $handlerName,
                'call_type' => $callType,
                'token_uid' => $this->runtimeContext->getTokenUid(),
                'client_label' => $this->runtimeContext->getClientLabel(),
                'be_user' => $beUser,
                'success' => $success ? 1 : 0,
                'error_message' => $errorMessage !== '' ? $errorMessage : null,
                'latency_ms' => max(0, $latencyMs),
            ]);
        } catch (\Throwable) {
            // Logging must never break tool execution.
        }
    }
}
