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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\OAuth;

use NITSAN\NsT3AF\Mcp\OAuth\PkceVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PkceVerifierTest extends TestCase
{
    private PkceVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PkceVerifier();
    }

    #[Test]
    public function verifyAcceptsValidS256Challenge(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        self::assertTrue($this->verifier->verify($verifier, $challenge));
    }

    #[Test]
    public function verifyRejectsInvalidVerifierLength(): void
    {
        self::assertFalse($this->verifier->verify('short', 'abc'));
    }
}
