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

namespace NITSAN\NsT3AF\Tests\Unit\Provider;

use NITSAN\NsT3AF\Provider\ModelSamplingCapability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelSamplingCapabilityTest extends TestCase
{
    #[DataProvider('reasoningModelIdsProvider')]
    public function testReasoningModelsRejectTemperature(string $modelId): void
    {
        self::assertFalse(ModelSamplingCapability::supportsTemperature($modelId));
        $filtered = ModelSamplingCapability::filterInvokeOptions($modelId, [
            'temperature' => 0.7,
            'top_p' => 0.9,
            'messages' => [],
        ]);
        self::assertArrayNotHasKey('temperature', $filtered);
        self::assertArrayNotHasKey('top_p', $filtered);
        self::assertArrayHasKey('messages', $filtered);
    }

    public function testChatModelsKeepTemperature(): void
    {
        self::assertTrue(ModelSamplingCapability::supportsTemperature('gpt-4.1-mini'));
        $options = ModelSamplingCapability::filterInvokeOptions('gpt-4.1-mini', ['temperature' => 0.3]);
        self::assertSame(0.3, $options['temperature']);
    }

    public function testUnsupportedSamplingErrorDetection(): void
    {
        $error = new \RuntimeException("Unsupported parameter: 'temperature' is not supported with this model.");
        self::assertTrue(ModelSamplingCapability::isUnsupportedSamplingParameterError($error));
        self::assertTrue(ModelSamplingCapability::shouldRetryWithoutSampling($error, ['temperature' => 0.7]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reasoningModelIdsProvider(): iterable
    {
        yield 'gpt-5' => ['gpt-5'];
        yield 'gpt-5-mini' => ['gpt-5-mini'];
        yield 'o3-mini' => ['o3-mini'];
        yield 'o1' => ['o1'];
    }
}
