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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\SchedulerCliCommandCatalogService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Console\CommandRegistry;

final class SchedulerCliCommandCatalogServiceTest extends TestCase
{
    public function testAllDiscoversAiuniverseCommandsFromRegistry(): void
    {
        $command = new class ('t3af:mcp:cleanup') extends Command {
            protected function configure(): void
            {
                $this->setDescription('Cleanup MCP sessions');
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };

        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->with('t3af')->willReturn([
            't3af:mcp:cleanup' => [
                'serviceName' => 'NITSAN\\NsT3AF\\Mcp\\Command\\CleanupCommand',
                'description' => 'Cleanup MCP sessions',
                'schedulable' => true,
            ],
        ]);
        $registry->method('getCommandByIdentifier')->with('t3af:mcp:cleanup')->willReturn($command);

        $service = new SchedulerCliCommandCatalogService($registry, ProviderTestStubs::emptyMcpToolsCardRegistry());
        $entries = $service->all();

        self::assertCount(1, $entries);
        self::assertSame('t3af:mcp:cleanup', $entries[0]['command']);
        self::assertSame('ns_t3af', $entries[0]['extension']);
        self::assertSame('mcp', $entries[0]['category']);
        self::assertSame(1, $entries[0]['schedulable']);
    }

    public function testFindByCommandReturnsMatchingEntry(): void
    {
        $command = new class ('t3af:ai-logs:cleanup') extends Command {
            protected function configure(): void
            {
                $this->setDescription('Delete AI log entries older than retention period');
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };

        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->willReturn([
            't3af:ai-logs:cleanup' => [
                'serviceName' => 'NITSAN\\NsT3AF\\Command\\CleanAiLogsCommand',
                'description' => 'Delete AI log entries older than retention period',
                'schedulable' => true,
            ],
        ]);
        $registry->method('getCommandByIdentifier')->willReturn($command);

        $service = new SchedulerCliCommandCatalogService($registry, ProviderTestStubs::emptyMcpToolsCardRegistry());
        $entry = $service->findByCommand('t3af:ai-logs:cleanup');

        self::assertIsArray($entry);
        self::assertSame('ns_t3af', $entry['extension']);
    }

    public function testFindByCommandResolvesDeprecatedLogClearAlias(): void
    {
        $command = new class ('t3af:ai-logs:cleanup') extends Command {
            protected function configure(): void
            {
                $this->setDescription('Delete AI log entries older than retention period');
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };

        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->willReturn([
            't3af:ai-logs:cleanup' => [
                'serviceName' => 'NITSAN\\NsT3AF\\Command\\CleanAiLogsCommand',
                'description' => 'Delete AI log entries older than retention period',
                'schedulable' => true,
            ],
            't3af:log:clear' => [
                'serviceName' => 'NITSAN\\NsT3AF\\Command\\CleanAiLogsCommand',
                'description' => 'Delete AI log entries older than retention period',
                'schedulable' => true,
            ],
        ]);
        $registry->method('getCommandByIdentifier')->with('t3af:ai-logs:cleanup')->willReturn($command);

        $service = new SchedulerCliCommandCatalogService($registry, ProviderTestStubs::emptyMcpToolsCardRegistry());
        $entry = $service->findByCommand('t3af:log:clear');

        self::assertIsArray($entry);
        self::assertSame('t3af:ai-logs:cleanup', $entry['command']);
        self::assertSame('ns_t3af', $entry['extension']);
    }

    public function testFindByCommandResolvesLegacyAlias(): void
    {
        $command = new class ('t3af:bulk:retranslate') extends Command {
            protected function configure(): void
            {
                $this->setDescription('Bulk retranslate');
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };

        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->willReturn([
            't3af:bulk:retranslate' => [
                'serviceName' => 'NITSAN\\NsT3Ai\\Command\\BulkRetranslateCommand',
                'description' => 'Bulk retranslate',
                'schedulable' => true,
            ],
        ]);
        $registry->method('getCommandByIdentifier')->willReturn($command);

        $service = new SchedulerCliCommandCatalogService($registry, ProviderTestStubs::emptyMcpToolsCardRegistry());
        $entry = $service->findByCommand('nst3ai:bulk:retranslate');

        self::assertIsArray($entry);
        self::assertSame('t3af:bulk:retranslate', $entry['command']);
        self::assertSame('ns_t3ai', $entry['extension']);
    }

    public function testIsSchedulerCliCommandAcceptsLegacyPrefixes(): void
    {
        $service = new SchedulerCliCommandCatalogService($this->createMock(CommandRegistry::class), ProviderTestStubs::emptyMcpToolsCardRegistry());

        self::assertTrue($service->isSchedulerCliCommand('t3af:mcp:cleanup'));
        self::assertTrue($service->isSchedulerCliCommand('nst3ai:bulk:retranslate'));
        self::assertFalse($service->isSchedulerCliCommand('cache:flush'));
    }

    public function testExtensionGroupsGroupsCommandsByExtension(): void
    {
        $commandA = new class ('t3af:mcp:cleanup') extends Command {
            protected function configure(): void
            {
                $this->setDescription('Cleanup MCP sessions');
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };
        $commandB = new class ('t3af:ai-logs:cleanup') extends Command {
            protected function configure(): void
            {
                $this->setDescription('Delete AI log entries older than retention period');
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };

        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->willReturn([
            't3af:mcp:cleanup' => [
                'serviceName' => 'NITSAN\\NsT3AF\\Mcp\\Command\\CleanupCommand',
                'description' => 'Cleanup MCP sessions',
                'schedulable' => true,
            ],
            't3af:ai-logs:cleanup' => [
                'serviceName' => 'NITSAN\\NsT3AF\\Command\\CleanAiLogsCommand',
                'description' => 'Delete AI log entries older than retention period',
                'schedulable' => true,
            ],
            't3af:log:clear' => [
                'serviceName' => 'NITSAN\\NsT3AF\\Command\\CleanAiLogsCommand',
                'description' => 'Delete AI log entries older than retention period',
                'schedulable' => true,
            ],
        ]);
        $registry->method('getCommandByIdentifier')->willReturnMap([
            ['t3af:mcp:cleanup', $commandA],
            ['t3af:ai-logs:cleanup', $commandB],
        ]);

        $service = new SchedulerCliCommandCatalogService($registry, ProviderTestStubs::emptyMcpToolsCardRegistry());
        $groups = $service->extensionGroups();

        self::assertCount(1, $groups);
        self::assertSame('ns_t3af', $groups[0]['id']);
        self::assertSame(2, $groups[0]['commandCount']);
        self::assertArrayHasKey('cliSnippet', $groups[0]['commands'][0]);
    }
}
