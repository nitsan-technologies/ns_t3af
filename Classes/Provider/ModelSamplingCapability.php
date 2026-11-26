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

namespace NITSAN\NsT3AF\Provider;

/**
 * OpenAI reasoning models (o-series, GPT-5, …) reject chat sampling parameters such as temperature.
 * Strip them from invoke options / request bodies when the resolved model does not accept them.
 */
final class ModelSamplingCapability
{
    /** @var list<string> */
    public const SAMPLING_PARAMETER_KEYS = [
        'temperature',
        'top_p',
        'frequency_penalty',
        'presence_penalty',
    ];

    public static function supportsTemperature(string $modelId): bool
    {
        return !self::isReasoningModel($modelId);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function filterInvokeOptions(string $modelId, array $options): array
    {
        if (self::supportsTemperature($modelId)) {
            return $options;
        }

        foreach (self::SAMPLING_PARAMETER_KEYS as $key) {
            unset($options[$key]);
        }

        return $options;
    }

    public static function isUnsupportedSamplingParameterError(\Throwable $throwable): bool
    {
        $current = $throwable;
        for ($depth = 0; $depth < 5 && $current instanceof \Throwable; $depth++) {
            $message = strtolower($current->getMessage());
            if (
                str_contains($message, 'not supported')
                && (
                    str_contains($message, 'temperature')
                    || str_contains($message, 'top_p')
                    || str_contains($message, 'frequency_penalty')
                    || str_contains($message, 'presence_penalty')
                )
            ) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function shouldRetryWithoutSampling(\Throwable $throwable, array $options): bool
    {
        if (!self::isUnsupportedSamplingParameterError($throwable)) {
            return false;
        }

        foreach (self::SAMPLING_PARAMETER_KEYS as $key) {
            if (array_key_exists($key, $options)) {
                return true;
            }
        }

        return false;
    }

    public static function isReasoningModel(string $model): bool
    {
        $normalized = strtolower(trim($model));
        if ($normalized === '') {
            return false;
        }

        foreach (['o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini', 'gpt-5'] as $prefix) {
            if ($normalized === $prefix
                || str_starts_with($normalized, $prefix . '-')
                || str_starts_with($normalized, $prefix . '.')
            ) {
                return true;
            }
        }

        return str_contains($normalized, 'gpt-5');
    }
}
