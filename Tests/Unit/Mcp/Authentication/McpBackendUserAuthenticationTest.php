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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Authentication;

use NITSAN\NsT3AF\Mcp\Authentication\McpBackendUserAuthentication;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * @internal
 */
final class McpBackendUserAuthenticationTest extends TestCase
{
    #[Test]
    public function moduleDataIsKeptInMemoryWithoutASession(): void
    {
        $user = new McpBackendUserAuthentication();
        $key = BackendUtility::class . '::getUpdateSignal';

        self::assertNull($user->getModuleData('nst3af-session-probe'));
        $user->pushModuleData($key, ['redirects:slugChanged' => ['set' => 'redirects:slugChanged']]);

        self::assertNull((new \ReflectionProperty($user, 'userSession'))->getValue($user));
        self::assertSame(
            ['redirects:slugChanged' => ['set' => 'redirects:slugChanged']],
            $user->getModuleData($key),
        );

        McpBackendUserAuthentication::discardUpdateSignal($user);

        self::assertNull($user->getModuleData($key));
        self::assertNull((new \ReflectionProperty($user, 'userSession'))->getValue($user));
    }
}
