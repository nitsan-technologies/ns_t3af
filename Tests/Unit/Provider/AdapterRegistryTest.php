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

namespace NITSAN\NsT3AF\Tests\Unit\Provider;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use PHPUnit\Framework\TestCase;

final class AdapterRegistryTest extends TestCase
{
    public function testIterableConstructorAddsAdapters(): void
    {
        $registry = new AdapterRegistry([
            $this->makeAdapter('a'),
            $this->makeAdapter('b'),
        ]);
        self::assertSame(['a', 'b'], $registry->types());
        self::assertTrue($registry->has('a'));
        self::assertTrue($registry->has('b'));
        self::assertFalse($registry->has('c'));
    }

    public function testGetReturnsRegisteredAdapter(): void
    {
        $adapter = $this->makeAdapter('foo');
        $registry = new AdapterRegistry([$adapter]);
        self::assertSame($adapter, $registry->get('foo'));
    }

    public function testGetUnknownThrows(): void
    {
        $this->expectException(UnknownAdapterException::class);
        (new AdapterRegistry())->get('nope');
    }

    public function testAddOverridesSameType(): void
    {
        $registry = new AdapterRegistry();
        $registry->add($this->makeAdapter('x', 'first'));
        $registry->add($this->makeAdapter('x', 'second'));
        self::assertSame('second', $registry->get('x')->getDisplayName());
    }

    private function makeAdapter(string $type, string $display = ''): AdapterInterface
    {
        return new class ($type, $display) implements AdapterInterface {
            public function __construct(private string $type, private string $display) {}
            public function getType(): string
            {
                return $this->type;
            }
            public function getDisplayName(): string
            {
                return $this->display;
            }
            public function getDefaultEndpoint(): string
            {
                return '';
            }
            public function getDefaultCapabilities(): array
            {
                return [];
            }
            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::ok();
            }
            public function platform(Provider $provider): object
            {
                return new \stdClass();
            }
        };
    }
}
