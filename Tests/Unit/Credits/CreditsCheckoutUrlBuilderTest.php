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
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

final class CreditsCheckoutUrlBuilderTest extends TestCase
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

    public function testReplacesCfRedirecttoWithAbsoluteReturnUrl(): void
    {
        $cipher = new CredentialCipher();
        $enc = $cipher->encrypt('bearer-pool-token-abc');

        $builder = $this->createBuilder([
            'license_keys' => 'T3PCRED-TEST-LIC-0001',
            'token_enc' => $enc,
        ]);

        $returnUrl = 'https://aiuniverse.ddev.site/typo3/module/t3af/dashboard/providers';
        $url = $builder->normalize(
            'https://t3planet.shop/subscribe/plan/starter?cf_credittoken_9hhpcq=OLD&cf_redirectto_dw4dki=%2Ftypo3%2Fmodule%2Fproviders%3Ftoken%3Dabc',
            $returnUrl,
        );

        self::assertStringContainsString(
            'cf_redirectto_dw4dki=' . rawurlencode($returnUrl),
            $url,
        );
        self::assertStringNotContainsString('token%3D', $url);
    }

    public function testReplacesCfCredittokenQueryParamWithBearerToken(): void
    {
        $cipher = new CredentialCipher();
        $enc = $cipher->encrypt('bearer-pool-token-abc');

        $builder = $this->createBuilder([
            'license_keys' => 'T3PCRED-TEST-LIC-0001',
            'token_enc' => $enc,
        ]);

        $url = $builder->normalize(
            'https://t3planet.shop/subscribe/plan/starter?cf_credittoken_9hhpcq=T3PCRED-TEST-LIC-0001&cf_redirectto_dw4dki=%2Freturn',
            'https://backend.example/return',
        );

        self::assertStringContainsString('cf_credittoken_9hhpcq=bearer-pool-token-abc', $url);
        self::assertStringNotContainsString('T3PCRED-TEST-LIC-0001', $url);
    }

    public function testSubstitutesTokenPlaceholderInLegacyTemplate(): void
    {
        $builder = $this->createBuilder([
            'license_keys' => 'KEY-1',
        ], tokenPlain: 'legacy-token-xyz');

        $url = $builder->normalize(
            'https://pay.example/checkout?cf_credittoken_x={token}&domain={domain}',
            'https://backend.example/return',
        );

        self::assertStringContainsString('cf_credittoken_x=legacy-token-xyz', $url);
    }

    public function testLeavesUrlUnchangedWhenNoTokenConfigured(): void
    {
        $builder = $this->createBuilder(['license_keys' => 'KEY-ONLY']);

        $input = 'https://t3planet.shop/subscribe/starter?cf_credittoken_9hhpcq=KEY-ONLY';
        self::assertSame($input, $builder->normalize($input, 'https://backend.example/return'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createBuilder(array $row, ?string $tokenPlain = null): CreditsCheckoutUrlBuilder
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        if ($tokenPlain !== null) {
            $extensionConfiguration->method('get')->willReturnCallback(
                static function (string $extensionKey, ?string $path = null) use ($tokenPlain): string {
                    if ($extensionKey === 'ns_t3af' && $path === 't3planetApiToken') {
                        return $tokenPlain;
                    }

                    return '';
                },
            );
        } else {
            $extensionConfiguration->method('get')->willReturn('');
        }

        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            $extensionConfiguration,
        );

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $domainResolver = new CreditsDomainResolver($siteFinder, $runtime);
        $returnUrlBuilder = new CreditsReturnUrlBuilder(
            $this->createMock(\TYPO3\CMS\Backend\Routing\UriBuilder::class),
            $domainResolver,
        );

        return new CreditsCheckoutUrlBuilder($runtime, $domainResolver, $returnUrlBuilder);
    }
}
