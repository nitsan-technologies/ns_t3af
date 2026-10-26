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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileAccess;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyPayloadStore;
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
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\Resource\Service\ResourceConsistencyService;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Validation\ResultException;

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
        private ?PublicHostnameResolverInterface $publicHostnameResolver = null,
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
            $root = $storage->getRootLevelFolder();
            RecordsApplyFileAccess::assertFolderAllowed($storageUid, $root->getIdentifier());

            return $root;
        }

        // Refuse before creating missing segments outside the editor's mounts.
        $intended = '/' . implode('/', $segments) . '/';
        RecordsApplyFileAccess::assertFolderAllowed($storageUid, $intended);

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
        $this->assertUploadContentReady($tempPath, $fileName, $folder);
        $fileName = basename($fileName);
        self::assertFileNameLength($fileName);

        $sha1 = sha1_file($tempPath);
        if ($sha1 === false) {
            throw new \RuntimeException('Could not hash the temporary upload file.', 1747320002);
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $existingFile = $this->findIdenticalFileInFolder($folder->getStorage(), $sha1, $folder);
        if ($existingFile !== null) {
            self::assertDedupeNameMatchesExisting($existingFile, $fileName, $extension);

            return ['file' => $existingFile, 'deduplicated' => true];
        }

        $storage = $folder->getStorage();
        $this->registerResourceConsistencyExceptionForSmallTextUpload($storage, $tempPath, $fileName);

        try {
            $file = $folder->getStorage()->addFile($tempPath, $folder, $fileName, DuplicationBehavior::RENAME);
        } catch (IllegalFileExtensionException $e) {
            throw new \InvalidArgumentException('This file extension is not allowed: ' . $e->getMessage(), 1747320003, $e);
        } catch (\TYPO3\CMS\Core\Validation\ResultException $e) {
            throw new \InvalidArgumentException(
                self::formatResourceConsistencyFailure($e, $fileName),
                1747320004,
                $e,
            );
        }

        // Uploads can be rewritten while stored (e.g. SVG sanitizer); re-check hash in this folder only.
        $existingFile = $this->findIdenticalFileInFolder($folder->getStorage(), $file->getSha1(), $folder, $file->getUid());
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

    /**
     * Dedupe must not return an existing file under a mismatched extension (e.g. JPEG bytes as .woff2).
     */
    public static function assertDedupeNameMatchesExisting(File $existing, string $requestedFileName, string $requestedExtension): void
    {
        $existingExtension = strtolower($existing->getExtension());
        if ($requestedExtension !== '' && $existingExtension !== '' && $requestedExtension !== $existingExtension) {
            throw new \InvalidArgumentException(
                sprintf(
                    'An identical file already exists as ".%s" (%s), but the upload name uses ".%s". '
                    . 'Rename to match the real type, or upload different bytes.',
                    $existingExtension,
                    $existing->getMimeType(),
                    $requestedExtension,
                ),
                1747320025,
            );
        }

        if ($requestedExtension !== '' && !self::isExtensionConsistentWithMime($requestedExtension, $existing->getMimeType())) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Mime-type "%s" not allowed for file extension "%s".',
                    $existing->getMimeType(),
                    $requestedExtension,
                ),
                1747320026,
            );
        }
    }

    public static function isExtensionConsistentWithMime(string $extension, string $mimeType): bool
    {
        $extension = strtolower($extension);
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
        $expected = GeneralUtility::makeInstance(MimeTypeDetector::class)
            ->getMimeTypesForFileExtension($extension);

        // Unknown extensions: do not invent a refusal here (FAL allow-list handles that on addFile).
        return $expected === [] || ($mimeType !== '' && in_array($mimeType, $expected, true));
    }

    /**
     * WOFF/WOFF2 are mapped from application/octet-stream install-wide; require real font magic
     * so random bytes / polyglots cannot be stored as font/* via MCP upload.
     */
    public static function assertFontFileIsGenuine(string $path, string $extension): void
    {
        $extension = strtolower($extension);
        if ($extension !== 'woff' && $extension !== 'woff2') {
            return;
        }

        $size = filesize($path);
        if ($size === false || $size < 44) {
            throw new \InvalidArgumentException(
                'This file is too small to be a valid .' . $extension . ' font.',
                1747320027,
            );
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the upload for font validation.', 1747320028);
        }
        try {
            $header = fread($handle, 12);
        } finally {
            fclose($handle);
        }
        if (!is_string($header) || strlen($header) < 12) {
            throw new \InvalidArgumentException(
                'Could not read a .' . $extension . ' font header.',
                1747320029,
            );
        }

        $expectedMagic = $extension === 'woff2' ? 'wOF2' : 'wOFF';
        if (!str_starts_with($header, $expectedMagic)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File content is not a real .%s font (expected magic bytes "%s").',
                    $extension,
                    $expectedMagic,
                ),
                1747320030,
            );
        }

        $declaredLength = unpack('N', substr($header, 8, 4));
        $length = is_array($declaredLength) ? (int) ($declaredLength[1] ?? 0) : 0;
        if ($length !== $size) {
            throw new \InvalidArgumentException(
                sprintf(
                    'This .%s file header length (%d) does not match the file size (%d).',
                    $extension,
                    $length,
                    $size,
                ),
                1747320031,
            );
        }

        // Polyglot guard: fonts must not carry executable script payloads after a forged header.
        $sample = (string) file_get_contents($path, false, null, 0, min($size, 65536));
        if (str_contains($sample, '<?php') || str_contains(strtolower($sample), '<script')) {
            throw new \InvalidArgumentException(
                'This .' . $extension . ' file contains script content and is not allowed.',
                1747320032,
            );
        }
    }

    public static function isFileInFolder(File $file, Folder $folder): bool
    {
        try {
            $parent = $file->getParentFolder();
        } catch (\Throwable) {
            return false;
        }

        return self::normalizeFolderIdentifier($parent->getIdentifier())
            === self::normalizeFolderIdentifier($folder->getIdentifier());
    }

    public static function normalizeFolderIdentifier(string $identifier): string
    {
        $identifier = str_replace('\\', '/', $identifier);
        if ($identifier === '' || $identifier === '/') {
            return '/';
        }

        return rtrim($identifier, '/') . '/';
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
     * Same blocked-name rules as {@see assertFileNameIsAllowed()} plus TYPO3's global allowed-extension list.
     */
    public function assertStorageAcceptsFileName(Folder $folder, string $fileName): void
    {
        $this->assertFileNameIsAllowed($fileName);
        $this->assertFileNamePassesCoreDenyPattern($fileName);
        $this->assertFileExtensionInGlobalAllowList($folder->getStorage(), basename($fileName));
    }

    /**
     * Content checks that must pass before a single-use upload token is consumed.
     */
    public function assertUploadContentReady(string $tempPath, string $fileName, Folder $folder): void
    {
        $fileName = basename($fileName);
        if ($fileName === '' || !str_contains($fileName, '.')) {
            throw new \InvalidArgumentException(
                'Could not determine a file name with an extension. Pass an explicit "fileName" like "image.jpg".',
                1747320001,
            );
        }

        $this->assertStorageAcceptsFileName($folder, $fileName);

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        self::assertFontFileIsGenuine($tempPath, $extension);
        $this->assertResourceConsistencyForUpload($folder->getStorage(), $tempPath, $fileName);
    }

    private function assertFileNamePassesCoreDenyPattern(string $fileName): void
    {
        $validator = GeneralUtility::makeInstance(FileNameValidator::class);
        if (!$validator->isValid(basename($fileName))) {
            throw new \InvalidArgumentException(
                'This file extension is not allowed by the storage configuration.',
                1747320003,
            );
        }
    }

    private function assertFileExtensionInGlobalAllowList(ResourceStorage $storage, string $fileName): void
    {
        try {
            GeneralUtility::makeInstance(ResourceConsistencyService::class)
                ->validate($storage, '', $fileName);
        } catch (ResultException $e) {
            throw new \InvalidArgumentException(
                self::formatResourceConsistencyFailure($e, $fileName),
                1747320004,
                $e,
            );
        }
    }

    private function assertResourceConsistencyForUpload(ResourceStorage $storage, string $tempPath, string $fileName): void
    {
        if ($this->isSmallUtf8TextUpload($fileName, $tempPath)) {
            return;
        }

        try {
            GeneralUtility::makeInstance(ResourceConsistencyService::class)
                ->validate($storage, $tempPath, $fileName);
        } catch (ResultException $e) {
            throw new \InvalidArgumentException(
                self::formatResourceConsistencyFailure($e, $fileName),
                1747320004,
                $e,
            );
        }
    }

    /**
     * finfo often reports 1–2 byte UTF-8 as application/octet-stream; trust declared text extensions instead of padding content.
     */
    private function isSmallUtf8TextUpload(string $fileName, string $tempPath): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['txt', 'md', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml', 'css', 'svg'], true)) {
            return false;
        }

        $size = filesize($tempPath);
        if ($size === false || $size > 512) {
            return false;
        }

        $content = file_get_contents($tempPath);

        return $content !== false && $content !== '' && mb_check_encoding($content, 'UTF-8');
    }

    /**
     * FAL re-validates MIME on addFile(); finfo still reports octet-stream for 1–2 byte UTF-8 text.
     */
    private function registerResourceConsistencyExceptionForSmallTextUpload(
        ResourceStorage $storage,
        string $tempPath,
        string $fileName,
    ): void {
        if (!$this->isSmallUtf8TextUpload($fileName, $tempPath)) {
            return;
        }

        GeneralUtility::makeInstance(ResourceConsistencyService::class)->addExceptionItem(
            $storage,
            $tempPath,
            basename($fileName),
        );
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
        $sanitizedFileName = basename($fileName);
        if ($sanitizedFileName !== '') {
            self::assertFileNameLength($sanitizedFileName);
        }
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
            'file_name' => $sanitizedFileName,
            'expires' => $validUntil,
            'used' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        return ['token' => $token, 'validUntil' => $validUntil];
    }

    /**
     * Single-use upload token that stores JSON in var/transient (not public fileadmin).
     *
     * @return array{token: string, validUntil: int, payloadId: string}
     */
    public function createRecordsApplyPayloadUploadToken(): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $userUid = (int) ($user->user['uid'] ?? 0);
        if ($userUid <= 0) {
            throw new \InvalidArgumentException('No authenticated backend user for upload token creation.', 1747320009);
        }

        $payloadStore = GeneralUtility::makeInstance(RecordsApplyPayloadStore::class);
        $payloadId = $payloadStore->generateId();
        $token = bin2hex(random_bytes(32));
        $validUntil = time() + $this->getUploadTokenTtl();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_UPLOAD_TOKENS);
        $connection->insert(self::TABLE_UPLOAD_TOKENS, [
            'token' => hash('sha256', $token),
            'be_user_uid' => $userUid,
            'storage_uid' => RecordsApplyPayloadStore::TOKEN_STORAGE_UID,
            'target_folder' => RecordsApplyPayloadStore::TOKEN_FOLDER_PREFIX . $payloadId,
            // Leave empty: payload id lives in target_folder; client supplies payload.json via ?fileName= or defaults in the endpoint.
            'file_name' => '',
            'expires' => $validUntil,
            'used' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        return ['token' => $token, 'validUntil' => $validUntil, 'payloadId' => $payloadId];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isRecordsApplyPayloadUploadTokenRow(array $row): bool
    {
        return GeneralUtility::makeInstance(RecordsApplyPayloadStore::class)->isPayloadUploadTokenRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function peekUploadToken(string $token): ?array
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

        return $row;
    }

    /**
     * Atomically consume a single-use upload token. Returns the DB row once, else null.
     *
     * @return array<string, mixed>|null
     */
    public function consumeUploadToken(string $token): ?array
    {
        $row = $this->peekUploadToken($token);
        if ($row === null) {
            return null;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_UPLOAD_TOKENS);
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

            $proxyConfigured = !empty($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']);
            $options = self::buildDownloadRequestOptions($currentUrl, $resolvedIp, $proxyConfigured, $maxBytes);

            try {
                $response = $requestFactory->request($currentUrl, 'GET', $options);
            } catch (GuzzleException $e) {
                $cause = $e;
                while ($cause !== null) {
                    if ($cause instanceof \InvalidArgumentException && $cause->getCode() === 1747320015) {
                        throw $cause;
                    }
                    $cause = $cause->getPrevious();
                }
                $failedHost = parse_url($currentUrl, PHP_URL_HOST);
                $failedHost = is_string($failedHost) && $failedHost !== '' ? $failedHost : 'that address';

                throw new \InvalidArgumentException(
                    'Could not download the file from ' . $failedHost . '.',
                    1747320010,
                    $e,
                );
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
            self::assertFileNameLength($requestedFileName);

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

        self::assertFileNameLength($fileName);

        return [$tempPath, $fileName];
    }

    private static function assertFileNameLength(string $fileName): void
    {
        if (strlen($fileName) > 255) {
            throw new \InvalidArgumentException(
                'The file name must be at most 255 bytes.',
                1747320026,
            );
        }
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
     * HTTP client options for one download hop.
     *
     * When DNS is pinned via CURLOPT_RESOLVE, {@see stream} must not be set: Guzzle's
     * stream handler rejects curl options. Without pinning, stream is used for size limits.
     *
     * @return array<string, mixed>
     */
    public static function buildDownloadRequestOptions(
        string $url,
        ?string $resolvedIp,
        bool $proxyConfigured,
        int $maxBytes,
    ): array {
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'connect_timeout' => 10,
            'timeout' => 300,
            'headers' => ['User-Agent' => 'TYPO3-AI-Foundation-MCP/1.0'],
        ];

        $parts = parse_url($url);
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $pinDns = !$proxyConfigured && $resolvedIp !== null && $host !== ''
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && defined('CURLOPT_RESOLVE');

        if ($pinDns && extension_loaded('curl')) {
            $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
            $pinnedIp = filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[' . $resolvedIp . ']'
                : $resolvedIp;
            // Leave stream unset. Guzzle uses the curl handler only when that key is absent,
            // and the stream handler rejects curl options.
            $options['curl'] = [\CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinnedIp]];
            $options['progress'] = static function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                if ($downloaded > $maxBytes) {
                    throw new \InvalidArgumentException(
                        'The file exceeds the maximum size of ' . round($maxBytes / 1048576) . ' MiB '
                        . '(configurable via mcpMaxFileSizeMb).',
                        1747320015,
                    );
                }
            };

            return $options;
        }

        $options['stream'] = true;

        return $options;
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

        $privateAddress = new \InvalidArgumentException(
            'The URL points to a private or reserved network address and cannot be downloaded.',
            1747320019,
        );
        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'], true)) {
            throw $privateAddress;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->publicUrlValidator->isPublicUrl($url)) {
                throw $privateAddress;
            }

            return null;
        }

        // Resolved once: the address that is checked is the address the download is pinned to.
        $resolver = $this->publicHostnameResolver ?? new NativePublicHostnameResolver();
        $resolvedIp = $resolver->resolve($host);
        $ipHost = str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp;
        if (!$this->publicUrlValidator->isPublicUrl($scheme . '://' . $ipHost . '/')) {
            throw $privateAddress;
        }

        return $resolvedIp;
    }

    /**
     * A download that fails the same way on every try: the URL, the host, the answer or the file is
     * wrong. Network trouble, timeouts and server errors (5xx, 408, 429) may pass on a retry.
     */
    public static function isPermanentFailure(\Throwable $exception): bool
    {
        $code = $exception->getCode();
        if ($code === 1747320013) {
            if (preg_match('/HTTP status (\d{3})/', $exception->getMessage(), $match) !== 1) {
                return false;
            }
            $status = (int) $match[1];

            return $status >= 400 && $status < 500 && !in_array($status, [408, 429], true);
        }

        return in_array($code, [1747320011, 1747320012, 1747320015, 1747320016, 1747320017, 1747320018, 1747320019, 1747320020], true);
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

    /**
     * Same-content reuse only inside the requested folder (not the whole storage).
     */
    private function findIdenticalFileInFolder(
        ResourceStorage $storage,
        string $sha1,
        Folder $folder,
        int $excludeFileUid = 0,
    ): ?File {
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
                if (
                    $file instanceof File
                    && $storage->checkFileActionPermission('read', $file)
                    && self::isFileInFolder($file, $folder)
                ) {
                    return $file;
                }
            } catch (\Exception) {
                // The editor may not read this file (or it can not be loaded): never hand it back anyway.
                continue;
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

    /**
     * Surface TYPO3 resource-consistency messages (extension allow-list, mime mismatch) as-is.
     */
    public static function formatResourceConsistencyFailure(\TYPO3\CMS\Core\Validation\ResultException $exception, string $fileName): string
    {
        $details = [];
        foreach ($exception->messages as $message) {
            $text = trim($message->message);
            if ($text !== '') {
                $details[] = $text;
            }
        }

        if ($details !== []) {
            return implode(' ', $details);
        }

        return 'The file "' . basename($fileName) . '" was rejected by TYPO3 file consistency checks.';
    }
}
