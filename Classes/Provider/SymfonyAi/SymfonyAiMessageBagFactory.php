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

namespace NITSAN\NsT3AF\Provider\SymfonyAi;

/**
 * Builds Symfony AI MessageBag instances from OpenAI-style chat messages.
 *
 * @internal
 */
final class SymfonyAiMessageBagFactory
{
    private const VENDOR_PREFIX = 'NITSAN\T3af\\Vendor\\';

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function createFromChatMessages(array $messages): ?object
    {
        $messageClass = $this->resolveClass('Symfony\\AI\\Platform\\Message\\Message');
        $bagClass = $this->resolveClass('Symfony\\AI\\Platform\\Message\\MessageBag');
        if ($messageClass === null || $bagClass === null || $messages === []) {
            return null;
        }

        $bagMessages = [];
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = isset($row['role']) && is_string($row['role']) ? $row['role'] : 'user';
            $content = $row['content'] ?? '';
            if (!is_string($content)) {
                $content = is_scalar($content) ? (string) $content : '';
            }
            $content = trim($content);
            if ($content === '') {
                continue;
            }

            $bagMessages[] = match ($role) {
                'system' => $messageClass::forSystem($content),
                'assistant' => $messageClass::ofAssistant($content),
                default => $messageClass::ofUser($content),
            };
        }

        if ($bagMessages === []) {
            return null;
        }

        return new $bagClass(...$bagMessages);
    }

    /**
     * @param class-string|string $fqcn
     * @return class-string|null
     */
    private function resolveClass(string $fqcn): ?string
    {
        if (class_exists($fqcn)) {
            return $fqcn;
        }

        $scoped = self::VENDOR_PREFIX . $fqcn;

        return class_exists($scoped) ? $scoped : null;
    }
}
