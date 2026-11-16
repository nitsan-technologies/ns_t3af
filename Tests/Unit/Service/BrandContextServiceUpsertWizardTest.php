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

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Service\BrandContextCompletenessCalculator;
use NITSAN\NsT3AF\Service\BrandContextFeatureSettingsService;
use NITSAN\NsT3AF\Service\BrandContextService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class BrandContextServiceUpsertWizardTest extends TestCase
{
    #[Test]
    public function upsertUpdatesExistingDefaultWithoutCreate(): void
    {
        $existing = BrandContextProfile::fromRow([
            'uid' => 12,
            'pid' => 5,
            'brand_name' => 'Acme',
            'industry' => 'Technology',
            'tone_tags' => json_encode(['Bold', 'Professional', 'Direct'], JSON_THROW_ON_ERROR),
            'voice_notes' => 'Clear',
            'is_default' => 1,
            'is_enabled' => 1,
        ]);

        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->method('findDefault')->with(5)->willReturn($existing);
        $profiles->method('belongsToStorage')->with(12, 5)->willReturn(true);
        $profiles->expects(self::atLeastOnce())
            ->method('save')
            ->willReturnCallback(static function (int $uid, array $values): int {
                self::assertSame(12, $uid);
                if (isset($values['brand_name'])) {
                    self::assertSame('Acme Renamed', $values['brand_name']);
                    self::assertSame('Healthcare', $values['industry']);
                    self::assertArrayHasKey('tone_tags', $values);
                    self::assertArrayHasKey('voice_notes', $values);
                    self::assertArrayNotHasKey('personas', $values);
                    self::assertArrayNotHasKey('keywords', $values);
                }

                return 12;
            });
        $profiles->method('findByUid')->with(12)->willReturn($existing);

        $service = $this->buildService($profiles);
        $uid = $service->upsertWizardProfile(5, [
            'uid' => 12,
            'brandName' => 'Acme Renamed',
            'industry' => 'Healthcare',
            'toneTags' => ['Bold', 'Professional', 'Friendly'],
            'voiceDescription' => 'Warm',
        ]);

        self::assertSame(12, $uid);
    }

    #[Test]
    public function upsertCreatesWhenNoProfileExists(): void
    {
        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->method('findDefault')->with(5)->willReturn(null);
        $created = BrandContextProfile::fromRow([
            'uid' => 99,
            'pid' => 5,
            'brand_name' => 'NewCo',
            'industry' => 'Technology',
            'tone_tags' => json_encode(['Bold', 'Professional', 'Direct'], JSON_THROW_ON_ERROR),
            'is_default' => 1,
            'is_enabled' => 1,
        ]);
        $profiles->expects(self::atLeastOnce())
            ->method('save')
            ->willReturnCallback(static function (int $uid, array $values): int {
                if ($uid === 0) {
                    self::assertSame('NewCo', $values['brand_name'] ?? null);

                    return 99;
                }

                return $uid;
            });
        $profiles->method('countByStoragePid')->with(5)->willReturn(0);
        $profiles->expects(self::once())->method('setDefault')->with(99, 5);
        $profiles->method('findByUid')->with(99)->willReturn($created);

        $service = $this->buildService($profiles);
        $uid = $service->upsertWizardProfile(5, [
            'brandName' => 'NewCo',
            'industry' => 'Technology',
            'toneTags' => ['Bold', 'Professional', 'Direct'],
        ]);

        self::assertSame(99, $uid);
    }

    private function buildService(BrandContextProfileRepositoryInterface $profiles): BrandContextService
    {
        $featureSettings = new BrandContextFeatureSettingsService(
            $profiles,
            $this->createMock(ExtensionSettingsService::class),
            $this->createMock(LanguageServiceFactory::class),
            ProviderTestStubs::t3AiBrandContextScopeProviders(),
        );

        return new BrandContextService(
            $profiles,
            new BrandContextCompletenessCalculator(),
            $featureSettings,
        );
    }
}
