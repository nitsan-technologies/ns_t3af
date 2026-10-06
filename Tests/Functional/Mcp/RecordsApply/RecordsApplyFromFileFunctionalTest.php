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

namespace NITSAN\NsT3AF\Tests\Functional\Mcp\RecordsApply;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileSource;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Real file storage: who may read a payload file, and which files are accepted.
 */
final class RecordsApplyFromFileFunctionalTest extends FunctionalTestCase
{
    private const SITE_ROOT_PAGE_ID = 1;

    private const ADMIN_UID = 1;

    private const EDITOR_UID = 2;

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

    private RecordsApplyFileSource $source;

    private ResourceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);
        $this->setUpBackendUser(self::ADMIN_UID);

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage('Payloads', 'fileadmin/', 'relative', '', true);
        $storage = $storageRepository->findByUid($storageUid);
        self::assertInstanceOf(ResourceStorage::class, $storage);
        $this->storage = $storage;

        $this->source = $this->get(RecordsApplyFileSource::class);
    }

    #[Test]
    public function anAdminReadsAPayloadFile(): void
    {
        $file = $this->createFile('batch.json', '{"data":{"tt_content":{"NEWc":{"pid":1,"CType":"text","header":"From a file"}}}}');

        [$data, $cmd, $bulk] = $this->source->read($file->getUid());

        self::assertSame('From a file', $data['tt_content']['NEWc']['header']);
        self::assertSame([], $cmd);
        self::assertSame([], $bulk);
    }

    #[Test]
    public function aFileThatIsNotJsonByNameIsRefused(): void
    {
        $file = $this->createFile('batch.txt', '{"data":{"tt_content":{"NEWc":{"pid":1}}}}');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500028);

        $this->source->read($file->getUid());
    }

    #[Test]
    public function aMissingFileIsRefusedWithTheSameAnswerAsAnInaccessibleOne(): void
    {
        try {
            $this->source->read(99999);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500027, $exception->getCode());
        }
    }

    #[Test]
    public function aBackendUserWithoutAccessToTheStorageCannotReadTheFile(): void
    {
        $file = $this->createFile('private.json', '{"data":{"tt_content":{"NEWc":{"pid":1}}}}');
        $this->setUpBackendUser(self::EDITOR_UID);

        try {
            $this->source->read($file->getUid());
            self::fail('Expected a refusal.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500027, $exception->getCode());
            self::assertStringNotContainsString('tt_content', $exception->getMessage());
        }
    }

    #[Test]
    public function aFileWithBrokenContentIsRefused(): void
    {
        $file = $this->createFile('broken.json', '{"data": [');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500030);

        $this->source->read($file->getUid());
    }

    private function createFile(string $name, string $contents): File
    {
        $file = $this->storage->createFile($name, $this->storage->getRootLevelFolder());
        self::assertInstanceOf(File::class, $file);
        $this->storage->setFileContents($file, $contents);

        return $file;
    }
}
