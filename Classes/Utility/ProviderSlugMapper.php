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

namespace NITSAN\NsT3AF\Utility;

use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Maps provider adapter types to legacy engine slugs used by child extensions
 * (ns_t3cs defaultModel, AiEngineConfiguration, …).
 */
final class ProviderSlugMapper
{
    /**
     * @var array<string, string>
     */
    private const ADAPTER_TO_SLUG = [
        'symfony.openai' => 'openai',
        Provider::ADAPTER_OPENAI_COMPATIBLE => 'customllm',
        'symfony.anthropic' => 'claude',
        'symfony.gemini' => 'gemini',
        'symfony.mistral' => 'mistral',
        Provider::ADAPTER_SYMFONY_OLLAMA => 'ollama',
        'symfony.huggingface' => 'huggingface',
        'custom.deepseek' => 'deepseek',
        'custom.xai' => 'xai',
        'custom.azure' => 'azure',
        'custom.llm' => 'customllm',
    ];

    /**
     * @var list<string>
     */
    private const T3CS_COMPATIBLE_SLUGS = [
        'openai',
        'claude',
        'gemini',
        'mistral',
        'customllm',
        'ollama',
        'huggingface',
    ];

    public static function slugFromAdapterType(string $adapterType): string
    {
        $normalized = Provider::normalizeAdapterType($adapterType);

        return self::ADAPTER_TO_SLUG[$normalized] ?? 'customllm';
    }

    public static function slugFromProvider(Provider $provider): string
    {
        return self::slugFromAdapterType($provider->adapterType);
    }

    public static function isT3CsCompatible(string $slug): bool
    {
        return in_array($slug, self::T3CS_COMPATIBLE_SLUGS, true);
    }

    /**
     * @return list<string>
     */
    public static function getT3CsCompatibleSlugs(): array
    {
        return self::T3CS_COMPATIBLE_SLUGS;
    }
}
