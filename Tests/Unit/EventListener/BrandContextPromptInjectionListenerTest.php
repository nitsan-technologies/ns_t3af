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

namespace NITSAN\NsT3AF\Tests\Unit\EventListener;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\EventListener\BrandContextPromptInjectionListener;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextPlaceholderService;
use NITSAN\NsT3AF\Service\BrandContextProfileOverrideReaderInterface;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class BrandContextPromptInjectionListenerTest extends TestCase
{
    public function testInjectsPlaceholdersAndPrependsBrandBlockToSystemPrompt(): void
    {
        $profile = $this->makeProfile();
        $listener = $this->makeListener($profile, storagePid: 10, pageId: 42);

        $provider = $this->makeProvider(systemPrompt: 'You are helpful.');
        $event = new BeforeProviderRequestEvent(
            $provider,
            'Generate copy for {brand_name}.',
            new AiOptions(pageId: 42),
            'complete',
        );

        $listener($event);

        self::assertSame('Generate copy for NITSAN Technologies.', $event->getPrompt());
        self::assertStringContainsString('=== BRAND CONTEXT ===', (string) $event->getOptions()->systemPrompt);
        self::assertStringContainsString('You are helpful.', (string) $event->getOptions()->systemPrompt);
    }

    public function testInjectsIntoChatMessagesAndAddsBrandSystemMessage(): void
    {
        $profile = $this->makeProfile();
        $listener = $this->makeListener($profile, storagePid: 10, pageId: 42);

        $options = new AiOptions(
            pageId: 42,
            extra: [
                'messages' => [
                    ['role' => 'user', 'content' => 'Write an og title for {brand_name}.'],
                ],
            ],
        );
        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(systemPrompt: 'You are helpful.'),
            'Write an og title for {brand_name}.',
            $options,
            'complete',
        );

        $listener($event);

        $json = json_encode($event->getOptions()->extra['messages'] ?? null);
        self::assertIsString($json);
        // A brand-context system message is prepended.
        self::assertStringContainsString('"role":"system"', $json);
        self::assertStringContainsString('=== BRAND CONTEXT ===', $json);
        // The original user message has its tokens substituted.
        self::assertStringContainsString('Write an og title for NITSAN Technologies.', $json);
        self::assertStringNotContainsString('{brand_name}', $json);
    }

    public function testMergesBrandBlockIntoExistingSystemMessage(): void
    {
        $profile = $this->makeProfile();
        $listener = $this->makeListener($profile, storagePid: 10, pageId: 42);

        $options = new AiOptions(
            pageId: 42,
            extra: [
                'messages' => [
                    ['role' => 'system', 'content' => 'Existing system instructions.'],
                    ['role' => 'user', 'content' => 'Title for {brand_name}.'],
                ],
            ],
        );
        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(),
            'Title for {brand_name}.',
            $options,
            'complete',
        );

        $listener($event);

        $json = json_encode($event->getOptions()->extra['messages'] ?? null);
        self::assertIsString($json);
        self::assertStringContainsString('=== BRAND CONTEXT ===', $json);
        self::assertStringContainsString('Existing system instructions.', $json);
        self::assertStringContainsString('Title for NITSAN Technologies.', $json);
        self::assertStringNotContainsString('{brand_name}', $json);
    }

    public function testBrandProfileTokenExpandsInlineAndSuppressesSystemPromptBlock(): void
    {
        $profile = $this->makeProfile();
        $listener = $this->makeListener($profile, storagePid: 10, pageId: 42);

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(systemPrompt: 'You are helpful.'),
            'Intro. [brand_profile] Write a title.',
            new AiOptions(pageId: 42),
            'complete',
        );

        $listener($event);

        // The token is expanded inline into the prompt.
        self::assertStringContainsString('=== BRAND CONTEXT ===', $event->getPrompt());
        self::assertStringNotContainsString('[brand_profile]', $event->getPrompt());
        // The separate system-block injection is suppressed (no duplication).
        self::assertNull($event->getOptions()->systemPrompt);
    }

    public function testBrandProfileTokenInMessagesSuppressesBrandSystemMessage(): void
    {
        $profile = $this->makeProfile();
        $listener = $this->makeListener($profile, storagePid: 10, pageId: 42);

        $options = new AiOptions(
            pageId: 42,
            extra: [
                'messages' => [
                    ['role' => 'user', 'content' => 'Title. [brand_profile] Done.'],
                ],
            ],
        );
        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(systemPrompt: 'You are helpful.'),
            'Title. [brand_profile] Done.',
            $options,
            'complete',
        );

        $listener($event);

        $json = json_encode($event->getOptions()->extra['messages'] ?? null);
        self::assertIsString($json);
        // Brand block expanded inline in the user message.
        self::assertStringContainsString('=== BRAND CONTEXT ===', $json);
        self::assertStringNotContainsString('[brand_profile]', $json);
        // No additional brand system message is injected.
        self::assertStringNotContainsString('"role":"system"', $json);
    }

    public function testSkipsWhenSkipBrandContextExtraFlagIsSet(): void
    {
        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->expects(self::never())->method('findDefault');

        $listener = new BrandContextPromptInjectionListener(
            new BrandContextResolver(
                $profiles,
                new SiteStorageContext($this->createMock(SiteFinder::class)),
                $this->makeFeatureSettings(),
            ),
            new BrandContextPlaceholderService(),
            new BrandContextAssembler(new BrandContextPlaceholderService()),
        );

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(),
            'Hello {brand_name}',
            new AiOptions(pageId: 42, extra: ['skipBrandContext' => true]),
            'complete',
        );

        $listener($event);

        self::assertSame('Hello {brand_name}', $event->getPrompt());
        self::assertNull($event->getOptions()->systemPrompt);
    }

    public function testDoesNotPrependSystemPromptForEmbedCalls(): void
    {
        $listener = $this->makeListener($this->makeProfile(), storagePid: 10, pageId: 42);

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(systemPrompt: 'You are helpful.'),
            '{brand_name}',
            new AiOptions(pageId: 42),
            'embed',
        );

        $listener($event);

        self::assertSame('NITSAN Technologies', $event->getPrompt());
        self::assertNull($event->getOptions()->systemPrompt);
    }

    private function makeListener(BrandContextProfile $profile, int $storagePid, int $pageId): BrandContextPromptInjectionListener
    {
        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->method('findDefault')->with($storagePid)->willReturn($profile);

        $site = $this->createMock(Site::class);
        $site->method('getRootPageId')->willReturn($storagePid);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->with($pageId)->willReturn($site);

        return new BrandContextPromptInjectionListener(
            new BrandContextResolver($profiles, new SiteStorageContext($siteFinder), $this->makeFeatureSettings()),
            new BrandContextPlaceholderService(),
            new BrandContextAssembler(new BrandContextPlaceholderService()),
        );
    }

    private function makeFeatureSettings(int $overrideUid = 0): BrandContextProfileOverrideReaderInterface
    {
        $service = $this->createMock(BrandContextProfileOverrideReaderInterface::class);
        $service->method('resolveProfileUid')->willReturn($overrideUid);

        return $service;
    }

    private function makeProfile(): BrandContextProfile
    {
        return BrandContextProfile::fromRow([
            'uid' => 1,
            'pid' => 10,
            'brand_name' => 'NITSAN Technologies',
            'industry' => 'Technology',
            'website_url' => '',
            'tagline' => 'Enterprise TYPO3 agency',
            'description' => '',
            'tone_tags' => '["Professional"]',
            'voice_notes' => '',
            'personas' => '[{"name":"CTO","level":"Expert"}]',
            'content_rules' => '',
            'forbidden_words' => '',
            'keywords' => '',
            'competitors' => '',
            'language_code' => 'en',
            'sample_content' => '',
            'compliance_notes' => '',
            'document_extract' => '',
            'is_default' => 1,
            'completeness' => 50,
            'crdate' => 0,
            'tstamp' => 0,
        ]);
    }

    private function makeProvider(string $systemPrompt = ''): Provider
    {
        return Provider::fromRow([
            'uid' => 1,
            'pid' => 10,
            'identifier' => 'openai',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'endpoint_url' => '',
            'model_id' => 'gpt-4o-mini',
            'embedding_model_id' => '',
            'system_prompt' => $systemPrompt,
            'temperature' => 0.7,
            'priority' => 0,
            'is_enabled' => 1,
            'is_default' => 1,
            'capabilities' => '',
            'cost_center' => '',
            'be_groups' => '',
            'privacy_level' => 'standard',
            'no_rerouting' => 0,
            'enabled_for_dashboard' => 1,
            'last_used_at' => 0,
        ]);
    }
}
