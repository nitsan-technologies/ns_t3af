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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * System readiness checks for the Overview status card.
 */
final class AiLabelSystemStatusService
{
    public const AUDIT_COMMAND = 't3af:ailabel:audit';

    public function __construct(
        private readonly EuIconManifestService $iconManifest,
        private readonly ?ConnectionPool $connectionPool = null,
    ) {}

    /**
     * @return list<array{label: string, status: string, variant: string}>
     */
    public function checks(): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'Official EU icons',
            'status' => $this->iconManifest->verify() ? 'present' : 'missing',
            'variant' => $this->iconManifest->verify() ? 'success' : 'danger',
        ];

        $imagick = extension_loaded('imagick') || class_exists(\Imagick::class, false);
        $checks[] = [
            'label' => 'ImageMagick (marking image files)',
            'status' => $imagick ? 'available' : 'not available',
            'variant' => $imagick ? 'success' : 'warning',
        ];

        $checks[] = [
            'label' => 'PHP exif extension',
            'status' => extension_loaded('exif') ? 'available' : 'not available',
            'variant' => extension_loaded('exif') ? 'success' : 'warning',
        ];

        $preserveMetadata = !(bool) ($GLOBALS['TYPO3_CONF_VARS']['GFX']['stripProfile'] ?? true);
        $checks[] = [
            'label' => 'Image processing keeps metadata',
            'status' => $preserveMetadata ? 'preserved' : 'strips it',
            'variant' => $preserveMetadata ? 'success' : 'warning',
        ];

        $checks[] = [
            'label' => 'Content Credentials tool',
            'status' => 'not installed',
            'variant' => 'secondary',
        ];

        $scheduled = $this->schedulerRegistered();
        $checks[] = [
            'label' => 'Scheduled AI Label audit',
            'status' => $scheduled ? 'runs weekly' : 'not scheduled',
            'variant' => $scheduled ? 'success' : 'warning',
        ];

        return $checks;
    }

    public function warningCount(): int
    {
        return count(array_filter(
            $this->checks(),
            static fn(array $check): bool => in_array($check['variant'], ['warning', 'danger'], true),
        ));
    }

    /**
     * Settings sidebar: only claim a schedule when a real Scheduler CLI task exists.
     *
     * @return array{scheduled: bool, badge: string, variant: string}
     */
    public function auditSchedule(): array
    {
        if ($this->schedulerRegistered()) {
            return [
                'scheduled' => true,
                'badge' => 'Weekly, Mon 06:00',
                'variant' => 'success',
            ];
        }

        return [
            'scheduled' => false,
            'badge' => 'Not scheduled',
            'variant' => 'warning',
        ];
    }

    public function schedulerRegistered(): bool
    {
        if (!class_exists(\TYPO3\CMS\Scheduler\Scheduler::class)) {
            return false;
        }

        try {
            $pool = $this->connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
            $connection = $pool->getConnectionForTable('tx_scheduler_task');
            if (!$connection->createSchemaManager()->tablesExist(['tx_scheduler_task'])) {
                return false;
            }

            $qb = $pool->getQueryBuilderForTable('tx_scheduler_task');
            $qb->getRestrictions()->removeAll();
            $rows = $qb->select('uid', 'disable', 'deleted', 'serialized_task_object')
                ->from('tx_scheduler_task')
                ->where($qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return false;
        }

        foreach ($rows as $row) {
            if ((int) ($row['disable'] ?? 0) === 1) {
                continue;
            }
            $blob = (string) ($row['serialized_task_object'] ?? '');
            if ($blob !== '' && str_contains($blob, self::AUDIT_COMMAND)) {
                return true;
            }
        }

        return false;
    }
}
