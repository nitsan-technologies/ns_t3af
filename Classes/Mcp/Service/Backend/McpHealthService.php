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

use NITSAN\NsT3AF\Mcp\Domain\Model\OAuthToken;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Mcp\Service\McpConnectionsService;
use NITSAN\NsT3AF\Mcp\Service\McpServerStatusService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Connection health checks for active MCP tokens.
 *
 * @internal
 */
readonly class McpHealthService
{
    public function __construct(
        private McpConnectionsService $connectionsService,
        private TokenRepository $tokenRepository,
        private McpServerStatusService $serverStatusService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listConnectionHealth(?ServerRequestInterface $request = null): array
    {
        $status = $this->serverStatusService->build($request);
        $serverOnline = (int) ($status['online'] ?? 0) === 1;
        $mcpReachable = (int) ($status['endpointChecks']['mcp'] ?? 0) === 1;

        return array_map(
            fn(array $conn): array => $this->enrichHealthRow($conn, $serverOnline, $mcpReachable),
            $this->connectionsService->listActive(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pingAll(?ServerRequestInterface $request = null): array
    {
        return $this->listConnectionHealth($request);
    }

    /**
     * @param array<string, mixed> $conn
     * @return array<string, mixed>
     */
    private function enrichHealthRow(array $conn, bool $serverOnline, bool $mcpReachable): array
    {
        $uid = (int) ($conn['uid'] ?? 0);
        $token = $uid > 0 ? $this->tokenRepository->findByUid($uid) : null;
        $healthy = $serverOnline && $mcpReachable && $token instanceof OAuthToken && $token->isActive();

        return array_merge($conn, [
            'healthy' => $healthy ? 1 : 0,
            'statusLabel' => $healthy ? 'ok' : 'degraded',
            'lastChecked' => time(),
        ]);
    }
}
