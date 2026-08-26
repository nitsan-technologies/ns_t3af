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

use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\AutoConfirmSettingsService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;

final class AutoConfirmSettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelHoldList'] = ['public_interest_text'];
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelAutoConfirmSources'] = [];
    }

    public function testOwnMediaSkipsTextTables(): void
    {
        $service = $this->service(['ailabelAutoConfirmOwn' => 'media']);

        self::assertTrue($service->isAutoConfirmAllowed('ns_t3ai', 'sys_file_metadata', false));
        self::assertFalse($service->isAutoConfirmAllowed('ns_t3ai', 'tt_content', false));
    }

    public function testHoldBlocksPublicInterestText(): void
    {
        $service = $this->service(['ailabelAutoConfirmOwn' => 'both']);

        self::assertFalse($service->isAutoConfirmAllowed('ns_t3ai', 'tt_content', true));
        self::assertTrue($service->isAutoConfirmAllowed('ns_t3ai', 'tt_content', false));
    }

    public function testExtconfSourceWorksWhenOwnIsOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelAutoConfirmSources'] = ['partner_ext'];
        $service = $this->service(['ailabelAutoConfirmOwn' => 'off']);

        self::assertTrue($service->isAutoConfirmAllowed('partner_ext', 'tt_content', false));
        self::assertFalse($service->isAutoConfirmAllowed('ns_t3ai', 'tt_content', false));
    }

    public function testDetectedUploadRequiresSetting(): void
    {
        $off = $this->service(['ailabelAutoConfirmDetected' => 'off']);
        self::assertFalse($off->isAutoConfirmAllowed('detected_upload', 'sys_file_metadata', false));

        $on = $this->service(['ailabelAutoConfirmDetected' => 'on']);
        self::assertTrue($on->isAutoConfirmAllowed('detected_upload', 'sys_file_metadata', false));
    }

    /**
     * @param array<string, string> $stored
     */
    private function service(array $stored): AutoConfirmSettingsService
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn($stored);

        return new AutoConfirmSettingsService(new AiLabelSettingsService($extensionSettings));
    }
}
