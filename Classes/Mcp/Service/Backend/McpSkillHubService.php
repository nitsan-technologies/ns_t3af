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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

use NITSAN\NsT3AF\Contract\McpSkillHubEntryProviderInterface;

/**
 * Skill Hub: curated community catalog + installed skill import/storage.
 */
readonly class McpSkillHubService
{
    /**
     * @param iterable<McpSkillHubEntryProviderInterface> $entryProviders
     */
    public function __construct(
        private McpSkillRepository $skillRepository,
        private iterable $entryProviders = [],
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function getCommunityCatalog(): array
    {
        $catalog = $this->buildCatalog();

        $rows = [];
        foreach ($catalog as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $keywords = [(string) ($entry['triggerKeyword'] ?? '')];
            $legacy = $entry['legacyTriggerKeywords'] ?? [];
            if (is_array($legacy)) {
                $keywords = array_merge($keywords, array_map('strval', $legacy));
            }

            $installed = false;
            foreach ($keywords as $keyword) {
                if ($this->isInstalled($keyword)) {
                    $installed = true;
                    break;
                }
            }

            $rows[] = array_merge($entry, [
                'id' => $id,
                'installed' => $installed,
            ]);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getInstalledSkills(): array
    {
        return array_map(function (array $row): array {
            $tags = array_values(array_filter(array_map('trim', explode(',', $row['tags']))));

            return array_merge($row, ['tagList' => $tags]);
        }, $this->skillRepository->findAll());
    }

    /**
     * @param list<string> $tags
     */
    public function importSkill(
        string $name,
        string $triggerKeyword,
        string $body,
        string $source = 'import',
        string $sourceUrl = '',
        string $version = '1.0.0',
        array $tags = [],
    ): int {
        $existing = $this->skillRepository->findByTriggerKeyword($triggerKeyword);
        if ($existing !== null) {
            $this->skillRepository->update(
                $existing['uid'],
                $name,
                $triggerKeyword,
                $version,
                $source,
                $sourceUrl,
                $body,
                implode(',', $tags),
            );

            return $existing['uid'];
        }

        return $this->skillRepository->insert(
            $name,
            $triggerKeyword,
            $version,
            $source,
            $sourceUrl,
            $body,
            $tags,
        );
    }

    /**
     * @return array{success: bool, uid: int, message: string}
     */
    public function importFromUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['success' => false, 'uid' => 0, 'message' => 'Invalid URL'];
        }

        $content = \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl($url);
        if (!is_string($content) || trim($content) === '') {
            return ['success' => false, 'uid' => 0, 'message' => 'Could not fetch skill content'];
        }

        return $this->importFromMarkdown($content, 'url', $url);
    }

    /**
     * @return array{success: bool, uid: int, message: string}
     */
    public function importFromMarkdown(string $content, string $source = 'file', string $sourceUrl = '', string $fileName = ''): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['success' => false, 'uid' => 0, 'message' => 'Skill markdown is empty'];
        }

        $parsed = $this->parseSkillMarkdown($content, $sourceUrl, $fileName);
        $uid = $this->importSkill(
            $parsed['name'],
            $parsed['triggerKeyword'],
            $content,
            $source,
            $sourceUrl,
            $parsed['version'],
            $parsed['tags'],
        );

        return ['success' => true, 'uid' => $uid, 'message' => 'Skill imported'];
    }

    public function remove(int $uid): void
    {
        $this->skillRepository->delete($uid);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildCatalog(): array
    {
        $catalog = [];
        foreach ($this->entryProviders as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            $catalog = array_replace($catalog, $provider->getEntries());
        }

        return $catalog;
    }

    private function isInstalled(string $triggerKeyword): bool
    {
        if ($triggerKeyword === '') {
            return false;
        }

        return $this->skillRepository->findByTriggerKeyword($triggerKeyword) !== null;
    }

    /**
     * @return array{name: string, triggerKeyword: string, version: string, tags: list<string>}
     */
    private function parseSkillMarkdown(string $content, string $sourceUrl = '', string $fileName = ''): array
    {
        $name = 'Imported Skill';
        $trigger = '/skill';
        $version = '1.0.0';
        $tags = ['imported'];

        if (preg_match('/^#\s+(.+?)(?:\s+—|\s+-|$)/m', $content, $matches) === 1) {
            $name = trim($matches[1]);
        }

        if (preg_match('/Trigger:\s*`([^`]+)`/i', $content, $matches) === 1) {
            $trigger = trim($matches[1]);
        }

        if (preg_match('/Version:\s*([0-9.]+)/i', $content, $matches) === 1) {
            $version = trim($matches[1]);
        }

        if ($trigger === '/skill') {
            if ($sourceUrl !== '') {
                $slug = basename(parse_url($sourceUrl, PHP_URL_PATH) ?: 'skill');
            } elseif ($fileName !== '') {
                $slug = pathinfo($fileName, PATHINFO_FILENAME);
            } else {
                $slug = 'skill';
            }
            $trigger = '/' . preg_replace('/[^a-z0-9_-]+/i', '', $slug);
        }

        return [
            'name' => $name,
            'triggerKeyword' => $trigger,
            'version' => $version,
            'tags' => $tags,
        ];
    }
}
