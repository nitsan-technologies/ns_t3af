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

namespace NITSAN\NsT3AF\Tests\Unit\Exception;

use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\AiUniverseException;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function testEveryDomainExceptionImplementsMarker(): void
    {
        self::assertInstanceOf(AiUniverseException::class, new CipherException('x'));
        self::assertInstanceOf(AiUniverseException::class, new UnknownAdapterException('x'));
        self::assertInstanceOf(AiUniverseException::class, new AdapterRuntimeException('x'));
    }

    public function testCipherExceptionIsRuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new CipherException('x'));
    }

    public function testUnknownAdapterIsOutOfBounds(): void
    {
        self::assertInstanceOf(\OutOfBoundsException::class, new UnknownAdapterException('x'));
    }

    public function testAdapterRuntimeIsRuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new AdapterRuntimeException('x'));
    }
}
