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

namespace NITSAN\NsT3AF\Mcp\Controller\Backend;

use NITSAN\NsT3AF\Access\AiUniverseRecordMap;
use NITSAN\NsT3AF\Access\RecordAccessEnforcer;
use NITSAN\NsT3AF\Mcp\Domain\Model\OAuthToken;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthClientLabelResolver;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpAnalyticsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpHealthService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpSecurityService;
use NITSAN\NsT3AF\Mcp\Service\McpConnectionsService;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use NITSAN\NsT3AF\Mcp\Service\McpPublicUrlService;
use NITSAN\NsT3AF\Mcp\Service\McpServerStatusService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceProvisionService;
use NITSAN\NsT3AF\Service\AiUniverseActivityLogService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
final class McpServerController
{
    public function __construct(
        private readonly McpServerStatusService $statusService,
        private readonly McpConnectionsService $connectionsService,
        private readonly TokenRepository $tokenRepository,
        private readonly AdvancedSettingsService $settingsService,
        private readonly WorkspaceListService $workspaceListService,
        private readonly WorkspaceProvisionService $workspaceProvisionService,
        private readonly WorkspacePreferenceService $workspacePreferenceService,
        private readonly McpPublicUrlService $publicUrlService,
        private readonly McpPathProvider $pathProvider,
        private readonly ExtensionSettingsService $extensionSettingsService,
        private readonly McpSecurityService $securityService,
        private readonly McpAnalyticsService $analyticsService,
        private readonly McpHealthService $healthService,
        private readonly AiUniverseActivityLogService $activityLogService,
        private readonly RecordAccessEnforcer $recordAccessEnforcer,
        private readonly OAuthClientLabelResolver $clientLabelResolver,
    ) {}

