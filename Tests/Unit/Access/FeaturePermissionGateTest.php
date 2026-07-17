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

namespace NITSAN\NsT3AF\Tests\Unit\Access;

use NITSAN\NsT3AF\Access\FeaturePermissionGate;
use NITSAN\NsT3AF\Access\T3AiPermissionResolver;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class FeaturePermissionGateTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    private FeaturePermissionGate $gate;

    protected function setUp(): void
    {
        $bindings = $this->createFeatureAccessBindingRegistry();
        $this->gate = new FeaturePermissionGate(
            new T3AiPermissionResolver($bindings),
            $bindings,
        );
    }

    public function testLegacyCustomOptionStillGrantsAccess(): void
    {
        $user = $this->user(customOptions: 'tx_t3ai_content:contentRewriter');

        self::assertTrue($this->gate->grantsModuleCard($user, 't3ai', 'content', 'contentRewriter'));
    }

    public function testNewFeatureBitGrantsAccessWhenLegacyMissing(): void
    {
        $user = $this->user(customOptions: 'T3Ai:Content');

        self::assertTrue($this->gate->grantsModuleTab($user, 't3ai', 'content'));
        self::assertTrue($this->gate->grantsModuleCard($user, 't3ai', 'content', 'contentRewriter'));
    }

    public function testT3CsTabPermissiveWithoutFeatureBits(): void
    {
        $user = $this->user(customOptions: '', modules: ['nitsan_nst3cs_t3cs']);

        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'DataSource'));
    }

    public function testT3CsTabGatedWhenFeatureBitsConfigured(): void
    {
        $user = $this->user(
            customOptions: 'T3Ai:T3CS,T3Ai:T3CS.Index',
            modules: ['nitsan_nst3cs_t3cs'],
        );

        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'DataSource'));
        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'Dashboard'));
        self::assertFalse($this->gate->grantsModuleTab($user, 't3cs', 'Chatbot'));
    }

    public function testT3CsChatAndAnalyticsOnlyUserHasLimitedTabs(): void
    {
        $user = $this->user(
            customOptions: 'T3Ai:T3CS,T3Ai:T3CS.Chat,T3Ai:T3CS.Analytics',
            modules: ['nitsan_nst3cs_t3cs'],
        );

        self::assertFalse($this->gate->grantsModuleTab($user, 't3cs', 'Dashboard'));
        self::assertFalse($this->gate->grantsModuleTab($user, 't3cs', 'DataSource'));
        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'Chatbot'));
        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'UsageAnalytics'));
        self::assertFalse($this->gate->grantsModuleTab($user, 't3cs', 'Search'));
    }

    public function testT3CsSearchTabRequiresSearchFeatureBit(): void
    {
        $user = $this->user(
            customOptions: 'T3Ai:T3CS,T3Ai:T3CS.Search',
            modules: ['nitsan_nst3cs_t3cs'],
        );

        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'Search'));
        self::assertFalse($this->gate->grantsModuleTab($user, 't3cs', 'DataSource'));
    }

    public function testT3CsManageFeatureBitsGrantTabs(): void
    {
        $user = $this->user(
            customOptions: 'T3Ai:T3CS,T3Ai:T3CS.Chat.Manage,T3Ai:T3CS.Search.Manage,T3Ai:T3CS.Index.Manage,T3Ai:T3CS.Analytics.Manage',
            modules: ['nitsan_nst3cs_t3cs'],
        );

        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'Dashboard'));
        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'DataSource'));
        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'Chatbot'));
        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'Search'));
        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'UsageAnalytics'));
    }

    public function testT3CsLegacyLogsBitGrantsUsageAnalyticsTab(): void
    {
        $user = $this->user(
            customOptions: 'T3Ai:T3CS,T3Ai:T3CS.Logs',
            modules: ['nitsan_nst3cs_t3cs'],
        );

        self::assertTrue($this->gate->grantsModuleTab($user, 't3cs', 'UsageAnalytics'));
    }

    public function testT3AiTabDeniedWhenFeatureMissing(): void
    {
        $user = $this->user(customOptions: 'T3Ai:Translation');

        self::assertFalse($this->gate->grantsModuleTab($user, 't3ai', 'content'));
        self::assertFalse($this->gate->grantsModuleCard($user, 't3ai', 'content', 'contentRewriter'));
    }

    public function testBulkTranslationTabRequiresTranslationAndBulkOps(): void
    {
        $translationOnly = $this->user(customOptions: 'T3Ai:Translation');
        self::assertFalse($this->gate->grantsModuleTab($translationOnly, 't3ai', 'bulkTranslation'));

        $withBulkOps = $this->user(customOptions: 'T3Ai:Translation,T3Ai:Pages');
        self::assertTrue($this->gate->grantsModuleTab($withBulkOps, 't3ai', 'bulkTranslation'));
    }

    public function testBulkSeoTabRequiresSeoAndBulkOps(): void
    {
        $seoOnly = $this->user(customOptions: 'T3Ai:SEO');
        self::assertFalse($this->gate->grantsModuleTab($seoOnly, 't3ai', 'bulkSeo'));

        $withBulkOps = $this->user(customOptions: 'T3Ai:SEO,T3Ai:Pages');
        self::assertTrue($this->gate->grantsModuleTab($withBulkOps, 't3ai', 'bulkSeo'));
    }

    public function testTranslationManageBitGrantsTabAccess(): void
    {
        $user = $this->user(customOptions: 'T3Ai:Translation.Manage');

        self::assertTrue($this->gate->grantsModuleTab($user, 't3ai', 'translation'));
    }

    public function testT3AaManageBitGrantsDashboardTabAndMediaCard(): void
    {
        $user = $this->user(
            customOptions: 'T3Ai:T3AA,T3Ai:T3AA.Media.Manage',
            modules: ['nitsan_nst3aa_dashboard'],
        );

        self::assertTrue($this->gate->grantsModuleTab($user, 't3aa', 'dashboard'));
        self::assertTrue($this->gate->grantsModuleCard($user, 't3aa', 'dashboard', 'mediaAiVoiceOver'));
        self::assertFalse($this->gate->grantsModuleCard($user, 't3aa', 'dashboard', 'seoSpeedCore'));
    }

    public function testT3AaLegacyCardStillGrantsAccess(): void
    {
        $user = $this->user(
            customOptions: 'tx_t3aa_dashboard:aiFileMetaAltText',
            modules: ['nitsan_nst3aa_dashboard'],
        );

        self::assertTrue($this->gate->grantsModuleCard($user, 't3aa', 'dashboard', 'aiFileMetaAltText'));
    }

    private function user(string $customOptions, array $modules = []): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->groupData = ['custom_options' => $customOptions];
        $user->method('check')->willReturnCallback(
            static function (string $type, string $value) use ($customOptions, $modules): bool {
                if ($type === 'custom_options') {
                    return str_contains($customOptions, $value);
                }
                if ($type === 'modules') {
                    return in_array($value, $modules, true);
                }

                return false;
            },
        );

        return $user;
    }
}
