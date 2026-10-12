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

namespace NITSAN\NsT3AF\Tests\Unit\Command;

use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Agent\Embedding\ProviderEmbeddingSource;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Command\IndexAgentToolsCommand;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class IndexAgentToolsCommandTest extends TestCase
{
    #[Test]
    public function rejectedEmbeddingModelPrintsOneReadableLineWithoutTheResponseBody(): void
    {
        $tester = $this->tester(new \RuntimeException(
            'Unexpected response code 422: "{"detail":[{"type":"model_attributes_type","loc":["body"],'
            . '"input":["ask clarification\nAsk the editor a short clarifying question"]}]}"',
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $this->display($tester);
        self::assertStringContainsString('model "mistral-embed" (HTTP 422)', $display);
        self::assertStringContainsString('AI Foundation > AI Providers', $display);
        self::assertStringNotContainsString('detail', $display);
        self::assertStringNotContainsString('ask clarification', $display);
    }

    #[Test]
    public function otherProviderErrorsKeepTheirReasonWithoutTheResponseBody(): void
    {
        $tester = $this->tester(new \RuntimeException(
            'Rate limit or quota exceeded at provider. [provider=Mistral_Provider call=embed] HTTP/2 429: '
            . '{"error":{"message":"Too many requests"}}',
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $this->display($tester);
        self::assertStringContainsString(
            'Rate limit or quota exceeded at provider. [provider=Mistral_Provider call=embed] HTTP/2 429',
            $display,
        );
        self::assertStringNotContainsString('Too many requests', $display);
        self::assertStringNotContainsString('mistral-embed', $display);
    }

    #[Test]
    public function successfulRebuildReportsSuccess(): void
    {
        $tester = $this->tester(null);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('index rebuilt', $this->display($tester));
    }

    private function tester(?\Throwable $failure): CommandTester
    {
        $index = $this->createMock(AgentToolIndexInterface::class);
        if ($failure !== null) {
            $index->method('rebuild')->willThrowException($failure);
        }

        $aiService = $this->createMock(AiServiceInterface::class);
        $aiService->method('provider')->willReturn(Provider::fromRow([
            'identifier' => 'Mistral_Provider',
            'adapter_type' => 'symfony.mistral',
            'model_id' => 'open-mistral-nemo',
            'embedding_model_id' => '',
        ]));

        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn(['agentEmbeddingSource' => 'provider']);
        $settings = new AgentSettingsService($extensionSettings);

        $resolver = new EmbeddingSourceResolver($settings, [new ProviderEmbeddingSource($aiService, $settings)]);

        return new CommandTester(new IndexAgentToolsCommand($index, $resolver));
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
