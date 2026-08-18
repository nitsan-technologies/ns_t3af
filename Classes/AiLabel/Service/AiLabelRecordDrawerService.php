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
use NITSAN\NsT3AF\AiLabel\Domain\LabellingMode;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Load and persist AI Label drawer form data for module list rows.
 */
final class AiLabelRecordDrawerService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AiLabelRecordEvaluator $evaluator,
        private readonly UndoCacheService $undoCacheService,
        private readonly ApplicableTablesResolver $applicableTablesResolver,
        private readonly FrontendLabelRenderer $frontendLabelRenderer,
        private readonly AiLabelSettingsService $settingsService,
    ) {}

    public function isAllowedTable(string $table): bool
    {
        return $this->applicableTablesResolver->isApplicable($table);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $table, int $uid): ?array
    {
        if (!$this->isAllowedTable($table) || $uid <= 0) {
            return null;
        }

        $record = $this->fetchRecord($table, $uid);
        if ($record === null) {
            return null;
        }

        $title = $this->resolveTitle($table, $record);
        $createdOn = $this->resolveCreatedOn($table, $record);
        $confirmedBy = (int) ($record['tx_nst3af_ailabel_confirmed_by'] ?? 0);
        $confirmedAt = (int) ($record['tx_nst3af_ailabel_confirmed_at'] ?? 0);
        $involvement = $this->evaluator->involvement($record);
        $labellingMode = $this->evaluator->labellingMode($record);
        $decision = $this->evaluator->decide($table, $record);

        $isMedia = $table === 'sys_file_metadata';
        $overlayPreview = $isMedia && $this->settingsService->isMediaOverlayEnabled();
        $visitorMarkup = $this->frontendLabelRenderer->renderBadgeMarkup($involvement, $overlayPreview);
        $showOverlay = $overlayPreview && $visitorMarkup !== '';

        return [
            'table' => $table,
            'uid' => $uid,
            'ref' => $table . ':' . $uid,
            'title' => $title,
            'isMedia' => $isMedia,
            'involvement' => $involvement->value,
            'labellingMode' => $labellingMode->value,
            'publicInterest' => (bool) ($record['tx_nst3af_ailabel_public_interest'] ?? false),
            'humanReview' => (bool) ($record['tx_nst3af_ailabel_human_review'] ?? false),
            'responsiblePerson' => (string) ($record['tx_nst3af_ailabel_responsible_person'] ?? ''),
            'exemptionReason' => (string) ($record['tx_nst3af_ailabel_exemption_reason'] ?? ''),
            'createdOn' => $createdOn,
            'confirmedByLabel' => $this->backendUserLabel($confirmedBy, $confirmedAt),
            'confirmedAt' => $confirmedAt,
            'recordingSource' => (string) ($record['tx_nst3af_ailabel_recording_source'] ?? ''),
            'aiSystem' => (string) ($record['tx_nst3af_ailabel_ai_system'] ?? ''),
            'detectionSummary' => $this->buildDetectionSummary($record, $isMedia),
            'visitorPreview' => [
                'showLabel' => $decision->showLabel,
                'reasonCode' => $decision->reasonCode->value,
                'markup' => $visitorMarkup,
                'isMediaOverlay' => $showOverlay,
                'mediaWrapperClass' => $this->settingsService->mediaWrapperClass(),
            ],
            'involvementOptions' => $this->involvementOptions(),
            'labellingModeOptions' => $this->labellingModeOptions(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function save(string $table, int $uid, array $data): array
    {
        if (!$this->isAllowedTable($table) || $uid <= 0) {
            return ['ok' => false, 'errors' => ['ref' => 'Invalid record reference.']];
        }

        $record = $this->fetchRecord($table, $uid);
        if ($record === null) {
            return ['ok' => false, 'errors' => ['ref' => 'Record not found.']];
        }

        $involvement = Involvement::tryFrom((string) ($data['involvement'] ?? ''));
        if ($involvement === null) {
            return ['ok' => false, 'errors' => ['involvement' => 'Invalid AI involvement value.']];
        }

        $labellingMode = LabellingMode::tryFrom((string) ($data['labelling_mode'] ?? ''));
        if ($labellingMode === null) {
            return ['ok' => false, 'errors' => ['labelling_mode' => 'Invalid labelling mode.']];
        }

        $responsiblePerson = trim((string) ($data['responsible_person'] ?? ''));
        $publicInterest = !empty($data['public_interest']);
        $exemptionReason = trim((string) ($data['exemption_reason'] ?? ''));

        $this->undoCacheService->remember($table, $uid, $this->extractAiLabelFields($record));

        $fields = [
            'tx_nst3af_ailabel_involvement' => $involvement->value,
            'tx_nst3af_ailabel_labelling_mode' => $labellingMode->value,
            'tx_nst3af_ailabel_public_interest' => $publicInterest ? 1 : 0,
            'tx_nst3af_ailabel_responsible_person' => $responsiblePerson,
            'tx_nst3af_ailabel_human_review' => $responsiblePerson !== '' ? 1 : 0,
            'tx_nst3af_ailabel_exemption_reason' => $exemptionReason,
        ];

        $this->connectionPool->getConnectionForTable($table)->update($table, $fields, ['uid' => $uid]);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRecord(string $table, int $uid): ?array
    {
        if ($table === 'sys_file_metadata') {
            $connection = $this->connectionPool->getConnectionForTable('sys_file_metadata');
            $row = $connection->createQueryBuilder()
                ->select('m.*', 'f.name AS file_name', 'f.creation_date AS file_creation_date')
                ->from('sys_file_metadata', 'm')
                ->leftJoin('m', 'sys_file', 'f', 'f.uid = m.file')
                ->where('m.uid = :uid')
                ->setParameter('uid', $uid)
                ->executeQuery()
                ->fetchAssociative();

            return is_array($row) ? $row : null;
        }

        $row = $this->connectionPool->getConnectionForTable($table)
            ->select(['*'], $table, ['uid' => $uid])
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveTitle(string $table, array $record): string
    {
        return match ($table) {
            'sys_file_metadata' => (string) ($record['file_name'] ?? 'File #' . ($record['uid'] ?? '')),
            'pages' => (string) ($record['title'] ?? 'Page #' . ($record['uid'] ?? '')),
            'tt_content' => (string) ($record['header'] ?? $record['CType'] ?? 'Content #' . ($record['uid'] ?? '')),
            default => $table . ' #' . ($record['uid'] ?? ''),
        };
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveCreatedOn(string $table, array $record): string
    {
        $timestamp = match ($table) {
            'sys_file_metadata' => (int) ($record['file_creation_date'] ?? 0),
            default => (int) ($record['crdate'] ?? 0),
        };

        return $timestamp > 0 ? date('Y-m-d', $timestamp) : '';
    }

    /**
     * @param array<string, mixed> $record
     */
    private function buildDetectionSummary(array $record, bool $isMedia): string
    {
        if (!$isMedia) {
            $source = trim((string) ($record['tx_nst3af_ailabel_recording_source'] ?? ''));
            if ($source === '') {
                return 'No automated detection data for this text record.';
            }

            return 'Recorded by ' . $source . '. Detection is only ever a suggestion; a person confirms it.';
        }

        $source = trim((string) ($record['tx_nst3af_ailabel_recording_source'] ?? ''));
        $parts = ['Content Credentials: none.'];
        $parts[] = $source === 'detected_upload'
            ? 'Detected on upload: suggestion pending person confirmation.'
            : 'IPTC digital source type: not inspected in this view.';
        $parts[] = 'EXIF signature: none.';
        $parts[] = 'Detection is only ever a suggestion; a person confirms it.';

        return implode(' ', $parts);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function involvementOptions(): array
    {
        $ll = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:ailabel.involvement.';
        $options = [];
        foreach (Involvement::cases() as $case) {
            $options[] = [
                'value' => $case->value,
                'label' => (string) ($GLOBALS['LANG']?->sL($ll . $case->value) ?? $case->value),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function labellingModeOptions(): array
    {
        $ll = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:ailabel.mode.';
        $options = [];
        foreach (LabellingMode::cases() as $case) {
            $options[] = [
                'value' => $case->value,
                'label' => (string) ($GLOBALS['LANG']?->sL($ll . $case->value) ?? $case->value),
            ];
        }

        return $options;
    }

    private function backendUserLabel(int $uid, int $confirmedAt): string
    {
        if ($uid <= 0 || $confirmedAt <= 0) {
            return '';
        }

        $record = BackendUtility::getRecord('be_users', $uid);
        $name = trim((string) ($record['realName'] ?? $record['username'] ?? ''));
        if ($name === '') {
            return '';
        }

        return $name . ', ' . date('j M Y H:i', $confirmedAt);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function extractAiLabelFields(array $record): array
    {
        $fields = [];
        foreach ($record as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'tx_nst3af_ailabel_')) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
