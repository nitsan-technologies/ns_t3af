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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\File;

use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\File\FileUploadFromUrlTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileUploadFromUrlToolTest extends TestCase
{
    private function tool(): FileUploadFromUrlTool
    {
        return new FileUploadFromUrlTool($this->createMock(FileService::class), new McpConfirmationPlanBuilder());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function placeholderUrls(): iterable
    {
        yield 'example.com' => ['https://example.com/cake.png'];
        yield 'subdomain of example.org' => ['https://images.example.org/cake.png'];
        yield '.test domain' => ['https://cdn.shop.test/cake.png'];
        yield 'localhost' => ['http://localhost/cake.png'];
    }

    #[Test]
    #[DataProvider('placeholderUrls')]
    public function planGivesNoCardForAPlaceholderAddress(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is a placeholder address');
        $this->tool()->plan(['url' => $url, 'directoryPath' => 'user_upload', 'fileName' => 'cake.png']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAWebAddress(): iterable
    {
        yield 'empty' => [''];
        yield 'file name only' => ['ebnodwdxc.png'];
        yield 'storage path' => ['/fileadmin/teslog/ebnodwdxc.png'];
        yield 'ftp' => ['ftp://files.typo3.org/ebnodwdxc.png'];
    }

    #[Test]
    #[DataProvider('notAWebAddress')]
    public function planGivesNoCardWithoutAWebAddress(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pass its sys_file uid to file_reference_add');
        $this->tool()->plan(['url' => $url, 'directoryPath' => 'fileadmin/', 'fileName' => 'ebnodwdxc.png']);
    }

    #[Test]
    public function planOffersACardForARealAddress(): void
    {
        $plan = $this->tool()->plan([
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/a7/ant.jpg',
            'directoryPath' => 'user_upload',
        ]);

        self::assertSame(McpConfirmationPlanBuilder::PLAN_KIND_TOOL_CONFIRMATION, $plan->context['planKind'] ?? null);
    }
}
