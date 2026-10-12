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

use NITSAN\NsT3AF\Agent\Service\AgentProviderTurnErrorPresenter;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class AgentProviderTurnErrorPresenterTest extends TestCase
{
    private AgentProviderTurnErrorPresenter $presenter;

    private AgentTranslator $translator;

    protected function setUp(): void
    {
        $this->presenter = new AgentProviderTurnErrorPresenter();
        $factory = $this->createMock(LanguageServiceFactory::class);
        $this->translator = new AgentTranslator($factory);
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(static function (string $label): string {
            return match (true) {
                str_contains($label, 'agent.turn.orchestratorFailed') => 'The agent could not complete this turn: %1$s',
                str_contains($label, 'agent.turn.providerUnavailable') => 'The AI provider is not available right now.',
                str_contains($label, 'agent.turn.providerRateLimited') => 'Rate limited: %1$s',
                default => $label,
            };
        });
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
    }

    #[Test]
    public function openAiBillingErrorIsExtractedVerbatim(): void
    {
        $message = 'You have no credits remaining. Add credits to continue using the API at https://platform.openai.com/settings/organization/billing/.';

        self::assertStringContainsString(
            'no credits remaining',
            AgentProviderTurnErrorPresenter::readableMessageFromThrowable(new AdapterRuntimeException($message)),
        );
        self::assertTrue(AgentProviderTurnErrorPresenter::looksLikeProviderAccountProblem(new AdapterRuntimeException($message)));
    }

    #[Test]
    public function editorsSeeGenericLineForAccountProblems(): void
    {
        $message = 'You have no credits remaining. Add credits to continue using the API.';
        $shown = $this->presenter->present(new AdapterRuntimeException($message), $this->translator, false);

        self::assertSame('The AI provider is not available right now.', $shown);
    }

    #[Test]
    public function adminsSeeMaskedVendorDetailForAccountProblems(): void
    {
        $message = 'You have no credits remaining. Add credits to continue using the API.';
        $shown = $this->presenter->present(new AdapterRuntimeException($message), $this->translator, true);

        self::assertStringContainsString('no credits remaining', $shown);
        self::assertStringStartsWith('The agent could not complete this turn:', $shown);
    }

    #[Test]
    public function invalidApiKeyFragmentsAreMasked(): void
    {
        $raw = 'Incorrect API key provided: sk-proj-abcdefghijklmnopqrstuvwxyz1234567890. You can find your API key at https://platform.openai.com.';

        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', AgentProviderTurnErrorPresenter::maskSensitiveProviderText($raw));
        self::assertStringContainsString('sk-proj-…', AgentProviderTurnErrorPresenter::maskSensitiveProviderText($raw));
    }

    #[Test]
    public function prefixLessKeysAndBearerTokensAreMasked(): void
    {
        $mistral = 'Authorization failed for key=abcdefghijklmnopqrstuvwxyz1234567890ABCD';
        $masked = AgentProviderTurnErrorPresenter::maskSensitiveProviderText($mistral);
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz1234567890ABCD', $masked);
        self::assertStringContainsString('key=…', $masked);

        $bearer = 'HTTP 401: Invalid Authorization header Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.payload';
        self::assertStringContainsString('Bearer …', AgentProviderTurnErrorPresenter::maskSensitiveProviderText($bearer));

        $lettersOnly = 'File identifier ' . str_repeat('a', 40) . ' is invalid';
        self::assertSame($lettersOnly, AgentProviderTurnErrorPresenter::maskSensitiveProviderText($lettersOnly));
    }

    #[Test]
    public function nestedHttpJsonIsExtractedFromExceptionChain(): void
    {
        $inner = new \RuntimeException('HTTP 402 returned: {"error":{"message":"Insufficient credits on Anthropic account"}}');
        $outer = new AdapterRuntimeException('complete_with_tools failed', 0, $inner);

        self::assertStringContainsString(
            'Insufficient credits on Anthropic account',
            AgentProviderTurnErrorPresenter::readableMessageFromThrowable($outer),
        );
    }

    #[Test]
    public function mistralRateLimitJsonIsReadable(): void
    {
        $raw = 'HTTP 429 returned for "https://api.mistral.ai/v1/chat/completions": {"message":"Rate limit exceeded","type":"rate_limit"}';

        self::assertSame('Rate limit exceeded', AgentProviderTurnErrorPresenter::readableProviderError($raw));
    }

    #[Test]
    public function bare429InTokenCountIsNotTreatedAsRateLimit(): void
    {
        $exception = new AdapterRuntimeException('Model returned 429 tokens in completion metadata');

        self::assertFalse(AgentProviderTurnErrorPresenter::looksLikeProviderAccountProblem($exception));
        // Not HTTP 429 — should surface as orchestrator detail, not rate-limit label.
        $shown = $this->presenter->present($exception, $this->translator, true);
        self::assertStringContainsString('429 tokens', $shown);
        self::assertStringNotContainsString('Rate limited:', $shown);
    }

    #[Test]
    public function http429InChainTriggersRateLimitMessage(): void
    {
        $exception = new AdapterRuntimeException(
            'Provider temporary server error.',
            0,
            new \RuntimeException('HTTP/1.1 429 Too Many Requests'),
        );

        $shown = $this->presenter->present($exception, $this->translator, true);
        self::assertStringStartsWith('Rate limited:', $shown);
    }

    #[Test]
    public function longVendorBodiesAreTruncated(): void
    {
        $body = str_repeat('word ', 400);
        $readable = AgentProviderTurnErrorPresenter::readableProviderError($body);

        self::assertLessThanOrEqual(321, mb_strlen($readable));
        self::assertStringEndsWith('…', $readable);
    }

    #[Test]
    public function nonAccountErrorsAreShownToEditors(): void
    {
        $exception = new AdapterRuntimeException('The model `gpt-unknown` does not exist.');

        self::assertFalse(AgentProviderTurnErrorPresenter::looksLikeProviderAccountProblem($exception));
        $shown = $this->presenter->present($exception, $this->translator, false);
        self::assertStringContainsString('gpt-unknown', $shown);
    }
}
