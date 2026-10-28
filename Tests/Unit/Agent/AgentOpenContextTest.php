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

use NITSAN\NsT3AF\Agent\Controller\AgentAjaxController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F1 — AgentAjaxController::readOpenContext(), the new helper
 * resolveContext() uses to make an agent-open-with-context payload additive
 * (see context/phase0/spike-f1-agent-open-api.md). Absent on a plain
 * toolbar/hotkey open, so this must default to [] without touching anything.
 *
 * @internal
 */
final class AgentOpenContextTest extends TestCase
{
    #[Test]
    public function absentOpenContextIsANoOp(): void
    {
        self::assertSame([], $this->readOpenContext([], []));
        self::assertSame([], $this->readOpenContext(['pageId' => 12], ['module' => 'web_layout']));
    }

    #[Test]
    public function arrayOpenContextInTheBodyWins(): void
    {
        $body = ['openContext' => ['pageId' => 42, 'chips' => [['key' => 'finding', 'label' => 'SEO-01', 'value' => 'Title too long']]]];

        self::assertSame($body['openContext'], $this->readOpenContext($body, ['openContext' => ['pageId' => 99]]));
    }

    #[Test]
    public function arrayOpenContextInTheQueryIsReadWhenBodyHasNone(): void
    {
        $query = ['openContext' => ['pageId' => 7, 'promptText' => 'Fix the meta description.']];

        self::assertSame($query['openContext'], $this->readOpenContext([], $query));
    }

    #[Test]
    public function jsonEncodedOpenContextInTheQueryStringIsDecoded(): void
    {
        $query = ['openContext' => '{"pageId":42,"promptText":"Fix the SEO title."}'];

        self::assertSame(['pageId' => 42, 'promptText' => 'Fix the SEO title.'], $this->readOpenContext([], $query));
    }

    #[Test]
    public function malformedJsonOpenContextIsDroppedNotFatal(): void
    {
        self::assertSame([], $this->readOpenContext([], ['openContext' => '{not valid json']));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function readOpenContext(array $body, array $query): array
    {
        $controller = (new \ReflectionClass(AgentAjaxController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentAjaxController::class, 'readOpenContext');

        return $method->invoke($controller, $body, $query);
    }
}
