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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

use Mcp\Exception\ToolCallException;
use TYPO3\CMS\Core\Core\Environment;

/**
 * One-time, non-public storage for large records_apply JSON payloads (not web-readable fileadmin).
 */
final class RecordsApplyPayloadStore
{
    public const TOKEN_FOLDER_PREFIX = '@records_apply_payload:';

    public const TOKEN_STORAGE_UID = 0;

    private const ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function directory(): string
    {
        $root = '';
        try {
            $root = Environment::getVarPath();
        } catch (\Throwable) {
            $root = '';
        }
        if ($root === '') {
            // ponytail: unit tests / CLI without full TYPO3 env — writable fallback under the extension.
            $root = dirname(__DIR__, 4) . '/Tests/Unit/_runtime';
        }
        $dir = rtrim($root, '/') . '/transient/mcp-records-apply';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create payload storage directory.', 1790500041);
        }

        return $dir;
    }

    public function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function isValidId(string $payloadId): bool
    {
        return preg_match(self::ID_PATTERN, $payloadId) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isPayloadUploadTokenRow(array $row): bool
    {
        return (int) ($row['storage_uid'] ?? -1) === self::TOKEN_STORAGE_UID
            && str_starts_with((string) ($row['target_folder'] ?? ''), self::TOKEN_FOLDER_PREFIX);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function payloadIdFromTokenRow(array $row): string
    {
        $fromFolder = substr((string) ($row['target_folder'] ?? ''), strlen(self::TOKEN_FOLDER_PREFIX));
        if ($this->isValidId($fromFolder)) {
            return $fromFolder;
        }

        $fromName = (string) ($row['file_name'] ?? '');
        if ($this->isValidId($fromName)) {
            return $fromName;
        }

        throw new \InvalidArgumentException('Malformed records_apply payload upload token.', 1790500040);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function storeUploadedPayload(string $payloadId, int $beUserUid, string $contents, int $validUntil): void
    {
        if (!$this->isValidId($payloadId)) {
            throw new \InvalidArgumentException('Invalid payload id.', 1790500040);
        }

        if (strlen($contents) > RecordsApplyFileSource::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Payload exceeds maximum size.', 1790500029);
        }

        $dir = $this->directory();
        $payloadPath = $this->payloadPath($payloadId);
        $metaPath = $this->metaPath($payloadId);

        if (file_put_contents($payloadPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Could not store the payload.', 1790500041);
        }

        $meta = json_encode([
            'beUserUid' => $beUserUid,
            'validUntil' => $validUntil,
            'crdate' => time(),
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($metaPath, $meta, LOCK_EX) === false) {
            @unlink($payloadPath);
            throw new \RuntimeException('Could not store payload metadata.', 1790500041);
        }

        $this->garbageCollect($dir);
    }

    /**
     * @throws ToolCallException
     */
    public function consume(string $payloadId, int $beUserUid): string
    {
        if (!$this->isValidId($payloadId)) {
            throw new ToolCallException('Invalid fromPayloadId. Nothing was written.', 1790500042);
        }

        $payloadPath = $this->payloadPath($payloadId);
        $metaPath = $this->metaPath($payloadId);

        if (!is_file($payloadPath) || !is_file($metaPath)) {
            throw new ToolCallException(
                sprintf('Payload %s was not found or has already been used. Nothing was written.', $payloadId),
                1790500042,
            );
        }

        try {
            $meta = json_decode((string) file_get_contents($metaPath), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->unlinkPair($payloadPath, $metaPath);
            throw new ToolCallException(sprintf('Payload %s could not be read. Nothing was written.', $payloadId), 1790500042);
        }

        if (!is_array($meta)
            || (int) ($meta['beUserUid'] ?? 0) !== $beUserUid
            || (int) ($meta['validUntil'] ?? 0) < time()
        ) {
            throw new ToolCallException(
                sprintf('Payload %s was not found or is not accessible to you. Nothing was written.', $payloadId),
                1790500042,
            );
        }

        try {
            $contents = (string) file_get_contents($payloadPath);
        } catch (\Throwable) {
            throw new ToolCallException(sprintf('Payload %s could not be read. Nothing was written.', $payloadId), 1790500042);
        } finally {
            $this->unlinkPair($payloadPath, $metaPath);
        }

        return $contents;
    }

    private function payloadPath(string $payloadId): string
    {
        return $this->directory() . '/' . $payloadId . '.json';
    }

    private function metaPath(string $payloadId): string
    {
        return $this->directory() . '/' . $payloadId . '.meta';
    }

    private function unlinkPair(string $payloadPath, string $metaPath): void
    {
        if (is_file($payloadPath)) {
            @unlink($payloadPath);
        }
        if (is_file($metaPath)) {
            @unlink($metaPath);
        }
    }

    private function garbageCollect(string $dir): void
    {
        $cutoff = time() - 86400;
        foreach (glob($dir . '/*.{json,meta}', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }
}
