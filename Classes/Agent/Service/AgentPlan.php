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

/**
 * Helpers for the "Progress" plan (the steps the model saves with update_plan).
 *
 * The model does not always update the plan, so the runner keeps it consistent: a confirmed
 * change finishes the step in progress. An NL final reply must not wipe open steps — that
 * let multi-element requests falsely "finish" after the first CType.
 *
 * @internal
 */
final class AgentPlan
{
    /**
     * The plan of the newest message that carries one.
     *
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array{title: string, status: string}>
     */
    public static function latest(array $historyMessages): array
    {
        for ($i = count($historyMessages) - 1; $i >= 0; --$i) {
            $meta = is_array($historyMessages[$i]['meta'] ?? null) ? $historyMessages[$i]['meta'] : [];
            if (is_array($meta['plan'] ?? null)) {
                $steps = [];
                foreach ($meta['plan'] as $step) {
                    if (is_array($step) && trim((string) ($step['title'] ?? '')) !== '') {
                        $status = (string) ($step['status'] ?? 'pending');
                        $steps[] = [
                            'title' => (string) $step['title'],
                            'status' => in_array($status, ['pending', 'in_progress', 'completed', 'failed'], true) ? $status : 'pending',
                        ];
                    }
                }

                return $steps;
            }
        }

        return [];
    }

    /**
     * @param list<array{title: string, status: string}> $steps
     */
    public static function hasOpenSteps(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step['status'] !== 'completed') {
                return true;
            }
        }

        return false;
    }

    /**
     * Progress step that still needs an image attached (title from update_plan heuristics).
     *
     * @param list<array{title: string, status: string}> $steps
     */
    public static function hasOpenImageAttachStep(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step['status'] ?? '') === 'completed') {
                continue;
            }
            $title = mb_strtolower((string) ($step['title'] ?? ''));
            if (preg_match('/\b(attach|image|bild|media|file|assets)\b/u', $title) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The step that was running when a tool failed is marked failed.
     *
     * @param list<array{title: string, status: string}> $steps
     * @return list<array{title: string, status: string}>
     */
    public static function failCurrent(array $steps): array
    {
        foreach ($steps as $index => $step) {
            if ($step['status'] === 'in_progress') {
                $steps[$index] = ['title' => $step['title'], 'status' => 'failed'];
                break;
            }
        }

        return $steps;
    }

    /**
     * A change of the plan's current step was applied: it is done, the next step starts.
     *
     * @param list<array{title: string, status: string}> $steps
     * @return list<array{title: string, status: string}>
     */
    public static function advance(array $steps): array
    {
        $current = null;
        foreach ($steps as $index => $step) {
            if ($step['status'] === 'in_progress' || $step['status'] === 'failed') {
                $current = $index;
                break;
            }
        }
        if ($current === null) {
            foreach ($steps as $index => $step) {
                if ($step['status'] === 'pending') {
                    $current = $index;
                    break;
                }
            }
            if ($current === null) {
                return $steps;
            }
        }
        $steps[$current] = ['title' => $steps[$current]['title'], 'status' => 'completed'];
        for ($next = $current + 1, $count = count($steps); $next < $count; ++$next) {
            if ($steps[$next]['status'] === 'pending') {
                $steps[$next] = ['title' => $steps[$next]['title'], 'status' => 'in_progress'];
                break;
            }
        }

        return $steps;
    }

    /**
     * The model answered and is done: nothing stays open.
     *
     * @param list<array{title: string, status: string}> $steps
     * @return list<array{title: string, status: string}>
     */
    public static function completeAll(array $steps): array
    {
        return array_map(static fn(array $step): array => ['title' => $step['title'], 'status' => 'completed'], $steps);
    }

    /**
     * @param list<array{title: string, status: string}> $steps
     */
    public static function promptBlock(array $steps): string
    {
        if ($steps === [] || !self::hasOpenSteps($steps)) {
            return '';
        }
        $lines = [];
        foreach ($steps as $number => $step) {
            $lines[] = sprintf('%d. [%s] %s', $number + 1, $step['status'], $step['title']);
        }

        return 'Current plan (you saved it earlier in this conversation; continue with the step in progress and keep it up to date with update_plan; a confirmed change already moved it on, correct it if that is wrong).'
            . " Do not claim the request is finished while any step is pending or in_progress — call the next write tool (or update_plan) instead:\n"
            . implode("\n", $lines);
    }
}
