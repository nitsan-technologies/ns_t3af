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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Fills Recent AI Usage receipt rows with extension / page / latency from AI Usage logs.
 *
 * Local charge stamping can be wiped by History sync; request_log keeps the values
 * shown in Request Details.
 *
 * @internal
 */
final class CreditsReceiptUsageEnricher
{
    public function __construct(
        private readonly RequestLogRepository $requestLogs,
    ) {}

    /**
     * @param list<array<string, mixed>> $receipts
     * @return list<array<string, mixed>>
     */
    public function enrich(array $receipts): array
    {
        if ($receipts === []) {
            return $receipts;
        }

        $hints = $this->requestLogs->resolveCreditsClientContextByReceipts($receipts);
        if ($hints === []) {
            return $receipts;
        }

        foreach ($receipts as &$receipt) {
            $uuid = trim((string) ($receipt['request_uuid'] ?? ''));
            if ($uuid === '' || !isset($hints[$uuid])) {
                continue;
            }
            $hint = $hints[$uuid];
            $extra = $this->decodeExtra($receipt['extra'] ?? null);
            $client = is_array($extra['client'] ?? null) ? $extra['client'] : [];

            if (trim((string) ($client['extension_key'] ?? '')) === ''
                && trim((string) ($hint['extension_key'] ?? '')) !== ''
            ) {
                $client['extension_key'] = (string) $hint['extension_key'];
            }
            if ((int) ($client['latency_ms'] ?? 0) <= 0 && (int) ($hint['latency_ms'] ?? 0) > 0) {
                $client['latency_ms'] = (int) $hint['latency_ms'];
            }
            $pageId = (int) ($client['page_id'] ?? $hint['page_id'] ?? 0);
            if ($pageId > 0) {
                $client['page_id'] = $pageId;
                if (trim((string) ($client['page_title'] ?? '')) === '') {
                    $title = trim((string) ($hint['page_title'] ?? ''));
                    if ($title === '') {
                        $title = $this->resolvePageTitle($pageId);
                    }
                    if ($title !== '') {
                        $client['page_title'] = $title;
                    }
                }
            }
            if (trim((string) ($client['status'] ?? '')) === ''
                && trim((string) ($hint['status'] ?? '')) !== ''
            ) {
                $client['status'] = (string) $hint['status'];
            }

            if ($client === []) {
                continue;
            }
            $extra['client'] = $client;
            $receipt['extra'] = json_encode($extra, JSON_THROW_ON_ERROR);
        }
        unset($receipt);

        return $receipts;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeExtra(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolvePageTitle(int $pageId): string
    {
        try {
            $row = BackendUtility::getRecord('pages', $pageId, 'title');
        } catch (\Throwable) {
            return '';
        }

        return is_array($row) ? trim((string) ($row['title'] ?? '')) : '';
    }
}
