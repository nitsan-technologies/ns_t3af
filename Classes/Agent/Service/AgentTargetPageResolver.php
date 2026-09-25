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

use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Resolves an explicit page target from NL agent messages (e.g. "Get Page 3").
 *
 * @internal
 */
final readonly class AgentTargetPageResolver
{
    public function __construct(
        private McpPlaygroundService $playgroundService,
    ) {}

    public function resolveFromMessage(
        string $message,
        int $fallbackPageId,
        BackendUserAuthentication $user,
    ): int {
        $reference = $this->extractPageReference($message);
        if ($reference === null) {
            return $fallbackPageId;
        }

        if (preg_match('/^\d+$/', $reference)) {
            $uid = (int) $reference;
            if ($uid > 0 && $this->userCanReadPage($uid, $user)) {
                return $uid;
            }
        }

        $uid = $this->searchPageUidByTitle($reference, $user);
        if ($uid > 0) {
            return $uid;
        }

        return $fallbackPageId;
    }

    public function extractPageReference(string $message): ?string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/@pages:(\d+)/i', $trimmed, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\bpage\s+(?:uid|id|#)\s*(\d+)\b/i', $trimmed, $matches)) {
            return $matches[1];
        }

        if (preg_match(
            '/\b(?:get|fetch|read|show|open|inspect|use|from)\s+(?:the\s+)?(.+?)(?:\s*[.,]|\s+translate|\s+then|\s+and|\s+optimi)/i',
            $trimmed,
            $matches,
        )) {
            $candidate = trim($matches[1]);
            if (preg_match('/^page\b/i', $candidate)) {
                return $candidate;
            }
        }

        if (preg_match(
            '/\b(?:on|for)\s+(?:the\s+)?(page\s+[^.,]+?)(?:\s*[.,]|\s+translate|\s+then|\s+optimi|$)/i',
            $trimmed,
            $matches,
        )) {
            return trim($matches[1]);
        }

        return null;
    }

    private function searchPageUidByTitle(string $reference, BackendUserAuthentication $user): int
    {
        $search = trim($reference);
        if ($search === '') {
            return 0;
        }

        $result = $this->playgroundService->invoke('pages_search', [
            'search' => $search,
            'limit' => 10,
        ]);
        if (!(bool) ($result['success'] ?? false)) {
            return 0;
        }

        $records = $this->normalizeRecords($result['result'] ?? null);
        $normalizedNeedle = strtolower($search);

        foreach ($records as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            if ($uid <= 0 || !$this->userCanReadPage($uid, $user)) {
                continue;
            }

            $title = strtolower(trim((string) ($record['title'] ?? '')));
            if ($title === $normalizedNeedle || str_contains($title, $normalizedNeedle)) {
                return $uid;
            }
        }

        foreach ($records as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            if ($uid > 0 && $this->userCanReadPage($uid, $user)) {
                return $uid;
            }
        }

        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRecords(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return [];
        }
        if (isset($payload['records']) && is_array($payload['records'])) {
            /** @var list<array<string, mixed>> $records */
            $records = array_values(array_filter($payload['records'], 'is_array'));

            return $records;
        }

        return [];
    }

    private function userCanReadPage(int $pageId, BackendUserAuthentication $user): bool
    {
        return BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }
}
