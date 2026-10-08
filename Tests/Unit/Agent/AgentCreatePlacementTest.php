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

use NITSAN\NsT3AF\Agent\Service\AgentCreatePlacement;
use NITSAN\NsT3AF\Agent\Service\AgentRecordLabeler;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentCreatePlacementTest extends TestCase
{
    use AgentTranslatorTrait;

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function aPositivePidIsTheFirstPageInsideThatPage(): void
    {
        $placement = $this->placement(null);

        self::assertSame(
            'Location: first page inside Page „Page 1“ [68]',
            $placement->describe('pages', 68),
        );
    }

    #[Test]
    public function aNegativePidIsInsideTheParentAfterThatPage(): void
    {
        $placement = $this->placement(['pid' => 1]);

        self::assertSame(
            'Location: inside Page „Home“ [1], after Page „Page 1“ [68]',
            $placement->describe('pages', -68),
        );
    }

    /**
     * @param array{pid: int}|null $anchor
     */
    private function placement(?array $anchor): AgentCreatePlacement
    {
        $labeler = $this->createMock(AgentRecordLabeler::class);
        $labeler->method('recordLabel')->willReturnCallback(
            static fn(string $table, int $uid): string => match ($uid) {
                1 => 'Page „Home“',
                68 => 'Page „Page 1“',
                default => 'Page #' . $uid,
            },
        );
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn($anchor);

        return new AgentCreatePlacement($this->createAgentTranslator(), $labeler, $records);
    }
}
