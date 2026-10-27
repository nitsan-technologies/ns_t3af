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

use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Domain\Model\Provider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Rebuilds the agent tool index after the HTTP response is sent (FPM flush when available).
 *
 * @internal
 */
final class AgentToolIndexDeferredRebuildService
{
    private static bool $scheduled = false;

    public function __construct(
        private readonly AgentToolIndexInterface $toolIndex,
        private readonly ProviderAgentToolIndexRebuildPolicy $rebuildPolicy,
    ) {}

    /**
     * @param array<string, mixed> $body Raw POST body from the provider drawer.
     */
    public function afterProviderSave(?Provider $before, ?Provider $after, array $body): void
    {
        if ($after === null) {
            return;
        }

        if (!$this->rebuildPolicy->shouldRebuildAfterSave($before, $after, $body)) {
            return;
        }

        $this->toolIndex->invalidate();
        $this->scheduleRebuild();
    }

    public function scheduleRebuild(): void
    {
        if (self::$scheduled) {
            return;
        }

        self::$scheduled = true;

        register_shutdown_function(static function (): void {
            if (\function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                GeneralUtility::makeInstance(AgentToolIndexInterface::class)->rebuild();
            } catch (\Throwable) {
                // Same as saveAction: warming must not break the provider flow.
            }
        });
    }
}
