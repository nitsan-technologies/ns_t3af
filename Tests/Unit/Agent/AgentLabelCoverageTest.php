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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the agent i18n contract: nothing the editor reads may fall back to a
 * raw key, and German must stay complete.
 *
 * @internal
 */
final class AgentLabelCoverageTest extends TestCase
{
    use AgentTranslatorTrait;

    private const EXTENSION_DIR = __DIR__ . '/../../..';

    /**
     * AiOptions feature keys, not translatable labels.
     *
     * @var list<string>
     */
    private const NON_LABEL_KEYS = [
        'agent.nl_turn',
        'agent.tool_summary',
    ];

    #[Test]
    public function germanCoversEveryEnglishAgentLabel(): void
    {
        $english = array_keys($this->agentLabels('default'));
        $german = array_keys($this->agentLabels('de'));

        self::assertSame([], array_values(array_diff($english, $german)), 'agent.* keys missing in de.locallang_be.xlf');
        self::assertSame([], array_values(array_diff($german, $english)), 'agent.* keys only present in German');
    }

    #[Test]
    public function everyReferencedLabelKeyExists(): void
    {
        $english = $this->agentLabels('default');
        $german = $this->agentLabels('de');

        $referenced = $this->collectReferencedKeys();
        self::assertNotSame([], $referenced, 'No agent.* label references found — the scanner is broken.');

        $missing = array_values(array_diff($referenced, array_keys($english)));
        self::assertSame([], $missing, 'agent.* keys used in code but missing from locallang_be.xlf');

        $missingGerman = array_values(array_diff($referenced, array_keys($german)));
        self::assertSame([], $missingGerman, 'agent.* keys used in code but missing from de.locallang_be.xlf');
    }

    #[Test]
    public function dynamicFactAndSubjectKeysResolve(): void
    {
        $english = $this->agentLabels('default');

        // AgentToolResultPresenter derives these from a fact key at runtime.
        foreach (['items', 'files', 'missingAltText', 'matchingFiles', 'matchingPages', 'matchingContentElements', 'matchingRecords', 'pages', 'contentElements', 'redirects', 'scheduledTasks', 'records'] as $factKey) {
            self::assertArrayHasKey('agent.subject.' . $factKey, $english);
            self::assertArrayHasKey('agent.fact.' . $factKey, $english);
        }
    }

    /**
     * @return array<string, string>
     */
    private function agentLabels(string $languageKey): array
    {
        return array_filter(
            self::readAgentLabels($languageKey),
            static fn(string $value, string $key): bool => str_starts_with($key, 'agent.'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    private function collectReferencedKeys(): array
    {
        $files = [
            ...$this->phpFiles(self::EXTENSION_DIR . '/Classes/Agent'),
            self::EXTENSION_DIR . '/Resources/Public/JavaScript/agent.js',
        ];

        $keys = [];
        foreach ($files as $file) {
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }
            preg_match_all('/[\'"](agent\.[A-Za-z0-9_.]+)[\'"]/', $code, $matches);
            foreach ($matches[1] as $key) {
                if (str_ends_with($key, '.') || in_array($key, self::NON_LABEL_KEYS, true)) {
                    continue;
                }
                $keys[$key] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
