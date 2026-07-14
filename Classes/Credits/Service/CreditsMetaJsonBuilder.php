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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;

/**
 * Builds `meta_json` for T3Planet Charge / Embed / Estimate (incl. caller attribution).
 *
 * @api Child extensions may use {@see withAttribution()} when calling {@see CreditsEstimateService}
 *      with a partial meta array.
 */
final class CreditsMetaJsonBuilder
{
    /**
     * @param list<string> $inputs Embed inputs only.
     * @return array<string, mixed>
     */
    public static function build(string $prompt, AiOptions $options, array $inputs = []): array
    {
        $meta = ['prompt' => self::resolvePrompt($prompt, $options)];
        $forEmbed = $inputs !== [];

        if (self::shouldIncludeProviderIdentifier($options->providerIdentifier)) {
            $meta['provider_identifier'] = $options->providerIdentifier;
        }
        // Embedding APIs do not accept chat completion parameters; omit for Embed.php.
        if (!$forEmbed) {
            if ($options->temperature !== null) {
                $meta['temperature'] = $options->temperature;
            }
            if ($options->maxTokens !== null) {
                $meta['max_tokens'] = $options->maxTokens;
            }
            if ($options->systemPrompt !== null && $options->systemPrompt !== '') {
                $meta['system_prompt'] = $options->systemPrompt;
            }
        }
        if ($inputs !== []) {
            $meta['inputs'] = $inputs;
        }
        if ($options->extra !== []) {
            $meta = array_merge($meta, $options->extra);
        }

        return self::sanitizeComposerMeta(self::withAttribution($meta, $options));
    }

    /**
     * Composer Charge/Stream route upstream from {@see ns_ai_feature_cost}; the client credits id must not be sent.
     */
    private static function shouldIncludeProviderIdentifier(?string $providerIdentifier): bool
    {
        $identifier = trim((string) $providerIdentifier);

        return $identifier !== ''
            && $identifier !== CreditsProviderIdentifier::IDENTIFIER;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function sanitizeComposerMeta(array $meta): array
    {
        if (($meta['provider_identifier'] ?? '') === CreditsProviderIdentifier::IDENTIFIER) {
            unset($meta['provider_identifier']);
        }

        return $meta;
    }

    /**
     * Merges caller attribution from {@see AiOptions} (AiOptions wins over existing keys).
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function withAttribution(array $meta, AiOptions $options): array
    {
        $extensionKey = trim((string) ($options->extensionKey ?? ''));
        if ($extensionKey !== '') {
            $meta['extension_key'] = $extensionKey;
        }

        $featureLabel = trim((string) ($options->featureLabel ?? ''));
        if ($featureLabel !== '') {
            $meta['feature_label'] = $featureLabel;
        }

        $requestSource = trim((string) ($options->requestSource ?? ''));
        if ($requestSource !== '') {
            $meta['request_source'] = $requestSource;
        }

        $entityType = trim((string) ($options->contentEntityType ?? ''));
        if ($entityType !== '') {
            $meta['content_entity_type'] = $entityType;
        }

        if ($options->contentEntityUid !== null && $options->contentEntityUid > 0) {
            $meta['content_entity_uid'] = $options->contentEntityUid;
        }

        return $meta;
    }

    /**
     * Caller extension for top-level Charge/Embed/Estimate body (mirrors meta_json when set).
     */
    public static function callerExtensionKey(AiOptions $options): string
    {
        return trim((string) ($options->extensionKey ?? ''));
    }

    /**
     * Credits Charge/Stream require a non-empty prompt; callers may pass chat turns only in {@see AiOptions::$extra}.
     */
    public static function resolvePrompt(string $prompt, AiOptions $options): string
    {
        $trimmed = trim($prompt);
        if ($trimmed !== '') {
            return $trimmed;
        }

        return self::promptFromMessages($options->extra['messages'] ?? null);
    }

    /**
     * @param mixed $messages
     */
    private static function promptFromMessages(mixed $messages): string
    {
        if (!is_array($messages) || $messages === []) {
            return '';
        }

        $parts = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text') {
                        $text = trim((string) ($block['text'] ?? ''));
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                }
                continue;
            }
            if (is_string($content)) {
                $text = trim($content);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
