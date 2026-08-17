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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Provider\Model\ModelCatalogFilter;
use NITSAN\NsT3AF\Provider\Model\ModelInfo;
use NITSAN\NsT3AF\Provider\Model\SymfonyAiCatalogReader;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WizardProviderCatalogTest extends TestCase
{
    #[Test]
    public function ensureProviderUidReusesIncompleteWizardDraft(): void
    {
        $draft = Provider::fromRow([
            'uid' => 42,
            'pid' => 0,
            'identifier' => 'openai',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'endpoint_url' => 'https://api.openai.com/v1',
            'api_key' => '',
            'model_id' => 'gpt-4o',
            'embedding_model_id' => '',
            'capabilities' => 'chat',
            'temperature' => 0.7,
            'system_prompt' => '',
            'is_default' => 0,
            'priority' => 50,
            'last_used_at' => 0,
            'last_status' => 'unknown',
            'last_status_at' => 0,
            'last_status_message' => '',
            'be_groups' => '',
            'is_enabled' => 1,
            'enabled_for_dashboard' => 1,
            'pricing_input_per_1m' => 0,
            'pricing_output_per_1m' => 0,
            'pricing_currency' => 'USD',
            'retention_days_override' => 0,
            'cost_center' => '',
            'privacy_level' => 'standard',
            'no_rerouting' => 0,
            'hidden' => 0,
            'deleted' => 0,
        ]);

        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findReusableWizardDraft')
            ->with(1, 'symfony.openai')
            ->willReturn($draft);
        $repo->expects(self::never())->method('save');

        $adapters = new AdapterRegistry([$this->fakeOpenAiAdapter()]);

        $catalog = new WizardProviderCatalog($repo, $adapters, $this->emptyCatalogReader(), new ModelCatalogFilter());

        self::assertSame(42, $catalog->ensureProviderUid('openai', '', 1));
    }

    #[Test]
    public function ensureProviderUidAllocatesNextIdentifierWhenBaseIsTakenAtStoragePid(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findReusableWizardDraft')->willReturn(null);
        $repo->expects(self::exactly(2))
            ->method('identifierExistsAtStoragePid')
            ->willReturnMap([
                ['openai', 1, true],
                ['openai-1', 1, false],
            ]);
        $repo->expects(self::once())
            ->method('save')
            ->with(
                0,
                self::callback(static function (array $values): bool {
                    return ($values['pid'] ?? null) === 1
                        && ($values['identifier'] ?? '') === 'openai-1'
                        && ($values['adapter_type'] ?? '') === 'symfony.openai';
                }),
            )
            ->willReturn(99);

        $adapters = new AdapterRegistry([$this->fakeOpenAiAdapter()]);

        $catalog = new WizardProviderCatalog($repo, $adapters, $this->emptyCatalogReader(), new ModelCatalogFilter());

        self::assertSame(99, $catalog->ensureProviderUid('openai', '', 1));
    }

    #[Test]
    public function ensureProviderUidReturnsNullWhenStoragePidIsInvalid(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->expects(self::never())->method('findReusableWizardDraft');
        $repo->expects(self::never())->method('save');

        $catalog = new WizardProviderCatalog($repo, new AdapterRegistry([$this->fakeOpenAiAdapter()]), $this->emptyCatalogReader(), new ModelCatalogFilter());

        self::assertNull($catalog->ensureProviderUid('openai', '', 0));
    }

    #[Test]
    public function listForWizardUsesCatalogModelsWhenAvailable(): void
    {
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->method('read')->willReturnMap([
            [
                'anthropic',
                [
                    new ModelInfo('claude-haiku-4-5-20251001', 'claude-haiku-4-5-20251001', [Capability::CHAT], 'catalog'),
                    new ModelInfo('claude-sonnet-4-5-20250929', 'claude-sonnet-4-5-20250929', [Capability::CHAT], 'catalog'),
                    new ModelInfo('claude-3-haiku-20240307', 'claude-3-haiku-20240307', [Capability::CHAT], 'catalog'),
                ],
            ],
            ['openai', []],
            ['gemini', []],
            ['ollama', []],
        ]);

        $catalog = new WizardProviderCatalog(
            $this->createMock(ProviderRepositoryInterface::class),
            new AdapterRegistry([]),
            $reader,
            new ModelCatalogFilter(),
        );

        $rows = $catalog->listForWizard(static fn(string $key): string => $key);
        $anthropic = null;
        foreach ($rows as $row) {
            if ($row['id'] === 'anthropic') {
                $anthropic = $row;
                break;
            }
        }

        self::assertNotNull($anthropic);
        self::assertSame('claude-sonnet-4-5-20250929', $anthropic['defaultModel']);
        self::assertContains('claude-sonnet-4-5-20250929', $anthropic['modelOptions']);
        self::assertNotContains('claude-3-haiku-20240307', $anthropic['modelOptions']);
    }

    #[Test]
    public function listForWizardFallsBackToStaticModelsWhenCatalogEmpty(): void
    {
        $catalog = new WizardProviderCatalog(
            $this->createMock(ProviderRepositoryInterface::class),
            new AdapterRegistry([]),
            $this->emptyCatalogReader(),
            new ModelCatalogFilter(),
        );

        $rows = $catalog->listForWizard(static fn(string $key): string => $key);
        $openai = null;
        $anthropic = null;
        $gemini = null;
        $ollama = null;
        foreach ($rows as $row) {
            match ($row['id']) {
                'openai' => $openai = $row,
                'anthropic' => $anthropic = $row,
                'gemini' => $gemini = $row,
                'ollama' => $ollama = $row,
                default => null,
            };
        }

        self::assertNotNull($openai);
        self::assertSame('gpt-5.6', $openai['defaultModel']);
        self::assertSame(['gpt-5.6', 'gpt-5.5', 'gpt-5-mini'], $openai['modelOptions']);

        self::assertNotNull($anthropic);
        self::assertSame('claude-sonnet-5', $anthropic['defaultModel']);
        self::assertSame(['claude-sonnet-5', 'claude-opus-5', 'claude-haiku-4-5'], $anthropic['modelOptions']);

        self::assertNotNull($gemini);
        self::assertSame('gemini-3.1-pro', $gemini['defaultModel']);
        self::assertSame(['gemini-3.7-flash', 'gemini-3.1-pro', 'gemini-3.1-flash-lite'], $gemini['modelOptions']);

        self::assertNotNull($ollama);
        self::assertSame('llama4', $ollama['defaultModel']);
        self::assertSame(['qwen3.8', 'deepseek-v4-pro', 'llama4'], $ollama['modelOptions']);
    }

    #[Test]
    public function listForWizardExcludesRetiredCatalogModels(): void
    {
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->method('read')->willReturnMap([
            [
                'anthropic',
                [
                    new ModelInfo('claude-sonnet-4-20250514', 'retired', [Capability::CHAT], 'catalog'),
                    new ModelInfo('claude-sonnet-4-5-20250929', 'current', [Capability::CHAT], 'catalog'),
                    new ModelInfo('claude-haiku-4-5-20251001', 'haiku', [Capability::CHAT], 'catalog'),
                ],
            ],
            ['openai', []],
            ['gemini', []],
            ['ollama', []],
        ]);

        $catalog = new WizardProviderCatalog(
            $this->createMock(ProviderRepositoryInterface::class),
            new AdapterRegistry([]),
            $reader,
            new ModelCatalogFilter(),
        );

        $rows = $catalog->listForWizard(static fn(string $key): string => $key);
        $anthropic = null;
        foreach ($rows as $row) {
            if ($row['id'] === 'anthropic') {
                $anthropic = $row;
                break;
            }
        }

        self::assertNotNull($anthropic);
        self::assertNotContains('claude-sonnet-4-20250514', $anthropic['modelOptions']);
        self::assertSame('claude-sonnet-4-5-20250929', $anthropic['defaultModel']);
    }

    private function emptyCatalogReader(): SymfonyAiCatalogReader
    {
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->method('read')->willReturn([]);

        return $reader;
    }

    private function fakeOpenAiAdapter(): AdapterInterface
    {
        return new class implements AdapterInterface {
            public function getType(): string
            {
                return 'symfony.openai';
            }

            public function getDisplayName(): string
            {
                return 'OpenAI';
            }

            public function getDefaultEndpoint(): string
            {
                return 'https://api.openai.com/v1';
            }

            public function getDefaultCapabilities(): array
            {
                return [Capability::CHAT];
            }

            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::ok();
            }

            public function platform(Provider $provider): object
            {
                return new \stdClass();
            }
        };
    }
}
