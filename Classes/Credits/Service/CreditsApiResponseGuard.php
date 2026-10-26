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

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;

/**
 * Rejects malformed T3Planet Credits API payloads before persistence or UI handoff.
 *
 * @internal
 */
final class CreditsApiResponseGuard
{
    public const MIN_BEARER_LENGTH = 16;

    public const MAX_BEARER_LENGTH = 512;

    /** ponytail: coarse ceiling for display/parsing; raise if catalog plans exceed this. */
    public const MAX_CREDITS_MAGNITUDE = 1_000_000_000.0;

    /** @var list<string> */
    private const CREDIT_AMOUNT_KEYS = [
        'available_credits',
        'total_remaining',
        'remaining_credits',
        'free',
        'free_credits',
        'paid',
        'paid_credits',
        'plan_used',
        'plan_credits_used',
        'plan_total',
        'plan_credits_total',
        'plan_remaining',
        'balance',
        'credits',
    ];

    /** @var list<string> */
    private const TIMESTAMP_KEYS = [
        'plan_expires_at',
        'expires_at',
        'expiresAt',
        'plan_renewed_at',
        'purchased_at',
        'crdate',
    ];

    /** @var list<string> */
    private const PLAIN_TEXT_KEYS = [
        'plan_name',
        'plan',
        'title',
        'subtitle',
        'name',
    ];

    public function assertBearerToken(#[\SensitiveParameter] mixed $token): void
    {
        if (!is_string($token)) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                502,
                'Invalid bearer token from T3Planet Credits API',
            );
        }

        $token = trim($token);
        if ($token === '') {
            throw new CreditsApiException(
                CreditsApiErrorCodes::TOKEN_MISSING,
                502,
                'Token endpoint did not return a token',
            );
        }

