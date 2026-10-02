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

use NITSAN\NsT3AF\Agent\Embedding\ProviderEmbeddingSource;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Api\EmbeddingResponse;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;

final class ProviderEmbeddingSourceTest extends TestCase
{
    public function testEmbedManyBatchesAndCachesDuplicates(): void
    {
        $calls = 0;
        $ai = $this->createMock(AiServiceInterface::class);
        $ai->expects(self::once())
            ->method('embed')
            ->willReturnCallback(function (string|array $text, AiOptions $options) use (&$calls): EmbeddingResponse {
                ++$calls;
                self::assertSame('agent_routing', $options->featureKey);
                $texts = is_array($text) ? $text : [$text];
                self::assertSame(['a', 'b'], $texts);

                return new EmbeddingResponse(
                    vectors: [[0.1], [0.2]],
                    modelId: 'emb-1',
                    providerIdentifier: 'p1',
                );
            });

        $source = new ProviderEmbeddingSource($ai, $this->settings(''));
        $first = $source->embedMany(['a', 'b']);
        $second = $source->embed('a');

        self::assertSame([[0.1], [0.2]], $first);
        self::assertSame([0.1], $second);
        self::assertSame(1, $calls);
        self::assertSame('emb-1', $source->modelId());
    }

    public function testEmbedManyChunksByBatchSize(): void
    {
        $texts = [];
        for ($i = 0; $i < ProviderEmbeddingSource::BATCH_SIZE + 2; ++$i) {
            $texts[] = 't' . $i;
        }

        $ai = $this->createMock(AiServiceInterface::class);
        $ai->expects(self::exactly(2))
            ->method('embed')
            ->willReturnCallback(function (string|array $text): EmbeddingResponse {
                $chunk = is_array($text) ? $text : [$text];
                /** @var list<list<float>> $vectors */
                $vectors = [];
                foreach ($chunk as $t) {
                    $vectors[] = [(float) strlen($t)];
                }

                return new EmbeddingResponse(
                    vectors: $vectors,
                    modelId: 'emb',
                    providerIdentifier: 'p',
                );
            });

        $source = new ProviderEmbeddingSource($ai, $this->settings('pid'));
        $out = $source->embedMany($texts);

        self::assertCount(count($texts), $out);
        self::assertSame([(float) strlen($texts[0])], $out[0]);
    }

    private function settings(string $embeddingProvider): AgentSettingsService
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'agentEmbeddingProvider' => $embeddingProvider,
        ]);

        return new AgentSettingsService($extensionSettings);
    }
}
