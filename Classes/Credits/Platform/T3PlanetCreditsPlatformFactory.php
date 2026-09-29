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

namespace NITSAN\NsT3AF\Credits\Platform;

use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a {@see symfony/ai-generic-platform} client pointed at T3Planet Credits v1 chat.
 *
 * @internal
 */
class T3PlanetCreditsPlatformFactory
{
    public const DEFAULT_MODEL_ALIAS = 't3planet/agent-standard';
    public const COMPLETIONS_PATH = '/v1/chat/completions';
    public const MODELS_PATH = 'v1/models';

    public function __construct(
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsDomainResolver $domainResolver,
    ) {}

    /**
     * @param non-empty-string|null $bearerToken Skip TokenResolver when already resolved (retry paths).
     */
    public function create(#[\SensitiveParameter] ?string $bearerToken = null): PlatformInterface
    {
        $token = $bearerToken ?? $this->tokenResolver->resolve();
        if ($token === '') {
            $token = $this->tokenResolver->issueFreshToken();
        }

        return Factory::createPlatform(
            baseUrl: $this->apiRoot(),
            apiKey: $token,
            httpClient: $this->httpClient($this->domainResolver->resolve()),
            supportsEmbeddings: false,
            completionsPath: self::COMPLETIONS_PATH,
            name: 't3planet-credits',
        );
    }

    public function apiRoot(): string
    {
        return rtrim($this->runtimeSettings->getApiBaseUrl(), '/') . '/API/AI';
    }

    public function domain(): string
    {
        return $this->domainResolver->resolve();
    }

    public function httpClient(string $domain): HttpClientInterface
    {
        $headers = [];
        $trimmed = trim($domain);
        if ($trimmed !== '') {
            $headers['X-T3P-Domain'] = $trimmed;
        }

        return HttpClient::create($headers !== [] ? ['headers' => $headers] : []);
    }
}
