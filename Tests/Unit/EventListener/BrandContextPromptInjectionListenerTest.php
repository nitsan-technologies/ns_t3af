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

    public function testEmbedNeverGainsBrandSystemBlockInPromptOrMessages(): void
    {
        // CTX-10: embeddings get inline token substitution only — never the
        // assembled system block, which would pollute the embedding input.
        $listener = $this->makeListener($this->makeProfile(), storagePid: 10, pageId: 42);

        $options = new AiOptions(
            pageId: 42,
            extra: [
                'messages' => [
                    ['role' => 'user', 'content' => 'Embed this about {brand_name}.'],
                ],
            ],
        );
        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(systemPrompt: 'You are helpful.'),
            'Embed this about {brand_name}.',
            $options,
            'embed',
        );

        $listener($event);

        // Plain prompt still gets inline token substitution...
        self::assertStringContainsString('NITSAN Technologies', $event->getPrompt());
        // ...but no brand system block is added anywhere for embed.
        self::assertNull($event->getOptions()->systemPrompt);
        self::assertStringNotContainsString('=== BRAND CONTEXT ===', $event->getPrompt());

        $json = json_encode($event->getOptions()->extra['messages'] ?? null);
        self::assertIsString($json);
        self::assertStringNotContainsString('=== BRAND CONTEXT ===', $json);
        self::assertStringNotContainsString('"role":"system"', $json);
    }

    public function testCallerSystemPromptGetsBrandBlockAndTokenResolution(): void
    {
        $listener = $this->makeListener($this->makeProfile(), storagePid: 10, pageId: 42);

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(systemPrompt: 'Provider default.'),
            'Write a title.',
            new AiOptions(systemPrompt: 'You are X for {brand_name}.', pageId: 42),
            'complete',
        );

        $listener($event);

        $systemPrompt = (string) $event->getOptions()->systemPrompt;
        self::assertStringContainsString('=== BRAND CONTEXT ===', $systemPrompt);
        self::assertStringContainsString('You are X for NITSAN Technologies.', $systemPrompt);
        self::assertStringNotContainsString('{brand_name}', $systemPrompt);
        self::assertSame(1, $event->getOptions()->extra['brandContextProfileUid'] ?? null);
    }

    public function testStampsBrandContextProfileUidOnOptionsForLineage(): void
    {
        $listener = $this->makeListener($this->makeProfile(uid: 7), storagePid: 10, pageId: 42);

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(),
            'Hello',
            new AiOptions(pageId: 42),
            'complete',
        );

        $listener($event);

        self::assertSame(7, $event->getOptions()->extra['brandContextProfileUid'] ?? null);
    }

    public function testStampsBrandContextProfileUidForEmbedCalls(): void
    {
        $listener = $this->makeListener($this->makeProfile(uid: 9), storagePid: 10, pageId: 42);

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(),
            '{brand_name}',
            new AiOptions(pageId: 42),
            'embed',
        );

        $listener($event);

        self::assertSame(9, $event->getOptions()->extra['brandContextProfileUid'] ?? null);
        self::assertSame('NITSAN Technologies', $event->getPrompt());
    }

    public function testEmptyStringSystemPromptBehavesLikeNull(): void
    {
        $listener = $this->makeListener($this->makeProfile(), storagePid: 10, pageId: 42);

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(systemPrompt: 'Provider default.'),
            'Write a title.',
            new AiOptions(systemPrompt: '', pageId: 42),
            'complete',
        );

        $listener($event);

        $systemPrompt = (string) $event->getOptions()->systemPrompt;
        self::assertStringContainsString('=== BRAND CONTEXT ===', $systemPrompt);
        self::assertStringContainsString('Provider default.', $systemPrompt);
    }

    public function testStripsBrandTokensWhenNoProfileResolves(): void
    {
        $listener = $this->makeListener(null, storagePid: 10, pageId: 42);

        $options = new AiOptions(
            systemPrompt: 'System for {brand_name}.',
            pageId: 42,
            extra: [
                'messages' => [
                    ['role' => 'user', 'content' => 'Title for {brand_name} [brand_profile].'],
                ],
            ],
        );
        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(),
            'Hi {brand_name} [brand_profile] {brand_context}',
            $options,
            'complete',
        );

        $listener($event);

        self::assertStringNotContainsString('{brand_name}', $event->getPrompt());
        self::assertStringNotContainsString('[brand_profile]', $event->getPrompt());
        self::assertStringNotContainsString('{brand_context}', $event->getPrompt());

        $json = json_encode($event->getOptions()->extra['messages'] ?? null);
        self::assertIsString($json);
        self::assertStringNotContainsString('{brand_name}', $json);
        self::assertStringNotContainsString('[brand_profile]', $json);

        $systemPrompt = (string) $event->getOptions()->systemPrompt;
        self::assertStringNotContainsString('{brand_name}', $systemPrompt);
    }

    private function makeListener(?BrandContextProfile $profile, int $storagePid, int $pageId): BrandContextPromptInjectionListener
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

    private function makeProfile(int $uid = 1): BrandContextProfile
    {
        return BrandContextProfile::fromRow([
            'uid' => $uid,
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
