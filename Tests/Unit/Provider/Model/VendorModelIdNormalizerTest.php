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

use NITSAN\NsT3AF\Provider\Model\VendorModelIdNormalizer;
use PHPUnit\Framework\TestCase;

final class VendorModelIdNormalizerTest extends TestCase
{
    public function testGeminiStripsModelsPrefix(): void
    {
        self::assertSame(
            'gemini-3.5-flash',
            VendorModelIdNormalizer::canonicalize('models/gemini-3.5-flash', 'symfony.gemini'),
        );
    }

    public function testGeminiLeavesShortIdUnchanged(): void
    {
        self::assertSame(
            'gemini-3.5-flash',
            VendorModelIdNormalizer::canonicalize('gemini-3.5-flash', 'symfony.gemini'),
        );
    }

    public function testNonGeminiAdapterDoesNotStripPrefix(): void
    {
        self::assertSame(
            'models/custom',
            VendorModelIdNormalizer::canonicalize('models/custom', 'symfony.openai'),
        );
    }

    public function testGeminiPrefersBaseModelIdFromApiItem(): void
    {
        self::assertSame(
            'gemini-3.5-flash',
            VendorModelIdNormalizer::idFromApiItem([
                'name' => 'models/gemini-3.5-flash',
                'baseModelId' => 'gemini-3.5-flash',
            ], 'symfony.gemini'),
        );
    }

    public function testIdsFromJsonNormalizesGeminiList(): void
    {
        $json = json_encode([
            'models' => [
                ['name' => 'models/gemini-3.5-flash'],
                ['name' => 'models/gemini-embedding-001'],
            ],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(
            ['gemini-3.5-flash', 'gemini-embedding-001'],
            VendorModelIdNormalizer::idsFromModelsListJson($json, 'symfony.gemini'),
        );
    }
}
