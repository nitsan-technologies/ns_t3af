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

namespace NITSAN\NsT3AF\Tests\Functional\Mcp\File;

use NITSAN\NsT3AF\Mcp\Http\FileUploadEndpoint;
use NITSAN\NsT3AF\Mcp\Service\FileUploadService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Pre-signed upload token lifecycle + /mcp_upload-style endpoint consume.
 *
 * @internal
 */
final class FileUploadTokenFunctionalTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
        'workspaces',
        'scheduler',
    ];

    protected array $testExtensionsToLoad = [
        'ns_t3af',
    ];

    /**
     * @var array<string, non-empty-string>
     */
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/ns_t3af/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
    }

    #[Test]
    public function consumeUploadTokenIsSingleUse(): void
    {
        $plaintext = bin2hex(random_bytes(16));
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nst3af_upload_tokens');
        $connection->insert('tx_nst3af_upload_tokens', [
            'token' => hash('sha256', $plaintext),
            'be_user_uid' => 1,
            'storage_uid' => 1,
            'target_folder' => '/',
            'file_name' => 'fixture.txt',
            'expires' => time() + 900,
            'used' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        /** @var FileUploadService $service */
        $service = $this->get(FileUploadService::class);

        $first = $service->consumeUploadToken($plaintext);
        self::assertIsArray($first);
        self::assertSame('fixture.txt', $first['file_name']);

        $second = $service->consumeUploadToken($plaintext);
        self::assertNull($second);

        $row = $connection->select(['used'], 'tx_nst3af_upload_tokens', [
            'token' => hash('sha256', $plaintext),
        ])->fetchAssociative();
        self::assertIsArray($row);
        self::assertGreaterThan(0, (int) $row['used']);
    }

    #[Test]
    public function uploadEndpointRejectsMissingToken(): void
    {
        /** @var FileUploadEndpoint $endpoint */
        $endpoint = $this->get(FileUploadEndpoint::class);

        $body = new Stream('php://temp', 'rw');
        $body->write('hello');
        $body->rewind();

        $request = (new ServerRequest('http://localhost/mcp_upload', 'PUT'))
            ->withBody($body)
            ->withHeader('Content-Type', 'application/octet-stream');

        $response = $endpoint($request);

        self::assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('error', $payload);
    }
}
