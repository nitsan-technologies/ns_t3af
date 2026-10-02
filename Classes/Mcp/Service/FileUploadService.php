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

namespace NITSAN\NsT3AF\Mcp\Service;

use Doctrine\DBAL\ParameterType;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use NITSAN\NsT3AF\Service\PublicUrlValidator;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\IllegalFileExtensionException;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Create-only FAL uploads for MCP: deny dangerous names, rename on conflict,
 * SHA1 dedupe, SSRF-safe URL download, and single-use upload tokens.
 */
readonly class FileUploadService
{
    private const TABLE_UPLOAD_TOKENS = 'tx_nst3af_upload_tokens';

    private const MAX_REDIRECTS = 5;

    /**
     * Formats a browser would execute in the site's origin (stored XSS).
     * TYPO3's fileDenyPattern does not cover these.
     *
     * @var list<string>
     */
    private const BROWSER_EXECUTABLE_EXTENSIONS = ['htm', 'html', 'xhtml', 'js', 'mjs', 'svgz', 'swf', 'hta'];

    /**
     * Formats the server itself could execute. Refused independently of
     * TYPO3's configurable fileDenyPattern.
     *
     * @var list<string>
     */
    private const SERVER_EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'phpsh', 'phtml', 'phtm', 'pht', 'phar',
        'shtml', 'shtm', 'cgi', 'pl', 'py', 'rb', 'sh', 'htaccess',
    ];

    /**
     * Exact names that reconfigure the web server / PHP rather than being executed.
     *
     * @var list<string>
     */
    private const DENIED_FILE_NAMES = ['.user.ini', '.htaccess', '.htpasswd', 'web.config'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private StorageRepository $storageRepository,
        private AdvancedSettingsService $advancedSettingsService,
        private PublicUrlValidator $publicUrlValidator,
        private McpPublicUrlService $mcpPublicUrlService,
        private ?RequestFactory $requestFactory = null,
    ) {}

    /**
     * Resolve a writable folder in a BE-user-accessible storage, creating
     * missing path segments as needed.
     */
    public function resolveFolder(int $storageUid, string $directoryPath): Folder
    {
        $storage = $this->getStorage($storageUid);
        $segments = GeneralUtility::trimExplode('/', $directoryPath, true);
        if ($segments === []) {
            return $storage->getRootLevelFolder();
        }

        $existingIdentifier = '/';
        while ($segments !== [] && $storage->hasFolder($existingIdentifier . $segments[0] . '/')) {
            $existingIdentifier .= array_shift($segments) . '/';
        }

        $folder = $storage->getFolder($existingIdentifier);
        if ($segments !== []) {
            try {
                $folder = $storage->createFolder(implode('/', $segments), $folder);
            } catch (ExistingTargetFolderException) {
                $folder = $storage->getFolder($existingIdentifier . implode('/', $segments) . '/');
            }
        }

        return $folder;
    }

    /**
     * Store a local temp file: SHA1 dedupe within the storage, else add with RENAME.
     *
     * @return array{file: File, deduplicated: bool}
     */
    public function storeFile(string $tempPath, string $fileName, Folder $folder): array
    {
        $fileName = basename($fileName);
        if ($fileName === '' || !str_contains($fileName, '.')) {
            throw new \InvalidArgumentException(
                'Could not determine a file name with an extension. Pass an explicit "fileName" like "image.jpg".',
                1747320001,
            );
        }

        $this->assertFileNameIsAllowed($fileName);

        $sha1 = sha1_file($tempPath);
        if ($sha1 === false) {
            throw new \RuntimeException('Could not hash the temporary upload file.', 1747320002);
        }

        $existingFile = $this->findIdenticalFile($folder->getStorage(), $sha1);
        if ($existingFile !== null) {
            return ['file' => $existingFile, 'deduplicated' => true];
        }

        try {
            $file = $folder->getStorage()->addFile($tempPath, $folder, $fileName, DuplicationBehavior::RENAME);
        } catch (IllegalFileExtensionException $e) {
            throw new \InvalidArgumentException('This file extension is not allowed: ' . $e->getMessage(), 1747320003, $e);
        } catch (\TYPO3\CMS\Core\Validation\ResultException $e) {
            throw new \InvalidArgumentException(
                'The file was rejected because its content does not match the file extension "'
                . pathinfo($fileName, PATHINFO_EXTENSION) . '". '
                . 'Make sure the URL/content actually delivers this file type.',
                1747320004,
                $e,
            );
        }

        // Uploads can be rewritten while stored (e.g. SVG sanitizer); re-check hash.
        $existingFile = $this->findIdenticalFile($folder->getStorage(), $file->getSha1(), $file->getUid());
        if ($existingFile !== null) {
            try {
                $folder->getStorage()->deleteFile($file);

                return ['file' => $existingFile, 'deduplicated' => true];
            } catch (\Exception) {
                // User may create but not delete; keep the copy.
            }
        }

        return ['file' => $file, 'deduplicated' => false];
    }

    public function assertFileNameIsAllowed(string $fileName): void
    {
        $lowerName = strtolower($fileName);
        if (in_array($lowerName, self::DENIED_FILE_NAMES, true)) {
            throw new \InvalidArgumentException(
                'The file name "' . $fileName . '" is not allowed because a file with that name reconfigures '
                . 'the web server or PHP.',
                1747320005,
            );
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($extension, self::SERVER_EXECUTABLE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Files with the extension ".' . $extension . '" are not allowed because they could be executed '
                . 'on the server.',
                1747320006,
            );
        }
        if (in_array($extension, self::BROWSER_EXECUTABLE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Files with the extension ".' . $extension . '" are not allowed because they could run '
                . 'script code in the browser when served from the file storage.',
                1747320007,
            );
        }

        foreach (explode('.', $lowerName) as $part) {
            if (in_array($part, self::SERVER_EXECUTABLE_EXTENSIONS, true)) {
                throw new \InvalidArgumentException(
                    'The file name "' . $fileName . '" is not allowed because it contains the potentially '
                    . 'executable extension ".' . $part . '".',
                    1747320008,
                );
            }
        }
    }

    /**
     * @return array{
     *     uid: int,
     *     name: string,
     *     identifier: string,
     *     publicUrl: string|null,
     *     mimeType: string,
     *     size: int,
     *     sha1: string,
     *     nextStep: string
     * }
     */
    public function describeFile(File $file): array
    {
        $uid = $file->getUid();

        return [
            'uid' => $uid,
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'combinedIdentifier' => $file->getCombinedIdentifier(),
            'storageUid' => $file->getStorage()->getUid(),
            'publicUrl' => $this->mcpPublicUrlService->makeAbsoluteUrl($file->getPublicUrl()),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sha1' => $file->getSha1(),
            'nextStep' => 'The file is stored but not yet attached. Prefer file_reference_add '
                . '(fileUids="' . $uid . '") or write_table with '
                . '[{"uid_local":' . $uid . ',"alternative":"..."}] on the file field. '
                . 'Edit title/alt/description via write_table on sys_file_metadata (filter by file=' . $uid . ').',
        ];
    }

    /**
     * @return array{token: string, validUntil: int}
     */
    public function createUploadToken(Folder $folder, string $fileName = ''): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $userUid = (int) ($user->user['uid'] ?? 0);
        if ($userUid <= 0) {
            throw new \InvalidArgumentException('No authenticated backend user for upload token creation.', 1747320009);
        }

        $token = bin2hex(random_bytes(32));
        $validUntil = time() + $this->getUploadTokenTtl();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_UPLOAD_TOKENS);

        $gcQuery = $connection->createQueryBuilder();
        $gcQuery->delete(self::TABLE_UPLOAD_TOKENS)
            ->where($gcQuery->expr()->lt('expires', $gcQuery->createNamedParameter(time() - 86400, ParameterType::INTEGER)))
            ->executeStatement();

        $connection->insert(self::TABLE_UPLOAD_TOKENS, [
            'token' => hash('sha256', $token),
            'be_user_uid' => $userUid,
            'storage_uid' => $folder->getStorage()->getUid(),
            'target_folder' => $folder->getIdentifier(),
            'file_name' => basename($fileName),
            'expires' => $validUntil,
            'used' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        return ['token' => $token, 'validUntil' => $validUntil];
    }

    /**
     * Atomically consume a single-use upload token. Returns the DB row once, else null.
     *
     * @return array<string, mixed>|null
     */
    public function consumeUploadToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_UPLOAD_TOKENS);
        $row = $connection->select(
            ['*'],
            self::TABLE_UPLOAD_TOKENS,
            ['token' => hash('sha256', $token)],
        )->fetchAssociative();

        if (!$row || (int) $row['used'] !== 0 || (int) $row['expires'] < time()) {
            return null;
        }

        $affected = $connection->executeStatement(
            'UPDATE ' . self::TABLE_UPLOAD_TOKENS . ' SET used = ?, tstamp = ? WHERE uid = ? AND used = 0',
            [time(), time(), (int) $row['uid']],
        );

        return $affected === 1 ? $row : null;
    }

    public function getMaxFileBytes(): int
    {
        return $this->advancedSettingsService->maxFileSizeMb() * 1024 * 1024;
    }

    public function getUploadTokenTtl(): int
    {
        return $this->advancedSettingsService->uploadTokenTtl();
    }

    public function extractFileNameFromContentDisposition(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*utf-8''([^;]+)/i", $header, $matches)) {
            $name = basename(rawurldecode(trim($matches[1], " \t\"")));

            return $name !== '' ? $name : null;
        }
        if (preg_match('/filename\s*=\s*"([^"]*)"/', $header, $matches)
            || preg_match('/filename\s*=\s*([^;]+)/', $header, $matches)
        ) {
            $name = basename(trim($matches[1], " \t\""));

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * Download a remote file with manual redirect following, PublicUrlValidator
     * on every hop, and CURLOPT_RESOLVE DNS pinning when possible.
     *
     * @return array{0: string, 1: string} tempPath, fileName
     */
    public function downloadFromUrl(string $url, string $requestedFileName = ''): array
    {
        $maxBytes = $this->getMaxFileBytes();
        $currentUrl = $url;
        $response = null;
        $requestFactory = $this->requestFactory ?? GeneralUtility::makeInstance(RequestFactory::class);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $resolvedIp = $this->assertPublicHttpUrl($currentUrl);

            $options = [
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'connect_timeout' => 10,
                'timeout' => 300,
                'headers' => ['User-Agent' => 'TYPO3-AI-Foundation-MCP/1.0'],
            ];

            $parts = parse_url($currentUrl);
            $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
            $proxyConfigured = !empty($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']);
            if (!$proxyConfigured && $resolvedIp !== null && $host !== ''
                && filter_var($host, FILTER_VALIDATE_IP) === false
                && defined('CURLOPT_RESOLVE')
            ) {
                $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
                $pinnedIp = filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? '[' . $resolvedIp . ']'
                    : $resolvedIp;
                $options['curl'] = [\CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinnedIp]];
            }

            try {
                $response = $requestFactory->request($currentUrl, 'GET', $options);
            } catch (GuzzleException $e) {
                throw new \InvalidArgumentException('Downloading the URL failed: ' . $e->getMessage(), 1747320010, $e);
            }

            if (!in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            $location = $response->getHeaderLine('Location');
            if ($location === '') {
                throw new \InvalidArgumentException('The URL redirected without a Location header.', 1747320011);
            }
            $currentUrl = (string) UriResolver::resolve(Utils::uriFor($currentUrl), Utils::uriFor($location));
            $response = null;
        }

        if ($response === null) {
            throw new \InvalidArgumentException(
                'The URL redirected more than ' . self::MAX_REDIRECTS . ' times.',
                1747320012,
            );
        }

        if ($response->getStatusCode() !== 200) {
            throw new \InvalidArgumentException(
                'Downloading the URL failed with HTTP status ' . $response->getStatusCode() . '.',
                1747320013,
            );
        }

        $finalUrl = $currentUrl;
        $tempPath = GeneralUtility::tempnam('nst3af_upload_');
        $handle = fopen($tempPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary file for writing.', 1747320014);
        }

        $body = $response->getBody();
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
                    1747320015,
                );
            }
            fwrite($handle, $chunk);
        }
        fclose($handle);

        if ($bytes === 0) {
            @unlink($tempPath);
            throw new \InvalidArgumentException('The URL returned an empty response body.', 1747320016);
        }

        if ($this->looksLikeHtmlDocument($tempPath)) {
            @unlink($tempPath);
            throw new \InvalidArgumentException(
                'The URL returned a web page (HTML), not a file. Pass a direct link to the file itself '
                . '(e.g. the image address ending in .jpg/.png/.pdf). '
                . 'YouTube and Vimeo page URLs are the exception — those become online media assets.',
                1747320017,
            );
        }

        if ($requestedFileName !== '') {
            return [$tempPath, $requestedFileName];
        }

        try {
            $fileName = $this->deriveFileName(
                $finalUrl,
                $response->getHeaderLine('Content-Disposition'),
                $response->getHeaderLine('Content-Type'),
            );
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }

        return [$tempPath, $fileName];
    }

    /**
     * Create a YouTube/Vimeo (etc.) online media file, or return an existing one.
     */
    public function tryCreateOnlineMedia(string $url, Folder $folder): ?File
    {
        $registry = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class);
        try {
            $file = $registry->transformUrlToFile($url, $folder);
        } catch (OnlineMediaAlreadyExistsException $e) {
            return $e->getOnlineMedia();
        }

        return $file instanceof File ? $file : null;
    }

    /**
     * Validate http(s) + PublicUrlValidator; return a pin-able resolved IP for hostnames.
     */
    private function assertPublicHttpUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Only http:// and https:// URLs can be downloaded. Got: ' . $url, 1747320018);
        }

        if (!$this->publicUrlValidator->isPublicUrl($url)) {
            throw new \InvalidArgumentException(
                'The URL points to a private or reserved network address and cannot be downloaded.',
                1747320019,
            );
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $resolvedIp = gethostbyname($host);
        if ($resolvedIp === $host) {
            throw new \InvalidArgumentException('Could not resolve host "' . $host . '".', 1747320020);
        }

        return $resolvedIp;
    }

    private function looksLikeHtmlDocument(string $tempPath): bool
    {
        $handle = @fopen($tempPath, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 1024);
        fclose($handle);

        $head = strtolower(ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? ''));
        foreach (['<!doctype html', '<html', '<head', '<body'] as $marker) {
            if (str_starts_with($head, $marker)) {
                return true;
            }
        }

        return (bool) preg_match('/^(<\?xml[^>]*\?>|<!--.{0,200}?-->)\s*(<!doctype html|<html)/s', $head);
    }

    private function deriveFileName(string $url, string $contentDisposition, string $contentType): string
    {
        $fileName = $this->extractFileNameFromContentDisposition($contentDisposition);

        if ($fileName === null) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $fileName = basename(rawurldecode((string) $path));
        }

        if ($fileName !== '' && str_contains($fileName, '.')) {
            return $fileName;
        }

        $mimeType = strtolower(trim(explode(';', $contentType)[0]));
        $extension = GeneralUtility::makeInstance(MimeTypeDetector::class)
            ->getFileExtensionsForMimeType($mimeType)[0] ?? null;
        if ($extension === null) {
            throw new \InvalidArgumentException(
                'Could not derive a file name from the URL (content type "' . $contentType . '"). Pass an explicit "fileName".',
                1747320021,
            );
        }

        return ($fileName !== '' ? $fileName : 'download') . '.' . $extension;
    }

    private function findIdenticalFile(ResourceStorage $storage, string $sha1, int $excludeFileUid = 0): ?File
    {
        $rows = GeneralUtility::makeInstance(FileIndexRepository::class)->findByContentHash($sha1);
        foreach ($rows as $row) {
            if ((int) ($row['storage'] ?? 0) !== $storage->getUid() || !empty($row['missing'])) {
                continue;
            }
            $uid = (int) $row['uid'];
            if ($uid === $excludeFileUid) {
                continue;
            }
            $identifier = (string) ($row['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            try {
                if (!$storage->hasFile($identifier)) {
                    continue;
                }
                $file = $storage->getFile($identifier);
                if ($file instanceof File) {
                    return $file;
                }
            } catch (\Exception) {
                try {
                    return GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($uid, $row);
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return null;
    }

    private function getStorage(int $storageUid): ResourceStorage
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1747320022);
        }

        $accessible = $backendUser->getFileStorages();
        $storage = $accessible[$storageUid] ?? null;
        if ($storage instanceof ResourceStorage) {
            return $storage;
        }

        if ($this->storageRepository->findByUid($storageUid) === null) {
            throw new \RuntimeException('Storage not found: ' . $storageUid, 1747320023);
        }

        throw new \RuntimeException('Access denied to storage: ' . $storageUid, 1747320024);
    }
}
