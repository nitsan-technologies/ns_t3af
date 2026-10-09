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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

/**
 * Proof that this call owns the right to run a request id.
 *
 * The token is unique per acquisition, so a call whose lock went stale and was taken over by a retry
 * can neither complete nor release the row of the call that replaced it.
 */
final readonly class RecordsApplyIdempotencyLock
{
    public function __construct(
        public int $userUid,
        public string $tool,
        public string $requestId,
        public string $token,
    ) {}
}
