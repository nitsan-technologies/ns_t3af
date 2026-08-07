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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\Backend;

use NITSAN\NsT3AF\Mcp\Configuration\McpDefaultPromptTemplateRegistry;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateRepository;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateService;
use PHPUnit\Framework\TestCase;

final class McpPromptTemplateServiceTest extends TestCase
{
    public function testDefaultRegistryContainsOneBuiltin(): void
    {
        $registry = new McpDefaultPromptTemplateRegistry();
        $defaults = $registry->getDefaults();

        self::assertCount(3, $defaults);
        self::assertSame(
            ['audit_page_seo', 'add_content_text_block', 'translate_page_content'],
            $registry->getBuiltinNames(),
        );
        self::assertSame(
            ['audit_seo', 'translate_missing', 'generate_alt_texts'],
            $registry->getRetiredBuiltinNames(),
        );
    }

    public function testDeleteBuiltinThrows(): void
    {
        $repository = $this->createMock(McpPromptTemplateRepository::class);
        $repository->method('findByUid')->willReturn([
            'uid' => 1,
            'name' => 'audit_page_seo',
            'description' => 'Create news',
            'templateBody' => 'body',
            'arguments' => [],
            'hidden' => false,
            'deleted' => false,
            'crdate' => 0,
            'tstamp' => 0,
        ]);

        $service = new McpPromptTemplateService($repository, new McpDefaultPromptTemplateRegistry());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Built-in prompt templates cannot be deleted');

        $service->delete(1);
    }

    public function testCreateWithReservedBuiltinNameThrows(): void
    {
        $repository = $this->createMock(McpPromptTemplateRepository::class);
        $service = new McpPromptTemplateService($repository, new McpDefaultPromptTemplateRegistry());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved for a built-in');

        $service->create('audit_page_seo', 'desc', 'body', []);
    }

    public function testCreateCustomTemplateValidatesName(): void
    {
        $repository = $this->createMock(McpPromptTemplateRepository::class);
        $service = new McpPromptTemplateService($repository, new McpDefaultPromptTemplateRegistry());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('snake_case');

        $service->create('Invalid-Name', 'desc', 'body', []);
    }

    public function testCreateRestoresSoftDeletedTemplateWithSameName(): void
    {
        $repository = $this->createMock(McpPromptTemplateRepository::class);
        $repository->expects(self::once())
            ->method('findAnyByName')
            ->with('weekly_content_review')
            ->willReturn([
                'uid' => 9,
                'name' => 'weekly_content_review',
                'description' => 'Old',
                'templateBody' => 'Old body',
                'arguments' => [],
                'hidden' => false,
                'deleted' => true,
                'crdate' => 0,
                'tstamp' => 0,
            ]);
        $repository->expects(self::once())
            ->method('restore')
            ->with(
                9,
                'Updated description',
                'Updated body',
                [['name' => 'page_id', 'required' => true, 'description' => 'Page UID']],
            );
        $repository->expects(self::never())->method('insert');

        $service = new McpPromptTemplateService($repository, new McpDefaultPromptTemplateRegistry());
        $uid = $service->create(
            'weekly_content_review',
            'Updated description',
            'Updated body',
            [['name' => 'page_id', 'required' => true, 'description' => 'Page UID']],
        );

        self::assertSame(9, $uid);
    }
}