    private function denyUnlessCanModifyOAuth(): ?JsonResponse
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $this->recordAccessEnforcer->denyUnlessCanModifyCatalogId(
            $user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication ? $user : null,
            AiUniverseRecordMap::OAUTH_CLIENTS,
        );
    }

    public function createWorkspaceAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('module.mcpServer.workspace.createDenied')], 403);
        }

        try {
            $workspaceId = $this->workspaceProvisionService->createMcpWorkspace($user);
            $this->workspacePreferenceService->saveForCurrentUser($workspaceId);

            return new JsonResponse([
                'success' => true,
                'workspaceId' => $workspaceId,
                'title' => $this->workspaceListService->resolveTitle($workspaceId),
            ]);
        } catch (\RuntimeException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('module.mcpServer.workspace.createFailed'),
            ], 500);
        }
    }

    public function saveWorkspacePreferenceAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
        }

        try {
            $workspaceId = $this->workspacePreferenceService->saveForCurrentUser((int) ($body['workspaceId'] ?? 0));
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 403);
        }

        return new JsonResponse([
            'success' => true,
            'workspaceId' => $workspaceId,
            'title' => $this->workspaceListService->resolveTitle($workspaceId),
        ]);
    }

    public function saveMcpModeAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
        }

        $mode = strtolower(trim((string) ($body['mcpMode'] ?? '')));
        if (!in_array($mode, ['context', 'native'], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid MCP mode'], 400);
        }

        if (!$this->extensionSettingsService->mergeGlobal('ns_t3af', ['mcpMode' => $mode])) {
            return new JsonResponse(['success' => false, 'message' => 'Could not save MCP mode'], 500);
        }

        $this->activityLogService->logMcpSettingsSaved('mode: ' . $mode);

        return new JsonResponse([
            'success' => true,
            'mcpMode' => $mode,
        ]);
    }

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($this->statusService->build($request));
    }

    public function connectionsAction(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(['connections' => $this->connectionsService->listActive()]);
    }

    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyOAuth()) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $uid = (int) (is_array($body) ? ($body['uid'] ?? 0) : 0);
        if ($uid <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token uid'], 400);
        }

        $this->tokenRepository->revokeByUid($uid);

        return new JsonResponse(['success' => true]);
    }

    public function revokeAllAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyOAuth()) {
            return $denied;
        }
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!is_object($user)) {
            return new JsonResponse(['success' => false], 403);
        }

        $count = $this->tokenRepository->revokeAllForUser((int) ($user->user['uid'] ?? 0));

        return new JsonResponse(['success' => true, 'revoked' => $count]);
    }

    public function issueMcpRemoteTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyOAuth()) {
            return $denied;
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false], 400);
        }

        $user = $GLOBALS['BE_USER'] ?? null;
        if (!is_object($user)) {
            return new JsonResponse(['success' => false], 403);
        }

        $beUserUid = (int) ($user->user['uid'] ?? 0);
        $label = trim((string) ($body['label'] ?? TokenRepository::LABEL_MCP_REMOTE));
        $workspaceId = (int) ($body['workspaceId'] ?? 0);
        $validityDays = max(1, (int) ($body['validityDays'] ?? 30));
        $lifetime = $validityDays * 86400;

        if ($this->tokenRepository->countActiveForUser($beUserUid) >= $this->settingsService->all()['oauthMaxActiveTokensPerUser']) {
            return new JsonResponse(['success' => false, 'message' => 'Maximum active tokens reached'], 403);
        }

        $plainToken = $this->tokenRepository->issueMcpRemoteToken($beUserUid, $workspaceId, $label, $lifetime);
        $baseUrl = rtrim($this->publicUrlService->resolveOrigin($request), '/') . rtrim($this->pathProvider->getBasePath(), '/');
        $url = $baseUrl . '?token=' . $plainToken;
        $token = $this->tokenRepository->findActiveByLabel($beUserUid, $label);

        return new JsonResponse([
            'success' => true,
            'token' => $plainToken,
            'url' => $url,
            'expiresIn' => $lifetime,
            'uid' => $token !== null ? $token->uid : 0,
        ]);
    }

    public function issueClientTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyOAuth()) {
            return $denied;
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
        }

        $user = $GLOBALS['BE_USER'] ?? null;
        if (!is_object($user)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $clientKey = trim((string) ($body['client'] ?? ''));
        $workspaceId = (int) ($body['workspaceId'] ?? 0);
        $settings = $this->settingsService->all();
        $beUserUid = (int) ($user->user['uid'] ?? 0);
        $accessLifetime = max(3600, (int) ($settings['accessTokenLifetime'] ?? 3600));
        $refreshLifetime = max($accessLifetime, 2592000);
        $maxTokens = max(1, (int) ($settings['oauthMaxActiveTokensPerUser'] ?? 5));
        $scope = (string) ($settings['oauthDefaultScopes'] ?? 'mcp:read mcp:write mcp:tools');

        [$label, $clientId] = match ($clientKey) {
            'n8n' => [TokenRepository::LABEL_N8N, 'n8n'],
            'manus' => [TokenRepository::LABEL_MANUS, 'manus'],
            default => [null, null],
        };

        if ($label === null || $clientId === null) {
            return new JsonResponse(['success' => false, 'message' => 'Unknown client'], 400);
        }

        try {
            $plainToken = $this->tokenRepository->issueClientBearerToken(
                $beUserUid,
                $clientId,
                $label,
                $workspaceId,
                $accessLifetime,
                $refreshLifetime,
                $maxTokens,
                $scope,
            );
        } catch (\RuntimeException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        }

        $token = $this->tokenRepository->findActiveByLabel($beUserUid, $label);

        return new JsonResponse([
            'success' => true,
            'client' => $clientKey,
            'token' => $plainToken,
            'preview' => substr(hash('sha256', $plainToken), 0, 8) . '…',
            'expiresIn' => $accessLifetime,
            'uid' => $token !== null ? $token->uid : 0,
        ]);
    }

    public function saveAdvancedSettingsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false], 400);
        }

        $this->settingsService->save($body);
        $this->activityLogService->logMcpSettingsSaved('advanced settings');

        return new JsonResponse(['success' => true, 'settings' => $this->settingsService->all()]);
    }

    public function securityScopesSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false], 400);
        }

        $payload = $body;
        if (array_key_exists('enforcePkce', $payload)) {
            $payload['enforcePkce'] = !empty($payload['enforcePkce']) ? '1' : '0';
        }

        $this->securityService->saveOAuthSettings($payload);
        $this->activityLogService->logMcpSettingsSaved('OAuth security scopes');

        return new JsonResponse(['success' => true, 'settings' => $this->securityService->allSecuritySettings()]);
    }

    public function ipAllowlistAddAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false], 400);
        }

        $label = trim((string) ($body['label'] ?? ''));
        $cidr = trim((string) ($body['cidr'] ?? ''));
        if ($label === '' || $cidr === '') {
            return new JsonResponse(['success' => false, 'message' => 'Label and CIDR required'], 400);
        }

        $uid = $this->securityService->addIpAllowlistEntry($label, $cidr, !empty($body['enabled']));

        return new JsonResponse(['success' => true, 'uid' => $uid, 'entries' => $this->securityService->listIpAllowlist()]);
    }

    public function ipAllowlistRemoveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $uid = (int) (is_array($body) ? ($body['uid'] ?? 0) : 0);
        if ($uid <= 0) {
            return new JsonResponse(['success' => false], 400);
        }

        $this->securityService->removeIpAllowlistEntry($uid);

        return new JsonResponse(['success' => true, 'entries' => $this->securityService->listIpAllowlist()]);
    }

    public function ipAllowlistToggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false], 400);
        }

        $uid = (int) ($body['uid'] ?? 0);
        $entry = null;
        foreach ($this->securityService->listIpAllowlist() as $row) {
            if ($row['uid'] === $uid) {
                $entry = $row;
                break;
            }
        }

        if ($entry === null) {
            return new JsonResponse(['success' => false, 'message' => 'Entry not found'], 404);
        }

        $enabled = array_key_exists('enabled', $body) ? !empty($body['enabled']) : !$entry['enabled'];
        $this->securityService->updateIpAllowlistEntry($uid, $entry['label'], $entry['cidr'], $enabled);

        return new JsonResponse(['success' => true, 'entries' => $this->securityService->listIpAllowlist()]);
    }

    public function mtlsSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->securityService->isMtlsFeatureAvailable()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Mutual TLS (mTLS) is coming soon.',
            ], 403);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false], 400);
        }

        $payload = $body;
        if (array_key_exists('mtlsValidationEnabled', $payload)) {
            $payload['mtlsValidationEnabled'] = !empty($payload['mtlsValidationEnabled']) ? '1' : '0';
        }

        $this->securityService->saveMtlsSettings($payload);

        return new JsonResponse(['success' => true, 'settings' => $this->securityService->allSecuritySettings()]);
    }

    public function tokenListAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!is_object($user)) {
            return new JsonResponse(['success' => false], 403);
        }

        $beUserUid = (int) ($user->user['uid'] ?? 0);
        $tokens = array_map(fn(OAuthToken $token): array => [
            'uid' => $token->uid,
            'label' => $this->clientLabelResolver->resolveForToken($token),
            'scope' => $token->scope,
            'expires' => $token->accessTokenExpires,
            'preview' => $token->preview(),
        ], $this->tokenRepository->findActiveForUser($beUserUid));

        return new JsonResponse(['success' => true, 'tokens' => $tokens]);
    }

    public function analyticsExportAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $period = (string) ($query['period'] ?? '7d');
        $csv = $this->analyticsService->exportCsv($period);

        return new JsonResponse([
            'success' => true,
            'filename' => 'mcp-analytics-' . $period . '.csv',
            'content' => base64_encode($csv),
        ]);
    }

    public function healthPingAllAction(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'success' => true,
            'connections' => $this->healthService->pingAll($request),
        ]);
    }

    private function translate(string $key): string
    {
        return (string) ($GLOBALS['LANG']?->sL(
            'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf:' . $key,
        ) ?? $key);
    }
}
