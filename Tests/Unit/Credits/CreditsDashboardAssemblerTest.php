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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditsCheckoutUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\CreditsDashboardAssembler;
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class CreditsDashboardAssemblerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key-' . str_repeat('x', 32);
    }

    protected function tearDown(): void
    {
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testAssembleBuildsBalanceAndFeatureBars(): void
    {
        $dashboard = $this->createAssembler()->assemble(
            ['credits' => ['free' => 12, 'paid' => 480, 'plan_used' => 100, 'plan_total' => 2000]],
            ['plan_name' => 'pro', 'plan_credits_total' => 2000, 'plan_credits_used' => 100],
            [
                'products' => [
                    [
                        'sku' => 'starter',
                        'title' => 'Starter',
                        'credits' => 250,
                        'price_amount' => 6,
                        'description' => ['Monthly credits', 'Priority support', 'All AI features'],
                        'checkout_url' => 'https://pay.example/pabbly/starter?token=set-by-server',
                        'checkout_embed_url' => 'https://payments.pabbly.com/api/checkout/embed.js?_p=test',
                        'sort_order' => 10,
                    ],
                ],
            ],
            [
                'features' => [
                    [
                        'feature_key' => 'seo_meta',
                        'label' => 'SEO meta',
                        'default_model' => 'gpt-4o',
                        'sort' => 10,
                        'example_cost' => '(100k/1M)×1 + (50k/1M)×5 ≈ $0.35',
                    ],
                    [
                        'feature_key' => 'image',
                        'label' => 'Image',
                        'default_model' => 'gpt-4o',
                        'sort' => 20,
                        'example_cost' => null,
                    ],
                ],
                'pricing' => ['model' => 'token', 'tokens_per_credit' => 1000, 'credit_unit_scale' => 1000],
            ],
            [
                [
                    'feature_key' => 'seo_meta',
                    'cost_units' => 2000,
                    'cost' => 2.0,
                    'crdate' => time(),
                    'model' => 'gpt-4o',
                    'extra' => json_encode(['tokens_total' => 450, 'tokens_input' => 300, 'tokens_output' => 150], JSON_THROW_ON_ERROR),
                ],
            ],
            [],
            'https://backend.example/return',
            [
                'seo_meta' => 5000,
                'image' => 1500,
            ],
        );

        self::assertSame(2392.0, $dashboard['balance']['remaining']);
        self::assertCount(1, $dashboard['products']);
        self::assertSame('https://pay.example/pabbly/starter?token=set-by-server', $dashboard['products'][0]['checkoutUrl']);
        self::assertSame(
            ['Monthly credits', 'Priority support', 'All AI features'],
            $dashboard['products'][0]['description'],
        );
        self::assertSame('seo_meta', $dashboard['features'][0]['key']);
        self::assertSame('gpt-4o', $dashboard['features'][0]['defaultModel']);
        self::assertSame('(100k/1M)×1 + (50k/1M)×5 ≈ $0.35', $dashboard['features'][0]['exampleCost']);
        self::assertSame('', $dashboard['features'][1]['exampleCost']);
        self::assertSame(5.0, $dashboard['features'][0]['usedCredits']);
        self::assertSame(1.5, $dashboard['features'][1]['usedCredits']);
        self::assertSame(100.0, $dashboard['features'][0]['usedBarPercent']);
        self::assertSame(30.0, $dashboard['features'][1]['usedBarPercent']);
        self::assertStringContainsString('0.001', (string) $dashboard['pricing']['footnote']);
        self::assertStringNotContainsString('450 tokens', (string) $dashboard['transactions'][0]['detail']);
        self::assertSame('gpt-4o', (string) $dashboard['transactions'][0]['detail']);
    }

    public function testNormalizeProductsDescriptionAcceptsLegacyStringAndEmptyArray(): void
    {
        $dashboard = $this->createAssembler()->assemble(
            [],
            [],
            [
                'products' => [
                    [
                        'sku' => 'legacy',
                        'title' => 'Legacy',
                        'credits' => 100,
                        'description' => 'Single line description',
                        'sort_order' => 10,
                    ],
                    [
                        'sku' => 'empty',
                        'title' => 'Empty',
                        'credits' => 50,
                        'description' => [],
                        'sort_order' => 20,
                    ],
                ],
            ],
            [],
            [],
            [],
            'https://backend.example/return',
        );

        self::assertSame(['Single line description'], $dashboard['products'][0]['description']);
        self::assertSame([], $dashboard['products'][1]['description']);
    }

    public function testSummarizeBalanceForModuleHeaderBadge(): void
    {
        $summary = $this->createAssembler()->summarizeBalance([
            'credits' => [
                'free' => 0,
                'paid' => 0,
                'plan_used' => 757,
                'plan_total' => 2000,
            ],
        ]);

        self::assertSame(1243.0, $summary['remaining']);
        self::assertSame(62, $summary['percentLeft']);
    }

    public function testNormalizeTransactionsWithoutTokensTotalKey(): void
    {
        $dashboard = $this->createAssembler()->assemble(
            [],
            [],
            [],
            [],
            [
                [
                    'feature_key' => 'legacy',
                    'cost' => 1,
                    'crdate' => time(),
                    'model' => 'gpt-4o',
                    'extra' => json_encode(['tokens_input' => 100, 'tokens_output' => 50], JSON_THROW_ON_ERROR),
                ],
            ],
            [],
            'https://backend.example/return',
        );

        self::assertSame('gpt-4o', (string) $dashboard['transactions'][0]['detail']);
        self::assertStringNotContainsString('tokens', (string) $dashboard['transactions'][0]['detail']);
    }

    public function testAssembleRecognizesActivePlanFromPlanSkuWithoutPlanName(): void
    {
        $dashboard = $this->createAssembler()->assemble(
            [
                'status' => true,
                'credits' => [
                    'free_credits' => 97,
                    'paid_credits' => 0,
                    'plan_used' => 0,
                    'plan_total' => 2000,
                    'plan_sku' => 'starter',
                ],
            ],
            [
                'status' => true,
                'plan_sku' => 'starter',
                'plan_active' => true,
                'plan_used' => 0,
                'plan_total' => 2000,
                'plan_renewed_at' => 1779367493,
            ],
            ['products' => []],
            [],
            [],
            [],
            'https://backend.example/return',
        );

        self::assertSame(1, $dashboard['plan']['hasPlan']);
        self::assertSame('Starter', $dashboard['plan']['name']);
        self::assertSame('starter', $dashboard['plan']['sku']);
        self::assertSame(2000.0, $dashboard['plan']['creditsTotal']);
        self::assertSame('starter', $dashboard['currentPlanSku']);
    }

    public function testAssembleRecognizesTrialCreditsAsActivePlan(): void
    {
        $dashboard = $this->createAssembler()->assemble(
            [
                'status' => true,
                'credits' => [
                    'free_credits' => 97,
                    'paid_credits' => 0,
                    'plan_used' => 0,
                    'plan_total' => 0,
                    'plan_sku' => 'none',
                ],
            ],
            [
                'status' => true,
                'plan_name' => 'none',
                'plan_sku' => 'none',
                'plan_active' => false,
                'trial_granted' => true,
            ],
            [
                'products' => [
                    [
                        'sku' => 'trial',
                        'type' => 'trial',
                        'title' => 'Free Trial',
                        'credits' => 100,
                        'is_active' => 1,
                    ],
                ],
            ],
            [],
            [],
            [],
            'https://backend.example/return',
        );

        self::assertSame(1, $dashboard['plan']['hasPlan']);
        self::assertSame(1, $dashboard['plan']['isTrial']);
        self::assertSame('Free Trial', $dashboard['plan']['name']);
        self::assertSame('trial', $dashboard['plan']['sku']);
        self::assertSame(100.0, $dashboard['plan']['creditsTotal']);
        self::assertSame(97.0, $dashboard['plan']['creditsRemaining']);
        self::assertSame(3.0, $dashboard['plan']['creditsUsed']);
    }

    public function testAssembleRecognizesTrialFromFreeCreditsWhenTrialGrantedMissing(): void
    {
        $dashboard = $this->createAssembler()->assemble(
            [
                'status' => true,
                'credits' => [
                    'free_credits' => 100,
                    'paid_credits' => 0,
                    'plan_used' => 0,
                    'plan_total' => 0,
                    'plan_sku' => 'none',
                ],
            ],
            [
                'status' => true,
                'plan_name' => 'none',
                'plan_sku' => 'none',
                'plan_active' => false,
            ],
            [
                'products' => [
                    [
                        'sku' => 'trial',
                        'type' => 'trial',
                        'title' => 'Free Trial',
                        'credits' => 100,
                        'is_active' => 1,
                    ],
                ],
            ],
            [],
            [],
            [],
            'https://backend.example/return',
        );

        self::assertSame(1, $dashboard['plan']['hasPlan']);
        self::assertSame(1, $dashboard['plan']['isTrial']);
    }

    public function testSummarizeBalanceReadsFreeCreditsFromBalanceApi(): void
    {
        $summary = $this->createAssembler()->summarizeBalance([
            'status' => true,
            'credits' => [
                'status' => true,
                'free_credits' => 100,
                'paid_credits' => 0,
                'plan_used' => 0,
                'plan_total' => 0,
                'plan_sku' => 'none',
            ],
        ]);

        self::assertSame(100.0, $summary['remaining']);
        self::assertSame(100, $summary['percentLeft']);
        self::assertSame(100.0, $summary['free']);
    }

    public function testSummarizeBalanceUsesTrialCatalogTotalAfterPartialUsage(): void
    {
        $summary = $this->createAssembler()->summarizeBalance(
            [
                'status' => true,
                'credits' => [
                    'free_credits' => 99.61,
                    'paid_credits' => 0,
                    'plan_used' => 0,
                    'plan_total' => 0,
                    'plan_sku' => 'none',
                ],
            ],
            [
                'trial_granted' => true,
            ],
            [
                'products' => [
                    [
                        'sku' => 'trial',
                        'type' => 'trial',
                        'title' => 'Free Trial',
                        'credits' => 100,
                    ],
                ],
            ],
        );

        self::assertSame(99.61, $summary['remaining']);
        self::assertSame(100.0, $summary['total']);
        self::assertSame(100, $summary['percentLeft']);
    }

    private function createAssembler(): CreditsDashboardAssembler
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'license_keys' => 'KEY-1,KEY-2',
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new ExtensionConfiguration(),
        );
        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $domainResolver = new CreditsDomainResolver($siteFinder, $runtime);
        $returnUrlBuilder = new CreditsReturnUrlBuilder(
            $this->createMock(\TYPO3\CMS\Backend\Routing\UriBuilder::class),
            $domainResolver,
        );

        return new CreditsDashboardAssembler(
            new CreditsCheckoutUrlBuilder($runtime, $domainResolver, $returnUrlBuilder),
        );
    }
}
