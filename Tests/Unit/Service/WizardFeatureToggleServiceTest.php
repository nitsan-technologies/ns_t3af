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

use NITSAN\NsT3AF\Contract\AiFeatureCardDescriptor;
use NITSAN\NsT3AF\Contract\AiFeatureCardProviderInterface;
use NITSAN\NsT3AF\Registry\AiFeatureCardProviderRegistry;
use NITSAN\NsT3AF\Service\WizardFeatureToggleService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsDynamicDefaultsRegistry;
use PHPUnit\Framework\TestCase;

final class WizardFeatureToggleServiceTest extends TestCase
{
    public function testExpandTogglesAppliesMasterValueToChildFields(): void
    {
        $service = $this->createService([
            new AiFeatureCardDescriptor(
                id: 'ai-seo',
                name: 'AI SEO',
                subtitle: 'SEO',
                extKey: 'ns_t3ai',
                settingsScope: 'seo',
                icon: 'actions-document-info',
                iconBg: 'bg',
                iconColor: 'color',
                tags: ['seo'],
                wizardEligible: true,
                wizardToggleField: 'seoFeature',
                wizardToggleChildFields: ['enableAllInOneSeo', 'enableNewSEO'],
            ),
        ]);

        $expanded = $service->expandTogglesForExtension('ns_t3ai', ['seoFeature' => false]);

        self::assertSame('0', $expanded['seoFeature']);
        self::assertSame('0', $expanded['enableAllInOneSeo']);
        self::assertSame('0', $expanded['enableNewSEO']);
    }

    public function testResolveMasterToggleDefaultUsesChildFieldState(): void
    {
        $service = $this->createService([
            new AiFeatureCardDescriptor(
                id: 'aa-ai-audio',
                name: 'AI Audio',
                subtitle: 'Audio',
                extKey: 'ns_t3aa',
                settingsScope: 'ai audio',
                icon: 'actions-music',
                iconBg: 'bg',
                iconColor: 'color',
                tags: ['audio'],
                wizardEligible: true,
                wizardToggleField: 'enableAiAudio',
                wizardToggleChildFields: ['enableAiAudio', 'enableElvenlabAiVoiceover', 'enableOpenAiVoiceover'],
            ),
        ]);

        $enabled = $service->resolveMasterToggleDefault(
            'ns_t3aa',
            'enableAiAudio',
            ['enableAiAudio', 'enableElvenlabAiVoiceover', 'enableOpenAiVoiceover'],
            ['enableElvenlabAiVoiceover' => '1'],
            [],
        );

        self::assertTrue($enabled);
    }

    public function testNormalizeToggleEnabledTreatsStringZeroAsDisabled(): void
    {
        self::assertFalse(WizardFeatureToggleService::normalizeToggleEnabled('0'));
        self::assertFalse(WizardFeatureToggleService::normalizeToggleEnabled('false'));
        self::assertFalse(WizardFeatureToggleService::normalizeToggleEnabled(0));
        self::assertFalse(WizardFeatureToggleService::normalizeToggleEnabled(false));
        self::assertTrue(WizardFeatureToggleService::normalizeToggleEnabled('1'));
        self::assertTrue(WizardFeatureToggleService::normalizeToggleEnabled(true));
    }

    public function testIsPersistableFieldAllowsExpandedChildFields(): void
    {
        $service = $this->createService([
            new AiFeatureCardDescriptor(
                id: 'ai-content',
                name: 'AI Content',
                subtitle: 'Content',
                extKey: 'ns_t3ai',
                settingsScope: 'content',
                icon: 'actions-message',
                iconBg: 'bg',
                iconColor: 'color',
                tags: ['content'],
                wizardEligible: true,
                wizardToggleField: 'contentFeature',
                wizardToggleChildFields: ['enableContentRewriter'],
            ),
        ]);

        self::assertTrue($service->isPersistableField('ns_t3ai', 'enableContentRewriter', [], []));
    }

    /**
     * @param list<AiFeatureCardDescriptor> $cards
     */
    private function createService(array $cards): WizardFeatureToggleService
    {
        $provider = new class ($cards) implements AiFeatureCardProviderInterface {
            /**
             * @param list<AiFeatureCardDescriptor> $cards
             */
            public function __construct(private readonly array $cards) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function getExtensionKey(): string
            {
                return $this->cards[0]->extKey ?? 'ns_demo';
            }

            public function getFeatureCards(): array
            {
                return $this->cards;
            }
        };

        return new WizardFeatureToggleService(
            new AiFeatureCardProviderRegistry([$provider]),
            new ExtensionSettingsDynamicDefaultsRegistry([]),
        );
    }
}
