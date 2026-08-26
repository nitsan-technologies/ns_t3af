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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use PHPUnit\Framework\TestCase;

final class AiLabelAuditReportOnlyTest extends TestCase
{
    public function testAuditCommandSourceNeverWritesLabelFields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/Classes/Command/AiLabelAuditCommand.php') ?: '';
        self::assertStringNotContainsString('recordOrigin', $source);
        self::assertStringNotContainsString('confirm(', $source);
        self::assertStringNotContainsString('bindGeneration', $source);
        self::assertStringContainsString('listUnboundGenerations', $source);
    }

    public function testCaptureListenerNeverPersistsPromptOrContent(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/Classes/AiLabel/EventListener/GenerationCaptureListener.php') ?: '';
        self::assertStringNotContainsString('prompt', strtolower($source));
        self::assertStringNotContainsString('bodytext', strtolower($source));
        self::assertStringContainsString('capture(', $source);
    }

    public function testNoAutomaticHumanReviewAssignmentInRecorder(): void
    {
        $recorder = file_get_contents(dirname(__DIR__, 3) . '/Classes/AiLabel/Service/OriginRecorder.php') ?: '';
        self::assertStringNotContainsString('tx_nst3af_ailabel_human_review', $recorder);
        self::assertStringNotContainsString('tx_nst3af_ailabel_responsible_person', $recorder);
    }
}
