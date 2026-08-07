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
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
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

        $catalog = new WizardProviderCatalog($repo, $adapters);

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

        $catalog = new WizardProviderCatalog($repo, $adapters);

        self::assertSame(99, $catalog->ensureProviderUid('openai', '', 1));
    }

    #[Test]
    public function ensureProviderUidReturnsNullWhenStoragePidIsInvalid(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->expects(self::never())->method('findReusableWizardDraft');
        $repo->expects(self::never())->method('save');

        $catalog = new WizardProviderCatalog($repo, new AdapterRegistry([$this->fakeOpenAiAdapter()]));

        self::assertNull($catalog->ensureProviderUid('openai', '', 0));
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
