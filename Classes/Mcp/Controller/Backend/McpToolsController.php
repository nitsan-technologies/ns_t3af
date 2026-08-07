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
use NITSAN\NsT3AF\Mcp\Service\Backend\McpCustomToolService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpSkillHubService;
use NITSAN\NsT3AF\Mcp\Service\McpToolsRegistryService;
use NITSAN\NsT3AF\Service\DashboardPeriodResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX endpoints for the MCP Tools backend module tab.
 */
final class McpToolsController
{
    public function __construct(
        private readonly McpToolsRegistryService $registryService,
        private readonly McpPlaygroundService $playgroundService,
        private readonly McpPromptTemplateService $promptTemplateService,
        private readonly McpCustomToolService $customToolService,
        private readonly McpSkillHubService $skillHubService,
        private readonly RecordAccessEnforcer $recordAccessEnforcer,
    ) {}

    private function denyUnlessCanModifyCatalog(string $catalogId): ?JsonResponse
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $this->recordAccessEnforcer->denyUnlessCanModifyCatalogId(
            $user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication ? $user : null,
            $catalogId,
        );
    }

    public function discoverAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_DISCOVERED_TABLES)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $newCount = $this->registryService->discoverExtensionTables();

            return new JsonResponse([
                'success' => true,
                'newCount' => $newCount,
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function toggleTableAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_DISCOVERED_TABLES)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $uid = (int) ($body['uid'] ?? 0);
        if ($uid <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid table uid'], 400);
        }

        $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $this->registryService->setTableEnabled($uid, $enabled);

            return new JsonResponse(['success' => true, 'enabled' => $enabled]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function saveTableAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_DISCOVERED_TABLES)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $uid = (int) ($body['uid'] ?? 0);
        $label = trim((string) ($body['label'] ?? ''));
        $prefix = trim((string) ($body['prefix'] ?? ''));

        if ($uid <= 0 || $label === '' || $prefix === '') {
            return new JsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
        }

        try {
            $this->registryService->saveTableConfig($uid, $label, $prefix);

            return new JsonResponse([
                'success' => true,
                'label' => $label,
                'prefix' => $prefix,
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function playgroundInvokeAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $toolName = trim((string) ($body['toolName'] ?? ''));
        if ($toolName === '') {
            return new JsonResponse(['success' => false, 'message' => 'Tool name is required'], 400);
        }

        $arguments = $body['arguments'] ?? [];
        if (!is_array($arguments)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid arguments payload'], 400);
        }

        $result = $this->playgroundService->invoke($toolName, $arguments);

        return new JsonResponse(array_merge(['success' => $result['success']], $result));
    }

    public function playgroundToolStatsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $toolName = trim((string) ($body['toolName'] ?? ''));
        if ($toolName === '') {
            return new JsonResponse(['success' => false, 'message' => 'Tool name is required'], 400);
        }

        $periodQuery = [
            'period' => trim((string) ($body['period'] ?? DashboardPeriodResolver::PRESET_7D)),
        ];
        if ($periodQuery['period'] === DashboardPeriodResolver::PRESET_CUSTOM) {
            $periodQuery['from'] = trim((string) ($body['from'] ?? ''));
            $periodQuery['to'] = trim((string) ($body['to'] ?? ''));
        }
        $stats = $this->playgroundService->getToolStats($toolName, $periodQuery);

        return new JsonResponse(['success' => true, 'stats' => $stats]);
    }

    public function promptCreateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_PROMPT_TEMPLATES)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $templateBody = trim((string) ($body['templateBody'] ?? ''));
        $arguments = $body['arguments'] ?? [];

        if ($name === '' || $templateBody === '') {
            return new JsonResponse(['success' => false, 'message' => 'Name and template body are required'], 400);
        }

        if (!is_array($arguments)) {
            $arguments = [];
        }
        $arguments = array_values(array_filter($arguments, 'is_array'));

        try {
            $uid = $this->promptTemplateService->create($name, $description, $templateBody, $arguments);

            return new JsonResponse(['success' => true, 'uid' => $uid]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function promptUpdateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_PROMPT_TEMPLATES)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $uid = (int) ($body['uid'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $templateBody = trim((string) ($body['templateBody'] ?? ''));
        $arguments = $body['arguments'] ?? [];

        if ($uid <= 0 || $name === '' || $templateBody === '') {
            return new JsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
        }

        if (!is_array($arguments)) {
            $arguments = [];
        }
        $arguments = array_values(array_filter($arguments, 'is_array'));

        try {
            $this->promptTemplateService->update($uid, $name, $description, $templateBody, $arguments);

            return new JsonResponse(['success' => true, 'uid' => $uid]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function promptDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_PROMPT_TEMPLATES)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $uid = (int) ($body['uid'] ?? 0);
        if ($uid <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid prompt uid'], 400);
        }

        try {
            $this->promptTemplateService->delete($uid);

            return new JsonResponse(['success' => true]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function customToolCreateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_CUSTOM_TOOLS)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $label = trim((string) ($body['label'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $handlerType = trim((string) ($body['handlerType'] ?? 'php'));
        $handlerValue = trim((string) ($body['handlerValue'] ?? ''));
        $parameters = $body['parameters'] ?? [];

        if ($label === '' || $handlerValue === '') {
            return new JsonResponse(['success' => false, 'message' => 'Label and handler configuration are required'], 400);
        }

        if (!is_array($parameters)) {
            $parameters = [];
        }
        $parameters = array_values(array_filter($parameters, 'is_array'));

        try {
            $uid = $this->customToolService->create(
                $label,
                $description,
                $handlerType,
                $handlerValue,
                $parameters,
            );

            return new JsonResponse(['success' => true, 'uid' => $uid]);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function customToolUpdateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_CUSTOM_TOOLS)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $uid = (int) ($body['uid'] ?? 0);
        $label = trim((string) ($body['label'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $handlerType = trim((string) ($body['handlerType'] ?? 'php'));
        $handlerValue = trim((string) ($body['handlerValue'] ?? ''));
        $parameters = $body['parameters'] ?? [];

        if ($uid <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid custom tool uid'], 400);
        }

        if ($label === '' || $handlerValue === '') {
            return new JsonResponse(['success' => false, 'message' => 'Label and handler configuration are required'], 400);
        }

        if (!is_array($parameters)) {
            $parameters = [];
        }
        $parameters = array_values(array_filter($parameters, 'is_array'));

        try {
            $this->customToolService->update(
                $uid,
                $label,
                $description,
                $handlerType,
                $handlerValue,
                $parameters,
            );

            return new JsonResponse(['success' => true, 'uid' => $uid]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function customToolDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyCatalog(AiUniverseRecordMap::MCP_CUSTOM_TOOLS)) {
            return $denied;
        }
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $uid = (int) ($body['uid'] ?? 0);
        if ($uid <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid custom tool uid'], 400);
        }

        try {
            $this->customToolService->delete($uid);

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function skillImportAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $source = trim((string) ($body['source'] ?? 'manual'));

        try {
            if ($source === 'url') {
                $url = trim((string) ($body['url'] ?? ''));
                $result = $this->skillHubService->importFromUrl($url);

                return new JsonResponse($result, $result['success'] ? 200 : 400);
            }

            if ($source === 'file') {
                $bodyContent = trim((string) ($body['body'] ?? ''));
                $fileName = trim((string) ($body['fileName'] ?? ''));
                $result = $this->skillHubService->importFromMarkdown($bodyContent, 'file', '', $fileName);

                return new JsonResponse($result, $result['success'] ? 200 : 400);
            }

            $name = trim((string) ($body['name'] ?? ''));
            $triggerKeyword = trim((string) ($body['triggerKeyword'] ?? ''));
            $bodyContent = trim((string) ($body['body'] ?? ''));
            if ($name === '' || $triggerKeyword === '' || $bodyContent === '') {
                return new JsonResponse(['success' => false, 'message' => 'Name, trigger, and body are required'], 400);
            }

            $uid = $this->skillHubService->importSkill(
                $name,
                $triggerKeyword,
                $bodyContent,
                'manual',
                trim((string) ($body['sourceUrl'] ?? '')),
            );

            return new JsonResponse(['success' => true, 'uid' => $uid, 'message' => 'Skill imported']);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public function skillRemoveAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUser()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $body = $this->parseBody($request);
        $uid = (int) ($body['uid'] ?? 0);
        if ($uid <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid skill uid'], 400);
        }

        try {
            $this->skillHubService->remove($uid);

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    /** @return array<string, mixed> */
    private function parseBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function isBackendUser(): bool
    {
        return ($GLOBALS['BE_USER'] ?? null) instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
    }
}
