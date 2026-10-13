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

namespace NITSAN\NsT3AF\Agent\Service;

/**
 * Turns provider failures (OpenAI, Anthropic, Gemini, Ollama, credits API, …) into one
 * editor-facing sentence. Full detail stays in AI Usage / logs.
 *
 * Account/billing/auth failures are generic for non-admin editors; admins see a masked vendor hint.
 *
 * @internal
 */
final class AgentProviderTurnErrorPresenter
{
    private const FALLBACK_DETAIL = 'The AI provider returned an error. Please try again.';

    private const DETAIL_MAX_LENGTH = 320;

    public function present(\Throwable $exception, AgentTranslator $translator, bool $isAdmin): string
    {
        $detail = self::maskSensitiveProviderText(self::readableMessageFromThrowable($exception));
        $configuration = self::configurationMessage($detail, $translator);
        if ($configuration !== null) {
            return $configuration;
        }

        if (self::looksLikeProviderAccountProblem($exception)) {
            if (!$isAdmin) {
                return $translator->translate('agent.turn.providerUnavailable');
            }

            return $translator->translate(
                'agent.turn.orchestratorFailed',
                [$detail !== '' && $detail !== self::FALLBACK_DETAIL ? $detail : $translator->translate('agent.turn.providerUnavailable')],
            );
        }

        if (self::looksLikeRateLimit($exception, $detail)) {
            $rateDetail = $detail !== '' && $detail !== self::FALLBACK_DETAIL
                ? $detail
                : $translator->translate('agent.turn.providerRateLimitedFallback');

            return $translator->translate('agent.turn.providerRateLimited', [$rateDetail]);
        }

        if ($detail === '' || $detail === self::FALLBACK_DETAIL) {
            return $translator->translate('agent.turn.providerUnavailable');
        }

        return $translator->translate('agent.turn.orchestratorFailed', [$detail]);
    }

    /**
     * Billing, quota, auth and similar account problems at the vendor (any adapter).
     */
    public static function looksLikeProviderAccountProblem(\Throwable $exception): bool
    {
        $detail = self::readableMessageFromThrowable($exception);
        if (self::messageLooksLikeProviderAccountProblem($detail)) {
            return true;
        }

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (self::messageLooksLikeProviderAccountProblem($current->getMessage())) {
                return true;
            }
        }

