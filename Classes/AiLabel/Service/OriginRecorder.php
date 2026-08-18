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

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\Api\AiLabelRecorderInterface;
use NITSAN\NsT3AF\Service\AiLogService;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Capture-and-bind queue (R2.6) plus unified origin recorder (R3, R14.1).
 */
final class OriginRecorder implements AiLabelRecorderInterface
{
    private const LOG_CHANNEL = 'ailabel';
    private const ORPHAN_TTL = 604800;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ApplicableTablesResolver $applicableTablesResolver,
        private readonly ?AiLogService $aiLogService = null,
    ) {}

    public function capture(
        string $correlationId,
        string $generatingExtension,
        string $adapterType,
        string $modelId,
        string $generationGroupId = '',
    ): void {
        $now = time();
        $this->connectionPool->getConnectionForTable('tx_nst3af_ailabel_generation')->insert(
            'tx_nst3af_ailabel_generation',
            [
                'correlation_id' => $correlationId,
                'generation_group_id' => $generationGroupId,
                'generating_extension' => $generatingExtension,
                'adapter_type' => $adapterType,
                'model_id' => $modelId,
                'target_table' => '',
                'target_uid' => 0,
                'crdate' => $now,
                'tstamp' => $now,
            ],
        );
    }

    public function bindGeneration(
        string $correlationId,
        string $table,
        int $uid,
        Involvement $involvement,
        string $recordingSource,
        string $aiSystem = '',
        string $aiVendor = '',
        string $generationGroupId = '',
    ): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_nst3af_ailabel_generation');
        $row = $connection->select(
            ['uid', 'generation_group_id'],
            'tx_nst3af_ailabel_generation',
            ['correlation_id' => $correlationId],
        )->fetchAssociative();

        $groupId = $generationGroupId !== ''
            ? $generationGroupId
            : (string) ($row['generation_group_id'] ?? '');

        $this->recordOrigin($table, $uid, $involvement, $recordingSource, $aiSystem, $aiVendor, null, $groupId);

        if (is_array($row) && isset($row['uid'])) {
            $connection->delete('tx_nst3af_ailabel_generation', ['uid' => (int) $row['uid']]);
        }
    }

    public function recordOrigin(
        string $table,
        int $uid,
        Involvement $involvement,
        string $recordingSource,
        string $aiSystem = '',
        string $aiVendor = '',
        ?Involvement $previousInvolvement = null,
        string $generationGroupId = '',
    ): void {
        if (!$this->isApplicableTable($table)) {
            return;
        }

        $previous = $previousInvolvement ?? $this->loadInvolvement($table, $uid);
        if ($previous === $involvement) {
            return;
        }

        $fields = [
            'tx_nst3af_ailabel_involvement' => $involvement->value,
            'tx_nst3af_ailabel_recording_source' => $recordingSource,
            'tx_nst3af_ailabel_ai_system' => $aiSystem,
            'tx_nst3af_ailabel_ai_vendor' => $aiVendor,
            'tx_nst3af_ailabel_confirmed_by' => 0,
            'tx_nst3af_ailabel_confirmed_at' => 0,
            'tx_nst3af_ailabel_version_hash' => '',
        ];
        if ($generationGroupId !== '') {
            $fields['tx_nst3af_ailabel_generation_group'] = $generationGroupId;
        }

        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            $fields,
            ['uid' => $uid],
        );

        $this->writeLog($table, $uid, $involvement->value, $recordingSource, $previous?->value);
    }

    public function markGenerated(string $table, int $uid, string $recordingSource = 'api'): void
    {
        $this->writeInvolvement($table, $uid, Involvement::AiGenerated, $recordingSource);
    }

    public function markModified(string $table, int $uid, string $recordingSource = 'api'): void
    {
        $this->writeInvolvement($table, $uid, Involvement::AiModified, $recordingSource);
    }

    public function clearInvolvement(string $table, int $uid, string $recordingSource = 'api'): void
    {
        $this->writeInvolvement($table, $uid, Involvement::NoAi, $recordingSource);
    }

    private function writeInvolvement(string $table, int $uid, Involvement $involvement, string $recordingSource): void
    {
        $this->assertApplicableTable($table);
        $previous = $this->loadInvolvement($table, $uid);
        // Force a write so confirmation is always reset after an API origin change.
        $this->recordOrigin(
            $table,
            $uid,
            $involvement,
            $recordingSource,
            previousInvolvement: $previous === $involvement ? Involvement::NotReviewed : $previous,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUnboundGenerations(): array
    {
        $this->pruneOrphans();
        $rows = $this->connectionPool->getConnectionForTable('tx_nst3af_ailabel_generation')
            ->select(
                ['*'],
                'tx_nst3af_ailabel_generation',
                ['target_table' => '', 'target_uid' => 0],
            )->fetchAllAssociative();

        return $rows;
    }

    public function pruneOrphans(): int
    {
        $cutoff = time() - self::ORPHAN_TTL;
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_nst3af_ailabel_generation');

        return (int) $qb->delete('tx_nst3af_ailabel_generation')
            ->where(
                $qb->expr()->eq('target_table', $qb->createNamedParameter('')),
                $qb->expr()->eq('target_uid', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($cutoff, \Doctrine\DBAL\ParameterType::INTEGER)),
            )
            ->executeStatement();
    }

    private function loadInvolvement(string $table, int $uid): ?Involvement
    {
        $value = $this->connectionPool->getConnectionForTable($table)
            ->select(
                ['tx_nst3af_ailabel_involvement'],
                $table,
                ['uid' => $uid],
            )->fetchOne();

        if (!is_string($value) || $value === '') {
            return null;
        }

        return Involvement::tryFrom($value);
    }

    private function isApplicableTable(string $table): bool
    {
        return $this->applicableTablesResolver->isApplicable($table);
    }

    private function assertApplicableTable(string $table): void
    {
        if ($this->isApplicableTable($table)) {
            return;
        }

        throw new \InvalidArgumentException(
            sprintf('Table "%s" is not registered for AI Label fields.', $table),
            1755512400,
        );
    }

    private function writeLog(string $table, int $uid, string $involvement, string $source, ?string $previous): void
    {
        if ($this->aiLogService === null) {
            return;
        }

        $this->aiLogService->writeLog(
            'Origin recorded',
            'info',
            self::LOG_CHANNEL,
            [
                'table' => $table,
                'uid' => $uid,
                'involvement' => $involvement,
                'previous' => $previous,
                'source' => $source,
            ],
        );
    }
}
