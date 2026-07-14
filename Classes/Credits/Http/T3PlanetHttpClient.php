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

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * @internal
 */
final class T3PlanetHttpClient
{
    private const TIMEOUT_SECONDS = 30;
    private const STREAM_TIMEOUT_SECONDS = 120;
    private const STREAM_READ_CHUNK_BYTES = 8192;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly RuntimeSettingsService $runtimeSettings,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postJson(string $endpoint, array $body, ?string $bearerToken = null, ?string $ifNoneMatch = null): array
    {
        return $this->decodeResponse(
            $this->sendRequest($endpoint, $body, $bearerToken, $ifNoneMatch),
        );
    }

    public function buildUrl(string $endpoint): string
    {
        $base = $this->runtimeSettings->getApiBaseUrl();
        $endpoint = trim($endpoint, '/');
        if (!str_starts_with($endpoint, 'API/')) {
            $endpoint = 'API/AI/' . $endpoint;
        }

        return $base . '/' . $endpoint;
    }

    /**
     * POST and yield raw SSE lines as they arrive (Stream.php).
     *
     * @param array<string, mixed> $body
     * @return \Generator<int, string, mixed, void>
     */
    public function stream(string $endpoint, array $body, ?string $bearerToken = null): \Generator
    {
        try {
            $response = $this->requestFactory->request(
                $this->buildUrl($endpoint),
                'POST',
                [
                    'headers' => $this->streamRequestHeaders($bearerToken),
                    'json' => $body,
                    'timeout' => self::STREAM_TIMEOUT_SECONDS,
                    'http_errors' => false,
                    'stream' => true,
                ],
            );
        } catch (\Throwable $exception) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::NETWORK_ERROR,
                0,
                $exception->getMessage(),
                [],
                $exception,
            );
        }

        $status = $response->getStatusCode();
        if ($status === 406) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                406,
                'HTTP 406 Not Acceptable from T3Planet API (check Apache MultiViews / content negotiation on /API/AI/).',
            );
        }

        if ($status < 200 || $status >= 300 || $this->isJsonContentType($response)) {
            $this->throwFromResponseBody($response, $status);
        }

        $streamBody = $response->getBody();
        $buffer = '';
        while (!$streamBody->eof()) {
            $chunk = $streamBody->read(self::STREAM_READ_CHUNK_BYTES);
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                yield $line;
            }
        }
        if ($buffer !== '') {
            yield rtrim($buffer, "\r");
        }
    }

    /**
     * @return array{status:int, body:array<string,mixed>|null, etag:?string}
     */
    public function postJsonWithStatus(string $endpoint, array $body, ?string $bearerToken = null, ?string $ifNoneMatch = null): array
    {
        $response = $this->sendRequest($endpoint, $body, $bearerToken, $ifNoneMatch);
        $status = $response->getStatusCode();
        if ($status === 304) {
            return ['status' => 304, 'body' => null, 'etag' => $this->extractEtag($response)];
        }

        return [
            'status' => $status,
            'body' => $this->decodeResponse($response),
            'etag' => $this->extractEtag($response),
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sendRequest(string $endpoint, array $body, ?string $bearerToken, ?string $ifNoneMatch): ResponseInterface
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }
        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            $headers['If-None-Match'] = $ifNoneMatch;
        }

        try {
            return $this->requestFactory->request(
                $this->buildUrl($endpoint),
                'POST',
                [
                    'headers' => $headers,
                    'json' => $body,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'http_errors' => false,
                ],
            );
        } catch (\Throwable $exception) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::NETWORK_ERROR,
                0,
                $exception->getMessage(),
                [],
                $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->getContents();
        if ($status === 406) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                406,
                'HTTP 406 Not Acceptable from T3Planet API (check Apache MultiViews / content negotiation on /API/AI/).',
            );
        }

        if ($raw === '') {
            if ($status >= 400) {
                $reason = trim($response->getReasonPhrase());
                $message = 'HTTP ' . $status;
                if ($reason !== '') {
                    $message .= ' (' . $reason . ')';
                }
                $message .= '. The T3Planet Credits API returned an empty body — check composer server logs for Charge.php / upstream AI.';

                throw new CreditsApiException(
                    $status >= 500 ? CreditsApiErrorCodes::INTERNAL_ERROR : CreditsApiErrorCodes::API_ERROR,
                    $status,
                    $message,
                );
            }

            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                $status,
                'Invalid JSON from T3Planet API',
            );
        }

        if ($status >= 400 || ($decoded['status'] ?? true) === false) {
            $this->throwDecodedApiError($status, $decoded);
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function streamRequestHeaders(?string $bearerToken): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        return $headers;
    }

    private function isJsonContentType(ResponseInterface $response): bool
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        return str_starts_with($contentType, 'application/json');
    }

    private function throwFromResponseBody(ResponseInterface $response, int $status): never
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->getContents();

        if ($raw === '') {
            if ($status >= 400) {
                $reason = trim($response->getReasonPhrase());
                $message = 'HTTP ' . $status;
                if ($reason !== '') {
                    $message .= ' (' . $reason . ')';
                }
                $message .= '. The T3Planet Credits API returned an empty body — check composer server logs for Stream.php / upstream AI.';

                throw new CreditsApiException(
                    $status >= 500 ? CreditsApiErrorCodes::INTERNAL_ERROR : CreditsApiErrorCodes::API_ERROR,
                    $status,
                    $message,
                );
            }

            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                $status,
                'Invalid JSON from T3Planet API',
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                $status,
                'Invalid JSON from T3Planet API',
            );
        }

        $this->throwDecodedApiError($status, $decoded);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function throwDecodedApiError(int $status, array $decoded): never
    {
        $errorCode = (string) ($decoded['error_code'] ?? $decoded['error'] ?? CreditsApiErrorCodes::API_ERROR);
        $message = (string) ($decoded['message'] ?? '');
        foreach (['upstream_message', 'upstream_error', 'detail'] as $detailKey) {
            $detail = trim((string) ($decoded[$detailKey] ?? ''));
            if ($detail !== '' && !str_contains($message, $detail)) {
                $message = $message !== '' && $message !== $errorCode
                    ? $message . ' — ' . $detail
                    : $detail;
            }
        }
        if ($message === '' || $message === $errorCode) {
            $message = $errorCode;
        }

        $extra = [];
        foreach (
            [
                'retry_after',
                'topup_url',
                'feature_key',
                'credits',
                'request_uuid',
                'cost',
                'cost_units',
                'credits_needed',
                'credits_needed_units',
                'pricing',
            ] as $key
        ) {
            if (array_key_exists($key, $decoded)) {
                $extra[$key] = $decoded[$key];
            }
        }

        if ($status === 402 || $errorCode === CreditsApiErrorCodes::INSUFFICIENT_CREDITS) {
            throw new InsufficientCreditsException(
                $message !== $errorCode ? $message : 'Insufficient credits',
                (string) ($decoded['topup_url'] ?? ''),
                $extra,
            );
        }

        throw new CreditsApiException($errorCode, $status, $message, $extra);
    }

    private function extractEtag(ResponseInterface $response): ?string
    {
        $etag = $response->getHeaderLine('ETag');

        return $etag !== '' ? trim($etag, '"') : null;
    }
}
