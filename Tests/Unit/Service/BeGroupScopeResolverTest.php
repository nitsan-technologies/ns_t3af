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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\BeGroupScopeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

final class BeGroupScopeResolverTest extends TestCase
{
    private BeGroupScopeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new BeGroupScopeResolver($this->createMock(SiteFinder::class));
    }

    #[Test]
    public function emptyMountpointsAndLanguagesResolveToAuto(): void
    {
        $scope = $this->resolver->resolve([
            'db_mountpoints' => '',
            'allowed_languages' => '',
        ]);

        self::assertTrue($scope['pageScope']['auto']);
        self::assertSame([], $scope['pageScope']['items']);
        self::assertTrue($scope['languageScope']['auto']);
        self::assertSame([], $scope['languageScope']['items']);
    }
}
