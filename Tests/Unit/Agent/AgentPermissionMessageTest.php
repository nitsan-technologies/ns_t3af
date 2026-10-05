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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentPermissionMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentPermissionMessageTest extends TestCase
{
    use AgentTranslatorTrait;

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function rewriteMapsInsufficientPermissions(): void
    {
        $translator = $this->createAgentTranslator();
        $rewritten = AgentPermissionMessage::rewrite(
            'Insufficient permissions to create a page under parent uid 12.',
            $translator,
        );

        self::assertSame(
            'You do not have permission to change this page or its content. Ask an administrator for page or content edit rights, then try again.',
            $rewritten,
        );
    }

    #[Test]
    public function rewriteMapsBackendAuthenticationRequired(): void
    {
        $translator = $this->createAgentTranslator();
        $rewritten = AgentPermissionMessage::rewrite(
            'Backend authentication required to edit page uid 5.',
            $translator,
        );

        self::assertStringContainsString('permission', strtolower($rewritten));
        self::assertStringNotContainsString('Backend authentication required', $rewritten);
    }

    #[Test]
    public function rewriteLeavesOtherMessagesUntouched(): void
    {
        $translator = $this->createAgentTranslator();
        $message = 'Page uid 9 was not found.';

        self::assertSame($message, AgentPermissionMessage::rewrite($message, $translator));
    }
}
