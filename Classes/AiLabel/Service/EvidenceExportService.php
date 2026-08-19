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

namespace NITSAN\NsT3AF\AiLabel\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * CSV/HTML evidence export of labelled records.
 */
final class EvidenceExportService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly MediaRuleEngine $mediaRuleEngine,
        private readonly TextRuleEngine $textRuleEngine,
        private readonly ComplianceStringsService $complianceStrings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function collectRows(): array
    {
        $rows = [];
        foreach (['tt_content', 'pages', 'sys_file_metadata'] as $table) {
            $records = $this->connectionPool->getConnectionForTable($table)
                ->select(['*'], $table)
                ->fetchAllAssociative();
            foreach ($records as $record) {
                $rows[] = $this->buildRow($table, $record);
                $this->stampExported($table, (int) $record['uid']);
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['table', 'uid', 'involvement', 'decision', 'reason_code', 'actor', 'timestamp', 'source']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['table'],
                $row['uid'],
                $row['involvement'],
                $row['decision'],
                $row['reason_code'],
                $row['actor'],
                $row['timestamp'],
                $row['source'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function toHtml(array $rows): string
    {
        $caveat = htmlspecialchars($this->complianceStrings->get('caveat'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $coverageCaveat = htmlspecialchars($this->complianceStrings->get('coverageCaveat'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '';
        foreach ($rows as $row) {
            $body .= sprintf(
                '<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars((string) $row['table'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                (int) $row['uid'],
                htmlspecialchars((string) $row['involvement'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $row['decision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $row['reason_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $row['actor'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $row['timestamp'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $row['source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>AI Label evidence</title>'
            . '<style>body{font-family:sans-serif;margin:2em}table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #ccc;padding:6px;text-align:left}'
            . '.warn{background:#fff3cd;padding:1em;margin:1em 0}@media print{body{margin:0.5in}}</style></head>'
            . '<body><h1>AI Label evidence export</h1>'
            . '<p class="warn">' . $caveat . '</p><p class="warn">' . $coverageCaveat . '</p>'
            . '<table><thead><tr><th>Table</th><th>UID</th><th>Involvement</th><th>Decision</th>'
            . '<th>Reason</th><th>Actor</th><th>Timestamp</th><th>Source</th></tr></thead><tbody>'
            . $body . '</tbody></table></body></html>';
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function buildRow(string $table, array $record): array
    {
        $involvementValue = (string) ($record['tx_nst3af_ailabel_involvement'] ?? 'not_reviewed');
        $involvement = \NITSAN\NsT3AF\AiLabel\Domain\Involvement::tryFrom($involvementValue)
            ?? \NITSAN\NsT3AF\AiLabel\Domain\Involvement::NotReviewed;
        $mode = \NITSAN\NsT3AF\AiLabel\Domain\LabellingMode::tryFrom(
            (string) ($record['tx_nst3af_ailabel_labelling_mode'] ?? 'automatic'),
        ) ?? \NITSAN\NsT3AF\AiLabel\Domain\LabellingMode::Automatic;
        $confirmed = (int) ($record['tx_nst3af_ailabel_confirmed_at'] ?? 0) > 0;

        if ($table === 'sys_file_metadata') {
            $decision = $this->mediaRuleEngine->decide($involvement, $mode, $confirmed, (int) ($record['crdate'] ?? 0));
        } else {
            $decision = $this->textRuleEngine->decide(
                $involvement,
                (bool) ($record['tx_nst3af_ailabel_public_interest'] ?? false),
                (bool) ($record['tx_nst3af_ailabel_human_review'] ?? false),
                (string) ($record['tx_nst3af_ailabel_responsible_person'] ?? ''),
                $confirmed,
            );
        }

        return [
            'table' => $table,
            'uid' => (int) ($record['uid'] ?? 0),
            'involvement' => $involvement->value,
            'decision' => $decision->showLabel ? 'label' : 'no_label',
            'reason_code' => $decision->reasonCode->value,
            'actor' => (string) ((int) ($record['tx_nst3af_ailabel_confirmed_by'] ?? 0)),
            'timestamp' => date('c', (int) ($record['tx_nst3af_ailabel_confirmed_at'] ?? $record['tstamp'] ?? time())),
            'source' => (string) ($record['tx_nst3af_ailabel_recording_source'] ?? ''),
        ];
    }

    private function stampExported(string $table, int $uid): void
    {
        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            ['tx_nst3af_ailabel_exported_at' => time()],
            ['uid' => $uid],
        );
    }
}
