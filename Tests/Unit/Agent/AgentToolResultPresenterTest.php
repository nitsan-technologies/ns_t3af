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

use NITSAN\NsT3AF\Agent\Service\AgentToolResultPresenter;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolResultPresenterTest extends TestCase
{
    #[Test]
    public function presentDecodesJsonStringAndBuildsPageFacts(): void
    {
        $ai = $this->createMock(AiServiceInterface::class);
        $ai->expects(self::once())
            ->method('complete')
            ->with(self::isString(), self::isInstanceOf(AiOptions::class))
            ->willReturn(new AiResponse(
                content: 'Home is a visible standard page with uid 1.',
                modelId: 'test',
                providerIdentifier: 'default',
            ));

        $presenter = new AgentToolResultPresenter($ai);
        $payload = json_encode([
            'uid' => 1,
            'pid' => 0,
            'title' => 'Home',
            'slug' => '/',
            'doktype' => 1,
            'hidden' => 0,
        ], JSON_THROW_ON_ERROR);

        $presented = $presenter->present('pages_get', $payload, true);

        self::assertTrue($presented['success']);
        self::assertSame('Home is a visible standard page with uid 1.', $presented['content']);
        self::assertSame('Home is a visible standard page with uid 1.', $presented['llmSummary']);
        self::assertStringContainsString('Home', $presented['summary']);
        self::assertSame(
            [
                ['label' => 'Title', 'value' => 'Home'],
                ['label' => 'UID', 'value' => '1'],
                ['label' => 'Parent', 'value' => '0'],
                ['label' => 'Slug', 'value' => '/'],
                ['label' => 'Type', 'value' => 'Standard page'],
                ['label' => 'Visibility', 'value' => 'Visible'],
            ],
            $presented['facts'],
        );
        self::assertIsArray($presented['details']);
        self::assertSame(1, $presented['details']['uid']);
    }

    #[Test]
    public function presentTreatsPayloadErrorAndNullAsFailureWithoutLlm(): void
    {
        $ai = $this->createMock(AiServiceInterface::class);
        $ai->expects(self::never())->method('complete');

        $presenter = new AgentToolResultPresenter($ai);

        $errorPresented = $presenter->present(
            'pages_get',
            '{"error":"Page not found"}',
            true,
        );
        self::assertFalse($errorPresented['success']);
        self::assertSame('Page not found', $errorPresented['content']);
        self::assertNull($errorPresented['llmSummary']);
        self::assertSame([], $errorPresented['facts']);

        $nullPresented = $presenter->present('pages_get', 'null', true);
        self::assertFalse($nullPresented['success']);
        self::assertSame('The tool returned no data.', $nullPresented['content']);
    }

    #[Test]
    public function presentFallsBackWhenLlmFails(): void
    {
        $ai = $this->createMock(AiServiceInterface::class);
        $ai->method('complete')->willThrowException(new \RuntimeException('no provider'));

        $presenter = new AgentToolResultPresenter($ai);
        $presented = $presenter->present(
            'pages_get',
            ['uid' => 48, 'title' => 'About', 'slug' => '/about'],
            true,
        );

        self::assertTrue($presented['success']);
        self::assertNull($presented['llmSummary']);
        self::assertStringContainsString('About', $presented['content']);
        self::assertStringContainsString('uid 48', $presented['content']);
    }

    #[Test]
    public function presentSkipsLlmForListResultsWithStructuredFacts(): void
    {
        $ai = $this->createMock(AiServiceInterface::class);
        $ai->expects(self::never())->method('complete');

        $presenter = new AgentToolResultPresenter($ai);
        $presented = $presenter->present('content_list', [
            ['uid' => 178, 'header' => 'The AI for problem solvers'],
            ['uid' => 179, 'header' => 'Keep thinking with Claude'],
        ], true);

        self::assertTrue($presented['success']);
        self::assertNull($presented['llmSummary']);
        self::assertSame('Found 2 items — details below.', $presented['content']);
        self::assertSame('2', $presented['facts'][0]['value']);
    }

    #[Test]
    public function presentBuildsGenericFactsForUnknownTools(): void
    {
        $ai = $this->createMock(AiServiceInterface::class);
        $ai->method('complete')->willThrowException(new \RuntimeException('skip'));

        $presenter = new AgentToolResultPresenter($ai);
        $presented = $presenter->present(
            'custom_lookup',
            ['name' => 'Acme', 'uid' => 9, 'description' => 'Widget'],
            true,
        );

        self::assertSame(
            [
                ['label' => 'Name', 'value' => 'Acme'],
                ['label' => 'Uid', 'value' => '9'],
                ['label' => 'Description', 'value' => 'Widget'],
            ],
            $presented['facts'],
        );
    }
}