        return false;
    }

    public static function looksLikeContextOverflow(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (self::looksLikeContextOverflowMessage($current->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @internal
     */
    public static function readableMessageFromThrowable(\Throwable $exception): string
    {
        $chain = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $chain[] = $current;
        }

        // Prefer the innermost exception: HTTP clients and adapters often wrap the vendor JSON.
        foreach (array_reverse($chain) as $current) {
            $message = trim($current->getMessage());
            if ($message === '') {
                continue;
            }
            $readable = self::readableProviderError($message);
            if ($readable === '' || $readable === self::FALLBACK_DETAIL || self::isGenericWrapperMessage($readable)) {
                continue;
            }

            return $readable;
        }

        $top = trim($exception->getMessage());

        return $top !== '' ? self::readableProviderError($top) : self::FALLBACK_DETAIL;
    }

    /**
     * @internal
     */
    public static function maskSensitiveProviderText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $masked = (string) preg_replace('/\bsk-proj-[a-zA-Z0-9_-]{4,}[a-zA-Z0-9_-]*/u', 'sk-proj-…', $text);
        $masked = (string) preg_replace('/\bsk-ant-[a-zA-Z0-9_-]{4,}[a-zA-Z0-9_-]*/u', 'sk-ant-…', $masked);
        $masked = (string) preg_replace('/\bsk-[a-zA-Z0-9_-]{8,}[a-zA-Z0-9_-]*/u', 'sk-…', $masked);
        $masked = (string) preg_replace('/\bAIza[0-9A-Za-z_-]{20,}\b/u', 'AIza…', $masked);
        $masked = (string) preg_replace('/\bxox[baprs]-[0-9A-Za-z-]{10,}\b/u', 'xox…', $masked);
        $masked = (string) preg_replace('/\bBearer\s+\S+/iu', 'Bearer …', $masked);
        $masked = (string) preg_replace(
            '/(?<=\b)(?:api[_-]?key|apikey|key)\s*=\s*[^\s&"\'<>]+/iu',
            'key=…',
            $masked,
        );
        // Prefix-less vendor keys (e.g. Mistral): mask long tokens that look like secrets (letters + digits).
        $masked = (string) preg_replace_callback(
            '/\b[A-Za-z0-9]{32,}\b/u',
            static function (array $matches): string {
                $token = $matches[0];
                if (preg_match('/[A-Za-z]/', $token) !== 1 || preg_match('/\d/', $token) !== 1) {
                    return $token;
                }

                return '…';
            },
            $masked,
        );

        return $masked;
    }

    private static function isGenericWrapperMessage(string $readable): bool
    {
        if (preg_match('/^complete_with_tools failed$/i', $readable) === 1) {
            return true;
        }

        return preg_match(
            '/^Provider (?:rejected request payload|authentication\/authorization failed|endpoint or model route not found|request timed out|temporary server error|runtime error)\./i',
            $readable,
        ) === 1 && !str_contains($readable, '{');
    }

    /**
     * @internal
     */
    public static function readableProviderError(string $raw): string
    {
        $raw = trim($raw);
        $start = strpos($raw, '{');
        if ($start !== false) {
            $end = strrpos($raw, '}');
            $json = $end !== false && $end > $start ? substr($raw, $start, $end - $start + 1) : '';
            $fromJson = $json !== '' ? self::extractErrorMessageFromPayload($json) : '';
            if ($fromJson !== '') {
                $raw = $fromJson;
            } else {
                $decoded = $end !== false && $end > $start ? json_decode($json, true) : null;
                $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? null) : null;
                $raw = is_string($message) && trim($message) !== '' ? trim($message) : trim(substr($raw, 0, $start));
            }
        }
        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw), ' :-');
        if ($raw === '') {
            return self::FALLBACK_DETAIL;
        }

        return mb_strlen($raw) > self::DETAIL_MAX_LENGTH ? rtrim(mb_substr($raw, 0, self::DETAIL_MAX_LENGTH)) . '…' : $raw;
    }

    private static function configurationMessage(string $detail, AgentTranslator $translator): ?string
    {
        if (preg_match('/^AI provider with identifier "[^"]+" not found\.$/', $detail) === 1) {
            return $translator->translate('agent.turn.providerNotFoundForSite');
        }
        if ($detail === 'No default AI provider is configured.') {
            return $translator->translate('agent.turn.providerNoDefaultForSite');
        }

        return null;
    }

    private static function looksLikeRateLimit(\Throwable $exception, string $detail): bool
    {
        if (self::hasHttpStatus429($exception)) {
            return true;
        }

        if (preg_match('/rate[\s_-]?limit|too many requests|requests per minute/i', $detail) === 1) {
            return true;
        }

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/rate[\s_-]?limit|too many requests|requests per minute/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function hasHttpStatus429(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current->getCode() === 429) {
                return true;
            }
            if (preg_match('/HTTP\/\d(?:\.\d)?\s+429\b/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function messageLooksLikeProviderAccountProblem(string $raw): bool
    {
        return preg_match(
            '/no credits remaining|credit balance|billing|insufficient[_ ]quota|exceeded your (?:current )?quota|quota exceeded|plans? (?:&|and) billing|invalid[_ ]api[_ ]key|incorrect api key|authentication[_ ]error|invalid x-api-key|payment required|unauthorized|authorisation failed|authorization failed/i',
            $raw,
        ) === 1;
    }

    private static function looksLikeContextOverflowMessage(string $raw): bool
    {
        return preg_match('/context[_ ]length|maximum context|context window|too many tokens|prompt is too long|reduce the length/i', $raw) === 1;
    }

    private static function extractErrorMessageFromPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return trim($payload);
        }

        $paths = [
            ['error', 'message'],
            ['message'],
            ['detail'],
            ['error_description'],
            ['error'],
            ['errors', 0, 'message'],
        ];
        foreach ($paths as $path) {
            $value = $decoded;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
