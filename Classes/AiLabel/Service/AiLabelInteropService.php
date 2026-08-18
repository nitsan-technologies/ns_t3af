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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * R18 interop: report to b13 only after confirmation; dispatch nt_aimark when present.
 */
final class AiLabelInteropService
{
    public function reportAfterConfirmation(string $table, int $uid, Involvement $involvement): void
    {
        if (class_exists(\B13\AiLabel\Service\AiLabelApi::class)) {
            $api = GeneralUtility::makeInstance(\B13\AiLabel\Service\AiLabelApi::class);
            if ($involvement === Involvement::AiGenerated) {
                $api->aiCreated($table, $uid);
            }
        }

        if (class_exists(\NetThinks\NtAimark\Event\AiContentGeneratedEvent::class)) {
            GeneralUtility::makeInstance(\Psr\EventDispatcher\EventDispatcherInterface::class)->dispatch(
                new \NetThinks\NtAimark\Event\AiContentGeneratedEvent($table, $uid, 'generated'),
            );
        }
    }
}
