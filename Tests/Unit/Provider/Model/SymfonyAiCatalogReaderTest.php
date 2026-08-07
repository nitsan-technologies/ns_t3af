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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\Model;

use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Model\SymfonyAiCatalogReader;
use PHPUnit\Framework\TestCase;

final class SymfonyAiCatalogReaderTest extends TestCase
{
    public function testReadsOpenAiCatalogForCanonicalVendorKey(): void
    {
        if (!class_exists(\Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog::class)) {
            self::markTestSkipped('symfony/ai-open-ai-platform is not installed.');
        }

        $reader = new SymfonyAiCatalogReader();
        $models = $reader->read('openai');

        self::assertNotEmpty($models);
        $ids = array_map(static fn($m) => $m->id, $models);
        self::assertContains('gpt-4o', $ids);
    }

    public function testReadsOpenAiCatalogForHyphenatedVendorKey(): void
    {
        if (!class_exists(\Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog::class)) {
            self::markTestSkipped('symfony/ai-open-ai-platform is not installed.');
        }

        $reader = new SymfonyAiCatalogReader();
        $models = $reader->read('open-ai');
        $canonical = $reader->read('openai');

        self::assertNotEmpty($models);
        $gpt4oByVendor = null;
        $gpt4oCanonical = null;
        foreach ($models as $model) {
            if ($model->id === 'gpt-4o') {
                $gpt4oByVendor = $model;
                break;
            }
        }
        foreach ($canonical as $model) {
            if ($model->id === 'gpt-4o') {
                $gpt4oCanonical = $model;
                break;
            }
        }
        self::assertNotNull($gpt4oByVendor);
        self::assertNotNull($gpt4oCanonical);
        self::assertSame($gpt4oCanonical->capabilities, $gpt4oByVendor->capabilities);
    }

    public function testOpenAiEmbeddingModelsAreTaggedEmbeddingOnly(): void
    {
        if (!class_exists(\Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog::class)) {
            self::markTestSkipped('symfony/ai-open-ai-platform is not installed.');
        }

        $reader = new SymfonyAiCatalogReader();
        $models = $reader->read('openai');
        $byId = [];
        foreach ($models as $model) {
            $byId[$model->id] = $model;
        }

        self::assertArrayHasKey('text-embedding-3-small', $byId);
        self::assertSame([Capability::EMBEDDINGS], $byId['text-embedding-3-small']->capabilities);
        self::assertArrayHasKey('gpt-4o', $byId);
        self::assertNotContains(Capability::EMBEDDINGS, $byId['gpt-4o']->capabilities);
    }
}
