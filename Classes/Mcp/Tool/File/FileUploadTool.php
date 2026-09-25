<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

namespace NITSAN\NsT3AF\Mcp\Tool\File;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\FileUploadService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class FileUploadTool implements McpFalStorageToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private FileService $fileService,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
        private AdvancedSettingsService $advancedSettingsService,
        private ?FileUploadService $fileUploadService = null,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $storageUid = (int) ($arguments['storageUid'] ?? 1);
        $fileName = (string) ($arguments['fileName'] ?? '');
        $directoryPath = (string) ($arguments['directoryPath'] ?? '/');
        $targetPath = rtrim($directoryPath, '/') . '/' . ltrim($fileName, '/');

        return $this->confirmationPlanBuilder->confirmation(
            'create',
            'file_upload',
            '_upload',
            '',
            'upload to ' . $targetPath,
            [
                'fileName' => $fileName,
                'directoryPath' => $directoryPath,
                'storageUid' => $storageUid,
            ],
        );
    }

    #[McpTool(
        name: 'file_upload',
        description: 'Upload a file to a storage directory. Prefer file_upload_prepare for binaries;'
            . ' use content for text; base64Content only for tiny files under the configured base64 limit.'
            . ' Optional expectedSha1/expectedSize verify the payload you send (before FAL processing).',
    )]
    public function execute(
        string $fileName,
        string $base64Content = '',
        string $content = '',
        string $directoryPath = '/',
        int $storageUid = 1,
        string $expectedSha1 = '',
        int $expectedSize = 0,
    ): string {
        if ($this->fileUploadService !== null) {
            $this->fileUploadService->assertFileNameIsAllowed($fileName);
        }

        $fileContent = $this->resolveContent($base64Content, $content, $expectedSha1, $expectedSize);
        $result = $this->fileService->uploadFile($storageUid, $directoryPath, $fileName, $fileContent);

        return json_encode(
            $this->annotateIfRewritten($result, $fileContent, $expectedSha1, $expectedSize),
            JSON_THROW_ON_ERROR,
        );
    }

    private function resolveContent(
        string $base64Content,
        string $content,
        string $expectedSha1,
        int $expectedSize,
    ): string {
        if ($base64Content !== '' && $content !== '') {
            throw new ToolCallException('Provide either "content" or "base64Content", not both');
        }

        if ($base64Content === '' && $content === '') {
            throw new ToolCallException(
                'Either "content" or "base64Content" must be provided. For larger binaries use file_upload_prepare.',
            );
        }

        if ($content !== '') {
            $this->assertExpectedIntegrity($content, $expectedSha1, $expectedSize);

            return $content;
        }

        $decoded = base64_decode($base64Content, true);
        if ($decoded === false) {
            throw new ToolCallException('Invalid base64 content');
        }

        $maxBytes = $this->advancedSettingsService->maxBase64UploadBytes();
        if (strlen($decoded) > $maxBytes) {
            throw new ToolCallException(
                'base64Content exceeds the maximum of ' . $maxBytes . ' bytes.'
                . ' Use file_upload_prepare (then PUT) for larger files.',
            );
        }

        $this->assertExpectedIntegrity($decoded, $expectedSha1, $expectedSize);

        return $decoded;
    }

    private function assertExpectedIntegrity(string $payload, string $expectedSha1, int $expectedSize): void
    {
        if ($expectedSize > 0 && strlen($payload) !== $expectedSize) {
            throw new ToolCallException(
                'Payload size ' . strlen($payload) . ' does not match expectedSize ' . $expectedSize . '.',
            );
        }

        if ($expectedSha1 !== '' && sha1($payload) !== $expectedSha1) {
            throw new ToolCallException('Payload SHA-1 does not match expectedSha1.');
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function annotateIfRewritten(
        array $result,
        string $inboundContent,
        string $expectedSha1,
        int $expectedSize,
    ): array {
        $storedSha1 = is_string($result['sha1'] ?? null) ? $result['sha1'] : '';
        $storedSize = isset($result['size']) ? (int) $result['size'] : 0;
        $inboundSha1 = sha1($inboundContent);
        $inboundSize = strlen($inboundContent);

        if ($storedSha1 === '' || ($storedSha1 === $inboundSha1 && $storedSize === $inboundSize)) {
            return $result;
        }

        $result['rewritten'] = true;
        $result['inboundSha1'] = $inboundSha1;
        $result['inboundSize'] = $inboundSize;
        $result['note'] = trim(
            (is_string($result['note'] ?? null) ? $result['note'] . ' ' : '')
            . 'FAL (or a sanitizer) rewrote the stored bytes after upload; '
            . 'response size/sha1 are the stored file. '
            . 'expectedSha1/expectedSize only validate the payload you sent.',
        );

        if ($expectedSha1 !== '' || $expectedSize > 0) {
            $result['integrityCheckedInbound'] = true;
        }

        return $result;
    }
}
