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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetHttpClient;
use NITSAN\NsT3AF\Credits\Platform\T3PlanetCreditsPlatformFactory;
use Psr\Log\LoggerInterface;

/**
 * Lists T3Planet Credits model aliases (`GET/POST /API/AI/v1/models`).
 *
 * @internal
 */
class T3PlanetCreditsModelsService
{
    private const CACHE_TTL_SECONDS = 60;

    /** @var list<array{id: string, label: string, supports_tools: bool, supports_stream: bool, max_output_tokens: int}>|null */
    private ?array $cached = null;

    private int $cachedAt = 0;

    public function __construct(
        private readonly T3PlanetHttpClient $http,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<array{id: string, label: string, supports_tools: bool, supports_stream: bool, max_output_tokens: int}>
     */
    public function listModels(bool $toolsOnly = false): array
    {
        $models = $this->fetchModels();
        if (!$toolsOnly) {
            return $models;
        }

        return array_values(array_filter(
            $models,
            static fn(array $model): bool => $model['supports_tools'],
        ));
    }

    /**
     * Prefer {@see T3PlanetCreditsPlatformFactory::DEFAULT_MODEL_ALIAS} when it supports tools.
     */
    public function resolveDefaultAlias(): string
    {
        $preferred = T3PlanetCreditsPlatformFactory::DEFAULT_MODEL_ALIAS;
        foreach ($this->listModels(true) as $model) {
            if ($model['id'] === $preferred) {
                return $preferred;
            }
        }
        foreach ($this->listModels(true) as $model) {
            return $model['id'];
        }

        return $preferred;
    }

    public function isKnownAlias(string $alias): bool
    {
        $alias = trim($alias);
        if ($alias === '') {
            return false;
        }
        foreach ($this->listModels() as $model) {
            if ($model['id'] === $alias) {
                return true;
            }
        }

        return str_starts_with($alias, 't3planet/');
    }

    /**
     * @return list<array{id: string, label: string, supports_tools: bool, supports_stream: bool, max_output_tokens: int}>
     */
    private function fetchModels(): array
    {
        if ($this->cached !== null && (time() - $this->cachedAt) < self::CACHE_TTL_SECONDS) {
            return $this->cached;
        }

        try {
            $token = $this->tokenResolver->resolve();
            $domain = $this->domainResolver->resolve();
            try {
                $payload = $this->http->getJson(
                    T3PlanetCreditsPlatformFactory::MODELS_PATH,
                    $token,
                    $domain,
                );
            } catch (CreditsApiException $exception) {
                if (!$this->tokenResolver->invalidateOnUnauthorized($exception)) {
                    throw $exception;
                }
                $payload = $this->http->getJson(
                    T3PlanetCreditsPlatformFactory::MODELS_PATH,
                    $this->tokenResolver->issueFreshToken(),
                    $domain,
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('T3Planet Credits /v1/models failed; using default alias.', [
                'exception' => $exception->getMessage(),
            ]);

            return $this->fallbackModels();
        }

        $models = $this->parseModelsPayload($payload);
        if ($models === []) {
            return $this->fallbackModels();
        }

        $this->cached = $models;
        $this->cachedAt = time();

        return $models;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{id: string, label: string, supports_tools: bool, supports_stream: bool, max_output_tokens: int}>
     */
    private function parseModelsPayload(array $payload): array
    {
        $rawList = $payload['data'] ?? $payload['models'] ?? null;
        if (!is_array($rawList)) {
            return [];
        }

        $models = [];
        foreach ($rawList as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? $row['alias'] ?? ''));
            if ($id === '') {
                continue;
            }
            $label = trim((string) ($row['name'] ?? $row['label'] ?? $row['description'] ?? $id));
            $models[] = [
                'id' => $id,
                'label' => $label !== '' ? $label : $id,
                'supports_tools' => (bool) ($row['supports_tools'] ?? $row['supportsTools'] ?? true),
                'supports_stream' => (bool) ($row['supports_stream'] ?? $row['supportsStream'] ?? false),
                'max_output_tokens' => max(0, (int) ($row['max_output_tokens'] ?? $row['maxOutputTokens'] ?? 0)),
            ];
        }

        return $models;
    }

    /**
     * @return list<array{id: string, label: string, supports_tools: bool, supports_stream: bool, max_output_tokens: int}>
     */
    private function fallbackModels(): array
    {
        $id = T3PlanetCreditsPlatformFactory::DEFAULT_MODEL_ALIAS;

        return [[
            'id' => $id,
            'label' => $id,
            'supports_tools' => true,
            'supports_stream' => false,
            'max_output_tokens' => 4096,
        ]];
    }
}
