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

namespace NITSAN\NsT3AF\Controller\Backend;

use NITSAN\NsT3AF\Access\Dto\GroupConfig;
use NITSAN\NsT3AF\Service\BeGroupAccessService;
use NITSAN\NsT3AF\Service\PermissionMatrixService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

final class AccessRolesAjaxController
{
    public function __construct(
        private readonly BeGroupAccessService $beGroupAccessService,
        private readonly PermissionMatrixService $permissionMatrixService,
    ) {}

    public function groupsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->assertAdmin()) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        return new JsonResponse([
            'ok' => true,
            'groups' => $this->beGroupAccessService->listGroupsSummary(),
        ]);
    }

    public function groupAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->assertAdmin()) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $uid = (int) ($request->getQueryParams()['uid'] ?? 0);
        $detail = $this->beGroupAccessService->getGroupDetail($uid);
        if ($detail === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Group not found'], 404);
        }

        return new JsonResponse(['ok' => true, 'group' => $detail]);
    }

    public function applyAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->assertAdmin()) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $body = $this->parseRequestBody($request);

        $uid = (int) ($body['groupUid'] ?? 0);
        $configRaw = $this->normalizeConfigPayload($body['config'] ?? null);
        if ($uid <= 0 || !is_array($configRaw)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid payload'], 400);
        }

        try {
            $config = GroupConfig::fromArray($configRaw);
            $this->beGroupAccessService->applyConfig($uid, $config);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $detail = $this->beGroupAccessService->getGroupDetail($uid);

        return new JsonResponse([
            'ok' => true,
            'group' => $detail,
            'groups' => $this->beGroupAccessService->listGroupsSummary(),
        ]);
    }

    public function matrixAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->assertAdmin()) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        return new JsonResponse([
            'ok' => true,
            'matrix' => $this->permissionMatrixService->buildMatrix(),
        ]);
    }

    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->assertAdmin()) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $body = $this->parseRequestBody($request);

        $configRaw = $this->normalizeConfigPayload($body['config'] ?? null);
        if (!is_array($configRaw)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid payload'], 400);
        }

        $groupUid = (int) ($body['groupUid'] ?? 0);

        try {
            $config = GroupConfig::fromArray($configRaw);
            $preview = $this->beGroupAccessService->previewConfig(
                $config,
                $groupUid > 0 ? $groupUid : null,
            );
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return new JsonResponse(['ok' => true, 'preview' => $preview]);
    }

    private function assertAdmin(): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user instanceof BackendUserAuthentication && $user->isAdmin();
    }

    /**
     * AjaxRequest.post() with application/json sends a raw JSON body; getParsedBody() is often empty.
     *
     * @return array<string, mixed>
     */
    private function parseRequestBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeConfigPayload(mixed $configRaw): ?array
    {
        if (is_array($configRaw)) {
            return $configRaw;
        }

        if (!is_string($configRaw) || trim($configRaw) === '') {
            return null;
        }

        $decoded = json_decode($configRaw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
