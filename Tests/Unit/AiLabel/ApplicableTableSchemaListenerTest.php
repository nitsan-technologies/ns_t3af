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
use NITSAN\NsT3AF\AiLabel\Service\ApplicableTableSchemaListener;
use NITSAN\NsT3AF\AiLabel\Service\ApplicableTablesResolver;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;

final class ApplicableTableSchemaListenerTest extends TestCase
{
    public function testAddsCreateTableFragmentForExtraTablesOnly(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'ailabelApplicableTables' => 'tx_news_domain_model_news',
        ]);

        $listener = new ApplicableTableSchemaListener(
            new ApplicableTablesResolver($dispatcher, new AiLabelSettingsService($extensionSettings)),
        );

        $event = new AlterTableDefinitionStatementsEvent([]);
        $listener->appendStatements($event);

        $sql = $event->getSqlData();
        self::assertCount(1, $sql);
        self::assertStringContainsString('CREATE TABLE `tx_news_domain_model_news`', (string) $sql[0]);
        self::assertStringContainsString('tx_nst3af_ailabel_involvement', (string) $sql[0]);
        self::assertStringNotContainsString('ALTER TABLE', (string) $sql[0]);
    }
}
