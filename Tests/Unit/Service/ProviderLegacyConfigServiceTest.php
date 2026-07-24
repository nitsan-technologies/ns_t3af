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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\ProviderLegacyConfigService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ProviderLegacyConfigServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 32);

        $extensionSettingsService = $this->createMock(ExtensionSettingsService::class);
        $extensionSettingsService->method('getAll')->willReturnCallback(
            static function (string $extensionKey): array {
                return match ($extensionKey) {
                    'ns_t3af' => [
                        'deepl_api_key' => 'deepl-secret',
                        'openai_admin_api_key' => 'admin-key',
                    ],
                    'ns_t3cs' => [],
                    default => [],
                };
            },
        );
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === ExtensionSettingsService::class,
        );
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($extensionSettingsService): ExtensionSettingsService {
                if ($id === ExtensionSettingsService::class) {
                    return $extensionSettingsService;
                }

                throw new \RuntimeException('Unexpected container service: ' . $id);
            },
        );
        GeneralUtility::setContainer($container);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testBuildLegacyConfigMapsDefaultProviderAndNonProviderKeys(): void
    {
        $cipher = new CredentialCipher();
        $openai = $this->provider(
            identifier: 'openai-main',
            adapterType: 'symfony.openai',
            apiKeyCipher: $cipher->encrypt('sk-openai'),
            modelId: 'gpt-4o',
            embeddingModelId: 'text-embedding-3-small',
            isDefault: true,
            capabilities: [Capability::CHAT, Capability::EMBEDDINGS],
        );

        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findAll')->willReturn([$openai]);
        $repo->method('findDefault')->willReturn($openai);

        $service = new ProviderLegacyConfigService($repo, $cipher);
        $config = $service->buildLegacyConfig('ns_t3af');

        self::assertSame('deepl-secret', $config['deepl_api_key']);
        self::assertSame('admin-key', $config['openai_admin_api_key']);
        self::assertSame('sk-openai', $config['openai_api_key']);
        self::assertSame('gpt-4o', $config['openai_model']);
        self::assertSame('openai', $config['defaultModel']);
        self::assertSame('openai', $config['defaultEmbeddingsModel']);
    }

    public function testBuildLegacyConfigInheritsUniverseAdminKeyForChildExtensions(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findAll')->willReturn([]);
        $repo->method('findDefault')->willReturn(null);

        $service = new ProviderLegacyConfigService($repo, new CredentialCipher());
        $config = $service->buildLegacyConfig('ns_t3cs');

        self::assertSame('admin-key', $config['openai_admin_api_key']);
    }

    public function testResolveDefaultSlugReturnsUnknownWhenNoDefault(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findAll')->willReturn([]);
        $repo->method('findDefault')->willReturn(null);

        $service = new ProviderLegacyConfigService($repo, new CredentialCipher());
        self::assertSame('unknown', $service->resolveDefaultSlug());
    }

    /**
     * @param list<string> $capabilities
     */
    private function provider(
        string $identifier,
        string $adapterType,
        string $apiKeyCipher = '',
        string $modelId = '',
        string $embeddingModelId = '',
        bool $isDefault = false,
        array $capabilities = [],
    ): Provider {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: $identifier,
            title: 'Test',
            adapterType: $adapterType,
            endpointUrl: 'https://api.example.com/v1',
            apiKeyCipher: $apiKeyCipher,
            modelId: $modelId,
            embeddingModelId: $embeddingModelId,
            capabilities: $capabilities,
            temperature: 0.7,
            systemPrompt: '',
            isDefault: $isDefault,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: 'unknown',
            lastStatusAt: 0,
            lastStatusMessage: '',
            isEnabled: true,
        );
    }
}
