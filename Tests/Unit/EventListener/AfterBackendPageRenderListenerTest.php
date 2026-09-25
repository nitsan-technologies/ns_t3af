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

namespace NITSAN\NsT3AF\Tests\Unit\EventListener;

use NITSAN\NsT3AF\EventListener\AfterBackendPageRenderListener;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;

/**
 * v12 cannot register PHP AsEventListener attributes (class does not exist).
 * YAML event.listener tags are the TYPO3 12–14 path, matching core Live Search.
 */
final class AfterBackendPageRenderListenerTest extends TestCase
{
    private string $extRoot;

    protected function setUp(): void
    {
        $this->extRoot = dirname(__DIR__, 3);
    }

    public function testListenerIsTaggedInServicesYamlForTypo3v12(): void
    {
        $yaml = (string) file_get_contents($this->extRoot . '/Configuration/Services.yaml');
        $class = AfterBackendPageRenderListener::class;
        $classPos = strpos($yaml, $class . ':');
        self::assertNotFalse($classPos, $class . ' must be declared in Services.yaml');

        $nextClass = strpos($yaml, "\n  NITSAN\\", $classPos + strlen($class) + 2);
        $block = $nextClass === false ? substr($yaml, $classPos) : substr($yaml, $classPos, $nextClass - $classPos);

        self::assertStringContainsString('name: event.listener', $block);
        self::assertStringContainsString(AfterBackendPageRenderEvent::class, $block);
    }

    public function testListenerDoesNotUseAsEventListenerAttribute(): void
    {
        $source = (string) file_get_contents(
            $this->extRoot . '/Classes/EventListener/AfterBackendPageRenderListener.php',
        );
        self::assertStringNotContainsString('use TYPO3\\CMS\\Core\\Attribute\\AsEventListener', $source);
        self::assertStringNotContainsString('#[AsEventListener', $source);
    }

    public function testToolbarItemUsesLiveSearchLinkClass(): void
    {
        $html = (string) file_get_contents(
            $this->extRoot . '/Resources/Private/Templates/Agent/ToolbarItem.html',
        );
        self::assertStringContainsString('toolbar-item-link', $html);
    }

    public function testLaunchBarSlotInsertsBesideSearchButtonNotAsTopbarDirectChild(): void
    {
        $js = (string) file_get_contents($this->extRoot . '/Resources/Public/JavaScript/agent.js');
        self::assertStringContainsString('anchor.parentElement.insertBefore(slot, anchor)', $js);
        self::assertStringNotContainsString('topbar.insertBefore(slot, anchor)', $js);
    }
}
