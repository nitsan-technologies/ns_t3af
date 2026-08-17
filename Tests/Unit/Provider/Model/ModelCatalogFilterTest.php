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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\Model;

use NITSAN\NsT3AF\Provider\Model\ModelCatalogFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelCatalogFilterTest extends TestCase
{
    private ModelCatalogFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ModelCatalogFilter();
    }

    #[Test]
    public function denylistAndPreviewModelsAreHidden(): void
    {
        $catalog = [
            'claude-sonnet-4-20250514',
            'gemini-2.5-flash-lite-preview-09-2025',
            'gemini-3-flash-preview',
            'claude-3-5-haiku-latest',
        ];

        foreach ($catalog as $id) {
            self::assertFalse($this->filter->isListedModelId($id, $catalog), $id);
        }
    }

    #[Test]
    public function currentRecommendedModelsStayVisible(): void
    {
        $catalog = [
            'claude-sonnet-4-5-20250929',
            'claude-haiku-4-5-20251001',
            'gemini-2.5-flash',
            'gemini-2.5-pro',
            'gpt-4o-mini',
            'text-embedding-3-small',
        ];

        foreach ($catalog as $id) {
            self::assertTrue($this->filter->isListedModelId($id, $catalog), $id);
        }
    }

    #[Test]
    public function supersededGenerationsAreHiddenWhenNewerExists(): void
    {
        $catalog = [
            'claude-3-7-sonnet-20250219',
            'claude-sonnet-4-5-20250929',
            'gemini-2.0-flash',
            'gemini-2.5-flash',
            'gpt-3.5-turbo',
            'gpt-4o',
        ];

        self::assertTrue($this->filter->isListedModelId('claude-3-7-sonnet-20250219', $catalog));
        self::assertFalse($this->filter->isListedModelId('gemini-2.0-flash', $catalog));
        self::assertFalse($this->filter->isListedModelId('gpt-3.5-turbo', $catalog));
        self::assertTrue($this->filter->isListedModelId('gpt-4o', $catalog));
    }

    #[Test]
    public function wizardChatFilterExcludesEmbeddingsButProviderListKeepsThem(): void
    {
        $catalog = ['gpt-4o', 'text-embedding-3-small'];

        self::assertTrue($this->filter->isListedModelId('text-embedding-3-small', $catalog));
        self::assertFalse($this->filter->isWizardChatModelId('text-embedding-3-small', $catalog));
        self::assertTrue($this->filter->isWizardChatModelId('gpt-4o', $catalog));
    }

    #[Test]
    public function filterListedModelIdsReturnsStableOrder(): void
    {
        $ids = [
            'claude-sonnet-4-20250514',
            'claude-sonnet-4-5-20250929',
            'gemini-2.5-flash-lite-preview-09-2025',
            'gemini-2.5-pro',
        ];

        self::assertSame(
            ['claude-sonnet-4-5-20250929', 'gemini-2.5-pro'],
            $this->filter->filterListedModelIds($ids),
        );
    }
}
