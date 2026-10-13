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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;

/**
 * Enforces provider capability flags at AI call sites.
 *
 * Empty {@see Provider::$capabilities} stays permissive (legacy rows). When the
 * list is non-empty, the call kind (and optional vision payload) must match.
 *
 * @internal
 */
final class ProviderCapabilityGuard
{
    public const CALL_COMPLETE = 'complete';
    public const CALL_STREAM = 'stream';
    public const CALL_EMBED = 'embed';
    public const CALL_TTS = 'tts';
    public const CALL_IMAGE_GENERATION = 'image_generation';

    /**
     * Whether this provider may be offered for text generation (SEO, pages, content, LLM translate).
     */
    public static function allowsChat(Provider $provider): bool
    {
        if ($provider->capabilities === []) {
            return true;
        }

        return $provider->hasCapability(Capability::CHAT)
            || $provider->hasCapability(Capability::COMPLETION);
    }

    /**
     * @throws AdapterRuntimeException
     */
    public static function assertCallAllowed(
        Provider $provider,
        string $callKind,
        AiOptions $options = new AiOptions(),
    ): void {
        $strictEvenWhenEmpty = $callKind === self::CALL_TTS
            || $callKind === self::CALL_IMAGE_GENERATION;

        if ($provider->capabilities === [] && !$strictEvenWhenEmpty) {
            return;
        }

        match ($callKind) {
            self::CALL_COMPLETE => self::assertAny(
                $provider,
                [Capability::CHAT, Capability::COMPLETION],
            ),
            self::CALL_STREAM => self::assertHas($provider, Capability::STREAMING),
            self::CALL_EMBED => self::assertHas($provider, Capability::EMBEDDINGS),
            self::CALL_TTS => self::assertHas($provider, Capability::TTS),
            self::CALL_IMAGE_GENERATION => self::assertHas($provider, Capability::IMAGE_GENERATION),
            default => null,
        };

        if ($provider->capabilities === []) {
            return;
        }

        if (
            ($callKind === self::CALL_COMPLETE || $callKind === self::CALL_STREAM)
            && self::optionsRequireVision($options)
        ) {
            self::assertHas($provider, Capability::VISION);
        }
    }

    /**
     * @param list<string> $allowed
     */
    private static function assertAny(Provider $provider, array $allowed): void
    {
        foreach ($allowed as $capability) {
            if ($provider->hasCapability($capability)) {
                return;
            }
        }

        $quoted = array_map(
            static fn(string $capability): string => '"' . $capability . '"',
            $allowed,
        );
        $capabilityList = count($quoted) === 1
            ? $quoted[0]
            : implode(' or ', $quoted);

        throw new AdapterRuntimeException(sprintf(
            'Provider "%s" does not have the %s capability enabled. Please enable at least one under AI Foundation → AI Providers.',
            $provider->identifier,
            $capabilityList,
        ));
    }

    private static function assertHas(Provider $provider, string $capability): void
    {
        if ($provider->hasCapability($capability)) {
            return;
        }

        self::throwMissing($provider, $capability);
    }

    private static function throwMissing(Provider $provider, string $capability): never
    {
        throw new AdapterRuntimeException(sprintf(
            'Provider "%s" does not have the "%s" capability enabled. Please enable it under AI Foundation → AI Providers.',
            $provider->identifier,
            $capability,
        ));
    }

    private static function optionsRequireVision(AiOptions $options): bool
    {
        $messages = $options->extra['messages'] ?? null;
        if (!is_array($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                if ($type === 'image_url' || $type === 'input_image') {
                    return true;
                }
                if (isset($block['image_url']) || isset($block['input_image'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
