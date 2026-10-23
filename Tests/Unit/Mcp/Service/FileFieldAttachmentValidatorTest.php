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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\FileFieldAttachmentValidator;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;

final class FileFieldAttachmentValidatorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalGlobals;

    protected function setUp(): void
    {
        $this->originalGlobals = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'jpg,jpeg,png';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = $this->originalGlobals;
        parent::tearDown();
    }

    #[Test]
    public function refusesPdfOnCommonImageTypesField(): void
    {
        $GLOBALS['TCA'] = [
            'pages' => [
                'columns' => [
                    'og_image' => [
                        'config' => ['type' => 'file', 'allowed' => 'common-image-types'],
                    ],
                ],
            ],
        ];

        $file = $this->createMock(File::class);
        $file->method('getExtension')->willReturn('pdf');
        $file->method('getName')->willReturn('brochure.pdf');

        $validator = new FileFieldAttachmentValidator(new TcaSchemaService());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed for field "og_image"');

        $validator->assertFileAllowedForField('pages', 'og_image', $file);
    }

    #[Test]
    public function refusesPdfOnCommonImageTypesEvenWhenGfxAllowsPdf(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'jpg,jpeg,png,pdf,ai,svg,webp,avif';

        $GLOBALS['TCA'] = [
            'pages' => [
                'columns' => [
                    'twitter_image' => [
                        'config' => ['type' => 'file', 'allowed' => 'jpg,jpeg,png,pdf,ai,svg,webp,avif'],
                    ],
                ],
            ],
        ];

        $file = $this->createMock(File::class);
        $file->method('getExtension')->willReturn('pdf');
        $file->method('getName')->willReturn('brochure.pdf');

        $validator = new FileFieldAttachmentValidator(new TcaSchemaService());

        $this->expectException(\InvalidArgumentException::class);
        $validator->assertFileAllowedForField('pages', 'twitter_image', $file);
    }

    #[Test]
    public function allowsJpgOnExpandedCommonImageField(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'jpg,jpeg,png,pdf,ai,svg,webp,avif';

        $GLOBALS['TCA'] = [
            'pages' => [
                'columns' => [
                    'og_image' => [
                        'config' => ['type' => 'file', 'allowed' => 'jpg,jpeg,png,pdf,ai,svg,webp,avif'],
                    ],
                ],
            ],
        ];

        $file = $this->createMock(File::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getName')->willReturn('hero.jpg');

        $validator = new FileFieldAttachmentValidator(new TcaSchemaService());
        $validator->assertFileAllowedForField('pages', 'og_image', $file);

        $this->expectNotToPerformAssertions();
    }
}
