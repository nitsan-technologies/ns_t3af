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

use NITSAN\NsT3AF\AiLabel\Event\CollectApplicableTablesEvent;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ApplicableTablesResolver;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ApplicableTablesResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testDefaultsIncludeCoreTables(): void
    {
        $resolver = new ApplicableTablesResolver(
            $this->passthroughDispatcher(),
            $this->settingsService([]),
        );

        self::assertTrue($resolver->isApplicable('tt_content'));
        self::assertTrue($resolver->isApplicable('pages'));
        self::assertTrue($resolver->isApplicable('sys_file_metadata'));
        self::assertFalse($resolver->isApplicable('tx_unknown_table'));
    }

    public function testEventCanAddATable(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event): object {
            if ($event instanceof CollectApplicableTablesEvent) {
                $event->addTable('tx_myext_item');
            }

            return $event;
        });

        $resolver = new ApplicableTablesResolver(
            $dispatcher,
            $this->settingsService([]),
        );

        self::assertTrue($resolver->isApplicable('tx_myext_item'));
        self::assertContains('tx_myext_item', $resolver->getTables());
    }

    public function testConfiguredTablesAreMergedWithDefaults(): void
    {
        $resolver = new ApplicableTablesResolver(
            $this->passthroughDispatcher(),
            $this->settingsService(['tx_news_domain_model_news']),
        );

        self::assertTrue($resolver->isApplicable('tt_content'));
        self::assertTrue($resolver->isApplicable('pages'));
        self::assertTrue($resolver->isApplicable('sys_file_metadata'));
        self::assertTrue($resolver->isApplicable('tx_news_domain_model_news'));
    }

    private function passthroughDispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        return $dispatcher;
    }

    /**
     * @param list<string> $tables
     */
    private function settingsService(array $tables): AiLabelSettingsService
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn(
            $tables === [] ? [] : ['ailabelApplicableTables' => implode(', ', $tables)],
        );

        return new AiLabelSettingsService($extensionSettings);
    }
}
