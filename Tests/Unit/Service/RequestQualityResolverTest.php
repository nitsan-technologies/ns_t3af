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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\QualityScore;
use NITSAN\NsT3AF\Service\RequestQualityResolver;
use PHPUnit\Framework\TestCase;

final class RequestQualityResolverTest extends TestCase
{
    private RequestQualityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RequestQualityResolver();
    }

    public function testResolveFromResponseQuality(): void
    {
        $response = new AiResponse(
            content: 'ok',
            modelId: 'gpt-4o',
            providerIdentifier: 'openai',
            quality: new QualityScore(88, 90, 85, 80, 82),
        );

        $resolved = $this->resolver->resolveForLog($response, new AiOptions());

        self::assertSame(88, $resolved['quality_score']);
        self::assertNotSame('', $resolved['quality_dimensions']);
    }

    public function testResolveFromOptionsExtraWhenResponseHasNoQuality(): void
    {
        $response = new AiResponse('ok', 'gpt-4o', 'openai');
        $options = new AiOptions(extra: [
            'quality' => ['score' => 72, 'relevance' => 70],
        ]);

        $resolved = $this->resolver->resolveForLog($response, $options);

        self::assertSame(72, $resolved['quality_score']);
    }
}
