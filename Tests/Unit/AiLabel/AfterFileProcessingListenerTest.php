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

use NITSAN\NsT3AF\AiLabel\EventListener\AfterFileProcessingListener;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use NITSAN\NsT3AF\AiLabel\Service\IptcDigitalSourceTypeService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileProcessingEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;

final class AfterFileProcessingListenerTest extends TestCase
{
    public function testSkipsWritableTempWhenNoIptcAndStampOff(): void
    {
        $iptc = $this->createMock(IptcDigitalSourceTypeService::class);
        $iptc->expects(self::once())->method('readDigitalSourceType')->willReturn(null);
        $iptc->expects(self::once())->method('isAiGeneratedSource')->with(null)->willReturn(false);
        $iptc->expects(self::never())->method('copyDigitalSourceType');
        $iptc->expects(self::never())->method('unlinkFalTemporaryFile');
        $iptc->method('imagickAvailable')->willReturn(true);

        $settings = new AiLabelSettingsService($this->extensionSettings([
            'ailabelMarkImageFile' => 'content_element_only',
        ]));
        $confirmation = new ConfirmationService($this->createMock(ConnectionPool::class));

        $original = $this->createMock(File::class);
        $processed = $this->createMock(ProcessedFile::class);
        $processed->method('exists')->willReturn(true);
        $processed->method('getMimeType')->willReturn('image/jpeg');
        $processed->expects(self::never())->method('getForLocalProcessing');
        $processed->expects(self::never())->method('updateWithLocalFile');

        $event = new AfterFileProcessingEvent(
            $this->createMock(DriverInterface::class),
            $processed,
            $original,
            'Image.CropScaleMask',
            [],
        );

        (new AfterFileProcessingListener($iptc, $settings, $confirmation))($event);
    }

    public function testCreatesWritableTempWhenAiDigitalSourceTypePresent(): void
    {
        $tempPath = sys_get_temp_dir() . '/fal-tempfile-processed-' . uniqid('', true) . '.jpg';
        file_put_contents($tempPath, 'fake-image');

        $iptc = $this->createMock(IptcDigitalSourceTypeService::class);
        $iptc->method('readDigitalSourceType')->willReturn(IptcDigitalSourceTypeService::TRAINED_ALGORITHMIC_MEDIA);
        $iptc->method('isAiGeneratedSource')->willReturn(true);
        $iptc->method('imagickAvailable')->willReturn(true);
        $iptc->expects(self::once())
            ->method('copyDigitalSourceType')
            ->with(self::isInstanceOf(File::class), $tempPath)
            ->willReturn(true);
        $iptc->expects(self::once())->method('unlinkFalTemporaryFile')->with($tempPath)
            ->willReturnCallback(static function (?string $path): void {
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
            });

        $settings = new AiLabelSettingsService($this->extensionSettings([
            'ailabelMarkImageFile' => 'overlay',
        ]));
        $confirmation = new ConfirmationService($this->createMock(ConnectionPool::class));

        $original = $this->createMock(File::class);
        $processed = $this->createMock(ProcessedFile::class);
        $processed->method('exists')->willReturn(true);
        $processed->method('getMimeType')->willReturn('image/jpeg');
        $processed->expects(self::once())->method('getForLocalProcessing')->with(true)->willReturn($tempPath);
        $processed->expects(self::once())->method('updateWithLocalFile')->with($tempPath);

        $event = new AfterFileProcessingEvent(
            $this->createMock(DriverInterface::class),
            $processed,
            $original,
            'Image.CropScaleMask',
            [],
        );

        try {
            (new AfterFileProcessingListener($iptc, $settings, $confirmation))($event);
            self::assertFileDoesNotExist($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * @param array<string, string> $stored
     */
    private function extensionSettings(array $stored): ExtensionSettingsService
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn($stored);

        return $extensionSettings;
    }
}
