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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\RecordsApply;

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyPayloadStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordsApplyPayloadStoreTest extends TestCase
{
    #[Test]
    public function storeUploadedPayloadDoesNotRequireGlobBrace(): void
    {
        $store = new RecordsApplyPayloadStore();
        $payloadId = $store->generateId();

        $store->storeUploadedPayload($payloadId, 1, '{"data":{}}', time() + 900);

        self::assertFileExists($store->directory() . '/' . $payloadId . '.json');
        self::assertFileExists($store->directory() . '/' . $payloadId . '.meta');
    }
}
