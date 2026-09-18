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

namespace NITSAN\NsT3AF\Agent\Embedding;

use NITSAN\NsT3AF\Agent\Contract\EmbeddingSourceInterface;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;

/**
 * Optional local embeddings via codewithkyrian/transformers (suggest + ext-ffi).
 *
 * @internal
 */
final class TransformersEmbeddingSource implements EmbeddingSourceInterface
{
    public const ID = 'transformers';

    public function __construct(
        private readonly AgentSettingsService $agentSettings,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function isAvailable(): bool
    {
        return extension_loaded('ffi')
            && (
                class_exists(\Codewithkyrian\Transformers\Pipelines\FeatureExtractionPipeline::class)
                || function_exists('Codewithkyrian\\Transformers\\pipeline')
            );
    }

    public function embed(string $text): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Transformers embedding source is not available (package or ext-ffi missing).');
        }

        $model = $this->modelId();
        // Optional suggest-dep: Reflect by name so analyse does not require the package.
        $pipelineName = 'Codewithkyrian\\Transformers\\' . 'pipeline';
        try {
            $pipeline = new \ReflectionFunction($pipelineName);
        } catch (\ReflectionException $exception) {
            throw new \RuntimeException('codewithkyrian/transformers pipeline() is not available.', 0, $exception);
        }

        $extractor = $pipeline->invoke('feature-extraction', $model);
        if (!\is_callable($extractor)) {
            throw new \RuntimeException('codewithkyrian/transformers pipeline() did not return a callable extractor.');
        }

        $raw = $extractor($text, pooling: 'mean', normalize: true);

        return $this->flattenToFloatList($raw);
    }

    public function modelId(): string
    {
        $configured = trim($this->agentSettings->getTransformersModel());

        return $configured !== '' ? $configured : 'Xenova/all-MiniLM-L6-v2';
    }

    /**
     * @return list<float>
     */
    private function flattenToFloatList(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \RuntimeException('Transformers embedding returned a non-array payload.');
        }

        $flat = [];
        $walker = static function (mixed $node) use (&$flat, &$walker): void {
            if (!is_array($node)) {
                return;
            }
            if ($node !== [] && array_is_list($node) && (is_float($node[0]) || is_int($node[0]))) {
                foreach ($node as $value) {
                    $flat[] = (float) $value;
                }

                return;
            }
            foreach ($node as $child) {
                $walker($child);
            }
        };
        $walker($raw);

        if ($flat === []) {
            throw new \RuntimeException('Transformers embedding returned an empty vector.');
        }

        return $flat;
    }
}
