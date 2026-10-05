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

namespace NITSAN\NsT3AF\Provider\Model;

use NITSAN\NsT3AF\Provider\Capability;

/**
 * Heuristic capability inference from vendor model IDs.
 *
 * Used as last-resort overlay when neither the live `/models` response nor the
 * bundled Symfony AI `ModelCatalog` advertises capabilities. Conservative on
 * purpose: emits only capabilities that are unambiguous from the ID
 * (embeddings, vision-capable families). Drawer keeps the
 * checkboxes editable so a wrong inference can be overridden.
 *
 * Patterns are matched case-insensitively against the model id (and adapter
 * type when relevant).
 *
 * @internal
 */
final class CapabilityInferrer
{
    /**
     * @return list<string>
     */
    public function infer(string $modelId, string $adapterType = ''): array
    {
        $id = strtolower(trim($modelId));
        if ($id === '') {
            return [];
        }

        if ($this->isEmbeddingModel($id)) {
            return [Capability::EMBEDDINGS];
        }

        $caps = [Capability::CHAT, Capability::STREAMING];

        if ($this->supportsVision($id)) {
            $caps[] = Capability::VISION;
        }

        return array_values(array_unique($caps));
    }

    private function isEmbeddingModel(string $id): bool
    {
        return str_contains($id, 'embedding')
            || str_contains($id, 'embed-')
            || str_starts_with($id, 'text-embedding')
            || str_contains($id, 'voyage-');
    }

    private function supportsVision(string $id): bool
    {
        // OpenAI: gpt-4o*, gpt-4-vision*, gpt-4-turbo (multimodal)
        // Anthropic: claude-3*, claude-3.5*, claude-3.7*, claude-4*
        // Google: gemini-1.5*, gemini-2*
        // Mistral: pixtral*
        $patterns = [
            '/^gpt-4o/',
            '/gpt-4.*vision/',
            '/^gpt-4-turbo/',
            '/^claude-(3|4)/',
            '/^claude-3\.(5|7)/',
            '/^gemini-(1\.5|2|pro-vision)/',
            '/^pixtral/',
            '/llava/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $id) === 1) {
                return true;
            }
        }

        return false;
    }
}
