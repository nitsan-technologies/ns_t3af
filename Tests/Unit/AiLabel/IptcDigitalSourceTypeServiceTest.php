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

use NITSAN\NsT3AF\AiLabel\Service\IptcDigitalSourceTypeService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class IptcDigitalSourceTypeServiceTest extends TestCase
{
    public function testDetectsTrainedAlgorithmicMediaUri(): void
    {
        $service = new IptcDigitalSourceTypeService();

        self::assertTrue($service->isAiGeneratedSource(
            IptcDigitalSourceTypeService::TRAINED_ALGORITHMIC_MEDIA,
        ));
        self::assertTrue($service->isAiGeneratedSource(
            'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia',
        ));
        self::assertFalse($service->isAiGeneratedSource('http://example.com/photo'));
        self::assertFalse($service->isAiGeneratedSource('City: Berlin'));
        self::assertFalse($service->isAiGeneratedSource(null));
    }

    public function testCopyDigitalSourceTypeIgnoresNonAiDigitalSourceType(): void
    {
        $service = $this->getMockBuilder(IptcDigitalSourceTypeService::class)
            ->onlyMethods(['readDigitalSourceType', 'imagickAvailable'])
            ->getMock();
        $service->method('readDigitalSourceType')->willReturn('City: Berlin');
        $service->method('imagickAvailable')->willReturn(true);

        $source = $this->createMock(File::class);

        self::assertFalse($service->copyDigitalSourceType($source, '/tmp/does-not-matter.jpg'));
    }

    public function testUnlinkFalTemporaryFileRemovesFalTempNamedFile(): void
    {
        $service = new IptcDigitalSourceTypeService();
        $path = sys_get_temp_dir() . '/fal-tempfile-unit-' . uniqid('', true) . '.png';
        file_put_contents($path, 'x');
        self::assertFileExists($path);

        $service->unlinkFalTemporaryFile($path);

        self::assertFileDoesNotExist($path);
    }

    public function testUnlinkFalTemporaryFileLeavesNonTempPathsAlone(): void
    {
        $service = new IptcDigitalSourceTypeService();
        $path = sys_get_temp_dir() . '/ordinary-unit-' . uniqid('', true) . '.png';
        file_put_contents($path, 'x');

        try {
            $service->unlinkFalTemporaryFile($path);
            self::assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    public function testWriteTrainedAlgorithmicMediaPersistsViaReplaceFile(): void
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class, false)) {
            self::markTestSkipped('imagick extension required');
        }

        $tempPath = sys_get_temp_dir() . '/fal-tempfile-write-' . uniqid('', true) . '.jpg';
        $image = new \Imagick();
        $image->newImage(8, 8, new \ImagickPixel('white'));
        $image->setImageFormat('jpeg');
        $image->writeImage($tempPath);
        $image->clear();

        $storage = $this->createMock(ResourceStorage::class);
        $storage->expects(self::once())
            ->method('replaceFile')
            ->with(self::isInstanceOf(File::class), $tempPath)
            ->willReturnCallback(static function (File $file, string $path): File {
                @unlink($path);

                return $file;
            });

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getForLocalProcessing')->with(true)->willReturn($tempPath);
        $file->method('getStorage')->willReturn($storage);

        (new IptcDigitalSourceTypeService())->writeTrainedAlgorithmicMedia($file);

        self::assertFileDoesNotExist($tempPath);
    }
}
