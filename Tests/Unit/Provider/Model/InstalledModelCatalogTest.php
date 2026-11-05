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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\Model;

use NITSAN\NsT3AF\Provider\Model\InstalledModelCatalog;
use PHPUnit\Framework\TestCase;

final class InstalledModelCatalogTest extends TestCase
{
    public function testStabilityAiRestrictsToFixedModes(): void
    {
        $catalog = new InstalledModelCatalog();

        self::assertTrue($catalog->restricts('ns_t3ai.stability_ai'));
        self::assertSame(['core', 'sd3', 'ultra'], $catalog->ids('ns_t3ai.stability_ai'));
        self::assertNull($catalog->rejection('ns_t3ai.stability_ai', 'core'));
        self::assertNull($catalog->rejection('ns_t3ai.stability_ai', 'SD3'));
        self::assertSame(
            'Model "zz-bogus-999" is not supported by this provider.',
            $catalog->rejection('ns_t3ai.stability_ai', 'zz-bogus-999'),
        );
    }
}
