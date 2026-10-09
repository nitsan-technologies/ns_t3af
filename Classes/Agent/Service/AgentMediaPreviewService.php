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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Thumbnails for the images a tool result or a prepared change is about.
 *
 * - A generated image, a file list, a file's metadata or an alt text draft shows the image
 *   itself instead of only its name or path.
 * - Thumbnails are processed preview files (small, also for non-public storages that have a
 *   processing folder); the original opens on click.
 * - Only files the backend user may read are shown.
 *
 * @internal
 */
final readonly class AgentMediaPreviewService
{
    public const MAX_PREVIEWS = 6;

    private const THUMBNAIL_WIDTH = 320;

    private const THUMBNAIL_HEIGHT = 240;

    private const MAX_DEPTH = 4;

    /** Keys whose integer value is a sys_file uid. */
    private const FILE_UID_KEYS = ['fileUid', 'file_uid', 'sysFileUid', 'fileId'];

    public function __construct(
        private ResourceFactory $resourceFactory,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array{fileUid: int, url: string, href: string, name: string, alt: string}>
     */
    public function forDetails(mixed $details): array
    {
        $previews = [];
        foreach (self::fileReferences($details, self::MAX_PREVIEWS * 2) as $reference) {
            $preview = $reference['uid'] > 0
                ? $this->previewForFile($reference['uid'])
                : self::previewForUrl($reference['url'], $reference['name']);
            if ($preview !== null) {
                $previews[] = $preview;
            }
            if (count($previews) >= self::MAX_PREVIEWS) {
                break;
            }
        }

        return $previews;
    }

    /**
     * The image a record stands for: a sys_file, or the file of a sys_file_metadata row.
     *
     * @return list<array{fileUid: int, url: string, href: string, name: string, alt: string}>
     */
    public function forRecord(string $table, int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        $fileUid = match ($table) {
            'sys_file' => $uid,
            'sys_file_metadata' => $this->fileOfMetadata($uid),
            default => 0,
        };
        $preview = $fileUid > 0 ? $this->previewForFile($fileUid) : null;

        return $preview !== null ? [$preview] : [];
    }

    /**
     * File uids (and plain image URLs) found in a tool result, in order, without duplicates.
     *
     * @return list<array{uid: int, url: string, name: string}>
     */
    public static function fileReferences(mixed $details, int $limit = self::MAX_PREVIEWS): array
    {
        $found = [];
        self::collect($details, $found, 0, $limit);

        return array_values($found);
    }

    /**
     * @param array<string, array{uid: int, url: string, name: string}> $found
     */
    private static function collect(mixed $value, array &$found, int $depth, int $limit): void
    {
        if (!is_array($value) || $depth > self::MAX_DEPTH || count($found) >= $limit) {
            return;
        }
        $name = is_scalar($value['name'] ?? null) ? (string) $value['name'] : '';

        foreach (self::FILE_UID_KEYS as $key) {
            if (is_numeric($value[$key] ?? null) && (int) $value[$key] > 0) {
                $found['uid:' . (int) $value[$key]] ??= ['uid' => (int) $value[$key], 'url' => '', 'name' => $name];
            }
        }
        // A row of the file list / file info: uid plus file properties.
        if (is_numeric($value['uid'] ?? null) && (int) $value['uid'] > 0
            && (isset($value['mimeType']) || (isset($value['identifier']) && isset($value['extension'])))
        ) {
            $found['uid:' . (int) $value['uid']] ??= ['uid' => (int) $value['uid'], 'url' => '', 'name' => $name];
        } elseif (is_string($value['publicUrl'] ?? null) && !self::hasFileUid($value)) {
            $url = trim($value['publicUrl']);
            if ($url !== '') {
                $found['url:' . $url] ??= ['uid' => 0, 'url' => $url, 'name' => $name];
            }
        }

        foreach ($value as $child) {
            if (count($found) >= $limit) {
                return;
            }
            if (is_array($child)) {
                self::collect($child, $found, $depth + 1, $limit);
            }
        }
    }

    /**
     * @param array<mixed> $value
     */
    private static function hasFileUid(array $value): bool
    {
        foreach (self::FILE_UID_KEYS as $key) {
            if (is_numeric($value[$key] ?? null) && (int) $value[$key] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{fileUid: int, url: string, href: string, name: string, alt: string}|null
     */
    private function previewForFile(int $fileUid): ?array
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            if ($file->isMissing() || !$file->checkActionPermission('read')) {
                return null;
            }
            if (!str_starts_with(strtolower($file->getMimeType()), 'image/')) {
                return null;
            }
            $original = self::normalizeUrl((string) $file->getPublicUrl());
            $thumbnail = $original;
            try {
                $processed = $file->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, [
                    'width' => self::THUMBNAIL_WIDTH,
                    'height' => self::THUMBNAIL_HEIGHT,
                ]);
                $processedUrl = self::normalizeUrl((string) $processed->getPublicUrl());
                if ($processedUrl !== '') {
                    $thumbnail = $processedUrl;
                }
            } catch (\Throwable) {
                // No processing (e.g. no image tool installed): the original is shown scaled down.
            }
            if ($thumbnail === '') {
                return null;
            }

            return [
                'fileUid' => $fileUid,
                'url' => $thumbnail,
                'href' => $original !== '' ? $original : $thumbnail,
                'name' => $file->getName(),
                'alt' => trim((string) ($file->getProperty('alternative') ?? '')),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{fileUid: int, url: string, href: string, name: string, alt: string}|null
     */
    private static function previewForUrl(string $url, string $name): ?array
    {
        $url = self::normalizeUrl($url);
        if ($url === '' || preg_match('/\.(png|jpe?g|gif|webp|svg|avif)(\?|$)/i', $url) !== 1) {
            return null;
        }

        return [
            'fileUid' => 0,
            'url' => $url,
            'href' => $url,
            'name' => $name !== '' ? $name : basename((string) parse_url($url, PHP_URL_PATH)),
            'alt' => '',
        ];
    }

    /**
     * "fileadmin/x.jpg" → "/fileadmin/x.jpg"; only http(s) and site-absolute URLs are kept.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1 || str_starts_with($url, '/')) {
            return $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return '';
        }

        return '/' . ltrim($url, '/');
    }

    private function fileOfMetadata(int $metadataUid): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
            $fileUid = $queryBuilder
                ->select('file')
                ->from('sys_file_metadata')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($metadataUid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();

            return is_numeric($fileUid) ? (int) $fileUid : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
