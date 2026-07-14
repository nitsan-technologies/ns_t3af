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

use NITSAN\NsT3AF\Service\BrandContextDocumentExtractor;
use NITSAN\NsT3AF\Service\BrandContextResearchService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * AJAX endpoints for AI Context drawer: auto-research and document extraction.
 *
 * @internal
 */
final class BrandContextAjaxController
{
    public function __construct(
        private readonly BrandContextResearchService $researchService,
        private readonly BrandContextDocumentExtractor $documentExtractor,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function researchAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteError = $this->requireSiteStorage($request);
        if ($siteError instanceof JsonResponse) {
            return $siteError;
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $url = trim((string) ($body['url'] ?? $body['websiteUrl'] ?? ''));
        $pageId = (int) ($body['id'] ?? $request->getQueryParams()['id'] ?? 0);

        if ($url === '') {
            return $this->errorResponse(
                'module.aiContext.error.researchUrlRequired',
                'Website URL is required.',
                400,
            );
        }

        try {
            $result = $this->researchService->research($url, $pageId);

            return new JsonResponse($result->toArray());
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse(
                'module.aiContext.error.researchUrlRequired',
                $exception->getMessage(),
                400,
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse(
                'module.aiContext.error.researchFailed',
                'Brand research failed: ' . $exception->getMessage(),
                500,
                $exception->getMessage(),
            );
        }
    }

    public function extractDocumentsAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteError = $this->requireSiteStorage($request);
        if ($siteError instanceof JsonResponse) {
            return $siteError;
        }

        $uploaded = $this->collectUploadedFiles($request);
        if ($uploaded === []) {
            return $this->errorResponse(
                'module.aiContext.error.documentsRequired',
                'Select at least one document to upload.',
                400,
            );
        }

        if (count($uploaded) > BrandContextDocumentExtractor::MAX_FILES) {
            return $this->errorResponse(
                'module.aiContext.error.documentsLimit',
                sprintf('Maximum %d documents allowed.', BrandContextDocumentExtractor::MAX_FILES),
                400,
            );
        }

        $tempFiles = [];
        try {
            foreach ($uploaded as $file) {
                $tempFiles[] = $this->materializeUpload($file);
            }

            $result = $this->documentExtractor->extractFromFiles($tempFiles);
            if ($result['extract'] === '' && $result['warnings'] !== []) {
                return new JsonResponse([
                    'success' => false,
                    'title' => $this->translate('module.aiContext.error.documentsExtractFailed'),
                    'message' => implode(' ', $result['warnings']),
                    'warnings' => $result['warnings'],
                ], 400);
            }

            return new JsonResponse([
                'success' => true,
                'extract' => $result['extract'],
                'files' => $result['files'],
                'warnings' => $result['warnings'],
            ]);
        } catch (\Throwable $exception) {
            return $this->errorResponse(
                'module.aiContext.error.documentsExtractFailed',
                'Document extraction failed: ' . $exception->getMessage(),
                500,
                $exception->getMessage(),
            );
        } finally {
            foreach ($tempFiles as $temp) {
                if (isset($temp['path']) && is_file($temp['path'])) {
                    @unlink($temp['path']);
                }
            }
        }
    }

    private function requireSiteStorage(ServerRequestInterface $request): ?JsonResponse
    {
        $resolution = $this->siteStorageContext->resolveFromRequest($request);
        if ($resolution->isResolved()) {
            return null;
        }

        $labelKey = $resolution->reason === 'page_not_in_site'
            ? 'module.siteStorage.pageNotInSite'
            : 'module.siteStorage.pageRequired';

        return $this->errorResponse(
            $labelKey,
            $resolution->reason === 'page_not_in_site'
                ? 'The selected page is not part of a configured site.'
                : 'Select a page in the page tree to manage AI Context.',
            400,
        );
    }

    /**
     * @return list<UploadedFileInterface>
     */
    private function collectUploadedFiles(ServerRequestInterface $request): array
    {
        $uploadedFiles = $request->getUploadedFiles();
        $candidates = $uploadedFiles['documents'] ?? $uploadedFiles;
        if ($candidates instanceof UploadedFileInterface) {
            $candidates = [$candidates];
        }
        if (!is_array($candidates)) {
            return [];
        }

        $files = [];
        foreach ($candidates as $upload) {
            if ($upload instanceof UploadedFileInterface) {
                if ($upload->getError() === UPLOAD_ERR_OK && $upload->getSize() > 0) {
                    $files[] = $upload;
                }
                continue;
            }
            if (is_array($upload)) {
                foreach ($upload as $nested) {
                    if ($nested instanceof UploadedFileInterface
                        && $nested->getError() === UPLOAD_ERR_OK
                        && $nested->getSize() > 0
                    ) {
                        $files[] = $nested;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @return array{path: string, name: string}
     */
    private function materializeUpload(UploadedFileInterface $upload): array
    {
        $clientName = $upload->getClientFilename() ?? 'upload.bin';
        $extension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $tempPath = tempnam(sys_get_temp_dir(), 'aiu-brand-doc-');
        if ($tempPath === false) {
            throw new \RuntimeException('Could not create temporary file.');
        }

        $targetPath = $extension !== '' ? $tempPath . '.' . $extension : $tempPath;
        if ($targetPath !== $tempPath) {
            rename($tempPath, $targetPath);
        }

        $upload->moveTo($targetPath);

        return [
            'path' => $targetPath,
            'name' => $clientName,
        ];
    }

    private function errorResponse(string $labelKey, string $fallback, int $status, ?string $detail = null): JsonResponse
    {
        $message = $detail ?? $fallback;

        return new JsonResponse([
            'success' => false,
            'title' => $this->translate($labelKey),
            'message' => $message,
        ], $status);
    }

    private function translate(string $labelKey): string
    {
        $lang = $this->getLanguageService();
        $fullKey = str_starts_with($labelKey, 'LLL:')
            ? $labelKey
            : 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:' . $labelKey;
        $translated = $lang->sL($fullKey);

        return $translated !== '' && !str_starts_with($translated, 'LLL:') ? $translated : $labelKey;
    }

    private function getLanguageService(): LanguageService
    {
        return $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);
    }
}
