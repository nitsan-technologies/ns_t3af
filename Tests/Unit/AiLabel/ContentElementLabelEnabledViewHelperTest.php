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
use NITSAN\NsT3AF\AiLabel\ViewHelpers\ContentElementLabelEnabledViewHelper;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ContentElementLabelEnabledViewHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    public function testOverlaySkipsMediaCTypes(): void
    {
        self::assertFalse($this->render('image', ['ailabelMarkImageFile' => 'overlay']));
        self::assertFalse($this->render('textmedia', ['ailabelMarkImageFile' => 'overlay']));
        self::assertFalse($this->render('textpic', ['ailabelMarkImageFile' => 'overlay']));
        self::assertTrue($this->render('text', ['ailabelMarkImageFile' => 'overlay']));
    }

    public function testContentElementOnlyAllowsAllCTypes(): void
    {
        self::assertTrue($this->render('image', ['ailabelMarkImageFile' => 'content_element_only']));
        self::assertTrue($this->render('text', ['ailabelMarkImageFile' => 'content_element_only']));
    }

    /**
     * @param array<string, string> $stored
     */
    private function render(string $cType, array $stored): bool
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn($stored);
        // makeInstance consumes one addInstance per call
        GeneralUtility::addInstance(
            AiLabelSettingsService::class,
            new AiLabelSettingsService($extensionSettings),
        );

        $vh = new ContentElementLabelEnabledViewHelper();
        $vh->initializeArguments();
        $vh->setArguments(['cType' => $cType]);

        return $vh->render();
    }
}
