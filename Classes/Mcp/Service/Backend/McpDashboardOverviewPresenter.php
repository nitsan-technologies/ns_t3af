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

use NITSAN\NsT3AF\Mcp\Domain\Enum\TokenType;
use NITSAN\NsT3AF\Mcp\Domain\Model\OAuthToken;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthClientLabelResolver;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\McpServerStatusService;
use NITSAN\NsT3AF\Mcp\Service\McpToolsRegistryService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Builds localized MCP overview assigns for the AI Foundation dashboard.
 */
final class McpDashboardOverviewPresenter
{
    private const LOCALLANG_MOD = 'EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf';

    private const TOP_TOOLS_LIMIT = 5;

    private const ACTIVE_CLIENTS_LIMIT = 5;

    public function __construct(
        private readonly McpServerStatusService $mcpServerStatusService,
        private readonly McpAnalyticsService $mcpAnalyticsService,
        private readonly McpToolsRegistryService $mcpToolsRegistryService,
        private readonly TokenRepository $tokenRepository,
        private readonly McpToolLogRepository $mcpToolLogRepository,
        private readonly AdvancedSettingsService $advancedSettingsService,
        private readonly OAuthClientLabelResolver $clientLabelResolver,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * @return array{
     *   serverStatus: array<string, mixed>,
     *   topTools: array<string, mixed>,
     *   activeClients: array<string, mixed>
     * }
     */
    public function build(ServerRequestInterface $request, string $period = '7d'): array
    {
        $status = $this->mcpServerStatusService->build($request);
        $summary = $this->mcpAnalyticsService->getSummary($period);
        $resolvedPeriod = $this->mcpAnalyticsService->resolvePeriod($period);
        $statistics = $this->mcpToolsRegistryService->getStatistics($period);
        $rawTopTools = $this->mcpAnalyticsService->getTopTools(self::TOP_TOOLS_LIMIT, $period);
        $clientUsage = $this->buildClientUsageMap(
            $resolvedPeriod['fromTimestamp'],
            $resolvedPeriod['toTimestamp'],
        );

        $enabled = (int) ($status['online'] ?? 0) === 1;
        $endpointOnline = $enabled && (int) ($status['endpointChecks']['mcp'] ?? 0) === 1;
        $tokens = $this->tokenRepository->findAllActive();
        $transportCounts = $this->countTransports($tokens);
        $oauthConfigured = $this->countConfiguredOAuthEndpoints($status);

        $mcpServerUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.mcp_server');
        $mcpToolsUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.mcp_tools');

        return [
            'serverStatus' => [
                'online' => self::fluidFlag($endpointOnline),
                'onlineLabel' => $this->translateModule(
                    $endpointOnline ? 'module.mcpServer.kpi.online' : 'module.mcpServer.kpi.offline',
                ),
                'endpoint' => (string) ($status['serverUrl'] ?? ''),
                'endpointOnline' => self::fluidFlag($endpointOnline),
                'uptime' => $this->formatDurationSince($this->advancedSettingsService->mcpServerOnlineSince()),
                'clientCount' => count($tokens),
                'toolCount' => (int) ($statistics['totalTools'] ?? 0),
                'successRate' => (float) ($summary['avgSuccessRate'] ?? 0.0),
                'successRateFormatted' => number_format((float) ($summary['avgSuccessRate'] ?? 0.0), 1) . '%',
                'successHigh' => self::fluidFlag((float) ($summary['avgSuccessRate'] ?? 0.0) >= 90.0),
                'transports' => [
                    [
                        'id' => 'http',
                        'label' => $this->translateModule('module.dashboard.mcpOverview.transport.http'),
                        'count' => $transportCounts['http'],
                        'active' => self::fluidFlag($enabled),
                    ],
                    [
                        'id' => 'stdio',
                        'label' => $this->translateModule('module.dashboard.mcpOverview.transport.stdio'),
                        'count' => $transportCounts['stdio'],
                        'active' => self::fluidFlag($enabled),
                    ],
                    [
                        'id' => 'mcp_remote',
                        'label' => $this->translateModule('module.dashboard.mcpOverview.transport.mcpRemote'),
                        'count' => $transportCounts['mcp_remote'],
                        'active' => self::fluidFlag($enabled && $transportCounts['mcp_remote'] > 0),
                    ],
                ],
                'oauthEndpointsConfigured' => $oauthConfigured,
                'mcpServerUri' => $mcpServerUri,
            ],
            'topTools' => $this->buildTopToolsAssigns(
                $rawTopTools,
                (string) ($resolvedPeriod['label'] ?? '7 Days'),
                (int) ($summary['toolCalls'] ?? 0),
                (int) ($statistics['totalTools'] ?? 0),
                $mcpToolsUri,
            ),
            'activeClients' => $this->buildActiveClientsAssigns(
                $tokens,
                $clientUsage,
                $mcpServerUri,
            ),
        ];
    }

    /**
     * @param list<array{toolName: string, calls: int, successRate: float}> $rawTopTools
     * @return array<string, mixed>
     */
    private function buildTopToolsAssigns(
        array $rawTopTools,
        string $periodLabel,
        int $totalCalls,
        int $toolCount,
        string $mcpToolsUri,
    ): array {
        $maxCalls = 0;
        foreach ($rawTopTools as $row) {
            $maxCalls = max($maxCalls, (int) ($row['calls'] ?? 0));
        }

        $items = [];
        $rank = 1;
        foreach ($rawTopTools as $row) {
            $calls = (int) ($row['calls'] ?? 0);
            $successRate = (float) ($row['successRate'] ?? 0.0);
            $items[] = [
                'rank' => $rank,
                'toolName' => (string) ($row['toolName'] ?? ''),
                'calls' => $calls,
                'callsFormatted' => number_format($calls),
                'successRate' => $successRate,
                'successRateFormatted' => number_format($successRate, 1) . '%',
                'successHigh' => self::fluidFlag($successRate >= 98.0),
                'barPercent' => $maxCalls > 0 ? (int) round(($calls / $maxCalls) * 100) : 0,
            ];
            ++$rank;
        }

        return [
            'periodLabel' => $periodLabel,
            'totalCalls' => $totalCalls,
            'totalCallsFormatted' => number_format($totalCalls),
            'toolCount' => $toolCount,
            'items' => $items,
            'hasItems' => self::fluidFlag($items !== []),
            'mcpToolsUri' => $mcpToolsUri,
        ];
    }

    /**
     * @param list<OAuthToken> $tokens
     * @param array<string, int> $clientUsage
     * @return array<string, mixed>
     */
    private function buildActiveClientsAssigns(
        array $tokens,
        array $clientUsage,
        string $mcpServerUri,
    ): array {
        $items = [];
        foreach (array_slice($tokens, 0, self::ACTIVE_CLIENTS_LIMIT) as $token) {
            $label = $this->resolveClientLabel($token);
            $items[] = [
                'name' => $label,
                'transport' => $this->resolveTransportLabel($token),
                'since' => $this->formatDurationSince($this->resolveActivityTimestamp($token)),
                'calls' => $clientUsage[$label] ?? 0,
                'callsFormatted' => number_format($clientUsage[$label] ?? 0),
            ];
        }

        return [
            'count' => count($tokens),
            'items' => $items,
            'hasItems' => self::fluidFlag($items !== []),
            'mcpServerUri' => $mcpServerUri,
        ];
    }

    /**
     * @param list<OAuthToken> $tokens
     * @return array{http: int, stdio: int, mcp_remote: int}
     */
    private function countTransports(array $tokens): array
    {
        $http = 0;
        $mcpRemote = 0;
        foreach ($tokens as $token) {
            if ($token->tokenType === TokenType::McpRemoteUrl) {
                ++$mcpRemote;
                continue;
            }
            ++$http;
        }

        return [
            'http' => $http,
            'stdio' => $this->advancedSettingsService->isMcpServerEnabled() ? 1 : 0,
            'mcp_remote' => $mcpRemote,
        ];
    }

    /**
     * @param array<string, mixed> $status
     */
    private function countConfiguredOAuthEndpoints(array $status): int
    {
        $checks = is_array($status['endpointChecks'] ?? null) ? $status['endpointChecks'] : [];
        $count = 0;
        foreach (['authorizationServer', 'protectedResource'] as $key) {
            if ((int) ($checks[$key] ?? 0) === 1) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array<string, int>
     */
    private function buildClientUsageMap(int $fromTimestamp, ?int $toTimestamp): array
    {
        $map = [];
        foreach ($this->mcpToolLogRepository->rateLimitsByClient($fromTimestamp, 0) as $row) {
            $label = trim((string) ($row['clientLabel'] ?? ''));
            if ($label === '') {
                continue;
            }
            $map[$label] = (int) ($row['used'] ?? 0);
        }

        return $map;
    }

    private function resolveClientLabel(OAuthToken $token): string
    {
        return $this->clientLabelResolver->resolveForToken($token);
    }

    private function resolveTransportLabel(OAuthToken $token): string
    {
        return match ($token->tokenType) {
            TokenType::McpRemoteUrl => $this->translateModule('module.dashboard.mcpOverview.transport.mcpRemote'),
            TokenType::Bearer => $this->translateModule('module.dashboard.mcpOverview.transport.oauth'),
        };
    }

    private function resolveActivityTimestamp(OAuthToken $token): int
    {
        if ($token->lastUsedAt > 0) {
            return $token->lastUsedAt;
        }

        return $token->crdate;
    }

    private function formatDurationSince(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return $this->translateModule('module.dashboard.mcpOverview.duration.unknown');
        }

        $seconds = max(0, (int) ($GLOBALS['EXEC_TIME'] ?? time()) - $timestamp);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }

    private function translateModule(string $key): string
    {
        return (string) ($GLOBALS['LANG']?->sL(
            'LLL:' . self::LOCALLANG_MOD . ':' . $key,
        ) ?? $key);
    }

    /** @return 0|1 */
    private static function fluidFlag(bool $value): int
    {
        return $value ? 1 : 0;
    }
}
