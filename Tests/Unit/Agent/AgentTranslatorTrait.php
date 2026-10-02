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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Builds an AgentTranslator backed by the real XLF files, so assertions run
 * against shipped labels instead of raw keys.
 *
 * @internal
 */
trait AgentTranslatorTrait
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language';

    protected function createAgentTranslator(string $languageKey = 'default'): AgentTranslator
    {
        $labels = self::readAgentLabels($languageKey);

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static function (string $input) use ($labels): string {
                $position = strrpos($input, ':');
                $key = $position === false ? $input : substr($input, $position + 1);

                return $labels[$key] ?? '';
            },
        );
        $GLOBALS['LANG'] = $languageService;

        return new AgentTranslator($this->createMock(LanguageServiceFactory::class));
    }

    protected function releaseAgentTranslator(): void
    {
        unset($GLOBALS['LANG']);
    }

    /**
     * @return array<string, string>
     */
    protected static function readAgentLabels(string $languageKey = 'default'): array
    {
        $file = self::LANGUAGE_DIR . '/' . ($languageKey === 'default' ? '' : $languageKey . '.') . 'locallang_be.xlf';
        $xml = simplexml_load_file($file);
        if ($xml === false) {
            throw new \RuntimeException('Cannot parse ' . $file);
        }

        $labels = [];
        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $id = (string) $unit['id'];
            $target = isset($unit->target) ? trim((string) $unit->target) : '';
            $labels[$id] = $target !== '' ? $target : (string) $unit->source;
        }

        return $labels;
    }
}
