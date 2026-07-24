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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\OpenAiCompatible;

use GuzzleHttp\Psr7\Response;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatibleAdapter;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiCompatibleAdapterTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('z', 32);
    }

    protected function tearDown(): void
    {
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testTestConnectionFailsWhenEndpointMissing(): void
    {
        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $this->createMock(RequestFactory::class));
        $provider = $this->makeProvider(endpointUrl: '', apiKeyCipher: $cipher->encrypt('k'));

        $result = $adapter->testConnection($provider);

        self::assertFalse($result->ok);
        self::assertStringContainsString('Endpoint', (string) $result->message);
    }

    public function testTestConnectionOkListsModels(): void
    {
        $cipher = new CredentialCipher();
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())->method('request')->willReturn(
            new Response(200, [], '{"data":[{"id":"alpha"},{"id":"beta"}]}'),
        );

        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            endpointUrl: 'https://example.com/v1',
            apiKeyCipher: $cipher->encrypt('secret'),
        );

        $result = $adapter->testConnection($provider);

        self::assertTrue($result->ok);
        self::assertSame(['alpha', 'beta'], $result->models);
    }

    private function makeProvider(string $endpointUrl, string $apiKeyCipher): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'p',
            title: 'P',
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: $endpointUrl,
            apiKeyCipher: $apiKeyCipher,
            modelId: 'm',
            embeddingModelId: '',
            capabilities: [Capability::CHAT],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
            beGroups: [],
        );
    }
}
