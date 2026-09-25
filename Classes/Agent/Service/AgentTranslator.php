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

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Single translation entry point for every agent service.
 *
 * Replaces the per-service private translate() helpers so the agent speaks the
 * backend user's language from one place.
 *
 * @internal
 */
final readonly class AgentTranslator
{
    private const LANGUAGE_FILE = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * Labels use sprintf placeholders (%1$s / %2$s).
     *
     * @param list<int|string> $arguments
     */
    public function translate(string $key, array $arguments = []): string
    {
        $label = self::LANGUAGE_FILE . $key;
        $languageService = $this->resolveLanguageService();
        $value = $languageService instanceof LanguageService ? (string) $languageService->sL($label) : '';

        if ($value === '' || $value === $label) {
            $value = $key;
        }

        if ($arguments === []) {
            return $value;
        }

        return sprintf($value, ...array_map(static fn(int|string $argument): string => (string) $argument, $arguments));
    }

    /**
     * CLI and MCP entry points do not always have $GLOBALS['LANG'] set up.
     */
    private function resolveLanguageService(): ?LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            return $languageService;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            return null;
        }

        try {
            return $this->languageServiceFactory->createFromUserPreferences($backendUser);
        } catch (\Throwable) {
            return null;
        }
    }
}
