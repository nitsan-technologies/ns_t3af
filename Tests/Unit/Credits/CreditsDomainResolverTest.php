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
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

final class CreditsDomainResolverTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousServer = null;

    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousServer = $_SERVER;
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key-' . str_repeat('x', 32);
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->previousServer ?? [];
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testResolveUsesHttpHostInWebContext(): void
    {
        $_SERVER['HTTP_HOST'] = 'Aiuniverse.DDEV.site:443';

        $resolver = $this->createResolver();

        self::assertSame('aiuniverse.ddev.site', $resolver->resolve());
    }

    public function testResolveUsesStoredCreditsDomainWhenHttpHostMissing(): void
    {
        $resolver = $this->createResolver(row: ['credits_domain' => 'shop.example.com']);

        self::assertSame('shop.example.com', $resolver->resolve());
    }

    public function testResolveUsesAbsoluteSiteBaseWhenCliHasNoHttpHost(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('cli-from-site.example');

        $site = new class ($uri) {
            public function __construct(private UriInterface $uri) {}

            public function getBase(): UriInterface
            {
                return $this->uri;
            }
        };

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        $resolver = $this->createResolver(siteFinder: $siteFinder);

        self::assertSame('cli-from-site.example', $resolver->resolve());
    }

    public function testResolveUsesRequestUriHostWhenProvided(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('from-request.example');
        $request->method('getUri')->willReturn($uri);

        $resolver = $this->createResolver();

        self::assertSame('from-request.example', $resolver->resolve($request));
    }

    public function testResolvePersistsHttpHostForLaterCliUse(): void
    {
        $_SERVER['HTTP_HOST'] = 'persist.example';

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'credit_mode' => 1,
            'license_keys' => '',
            'credits_domain' => '',
        ]);
        $repository->expects(self::once())->method('updateSingleton')->with(
            self::equalTo(['credits_domain' => 'persist.example']),
        );

        $resolver = $this->createResolver(repository: $repository);

        self::assertSame('persist.example', $resolver->resolve());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createResolver(
        array $row = [],
        ?SiteFinder $siteFinder = null,
        ?RuntimeSettingsRepository $repository = null,
    ): CreditsDomainResolver {
        if ($repository === null) {
            $repository = $this->createMock(RuntimeSettingsRepository::class);
            $repository->method('findSingleton')->willReturn([
                'credit_mode' => 1,
                'license_keys' => '',
                'credits_domain' => '',
                ...$row,
            ]);
        }

        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            $this->createMock(ExtensionConfiguration::class),
        );

        if ($siteFinder === null) {
            $siteFinder = $this->createMock(SiteFinder::class);
            $siteFinder->method('getAllSites')->willReturn([]);
        }

        return new CreditsDomainResolver($siteFinder, $runtime);
    }
}
