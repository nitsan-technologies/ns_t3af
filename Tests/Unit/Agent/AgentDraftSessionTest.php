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

use NITSAN\NsT3AF\Agent\Service\AgentDraftSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A draft must survive another backend request saving its older copy of the session (the page
 * module reload after Apply flushes its flash messages that way).
 *
 * @internal
 */
final class AgentDraftSessionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionData = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        GeneralUtility::resetSingletonInstances([]);
        parent::tearDown();
    }

    #[Test]
    public function draftSurvivesAnotherRequestSavingTheSession(): void
    {
        $this->useRegistry();
        $user = $this->user(7);
        $drafts = new AgentDraftSession();

        $sessionBefore = $this->sessionData;
        $drafts->storeDraft('d1', ['tool' => 'file_reference_add'], $user);
        $this->sessionData = $sessionBefore;

        self::assertSame(['tool' => 'file_reference_add'], $drafts->getDraft('d1', $user));
        self::assertArrayNotHasKey('nst3af_agent_drafts', $this->sessionData);
    }

    #[Test]
    public function draftBelongsToItsEditor(): void
    {
        $this->useRegistry();
        $drafts = new AgentDraftSession();
        $drafts->storeDraft('d1', ['tool' => 'write_table'], $this->user(7));

        self::assertNull($drafts->getDraft('d1', $this->user(8)));
        $drafts->removeDraft('d1', $this->user(8));
        self::assertNotNull($drafts->getDraft('d1', $this->user(7)));

        $drafts->removeDraft('d1', $this->user(7));
        self::assertNull($drafts->getDraft('d1', $this->user(7)));
    }

    #[Test]
    public function sessionIsUsedWhenTheRegistryIsNotReachable(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willThrowException(new \RuntimeException('no database'));
        $registry->method('set')->willThrowException(new \RuntimeException('no database'));
        GeneralUtility::setSingletonInstance(Registry::class, $registry);
        $user = $this->user(7);
        $drafts = new AgentDraftSession();

        $drafts->storeDraft('d1', ['tool' => 'write_table'], $user);

        self::assertSame(['tool' => 'write_table'], $drafts->getDraft('d1', $user));
        self::assertArrayHasKey('d1', $this->sessionData['nst3af_agent_drafts']);
    }

    private function useRegistry(): void
    {
        /** @var array<string, array<string, mixed>> $entries */
        $entries = [];
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(
            static function (string $namespace, string $key, mixed $default = null) use (&$entries): mixed {
                return $entries[$namespace][$key] ?? $default;
            },
        );
        $registry->method('set')->willReturnCallback(
            static function (string $namespace, string $key, mixed $value) use (&$entries): void {
                $entries[$namespace][$key] = $value;
            },
        );
        $registry->method('remove')->willReturnCallback(
            static function (string $namespace, string $key) use (&$entries): void {
                unset($entries[$namespace][$key]);
            },
        );
        GeneralUtility::setSingletonInstance(Registry::class, $registry);
    }

    private function user(int $uid): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => $uid];
        $user->method('getSessionData')->willReturnCallback(
            fn(string $key): mixed => $this->sessionData[$key] ?? null,
        );
        $user->method('setAndSaveSessionData')->willReturnCallback(
            function (string $key, mixed $data): void {
                $this->sessionData[$key] = $data;
            },
        );

        return $user;
    }
}
