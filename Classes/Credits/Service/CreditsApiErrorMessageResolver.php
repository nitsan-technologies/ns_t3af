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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;

/**
 * Maps T3Planet Credits API error codes to backend user-facing messages.
 *
 * @internal
 */
final class CreditsApiErrorMessageResolver
{
    private const LANGUAGE_FILE = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_credits.xlf:';

    public function resolve(CreditsApiException $exception): string
    {
        $message = $this->translate($exception->errorCode, $exception);
        if ($message !== '') {
            return $message;
        }

        $fallback = trim($exception->getMessage());
        if ($fallback !== '' && $fallback !== $exception->errorCode) {
            return $fallback;
        }

        return $this->translate('api_error', $exception)
            ?: 'The T3Planet Credits service returned an unexpected error. Please try again later.';
    }

    /**
     * @return array<string, mixed>
     */
    public function buildErrorPayload(CreditsApiException $exception): array
    {
        $payload = [
            'status' => false,
            'error_code' => $exception->errorCode,
            'error' => $exception->errorCode,
            'message' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception->errorCode,
            'userMessage' => $this->resolve($exception),
        ];

        if ($exception instanceof InsufficientCreditsException && $exception->topupUrl !== '') {
            $payload['topup_url'] = $exception->topupUrl;
        }

        foreach ($exception->extra as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    private function translate(string $errorCode, CreditsApiException $exception): string
    {
        $label = (string) ($GLOBALS['LANG']?->sL(self::LANGUAGE_FILE . 'credits.api.error.' . $errorCode) ?? '');
        if ($label === '' || $label === 'credits.api.error.' . $errorCode) {
            return '';
        }

        return match ($errorCode) {
            'rate_limited' => sprintf(
                $label,
                max(1, (int) ($exception->extra['retry_after'] ?? 60)),
            ),
            'insufficient_credits' => $this->appendTopupHint($label, $exception),
            default => $label,
        };
    }

    private function appendTopupHint(string $label, CreditsApiException $exception): string
    {
        if (!$exception instanceof InsufficientCreditsException || $exception->topupUrl === '') {
            return $label;
        }

        $hint = (string) ($GLOBALS['LANG']?->sL(self::LANGUAGE_FILE . 'credits.api.error.insufficient_credits.topup_hint') ?? '');
        if ($hint === '' || $hint === 'credits.api.error.insufficient_credits.topup_hint') {
            return $label . ' ' . $exception->topupUrl;
        }

        return $label . ' ' . sprintf($hint, $exception->topupUrl);
    }
}
