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

use const JSON_THROW_ON_ERROR;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Reads the payload of a records_apply call from a JSON file in the TYPO3 file list.
 *
 * For batches too big to send inline: the client uploads a JSON file (file_upload, file_upload_prepare) and passes
 * its sys_file uid. The file holds {"data": {...}, "cmd": {...}, "bulk": [...]}, each key optional.
 *
 * The file is untrusted input, exactly like inline arguments: it is only read when the backend user may read it
 * (storage, file mount, read permission), it must be a .json file under a size cap, and what it contains goes through
 * the same preflight as everything else. Its content is never echoed in an answer or a log.
 */
readonly class RecordsApplyFileSource
{
    /** Hard ceiling for a payload file, whatever the upload limit says. */
    public const MAX_FILE_BYTES = 10485760;

    private const KEYS = ['data', 'cmd', 'bulk'];

    public function __construct(
        private ResourceFactory $resourceFactory,
        private AdvancedSettingsService $settings,
        private ?RecordsApplyFileAccess $fileAccess = null,
    ) {}

    /**
     * @return array{0: array<mixed>, 1: array<mixed>, 2: array<mixed>} datamap, cmdmap, bulk entries
     * @throws ToolCallException
     */
    public function read(int $fileUid): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new ToolCallException('fromFile needs an authenticated backend user. Nothing was written.', 1790500027);
        }

        $cap = min(self::MAX_FILE_BYTES, $this->settings->maxFileSizeMb() * 1024 * 1024);

        $file = null;
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            $storage = $file->getStorage();
            $allowed = isset($backendUser->getFileStorages()[$storage->getUid()])
                && $storage->isWithinFileMountBoundaries($file)
                && $storage->checkFileActionPermission('read', $file)
                // TYPO3 checks file mounts only for a backend request; the MCP server has none.
                && ($this->fileAccess?->canReadFile($file) ?? true);
        } catch (\Throwable) {
            $allowed = false;
        }

        if (!$allowed || $file === null) {
            // The same answer for "no such file" and "not yours", so file uids cannot be probed.
            throw new ToolCallException(sprintf('File %d was not found or is not accessible to you. Nothing was written.', $fileUid), 1790500027);
        }

        if (strtolower($file->getExtension()) !== 'json') {
            throw new ToolCallException('fromFile needs a .json file. Nothing was written.', 1790500028);
        }

        if ($file->getSize() > $cap) {
            throw new ToolCallException(
                sprintf('The file is too large: %d bytes, the maximum is %d. Nothing was written. Split it into several files.', $file->getSize(), $cap),
                1790500029,
            );
        }

        try {
            $contents = $file->getContents();
        } catch (\Throwable) {
            throw new ToolCallException(sprintf('File %d could not be read. Nothing was written.', $fileUid), 1790500027);
        }

        if (strlen($contents) > $cap) {
            throw new ToolCallException(sprintf('The file is too large: the maximum is %d bytes. Nothing was written.', $cap), 1790500029);
        }

        return self::parse($contents);
    }

    /**
     * @return array{0: array<mixed>, 1: array<mixed>, 2: array<mixed>}
     * @throws ToolCallException
     */
    public static function parse(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ToolCallException(sprintf('The file is not valid JSON (%s). Nothing was written.', $exception->getMessage()), 1790500030, $exception);
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ToolCallException('The file must hold a JSON object: {"data": {...}, "cmd": {...}, "bulk": [...]}. Nothing was written.', 1790500031);
        }

        $unknown = array_values(array_diff(array_map('strval', array_keys($decoded)), self::KEYS));
        if ($unknown !== []) {
            throw new ToolCallException(
                sprintf('Unknown key(s) in the file: %s. Allowed: %s. Nothing was written.', implode(', ', array_map(RecordsApplyProblems::safe(...), array_slice($unknown, 0, 5))), implode(', ', self::KEYS)),
                1790500031,
            );
        }

        $data = $decoded['data'] ?? [];
        $cmd = $decoded['cmd'] ?? [];
        $bulk = $decoded['bulk'] ?? [];

        foreach (['data' => $data, 'cmd' => $cmd] as $name => $section) {
            if (!is_array($section) || ($section !== [] && array_is_list($section))) {
                throw new ToolCallException(sprintf('"%s" in the file must be a JSON object. Nothing was written.', $name), 1790500031);
            }
        }

        if (!is_array($bulk) || ($bulk !== [] && !array_is_list($bulk))) {
            throw new ToolCallException('"bulk" in the file must be a JSON list. Nothing was written.', 1790500031);
        }

        if ($data === [] && $cmd === [] && $bulk === []) {
            throw new ToolCallException('The file holds no data, cmd or bulk. Nothing was written.', 1790500031);
        }

        return [$data, $cmd, $bulk];
    }
}
