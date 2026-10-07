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

use NITSAN\NsT3AF\Agent\Service\AgentGovernanceGuard;
use NITSAN\NsT3AF\Agent\Service\AgentProviderOptions;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditsModelsService;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Model\ModelCatalogFilter;
use NITSAN\NsT3AF\Provider\Model\SymfonyAiCatalogReader;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use NITSAN\NsT3AF\Tests\Unit\Credits\CreditsModeResolverFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * With two active providers and none marked default, "Default" has to stand for a real provider:
 * the selector shows its name and the first message runs with it (ticket 14zervyu1nf).
 *
 * @internal
 */
final class AgentProviderOptionsTest extends TestCase
{
    use AgentTranslatorTrait;
    use CreditsModeResolverFixtureTrait;

    private const PAGE_ID = 5;
    private const STORAGE_PID = 1;

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function defaultOptionNamesTheFallbackProviderWhenNoDefaultIsSet(): void
    {
        $repository = $this->repository(null, [
            $this->provider('mistral', 'Mistral Large'),
            $this->provider('openai', 'OpenAI GPT'),
        ]);

        $options = $this->subject($repository)->options(self::PAGE_ID, null);

        self::assertSame('default', $options[0]['value']);
        self::assertStringStartsWith('Default: Mistral Large', $options[0]['label']);
        self::assertSame(['default', 'mistral', 'openai'], array_column($options, 'value'));
    }

    #[Test]
    public function defaultOptionStaysGenericWhenNoProviderIsUsable(): void
    {
        $options = $this->subject($this->repository(null, []))->options(self::PAGE_ID, null);

        self::assertSame([['value' => 'default', 'label' => 'Default provider']], $options);
    }

    #[Test]
    public function defaultIsResolvedToTheFirstUsableProviderWhenNoDefaultExists(): void
    {
        $repository = $this->repository(null, [
            $this->provider('disabled', 'Disabled', isEnabled: false),
            $this->provider('mistral', 'Mistral Large'),
            $this->provider('openai', 'OpenAI GPT'),
        ]);
        $subject = $this->subject($repository);

        self::assertSame('mistral', $subject->resolveIdentifier('default', self::PAGE_ID, null));
        self::assertSame('mistral', $subject->resolveIdentifier('', self::PAGE_ID, null));
    }

    #[Test]
    public function explicitProviderIsNeverReplaced(): void
    {
        $repository = $this->repository(null, [$this->provider('mistral', 'Mistral Large')]);

        self::assertSame('openai', $this->subject($repository)->resolveIdentifier('openai', self::PAGE_ID, null));
    }

    #[Test]
    public function usableDefaultProviderKeepsTheDefaultChoice(): void
    {
        $default = $this->provider('mistral', 'Mistral Large', isDefault: true);
        $repository = $this->repository($default, [$default, $this->provider('openai', 'OpenAI GPT')]);

        self::assertSame('default', $this->subject($repository)->resolveIdentifier('default', self::PAGE_ID, null));
    }

    #[Test]
    public function defaultStaysUnchangedWhenNothingIsUsable(): void
    {
        $repository = $this->repository(null, [$this->provider('disabled', 'Disabled', isEnabled: false)]);

        self::assertSame('default', $this->subject($repository)->resolveIdentifier('default', self::PAGE_ID, null));
    }

    #[Test]
    public function creditsModePassesTheChoiceThrough(): void
    {
        $repository = $this->repository(null, [$this->provider('mistral', 'Mistral Large')]);

        self::assertSame('default', $this->subject($repository, true)->resolveIdentifier('default', self::PAGE_ID, null));
    }

    /**
     * @param list<Provider> $all
     * @return ProviderRepositoryInterface&MockObject
     */
    private function repository(?Provider $default, array $all): ProviderRepositoryInterface
    {
        $repository = $this->createMock(ProviderRepositoryInterface::class);
        $repository->method('findDefault')->willReturn($default);
        $repository->method('findAllByStoragePid')->willReturn($all);

        return $repository;
    }

    private function subject(ProviderRepositoryInterface $repository, bool $creditsMode = false): AgentProviderOptions
    {
        $site = $this->createMock(Site::class);
        $site->method('getRootPageId')->willReturn(self::STORAGE_PID);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        $toolCalling = $this->createMock(AiToolCallingServiceInterface::class);
        $toolCalling->method('supportsToolCalling')->willReturn(true);

        $providerCatalog = new WizardProviderCatalog(
            $repository,
            new AdapterRegistry([]),
            $this->createMock(SymfonyAiCatalogReader::class),
            (new \ReflectionClass(ModelCatalogFilter::class))->newInstanceWithoutConstructor(),
        );

        return new AgentProviderOptions(
            $repository,
            new SiteStorageContext($siteFinder),
            $toolCalling,
            $this->creditModeResolver($creditsMode),
            $providerCatalog,
            // Only consulted for non-admin users; these tests run without a user.
            (new \ReflectionClass(AgentGovernanceGuard::class))->newInstanceWithoutConstructor(),
            $this->createAgentTranslator(),
            $this->createMock(T3PlanetCreditsModelsService::class),
        );
    }

    private function provider(string $identifier, string $title, bool $isDefault = false, bool $isEnabled = true): Provider
    {
        return new Provider(
            uid: 1,
            pid: self::STORAGE_PID,
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
            isDefault: $isDefault,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
            isEnabled: $isEnabled,
        );
    }
}
