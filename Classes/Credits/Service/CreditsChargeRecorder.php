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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Persists a successful T3Planet charge into the local receipt mirror.
 *
 * @internal
 */
class CreditsChargeRecorder
{
    public function __construct(
        private readonly LocalReceiptCache $receiptCache,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array{
     *     extension_key?: string,
     *     page_id?: int,
     *     page_title?: string,
     *     latency_ms?: int,
     *     status?: string
     * } $clientContext Stamped into receipt `extra.client` for Recent AI Usage.
     */
    public function record(
        string $requestUuid,
        string $featureKey,
        array $payload,
        array $clientContext = [],
    ): void {
        if (($payload['status'] ?? false) !== true) {
            return;
        }

        if ($clientContext !== []) {
            $existing = is_array($payload['client'] ?? null) ? $payload['client'] : [];
            $payload['client'] = array_merge($existing, $this->normalizeClientContext($clientContext));
        }

        $this->receiptCache->storeFromCharge($requestUuid, $featureKey, $payload);
    }

    /**
     * @return array{
     *     extension_key?: string,
     *     page_id?: int,
     *     page_title?: string,
     *     latency_ms?: int,
     *     status?: string
     * }
     */
    public static function contextFromAiOptions(AiOptions $options, int $latencyMs, string $status = 'success'): array
    {
        $context = [
            'latency_ms' => max(0, $latencyMs),
            'status' => $status !== '' ? $status : 'success',
        ];

        $extensionKey = trim((string) ($options->extensionKey ?? ''));
        if ($extensionKey !== '') {
            $context['extension_key'] = $extensionKey;
        }

        $pageId = $options->pageId;
        if ($pageId !== null && $pageId > 0) {
            $context['page_id'] = $pageId;
            $title = self::resolvePageTitle($pageId);
            if ($title !== '') {
                $context['page_title'] = $title;
            }
        }

        return $context;
    }

    /**
     * @return array{
     *     extension_key?: string,
     *     latency_ms?: int,
     *     status?: string
     * }
     */
    public static function contextFromExtensionKey(?string $extensionKey, int $latencyMs, string $status = 'success'): array
    {
        $context = [
            'latency_ms' => max(0, $latencyMs),
            'status' => $status !== '' ? $status : 'success',
        ];
        $key = trim((string) $extensionKey);
        if ($key !== '') {
            $context['extension_key'] = $key;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $clientContext
     * @return array<string, mixed>
     */
    private function normalizeClientContext(array $clientContext): array
    {
        $normalized = [];

        $extensionKey = trim((string) ($clientContext['extension_key'] ?? ''));
        if ($extensionKey !== '') {
            $normalized['extension_key'] = $extensionKey;
        }

        $pageId = (int) ($clientContext['page_id'] ?? 0);
        if ($pageId > 0) {
            $normalized['page_id'] = $pageId;
        }

        $pageTitle = trim((string) ($clientContext['page_title'] ?? ''));
        if ($pageTitle === '' && $pageId > 0) {
            $pageTitle = self::resolvePageTitle($pageId);
        }
        if ($pageTitle !== '') {
            $normalized['page_title'] = $pageTitle;
        }

        if (array_key_exists('latency_ms', $clientContext)) {
            $normalized['latency_ms'] = max(0, (int) $clientContext['latency_ms']);
        }

        $status = trim((string) ($clientContext['status'] ?? ''));
        if ($status !== '') {
            $normalized['status'] = $status;
        }

        return $normalized;
    }

    private static function resolvePageTitle(int $pageId): string
    {
        try {
            $row = BackendUtility::getRecord('pages', $pageId, 'title');
        } catch (\Throwable) {
            return '';
        }

        return is_array($row) ? trim((string) ($row['title'] ?? '')) : '';
    }
}
