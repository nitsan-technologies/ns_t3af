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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;

/**
 * Decides whether the agent must stop and wait for the editor after a tool message.
 *
 * A tool message asks to pause with `meta.orchestratorPause`. Inline drafts that only touch
 * low-risk fields (and are not destructive) let the agent continue; every other pause stops the turn.
 *
 * @internal
 */
final readonly class AgentPausePolicy
{
    public function __construct(
        private AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
    ) {}

    /**
     * @param array{role: string, content: string, meta: array<string, mixed>} $toolMessage
     * @return string|null pause reason, null = continue
     */
    public function pauseReason(array $toolMessage): ?string
    {
        $meta = $toolMessage['meta'];
        if (($meta['orchestratorPause'] ?? false) !== true) {
            return null;
        }

        $type = (string) ($meta['type'] ?? '');
        if ($type !== 'inline_draft') {
            return $type !== '' ? $type : 'blocked';
        }

        $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : [];
        $fields = is_array($draft['fields'] ?? null) ? array_values(array_filter($draft['fields'], 'is_array')) : [];
        $destructive = (string) ($meta['severity'] ?? '') === ToolSeverity::Destructive->value;

        return !$destructive && $this->usesOnlyLowRiskFields($fields) ? null : 'draft_review';
    }

    /**
     * @param list<array<mixed>> $fields
     */
    private function usesOnlyLowRiskFields(array $fields): bool
    {
        if ($fields === []) {
            return false;
        }
        foreach ($fields as $field) {
            $table = (string) ($field['table'] ?? '');
            $name = (string) ($field['field'] ?? $field['key'] ?? '');
            if (!$this->lowRiskFieldMatrix->isSafeField($table, $name)) {
                return false;
            }
        }

        return true;
    }
}
