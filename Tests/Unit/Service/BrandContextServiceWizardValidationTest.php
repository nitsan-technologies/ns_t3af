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

use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Service\BrandContextCompletenessCalculator;
use NITSAN\NsT3AF\Service\BrandContextFeatureSettingsService;
use NITSAN\NsT3AF\Service\BrandContextService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class BrandContextServiceWizardValidationTest extends TestCase
{
    private BrandContextService $service;

    protected function setUp(): void
    {
        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $featureSettings = new BrandContextFeatureSettingsService(
            $profiles,
            $this->createMock(ExtensionSettingsService::class),
            $this->createMock(LanguageServiceFactory::class),
            ProviderTestStubs::t3AiBrandContextScopeProviders(),
        );

        $this->service = new BrandContextService(
            $profiles,
            new BrandContextCompletenessCalculator(),
            $featureSettings,
        );
    }

    #[Test]
    public function validateWizardPayloadAcceptsLighterRequiredSet(): void
    {
        self::assertNull($this->service->validateWizardPayload([
            'brandName' => 'Acme Corp',
            'industry' => 'Technology',
            'toneTags' => ['Bold', 'Professional', 'Direct'],
        ]));
    }

    #[Test]
    public function validateWizardPayloadRejectsMissingBrandName(): void
    {
        self::assertSame(
            'Brand name is required.',
            $this->service->validateWizardPayload([
                'industry' => 'Technology',
                'toneTags' => ['Bold', 'Professional', 'Direct'],
            ]),
        );
    }

    #[Test]
    public function validateWizardPayloadRejectsMissingIndustry(): void
    {
        self::assertSame(
            'Industry is required.',
            $this->service->validateWizardPayload([
                'brandName' => 'Acme Corp',
                'toneTags' => ['Bold', 'Professional', 'Direct'],
            ]),
        );
    }

    #[Test]
    public function validateWizardPayloadRejectsInvalidToneTagCount(): void
    {
        self::assertSame(
            'Pick 3–5 tone tags.',
            $this->service->validateWizardPayload([
                'brandName' => 'Acme Corp',
                'industry' => 'Technology',
                'toneTags' => ['Bold', 'Professional'],
            ]),
        );
    }
}
