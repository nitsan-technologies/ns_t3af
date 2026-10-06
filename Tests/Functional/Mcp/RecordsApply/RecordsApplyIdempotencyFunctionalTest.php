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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyIdempotency;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Real database: a retried records_apply call with the same requestId is applied once.
 */
final class RecordsApplyIdempotencyFunctionalTest extends FunctionalTestCase
{
    private const SITE_ROOT_PAGE_ID = 1;

    private const ADMIN_UID = 1;

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

    private RecordsApplyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);
        $this->setUpBackendUser(self::ADMIN_UID);

        $this->service = $this->get(RecordsApplyService::class);
    }

    #[Test]
    public function theSameRequestIdWithTheSamePayloadIsAppliedOnce(): void
    {
        $data = $this->oneElement('Applied once');

        $first = $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-1');
        $second = $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-1');

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->batchId, $second->batchId);
        self::assertSame($first->created, $second->created);
        self::assertTrue($second->written);
        self::assertSame(1, $this->countContent('Applied once'));
    }

    #[Test]
    public function aDifferentRequestIdIsANewRequest(): void
    {
        $data = $this->oneElement('Twice');

        $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-a');
        $second = $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-b');

        self::assertFalse($second->replayed);
        self::assertSame(2, $this->countContent('Twice'));
    }

    #[Test]
    public function withoutARequestIdEveryCallWrites(): void
    {
        $data = $this->oneElement('No id');

        $this->service->apply($data, [], false, true, true);
        $this->service->apply($data, [], false, true, true);

        self::assertSame(2, $this->countContent('No id'));
        self::assertSame(0, $this->countIdempotencyRows());
    }

    #[Test]
    public function theSameRequestIdWithAnotherPayloadIsRefusedAndWritesNothing(): void
    {
        $this->service->apply($this->oneElement('Original'), [], false, true, true, [], 'records_apply', 'req-2');

        try {
            $this->service->apply($this->oneElement('Different'), [], false, true, true, [], 'records_apply', 'req-2');
            self::fail('Expected the reused request id to be refused.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500012, $exception->getCode());
        }

        self::assertSame(1, $this->countContent('Original'));
        self::assertSame(0, $this->countContent('Different'));
    }

    #[Test]
    public function aFailedCallFreesTheRequestIdForARetry(): void
    {
        $broken = ['be_groups' => ['NEWgroup' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Nowhere']]];

        try {
            $this->service->apply($broken, [], false, true, false, [], 'records_apply', 'req-3');
            self::fail('Expected DataHandler to refuse the root-level record below a page.');
        } catch (ToolCallException) {
            // expected
        }

        self::assertSame(0, $this->countIdempotencyRows());

        // The corrected request may reuse the id: the failed call never counted.
        $result = $this->service->apply($this->oneElement('Fixed'), [], false, true, true, [], 'records_apply', 'req-3');

        self::assertFalse($result->replayed);
        self::assertSame(1, $this->countContent('Fixed'));
    }

    #[Test]
    public function aRefusedRequestDoesNotTakeTheRequestId(): void
    {
        try {
            $this->service->apply(['tt_content' => ['NEWc' => ['pid' => 99999, 'CType' => 'text']]], [], false, true, true, [], 'records_apply', 'req-4');
            self::fail('Expected the preflight to refuse the missing page.');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(0, $this->countIdempotencyRows());
    }

    #[Test]
    public function aDryRunNeitherStoresNorReplaysARequestId(): void
    {
        $data = $this->oneElement('Dry then real');

        $dry = $this->service->apply($data, [], true, true, true, [], 'records_apply', 'req-5');
        self::assertFalse($dry->written);
        self::assertSame(0, $this->countIdempotencyRows());

        $real = $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-5');
        self::assertFalse($real->replayed);
        self::assertTrue($real->written);

        // And once the real run is stored, a dry run with the same id is still a dry run.
        $dryAgain = $this->service->apply($data, [], true, true, true, [], 'records_apply', 'req-5');
        self::assertFalse($dryAgain->replayed);
        self::assertFalse($dryAgain->written);
        self::assertSame(1, $this->countContent('Dry then real'));
    }

    #[Test]
    public function aRetriedDeleteIsAnsweredFromTheStoredResultInsteadOfBeingRefused(): void
    {
        $created = $this->service->apply($this->oneElement('To delete'), [], false, true, true);
        $uid = $created->created['NEWc'];
        $cmd = ['tt_content' => [$uid => ['delete' => 1]]];

        $first = $this->service->apply([], $cmd, false, true, true, [], 'records_apply', 'req-6');
        // The record is gone now; sent again, the same request would not pass validation. The id answers it.
        $second = $this->service->apply([], $cmd, false, true, true, [], 'records_apply', 'req-6');

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame(['tt_content' => ['delete' => 1]], $second->operations);
    }

    #[Test]
    public function aCallThatIsStillRunningIsNotStartedASecondTime(): void
    {
        $data = $this->oneElement('Parallel');
        $this->insertRow('req-7', RecordsApplyIdempotency::payloadHash($data, [], [], true, true), 'pending', time(), time());

        try {
            $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-7');
            self::fail('Expected the parallel call to be refused.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500013, $exception->getCode());
        }

        self::assertSame(0, $this->countContent('Parallel'));
    }

    #[Test]
    public function aPendingRowOfADeadCallIsTakenOver(): void
    {
        $data = $this->oneElement('Takeover');
        $old = time() - RecordsApplyIdempotency::PENDING_TIMEOUT - 60;
        $this->insertRow('req-8', RecordsApplyIdempotency::payloadHash($data, [], [], true, true), 'pending', $old, $old);

        $result = $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-8');

        self::assertFalse($result->replayed);
        self::assertSame(1, $this->countContent('Takeover'));
        self::assertSame('done', $this->stateOf('req-8'));
    }

    #[Test]
    public function aFinishedRowPastItsLifetimeIsForgotten(): void
    {
        $data = $this->oneElement('Forgotten');
        $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-9');
        $this->getConnectionPool()->getConnectionForTable(RecordsApplyIdempotency::TABLE)
            ->update(RecordsApplyIdempotency::TABLE, ['crdate' => time() - 30 * 86400], ['request_id' => 'req-9']);

        $again = $this->service->apply($data, [], false, true, true, [], 'records_apply', 'req-9');

        self::assertFalse($again->replayed);
        self::assertSame(2, $this->countContent('Forgotten'));
    }

    #[Test]
    public function theCleanupRemovesExpiredFinishedRowsAndDeadPendingRowsOnly(): void
    {
        $now = time();
        $this->insertRow('fresh-done', 'h', 'done', $now, $now);
        $this->insertRow('old-done', 'h', 'done', $now - 30 * 86400, $now - 30 * 86400);
        $this->insertRow('fresh-pending', 'h', 'pending', $now, $now);
        $this->insertRow('dead-pending', 'h', 'pending', $now - 3600, $now - 3600);

        $deleted = $this->get(RecordsApplyIdempotency::class)->deleteExpired();

        self::assertSame(2, $deleted);
        self::assertSame('done', $this->stateOf('fresh-done'));
        self::assertSame('pending', $this->stateOf('fresh-pending'));
        self::assertNull($this->stateOf('old-done'));
        self::assertNull($this->stateOf('dead-pending'));
    }

    #[Test]
    public function aMalformedRequestIdIsRefusedBeforeAnythingHappens(): void
    {
        try {
            $this->service->apply($this->oneElement('Bad id'), [], false, true, true, [], 'records_apply', 'no spaces allowed');
            self::fail('Expected the request id to be refused.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500014, $exception->getCode());
        }

        self::assertSame(0, $this->countContent('Bad id'));
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function oneElement(string $header): array
    {
        return ['tt_content' => ['NEWc' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => $header]]];
    }

    private function countContent(string $header): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery('SELECT COUNT(*) FROM tt_content WHERE header = ? AND deleted = 0', [$header])
            ->fetchOne();
    }

    private function countIdempotencyRows(): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable(RecordsApplyIdempotency::TABLE)
            ->executeQuery('SELECT COUNT(*) FROM ' . RecordsApplyIdempotency::TABLE)
            ->fetchOne();
    }

    private function stateOf(string $requestId): ?string
    {
        $state = $this->getConnectionPool()->getConnectionForTable(RecordsApplyIdempotency::TABLE)
            ->executeQuery('SELECT state FROM ' . RecordsApplyIdempotency::TABLE . ' WHERE request_id = ?', [$requestId])
            ->fetchOne();

        return is_string($state) ? $state : null;
    }

    private function insertRow(string $requestId, string $hash, string $state, int $crdate, int $tstamp): void
    {
        $this->getConnectionPool()->getConnectionForTable(RecordsApplyIdempotency::TABLE)->insert(RecordsApplyIdempotency::TABLE, [
            'be_user_uid' => self::ADMIN_UID,
            'tool' => 'records_apply',
            'request_id' => $requestId,
            'payload_hash' => $hash,
            'state' => $state,
            'lock_token' => 'tok' . $requestId,
            'response' => $state === 'done' ? '{"batchId":"ra-x"}' : '',
            'crdate' => $crdate,
            'tstamp' => $tstamp,
        ]);
    }
}
