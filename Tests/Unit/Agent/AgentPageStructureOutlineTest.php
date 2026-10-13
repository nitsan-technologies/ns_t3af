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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentPageStructureOutline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentPageStructureOutlineTest extends TestCase
{
    #[Test]
    public function aBulletOutlineBecomesNestedStructureJson(): void
    {
        $outline = "• About Us\n• Products\n  • Breads\n  • Cakes\n• Contact";
        $parsed = AgentPageStructureOutline::parseOutline($outline);

        self::assertNotNull($parsed);
        self::assertSame('', $parsed['title']);
        self::assertSame(['About Us', 'Products', 'Contact'], array_column($parsed['children'], 'title'));
        self::assertSame(['Breads', 'Cakes'], array_column($parsed['children'][1]['children'], 'title'));
    }

    #[Test]
    public function resolveEditKeepsACustomOutlineInsteadOfTheDraft(): void
    {
        $draft = json_encode(['title' => 'Home', 'children' => [['title' => 'Old', 'children' => []]]], JSON_THROW_ON_ERROR);
        $resolved = AgentPageStructureOutline::resolveEdit("• About Us\n• Contact", $draft);

        self::assertFalse($resolved['fellBackToDraft']);
        $decoded = json_decode($resolved['structure'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['About Us', 'Contact'], array_column($decoded['children'], 'title'));
    }

    #[Test]
    public function resolveEditFallsBackWhenTheCustomTextIsNotAnOutline(): void
    {
        $draft = '{"title":"Home","children":[]}';
        $resolved = AgentPageStructureOutline::resolveEdit('Company and location', $draft);

        self::assertTrue($resolved['fellBackToDraft']);
        self::assertSame($draft, $resolved['structure']);
    }

    #[Test]
    public function formatOutlineListsNestedTitles(): void
    {
        $outline = AgentPageStructureOutline::formatOutline([
            'title' => '',
            'children' => [
                ['title' => 'Shop', 'children' => [['title' => 'Gift cards', 'children' => []]]],
                ['title' => 'Team', 'children' => []],
            ],
        ]);

        self::assertSame("• Shop\n  • Gift cards\n• Team", $outline);
    }
}
