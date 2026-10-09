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

namespace NITSAN\NsT3AF\Mcp\Http;

use const JSON_THROW_ON_ERROR;

use NITSAN\NsT3AF\Mcp\Authentication\BackendUserBootstrap;
use NITSAN\NsT3AF\Mcp\Service\FileUploadService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Target of pre-signed upload URLs from {@see \NITSAN\NsT3AF\Mcp\Tool\File\FileUploadPrepareTool}.
 *
 * Authenticated by a single-use upload token (Bearer or ?token=), not the OAuth access token.
 */
readonly class FileUploadEndpoint
{
    public function __construct(
        private FileUploadService $fileUploadService,
        private BackendUserBootstrap $backendUserBootstrap,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if ($request->getMethod() === 'OPTIONS') {
                return $this->withCorsHeaders($this->responseFactory->createResponse(204));
            }

            if (!in_array($request->getMethod(), ['PUT', 'POST'], true)) {
                return $this->jsonError('Use HTTP PUT (or POST) with the raw file bytes as request body.', 405);
            }

            $token = $this->extractToken($request);
            $tokenRow = $this->fileUploadService->consumeUploadToken($token);
            if ($tokenRow === null) {
                return $this->jsonError('Invalid, expired, or already used upload token.', 401);
            }

            try {
                $this->backendUserBootstrap->bootstrap((int) $tokenRow['be_user_uid']);
            } catch (\RuntimeException) {
                return $this->jsonError('The backend user this upload token belongs to is not available.', 401);
            }

            [$body, $uploadedFileName] = $this->extractUpload($request);

            $fileName = (string) ($tokenRow['file_name'] ?? '')
                ?: trim((string) ($request->getQueryParams()['fileName'] ?? ''))
                ?: ($uploadedFileName ?? '')
                ?: ($this->fileUploadService->extractFileNameFromContentDisposition(
                    $request->getHeaderLine('Content-Disposition'),
                ) ?? '');
            if ($fileName === '') {
                return $this->jsonError(
                    'No file name given. Pass it as ?fileName=<name.ext> query parameter or Content-Disposition header.',
                    400,
                );
            }

            $this->fileUploadService->assertFileNameIsAllowed($fileName);

            $tempPath = $this->bufferToTempFile($body, $this->fileUploadService->getMaxFileBytes());
            try {
                $folder = $this->fileUploadService->resolveFolder(
                    (int) $tokenRow['storage_uid'],
                    (string) $tokenRow['target_folder'],
                );
                $stored = $this->fileUploadService->storeFile($tempPath, $fileName, $folder);
            } finally {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }

            $data = $this->fileUploadService->describeFile($stored['file']);
            if ($stored['deduplicated']) {
                $data['deduplicated'] = true;
                $data['note'] = 'A file with identical content already existed in this storage (see identifier, '
                    . 'possibly in a different folder than requested); it is returned instead of creating a duplicate.';
            } elseif ($stored['file']->getName() !== basename($fileName)) {
                $data['renamedFrom'] = basename($fileName);
            }

            return $this->withCorsHeaders($this->jsonResponse($data, 201));
        } catch (InsufficientFolderAccessPermissionsException|InsufficientFolderWritePermissionsException $e) {
            return $this->jsonError('No permission for the target folder: ' . $e->getMessage(), 403);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->logger->error('Pre-signed upload failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->jsonError('Upload failed due to an unexpected server error (see TYPO3 log).', 500);
        }
    }

    private function extractToken(ServerRequestInterface $request): string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        return (string) ($request->getQueryParams()['token'] ?? '');
    }

    /**
     * @return array{0: StreamInterface, 1: string|null}
     */
    private function extractUpload(ServerRequestInterface $request): array
    {
        $uploadedFiles = $request->getUploadedFiles();
        if ($uploadedFiles !== []) {
            $first = reset($uploadedFiles);
            if (is_array($first)) {
                $first = reset($first);
            }
            if ($first instanceof UploadedFileInterface) {
                $clientName = $first->getClientFilename();

                return [$first->getStream(), $clientName !== null ? basename($clientName) : null];
            }
        }

        return [$request->getBody(), null];
    }

    private function bufferToTempFile(StreamInterface $body, int $maxBytes): string
    {
        $tempPath = GeneralUtility::tempnam('nst3af_upload_');
        $handle = fopen($tempPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary file for writing.', 1747330001);
        }

        $bytes = 0;
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                fclose($handle);
                @unlink($tempPath);
                throw new \InvalidArgumentException(
                    'The file exceeds the maximum size of ' . round($maxBytes / 1048576) . ' MiB '
                    . '(configurable via mcpMaxFileSizeMb).',
                    1747330002,
                );
            }
            fwrite($handle, $chunk);
        }
        fclose($handle);

        if ($bytes === 0) {
            @unlink($tempPath);
            throw new \InvalidArgumentException(
                'The request body was empty — send the raw file bytes as PUT/POST body.',
                1747330003,
            );
        }

        return $tempPath;
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(array $payload, int $status): ResponseInterface
    {
        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }

    private function jsonError(string $message, int $status): ResponseInterface
    {
        return $this->withCorsHeaders($this->jsonResponse(['error' => $message], $status));
    }

    private function withCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'PUT, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Content-Disposition')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
