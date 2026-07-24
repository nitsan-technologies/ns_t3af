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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Prompt;

use Mcp\Server;
use NITSAN\NsT3AF\Mcp\Prompt\McpDynamicPromptRegistrar;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateRepository;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateService;
use PHPUnit\Framework\TestCase;

final class McpDynamicPromptRegistrarTest extends TestCase
{
    public function testRenderTemplateReplacesArguments(): void
    {
        $service = $this->createMock(McpPromptTemplateService::class);
        $repository = $this->createMock(McpPromptTemplateRepository::class);
        $repository->method('findVisible')->willReturn([
            [
                'uid' => 1,
                'name' => 'create_news_article',
                'description' => 'Create news',
                'templateBody' => 'Brief {{brief}} on page {{pid}}',
                'arguments' => [
                    ['name' => 'brief', 'required' => true, 'description' => ''],
                    ['name' => 'pid', 'required' => true, 'description' => ''],
                ],
                'hidden' => false,
                'deleted' => false,
                'crdate' => 0,
                'tstamp' => 0,
            ],
        ]);

        $registrar = new McpDynamicPromptRegistrar($service, $repository);
        $builder = Server::builder();
        $registrar->register($builder);

        $server = $builder->build();
        self::assertInstanceOf(\Mcp\Server::class, $server);
    }
}
