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
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;

final class AiLabelSettingsServiceTest extends TestCase
{
    public function testSavePersistsViaExtensionSettingsService(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->expects(self::once())
            ->method('mergeGlobal')
            ->with(
                'ns_t3af',
                self::callback(static function (array $values): bool {
                    return ($values['ailabelLabelWording'] ?? '') === 'icon_only'
                        && ($values['ailabelLabelSize'] ?? '') === 'small';
                }),
            );

        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'ailabelLabelWording' => 'icon_only',
            'ailabelLabelSize' => 'small',
        ]);

        $service = new AiLabelSettingsService($extensionSettings);
        $service->save([
            'labelWording' => 'icon_only',
            'labelSize' => 'small',
        ]);

        self::assertSame('icon_only', $service->all()['labelWording']);
        self::assertSame('small', $service->all()['labelSize']);
    }

    public function testApplicableTablesAreParsedFromStoredCsv(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'ailabelApplicableTables' => 'tx_news_domain_model_news, tx_blog_domain_model_post',
        ]);

        $service = new AiLabelSettingsService($extensionSettings);

        self::assertSame(
            ['tx_news_domain_model_news', 'tx_blog_domain_model_post'],
            $service->getConfiguredApplicableTables(),
        );
    }

    public function testMediaPositionClassMapsTopRight(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'ailabelLabelPosition' => 'top_right',
        ]);

        $service = new AiLabelSettingsService($extensionSettings);

        self::assertSame('nst3af-ailabel-media--pos-top-right', $service->mediaPositionClass());
    }

    public function testMediaOverlayEnabledWhenMarkImageFileIsOverlay(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'ailabelMarkImageFile' => 'overlay',
        ]);

        $service = new AiLabelSettingsService($extensionSettings);

        self::assertTrue($service->isMediaOverlayEnabled());
    }
}
