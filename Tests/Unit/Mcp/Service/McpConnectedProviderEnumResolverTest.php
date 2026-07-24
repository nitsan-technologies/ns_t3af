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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Mcp\Service\McpConnectedProviderEnumResolver;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Tests\Unit\Credits\StubCreditsReleaseGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * @internal
 */
final class McpConnectedProviderEnumResolverTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 32);
    }

    #[Test]
    public function resolveEnumReturnsCreditsIdentifierWhenCreditsModeIsActive(): void
    {
        $resolver = $this->createResolver(creditsActive: true);

        self::assertSame([CreditsProviderIdentifier::IDENTIFIER], $resolver->resolveEnum());
        self::assertStringContainsString('T3Planet Credits mode is active', $resolver->buildDescription());
        self::assertStringContainsString(CreditsProviderIdentifier::IDENTIFIER, $resolver->buildDescription());
    }

    #[Test]
    public function resolveEnumListsConnectedProvidersWhenCreditsModeIsInactive(): void
    {
        $repository = $this->createMock(ProviderRepositoryInterface::class);
        $repository->method('findAll')->willReturn([
            $this->connectedProvider('openai-1', 'OpenAI'),
            $this->disconnectedProvider('offline-1', 'Offline'),
        ]);

        $resolver = new McpConnectedProviderEnumResolver(
            $repository,
            $this->creditModeResolver(active: false),
        );

        self::assertSame(['openai-1'], $resolver->resolveEnum());
        self::assertStringContainsString('openai-1 (OpenAI)', $resolver->buildDescription());
    }

    private function createResolver(bool $creditsActive): McpConnectedProviderEnumResolver
    {
        $repository = $this->createMock(ProviderRepositoryInterface::class);
        $repository->expects(self::never())->method('findAll');

        return new McpConnectedProviderEnumResolver(
            $repository,
            $this->creditModeResolver($creditsActive),
        );
    }

    private function creditModeResolver(bool $active): CreditModeResolver
    {
        $cipher = new CredentialCipher();
        $tokenEnc = $active ? $cipher->encrypt('plain-token') : '';

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'credit_mode' => $active ? 1 : 0,
            'license_keys' => $active ? 'license-key' : '',
            'token_enc' => $tokenEnc,
        ]);

        $runtime = new RuntimeSettingsService(
            $repository,
            $cipher,
            new ExtensionConfiguration(),
        );

        return new CreditModeResolver($runtime, new StubCreditsReleaseGate($active));
    }

    private function connectedProvider(string $identifier, string $title): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: $identifier,
            title: $title,
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: ['chat'],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: McpConnectedProviderEnumResolver::STATUS_CONNECTED,
            lastStatusAt: 0,
            lastStatusMessage: '',
            isEnabled: true,
        );
    }

    private function disconnectedProvider(string $identifier, string $title): Provider
    {
        return new Provider(
            uid: 2,
            pid: 0,
            identifier: $identifier,
            title: $title,
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: ['chat'],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: 'error',
            lastStatusAt: 0,
            lastStatusMessage: '',
            isEnabled: true,
        );
    }
}