        $length = strlen($token);
        if ($length < self::MIN_BEARER_LENGTH || $length > self::MAX_BEARER_LENGTH) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                502,
                'Invalid bearer token from T3Planet Credits API',
            );
        }

        if (preg_match('/^[A-Za-z0-9._~-]+$/', $token) !== 1) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                502,
                'Invalid bearer token from T3Planet Credits API',
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function guardBalance(array $payload): array
    {
        if (($payload['not_modified'] ?? false) === true) {
            return $payload;
        }

        $this->assertPlainTextFields($payload);
        $this->assertTimestampFields($payload);

        if (array_key_exists('balance', $payload)) {
            $this->assertCreditAmount($payload['balance'], 'balance');
        }

        $credits = $payload['credits'] ?? null;
        if (is_array($credits)) {
            $this->assertPlainTextFields($credits);
            $this->assertTimestampFields($credits);
            $this->assertCreditBlock($credits);
        } else {
            $this->assertCreditBlock($payload);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function guardCurrentPlan(array $payload): array
    {
        if (($payload['not_modified'] ?? false) === true) {
            return $payload;
        }

        $this->assertPlainTextFields($payload);
        $this->assertTimestampFields($payload);

        if (array_key_exists('balance', $payload)) {
            $this->assertCreditAmount($payload['balance'], 'balance');
        }

        $credits = $payload['credits'] ?? null;
        if (is_array($credits)) {
            $this->assertPlainTextFields($credits);
            $this->assertTimestampFields($credits);
            $this->assertCreditBlock($credits);
        }

        return $payload;
    }

    /**
     * Validates Token / ClaimToken / RefreshToken payloads before persisting a bearer.
     *
     * @param array<string, mixed> $payload
     */
    public function guardClaimTokenResponse(array $payload): string
    {
        if (!array_key_exists('token', $payload)) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::TOKEN_MISSING,
                502,
                'Token endpoint did not return a token',
            );
        }

        $rawToken = $payload['token'];
        $this->assertBearerToken($rawToken);
        $token = trim($rawToken);

        $this->assertPlainTextFields($payload);
        $this->assertTimestampFields($payload);

        if (array_key_exists('balance', $payload)) {
            $this->assertCreditAmount($payload['balance'], 'balance');
        }

        $credits = $payload['credits'] ?? null;
        if (is_array($credits)) {
            $this->assertPlainTextFields($credits);
            $this->assertTimestampFields($credits);
            $this->assertCreditBlock($credits);
        } else {
            $this->assertCreditBlock($payload);
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function guardProducts(array $payload): array
    {
        if (($payload['not_modified'] ?? false) === true) {
            return $payload;
        }

        $products = $payload['products'] ?? null;
        if (!is_array($products)) {
            return $payload;
        }

        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                throw $this->invalidResponse('Invalid product entry at index ' . $index);
            }
            $this->assertPlainTextFields($product);
            if (array_key_exists('credits', $product)) {
                $this->assertCreditAmount($product['credits'], 'products.credits');
            }
            if (array_key_exists('price_amount', $product)) {
                $this->assertNonNegativeAmount($product['price_amount'], 'products.price_amount');
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function assertCreditBlock(array $block): void
    {
        foreach (self::CREDIT_AMOUNT_KEYS as $key) {
            if (array_key_exists($key, $block)) {
                $this->assertCreditAmount($block[$key], $key);
            }
        }

        foreach (['free_units', 'paid_units', 'plan_used_units', 'plan_total_units', 'available_credit_units', 'total_remaining_units'] as $unitsKey) {
            if (array_key_exists($unitsKey, $block)) {
                $this->assertCreditUnits($block[$unitsKey], $unitsKey);
            }
        }

        $nested = $block['credits'] ?? null;
        if (is_array($nested)) {
            $this->assertCreditBlock($nested);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertPlainTextFields(array $payload): void
    {
        foreach (self::PLAIN_TEXT_KEYS as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_scalar($value)) {
                throw $this->invalidResponse('Invalid ' . $key . ' in credits API response');
            }
            $text = (string) $value;
            if (preg_match('/[<>]/', $text) === 1) {
                throw $this->invalidResponse('Invalid ' . $key . ' in credits API response');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertTimestampFields(array $payload): void
    {
        foreach (self::TIMESTAMP_KEYS as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            if (!$this->isValidTimestampValue($payload[$key])) {
                throw $this->invalidResponse('Invalid ' . $key . ' in credits API response');
            }
        }
    }

    private function assertCreditAmount(mixed $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_numeric($value)) {
            throw $this->invalidResponse('Invalid ' . $field . ' in credits API response');
        }

        $amount = (float) $value;
        if (!is_finite($amount) || $amount < 0.0 || $amount > self::MAX_CREDITS_MAGNITUDE) {
            throw $this->invalidResponse('Invalid ' . $field . ' in credits API response');
        }
    }

    private function assertCreditUnits(mixed $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_numeric($value)) {
            throw $this->invalidResponse('Invalid ' . $field . ' in credits API response');
        }

        $units = (float) $value;
        if (!is_finite($units) || $units < 0.0 || $units > self::MAX_CREDITS_MAGNITUDE * 1000) {
            throw $this->invalidResponse('Invalid ' . $field . ' in credits API response');
        }
    }

    private function assertNonNegativeAmount(mixed $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_numeric($value)) {
            throw $this->invalidResponse('Invalid ' . $field . ' in credits API response');
        }

        $amount = (float) $value;
        if (!is_finite($amount) || $amount < 0.0) {
            throw $this->invalidResponse('Invalid ' . $field . ' in credits API response');
        }
    }

    private function isValidTimestampValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return true;
        }
        if (is_int($value)) {
            return $value >= 0;
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0.0 && (float) (int) $value === $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return true;
        }

        return false;
    }

    private function invalidResponse(string $message): CreditsApiException
    {
        return new CreditsApiException(
            CreditsApiErrorCodes::INVALID_RESPONSE,
            502,
            $message,
        );
    }
}
