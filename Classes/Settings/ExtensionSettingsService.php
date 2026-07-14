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

namespace NITSAN\NsT3AF\Settings;

use NITSAN\NsT3AF\Service\SiteExtensionSettingsResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use Psr\Http\Message\ServerRequestInterface;

class ExtensionSettingsService
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $requestCache = [];

    private static bool $loading = false;

    public function __construct(
        private readonly ExtensionSettingsRegistry $registry,
        private readonly ExtensionSettingsRepository $repository,
        private readonly ExtensionSettingsSchemaService $schemaService,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly ExtensionSettingsDynamicDefaultsRegistry $dynamicDefaultsRegistry,
        private readonly SiteExtensionSettingsResolver $storageResolver,
        private readonly ExtensionSettingsSecretService $secretService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function getAll(string $extensionKey, ?int $storagePid = null, ?int $pageId = null): array
    {
        if (!$this->registry->isManaged($extensionKey)) {
            return [];
        }

        $resolvedPid = $this->resolveStoragePidForRead($extensionKey, $storagePid, $pageId);
        $cacheKey = $extensionKey . ':' . $resolvedPid;
        if (isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }

        if (self::$loading) {
            return ExtensionSettingsBootstrapReader::getDefaults($extensionKey);
        }

        self::$loading = true;
        try {
            $schemaDefaults = $this->schemaService->getDefaults($extensionKey);
            $dynamicDefaults = $resolvedPid > 0
                ? $this->dynamicDefaultsRegistry->getForExtension($extensionKey, $resolvedPid)
                : [];
            $stored = $this->getStoredValues($extensionKey, $resolvedPid);
            $stored = $this->secretService->decryptValues($extensionKey, $stored);

            self::$requestCache[$cacheKey] = array_merge($schemaDefaults, $dynamicDefaults, $stored);

            return self::$requestCache[$cacheKey];
        } finally {
            self::$loading = false;
        }
    }

    /**
     * Pid-agnostic settings read for global (non page-bound) features such as the
     * fileadmin File list. Merges schema defaults with the stored values from every
     * pid, letting any non-empty configured value win regardless of which site/pid
     * it was saved on. Mirrors how provider lookups resolve across all pids.
     *
     * @return array<string, string>
     */
    public function getAllIgnorePid(string $extensionKey): array
    {
        if (!$this->registry->isManaged($extensionKey)) {
            return [];
        }

        $merged = $this->schemaService->getDefaults($extensionKey);

        $rows = $this->repository->findAllByExtensionKey($extensionKey);
        usort($rows, static fn(array $a, array $b): int => (int) ($a['pid'] ?? 0) <=> (int) ($b['pid'] ?? 0));

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['settings_json'] ?? '{}'), true);
            if (!is_array($decoded)) {
                continue;
            }

            $stored = [];
            foreach ($decoded as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $stored[$key] = is_scalar($value) ? (string) $value : '';
            }
            $stored = $this->secretService->decryptValues($extensionKey, $stored);

            foreach ($stored as $key => $value) {
                if ($value !== '' || !array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    public function get(string $extensionKey, string $path, mixed $default = null, ?int $storagePid = null, ?int $pageId = null): mixed
    {
        $all = $this->getAll($extensionKey, $storagePid, $pageId);
        if ($path === '') {
            return $all;
        }

        $segments = explode('/', $path);
        $value = $all;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Persist global extension settings (pid 0 and every configured site row).
     *
     * Used for MCP-wide toggles from Admin Tools where no page tree context exists.
     *
     * @param array<string, scalar|null> $values
     */
    public function mergeGlobal(string $extensionKey, array $values): bool
    {
        if (!$this->registry->isManaged($extensionKey) || $values === []) {
            return false;
        }

        $pids = [0];
        foreach ($this->repository->findAllByExtensionKey($extensionKey) as $row) {
            $pids[] = (int) ($row['pid'] ?? 0);
        }
        $pids = array_values(array_unique($pids));

        foreach ($pids as $pid) {
            $this->mergeAtStoragePid($extensionKey, $values, $pid);
        }

        self::$requestCache = [];

        return true;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function merge(string $extensionKey, array $values, ?int $storagePid = null): void
    {
        if (!$this->registry->isManaged($extensionKey)) {
            return;
        }

        $resolvedPid = $this->resolveStoragePidForWrite($storagePid);
        if ($resolvedPid <= 0) {
            return;
        }

        $this->mergeAtStoragePid($extensionKey, $values, $resolvedPid);
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function replace(string $extensionKey, array $values, ?int $storagePid = null): void
    {
        if (!$this->registry->isManaged($extensionKey)) {
            return;
        }

        $resolvedPid = $this->resolveStoragePidForWrite($storagePid);
        if ($resolvedPid <= 0) {
            return;
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = is_scalar($value) ? (string) $value : '';
        }

        $this->ensureRowExists($extensionKey, $resolvedPid);
        $stored = $this->secretService->encryptValues($extensionKey, $normalized);

        $this->repository->updateSettingsJson(
            $extensionKey,
            (string) json_encode($stored, JSON_THROW_ON_ERROR),
            $resolvedPid,
        );
        unset(self::$requestCache[$extensionKey . ':' . $resolvedPid]);
    }

    /**
     * @return array<string, string>
     */
    public function getStoredValues(string $extensionKey, ?int $storagePid = null): array
    {
        $stored = $this->getStoredValuesRaw($extensionKey, $storagePid);

        return $this->secretService->decryptValues($extensionKey, $stored);
    }

    /**
     * @return array<string, string>
     */
    public function getStoredValuesRaw(string $extensionKey, ?int $storagePid = null): array
    {
        $resolvedPid = $storagePid !== null
            ? max(0, $storagePid)
            : $this->resolveStoragePidForRead($extensionKey, null, null);

        try {
            $row = $this->repository->findByExtensionKey($extensionKey, $resolvedPid);
        } catch (\Throwable) {
            return [];
        }

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) ($row['settings_json'] ?? '{}'), true);

        if (!is_array($decoded)) {
            return [];
        }

        $stored = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $stored[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $stored;
    }

    /**
     * Decrypted values prepared for AI Features drawer rendering (secrets masked).
     *
     * @return array<string, string>
     */
    public function getAllForDisplay(string $extensionKey, ?int $storagePid = null): array
    {
        $values = $this->getAll($extensionKey, $storagePid);

        return $this->secretService->maskValuesForDisplay($extensionKey, $values);
    }

    public function clearRequestCache(): void
    {
        self::$requestCache = [];
    }

    public function isSiteSettingsInitialized(int $storagePid): bool
    {
        if ($storagePid <= 0) {
            return true;
        }

        foreach ($this->registry->getManagedExtensionKeys() as $extensionKey) {
            if ($this->getStoredValues($extensionKey, $storagePid) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function getFullDefaultValues(string $extensionKey, int $storagePid = 0): array
    {
        if (!$this->registry->isManaged($extensionKey)) {
            return [];
        }

        $schemaDefaults = $this->schemaService->getDefaults($extensionKey);
        $dynamicDefaults = $storagePid > 0
            ? $this->dynamicDefaultsRegistry->getForExtension($extensionKey, $storagePid)
            : [];

        return array_merge($schemaDefaults, $dynamicDefaults);
    }

    /**
     * @param array<string, string> $submittedOverrides Applied on $triggerExtensionKey only.
     */
    public function initializeSiteSettings(
        int $storagePid,
        string $triggerExtensionKey,
        array $submittedOverrides = [],
    ): void {
        if ($storagePid <= 0) {
            return;
        }

        foreach ($this->registry->getManagedExtensionKeys() as $extensionKey) {
            $values = $this->getFullDefaultValues($extensionKey, $storagePid);
            if ($extensionKey === $triggerExtensionKey) {
                $values = array_merge($values, $submittedOverrides);
            }

            if ($values === []) {
                continue;
            }

            $this->replace($extensionKey, $values, $storagePid);
        }
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function mergeAtStoragePid(string $extensionKey, array $values, int $storagePid): void
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = is_scalar($value) ? (string) $value : '';
        }

        if ($normalized === []) {
            return;
        }

        $this->ensureRowExists($extensionKey, $storagePid);
        $existingRaw = $this->getStoredValuesRaw($extensionKey, $storagePid);
        $stored = $this->secretService->mergeForSave($extensionKey, $existingRaw, $normalized);

        $this->repository->updateSettingsJson(
            $extensionKey,
            (string) json_encode($stored, JSON_THROW_ON_ERROR),
            $storagePid,
        );
        unset(self::$requestCache[$extensionKey . ':' . $storagePid]);
    }

    private function ensureRowExists(string $extensionKey, int $storagePid): void
    {
        try {
            if ($this->repository->findByExtensionKey($extensionKey, $storagePid) === null) {
                $this->repository->insert($extensionKey, $storagePid);
            }
        } catch (\Throwable) {
            // Database may be unavailable during early bootstrap.
        }
    }

    private function resolveStoragePidForRead(string $extensionKey, ?int $storagePid, ?int $pageId): int
    {
        if ($storagePid !== null && $storagePid > 0) {
            return $storagePid;
        }

        $resolved = $this->storageResolver->resolve($pageId, true, $extensionKey);
        if ($resolved !== null && $resolved > 0) {
            return $resolved;
        }

        return 0;
    }

    private function resolveStoragePidForWrite(?int $storagePid): int
    {
        if ($storagePid !== null && $storagePid > 0) {
            return $storagePid;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $resolution = $this->siteStorageContext->resolveFromRequest($request);
            if ($resolution->isResolved()) {
                return $resolution->storagePid;
            }
        }

        return max(0, $storagePid ?? 0);
    }
}
