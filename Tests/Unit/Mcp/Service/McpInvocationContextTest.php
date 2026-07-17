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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Mcp\Service\McpInvocationContext;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class McpInvocationContextTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function enrichAiOptionsOverridesProviderIdentifier(): void
    {
        $context = new McpInvocationContext($this->createMock(WorkspaceListService::class));
        $context->applyFromArguments(['aiProvider' => 'custom.provider']);

        $options = $context->enrichAiOptions(new AiOptions(
            providerIdentifier: 'default.provider',
            modelId: 'gpt-4',
        ));

        self::assertSame('custom.provider', $options->providerIdentifier);
        self::assertSame('gpt-4', $options->modelId);
    }

    #[Test]
    public function applyFromArgumentsTreatsCreditsProviderAsDefaultRoute(): void
    {
        $context = new McpInvocationContext($this->createMock(WorkspaceListService::class));
        $context->applyFromArguments(['aiProvider' => CreditsProviderIdentifier::IDENTIFIER]);

        $options = $context->enrichAiOptions(new AiOptions(
            providerIdentifier: 'default.provider',
            modelId: 'gpt-4',
        ));

        self::assertSame('default.provider', $options->providerIdentifier);
    }

    #[Test]
    public function assertProviderIsUsableRejectsDisconnectedProvider(): void
    {
        $lookup = $this->createMock(ProviderLookupInterface::class);
        $lookup->method('findByIdentifier')->willReturn(new Provider(
            uid: 1,
            pid: 0,
            identifier: 'offline.provider',
            title: 'Offline',
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4',
            embeddingModelId: '',
            capabilities: ['chat'],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: 'error',
            lastStatusAt: 0,
            lastStatusMessage: '',
            isEnabled: true,
        ));

        $context = new McpInvocationContext($this->createMock(WorkspaceListService::class));
        $context->applyFromArguments(['aiProvider' => 'offline.provider']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not connected');

        $context->assertProviderIsUsable($lookup);
    }

    #[Test]
    public function applyFromArgumentsRejectsNegativeWorkspaceId(): void
    {
        $GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);

        $context = new McpInvocationContext($this->createMock(WorkspaceListService::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('workspaceId must be zero or a positive');

        $context->applyFromArguments(['workspaceId' => -1]);
    }

    #[Test]
    public function applyFromArgumentsIgnoresZeroWorkspaceIdToPreserveBackendPreference(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->workspace = 1;
        $backendUser->expects(self::never())->method('setWorkspace');
        $GLOBALS['BE_USER'] = $backendUser;

        $context = new McpInvocationContext($this->createMock(WorkspaceListService::class));
        $context->applyFromArguments(['workspaceId' => 0]);

        self::assertSame(1, $backendUser->workspace);
    }
}
