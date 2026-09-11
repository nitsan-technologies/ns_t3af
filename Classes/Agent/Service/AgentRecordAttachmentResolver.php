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

/**
 * Parses @table:uid and @file:storageUid:identifier composer attachments.
 *
 * @internal
 */
final readonly class AgentRecordAttachmentResolver
{
    /** @var array<string, string> table → read tool name */
    private const TABLE_READ_TOOLS = [
        'tt_content' => 'content_get',
        'pages' => 'pages_get',
        'sys_redirect' => 'redirect_get',
        'be_users' => 'backend_user_get',
        'be_groups' => 'backend_group_get',
    ];

    /** @var array<string, string> read tool name → table */
    private const READ_TOOL_TABLES = [
        'content_get' => 'tt_content',
        'pages_get' => 'pages',
        'redirect_get' => 'sys_redirect',
        'backend_user_get' => 'be_users',
        'backend_group_get' => 'be_groups',
    ];

    /**
     * @return list<array{table: string, uid: int}>
     */
    public function extractAttachments(string $message): array
    {
        if (preg_match_all('/@([a-z0-9_]+):(\d+)/i', $message, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $attachments = [];
        $seen = [];
        foreach ($matches as $match) {
            $table = strtolower($match[1]);
            if ($table === 'file') {
                continue;
            }
            $uid = (int) $match[2];
            if ($table === '' || $uid <= 0) {
                continue;
            }
            $key = $table . ':' . $uid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $attachments[] = ['table' => $table, 'uid' => $uid];
        }

        return $attachments;
    }

    /**
     * @return list<array{storageUid: int, identifier: string}>
     */
    public function extractFileAttachments(string $message): array
    {
        if (preg_match_all('/@file:(\d+):(\S+)/i', $message, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $attachments = [];
        $seen = [];
        foreach ($matches as $match) {
            $storageUid = (int) $match[1];
            $identifier = trim($match[2]);
            if ($storageUid <= 0 || $identifier === '') {
                continue;
            }
            $key = $storageUid . ':' . $identifier;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $attachments[] = [
                'storageUid' => $storageUid,
                'identifier' => $identifier,
            ];
        }

        return $attachments;
    }

    /**
     * @param callable(string): bool $toolExists
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    public function resolveReadInvocation(string $table, int $uid, callable $toolExists): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $candidates = [];
        if (isset(self::TABLE_READ_TOOLS[$table])) {
            $candidates[] = self::TABLE_READ_TOOLS[$table];
        }
        $candidates[] = $table . '_get';

        foreach (array_unique($candidates) as $tool) {
            if ($toolExists($tool)) {
                return [
                    'tool' => $tool,
                    'arguments' => ['uid' => $uid],
                ];
            }
        }

        return null;
    }

    /**
     * @param callable(string): bool $toolExists
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    public function resolveFileReadInvocation(int $storageUid, string $identifier, callable $toolExists): ?array
    {
        if ($storageUid <= 0 || trim($identifier) === '') {
            return null;
        }

        if (!$toolExists('file_get_info')) {
            return null;
        }

        return [
            'tool' => 'file_get_info',
            'arguments' => [
                'storageUid' => $storageUid,
                'fileIdentifier' => $identifier,
            ],
        ];
    }

    /**
     * @param list<array{table: string, uid: int}> $attachments
     * @return array<string, mixed>
     */
    public function mergeUidFromAttachments(string $toolName, array $attachments): array
    {
        $table = self::READ_TOOL_TABLES[strtolower(trim($toolName))] ?? null;
        if ($table === null) {
            return [];
        }

        foreach ($attachments as $attachment) {
            if (($attachment['table'] ?? '') === $table && (int) ($attachment['uid'] ?? 0) > 0) {
                return ['uid' => (int) $attachment['uid']];
            }
        }

        return [];
    }

    public function formatFileAttachmentToken(int $storageUid, string $identifier): string
    {
        return '@file:' . $storageUid . ':' . ltrim($identifier, '/');
    }
}
