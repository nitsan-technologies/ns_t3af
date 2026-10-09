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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Dto;

use NITSAN\NsT3AF\Mcp\Dto\PreviewResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PreviewResultTest extends TestCase
{
    #[Test]
    public function resolveSelectionsUsesExactVariantValues(): void
    {
        $preview = new PreviewResult(
            tool: 't3ai_generate_all_seo',
            target: ['table' => 'pages', 'uid' => 1, 'languageId' => 0],
            fields: [
                ['key' => 'metaTitle', 'label' => 'Meta Title', 'current' => ''],
                ['key' => 'keywords', 'label' => 'Keywords', 'current' => ''],
            ],
            variants: [
                ['label' => 'A', 'angle' => '', 'values' => ['metaTitle' => 'A1', 'keywords' => 'ka']],
                ['label' => 'B', 'angle' => '', 'values' => ['metaTitle' => 'B1', 'keywords' => 'kb']],
            ],
        );

        self::assertSame(
            ['metaTitle' => 'B1', 'keywords' => 'ka'],
            $preview->resolveSelections(['metaTitle' => 1, 'keywords' => 0]),
        );
    }

    #[Test]
    public function incompleteVariantSkipsMissingFieldKeysOnResolve(): void
    {
        $preview = new PreviewResult(
            tool: 't3ai_generate_all_seo',
            target: ['table' => 'pages', 'uid' => 1, 'languageId' => 0],
            fields: [
                ['key' => 'metaTitle', 'label' => 'Meta Title', 'current' => ''],
                ['key' => 'keywords', 'label' => 'Keywords', 'current' => ''],
            ],
            variants: [
                // Incomplete: keywords missing (child tools must complete before shipping).
                ['label' => 'A', 'angle' => '', 'values' => ['metaTitle' => 'Only title']],
            ],
        );

        self::assertFalse($this->variantsCoverFieldKeys($preview, ['metaTitle', 'keywords']));
        self::assertSame(
            ['metaTitle' => 'Only title'],
            $preview->resolveSelections(['metaTitle' => 0, 'keywords' => 0]),
        );
    }

    #[Test]
    public function completeVariantsCoverRequestedFieldKeys(): void
    {
        $preview = new PreviewResult(
            tool: 't3ai_generate_all_seo',
            target: ['table' => 'pages', 'uid' => 1, 'languageId' => 0],
            fields: [
                ['key' => 'metaTitle', 'label' => 'Meta Title', 'current' => ''],
                ['key' => 'keywords', 'label' => 'Keywords', 'current' => ''],
            ],
            variants: [
                ['label' => 'A', 'angle' => '', 'values' => ['metaTitle' => 'A', 'keywords' => '']],
                ['label' => 'B', 'angle' => '', 'values' => ['metaTitle' => 'B', 'keywords' => 'kb']],
            ],
        );

        self::assertTrue($this->variantsCoverFieldKeys($preview, ['metaTitle', 'keywords']));
    }

    /**
     * Mirrors child-tool completeness rule without needing a live LLM.
     *
     * @param list<string> $fieldKeys
     */
    private function variantsCoverFieldKeys(PreviewResult $preview, array $fieldKeys): bool
    {
        if ($fieldKeys === [] || $preview->variants === []) {
            return false;
        }
        foreach ($preview->variants as $variant) {
            $values = is_array($variant['values'] ?? null) ? $variant['values'] : [];
            foreach ($fieldKeys as $fieldKey) {
                if (!array_key_exists($fieldKey, $values)) {
                    return false;
                }
            }
        }

        return true;
    }
}
