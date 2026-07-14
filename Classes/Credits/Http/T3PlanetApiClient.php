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

namespace NITSAN\NsT3AF\Credits\Http;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\Service\CreditsMetaJsonBuilder;

/**
 * Thin wrapper for v1 T3Planet Credits endpoints.
 *
 * @internal
 */
class T3PlanetApiClient
{
    public function __construct(
        private readonly T3PlanetHttpClient $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function issueToken(string $licenseKeys, string $domain): array
    {
        return $this->http->postJson('Token', [
            'license_keys' => $licenseKeys,
            'domain' => $domain,
        ]);
    }

    /**
     * Registers additional licence keys on an existing Bearer pool (backend-only).
     *
     * @return array<string, mixed>
     */
    public function attachLicenses(string $domain, string $licenseKeys, string $bearerToken): array
    {
        return $this->http->postJson('AttachLicenses', [
            'domain' => $domain,
            'license_keys' => $licenseKeys,
        ], $bearerToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(string $domain, string $bearerToken, ?string $ifNoneMatch = null): array
    {
        $result = $this->http->postJsonWithStatus('Balance', ['domain' => $domain], $bearerToken, $ifNoneMatch);
        if ($result['status'] === 304) {
            return ['not_modified' => true];
        }

        return $result['body'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function currentPlan(string $domain, string $bearerToken, ?string $ifNoneMatch = null): array
    {
        $result = $this->http->postJsonWithStatus('CurrentPlan', ['domain' => $domain], $bearerToken, $ifNoneMatch);
        if ($result['status'] === 304) {
            return ['not_modified' => true];
        }

        return $result['body'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function features(string $domain, string $bearerToken, ?string $ifNoneMatch = null): array
    {
        $result = $this->http->postJsonWithStatus('Features', ['domain' => $domain], $bearerToken, $ifNoneMatch);
        if ($result['status'] === 304) {
            return ['not_modified' => true];
        }

        return $result['body'] ?? [];
    }

    /**
     * @return array{body: array<string, mixed>, etag: ?string}
     */
    public function products(
        string $domain,
        string $bearerToken,
        string $redirectTo,
        ?string $ifNoneMatch = null,
    ): array {
        $body = ['domain' => $domain];
        if ($redirectTo !== '') {
            $body['redirect_to'] = $redirectTo;
            $body['backend_url'] = $redirectTo;
        }

        $result = $this->http->postJsonWithStatus('Products', $body, $bearerToken, $ifNoneMatch);
        if ($result['status'] === 304) {
            return ['body' => ['not_modified' => true], 'etag' => $result['etag']];
        }

        return ['body' => $result['body'] ?? [], 'etag' => $result['etag']];
    }

    /**
     * @param array<string, mixed> $metaJson
     * @param 'charge'|'embed'     $endpoint
     *
     * @return array<string, mixed>
     */
    public function estimate(
        string $domain,
        string $featureKey,
        array $metaJson,
        string $bearerToken,
        string $endpoint = 'charge',
    ): array {
        return $this->http->postJson('Estimate', $this->withCallerAttribution([
            'domain' => $domain,
            'feature_key' => $featureKey,
            'meta_json' => $metaJson,
            'endpoint' => $endpoint === 'embed' ? 'embed' : 'charge',
        ], $metaJson), $bearerToken);
    }

    /**
     * @param array<string, mixed> $metaJson
     * @return array<string, mixed>
     */
    public function charge(
        string $domain,
        string $requestUuid,
        string $featureKey,
        array $metaJson,
        string $bearerToken,
        ?AiOptions $options = null,
    ): array {
        return $this->http->postJson('Charge', $this->withCallerAttribution([
            'domain' => $domain,
            'request_uuid' => $requestUuid,
            'feature_key' => $featureKey,
            'meta_json' => $metaJson,
        ], $metaJson, $options), $bearerToken);
    }

    /**
     * @param array<string, mixed> $metaJson
     * @return array<string, mixed>
     */
    public function embed(
        string $domain,
        string $requestUuid,
        string $featureKey,
        array $metaJson,
        string $bearerToken,
        ?AiOptions $options = null,
    ): array {
        return $this->http->postJson('Embed', $this->withCallerAttribution([
            'domain' => $domain,
            'request_uuid' => $requestUuid,
            'feature_key' => $featureKey !== '' ? $featureKey : 'embedding',
            'meta_json' => $metaJson,
        ], $metaJson, $options), $bearerToken);
    }

    /**
     * Text-to-speech synthesis via Speak.php. Returns a JSON envelope carrying
     * base64-encoded audio plus the standard credits/charged fields.
     *
     * @param array<string, mixed> $metaJson
     * @return array<string, mixed>
     */
    public function speak(
        string $domain,
        string $requestUuid,
        string $featureKey,
        array $metaJson,
        string $bearerToken,
        ?string $extensionKey = null,
    ): array {
        $body = [
            'domain' => $domain,
            'request_uuid' => $requestUuid,
            'feature_key' => $featureKey !== '' ? $featureKey : 'text_to_speech',
            'meta_json' => $metaJson,
        ];
        $extensionKey = trim((string) ($extensionKey ?? ($metaJson['extension_key'] ?? '')));
        if ($extensionKey !== '') {
            $body['extension_key'] = $extensionKey;
        }

        return $this->http->postJson('Speak', $body, $bearerToken);
    }

    /**
     * Duplicates caller `extension_key` at top level when present (server may persist it later).
     *
     * @param array<string, mixed>      $body
     * @param array<string, mixed>      $metaJson
     */
    private function withCallerAttribution(array $body, array $metaJson, ?AiOptions $options = null): array
    {
        $extensionKey = '';
        if ($options !== null) {
            $extensionKey = CreditsMetaJsonBuilder::callerExtensionKey($options);
        }
        if ($extensionKey === '') {
            $extensionKey = trim((string) ($metaJson['extension_key'] ?? ''));
        }
        if ($extensionKey !== '') {
            $body['extension_key'] = $extensionKey;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $metaJson
     * @return \Generator<int, string, mixed, void>
     */
    public function stream(
        string $domain,
        string $requestUuid,
        string $featureKey,
        array $metaJson,
        string $bearerToken,
        ?AiOptions $options = null,
    ): \Generator {
        return $this->http->stream('Stream', $this->withCallerAttribution([
            'domain' => $domain,
            'request_uuid' => $requestUuid,
            'feature_key' => $featureKey,
            'meta_json' => $metaJson,
        ], $metaJson, $options), $bearerToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function abort(string $domain, string $requestUuid, string $bearerToken): array
    {
        return $this->http->postJson('Abort', [
            'domain' => $domain,
            'request_uuid' => $requestUuid,
        ], $bearerToken);
    }
}
