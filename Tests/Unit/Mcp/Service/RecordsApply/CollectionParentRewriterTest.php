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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\RecordsApply;

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\CollectionParentRewriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CollectionParentRewriterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    private CollectionParentRewriter $rewriter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA'] = [
            'tt_content' => [
                'columns' => [
                    'nitsan_nsfaq_faq' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'nitsan_nsfaq_faq',
                            'foreign_field' => 'foreign_table_parent_uid',
                        ],
                    ],
                ],
            ],
            'nitsan_nsfaq_faq' => [
                'columns' => [
                    'title' => ['config' => ['type' => 'input']],
                    'foreign_table_parent_uid' => ['config' => ['type' => 'passthrough']],
                ],
            ],
        ];
        $this->rewriter = new CollectionParentRewriter();
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        parent::tearDown();
    }

    #[Test]
    public function aNewParentFromThisBatchIsRewrittenToItsUid(): void
    {
        $updates = $this->rewriter->updates(
            [
                'tt_content' => [
                    'NEWce' => ['pid' => 12, 'CType' => 'nitsan_NsFaq'],
                ],
                'nitsan_nsfaq_faq' => [
                    'NEWq1' => ['pid' => 12, 'title' => 'Q1', 'foreign_table_parent_uid' => 'NEWce'],
                    'NEWq2' => ['pid' => '-NEWq1', 'title' => 'Q2', 'foreign_table_parent_uid' => 'NEWce'],
                ],
            ],
            ['NEWce' => 1781, 'NEWq1' => 1, 'NEWq2' => 2],
        );

        self::assertSame([
            ['table' => 'nitsan_nsfaq_faq', 'uid' => 1, 'field' => 'foreign_table_parent_uid', 'value' => 1781],
            ['table' => 'nitsan_nsfaq_faq', 'uid' => 2, 'field' => 'foreign_table_parent_uid', 'value' => 1781],
        ], $updates);
    }

    #[Test]
    public function aFileReferenceParentFromThisBatchIsRewrittenOnUidForeign(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'columns' => [
                'media' => [
                    'config' => [
                        'type' => 'file',
                        'foreign_table' => 'sys_file_reference',
                        'foreign_field' => 'uid_foreign',
                    ],
                ],
            ],
        ];
        $GLOBALS['TCA']['sys_file_reference'] = [
            'columns' => [
                'uid_foreign' => ['config' => ['type' => 'passthrough']],
                'uid_local' => ['config' => ['type' => 'passthrough']],
            ],
        ];

        $updates = $this->rewriter->updates(
            [
                'pages' => [
                    'NEWp' => ['pid' => 1, 'title' => 'With image'],
                ],
                'sys_file_reference' => [
                    'NEWref' => ['uid_local' => 55, 'uid_foreign' => 'NEWp', 'tablenames' => 'pages', 'fieldname' => 'media'],
                ],
            ],
            ['NEWp' => 99, 'NEWref' => 12],
        );

        self::assertSame([
            ['table' => 'sys_file_reference', 'uid' => 12, 'field' => 'uid_foreign', 'value' => 99],
        ], $updates);
    }

    #[Test]
    public function aPlainIntegerIncludingZeroIsLeftAlone(): void
    {
        $updates = $this->rewriter->updates(
            [
                'nitsan_nsfaq_faq' => [
                    'NEWq1' => ['pid' => 12, 'foreign_table_parent_uid' => 0],
                    'NEWq2' => ['pid' => 12, 'foreign_table_parent_uid' => 1781],
                    'NEWq3' => ['pid' => 12, 'foreign_table_parent_uid' => '0'],
                    'NEWq4' => ['pid' => 12, 'foreign_table_parent_uid' => 'NEWmissing'],
                ],
            ],
            ['NEWq1' => 1, 'NEWq2' => 2, 'NEWq3' => 3, 'NEWq4' => 4, 'NEWce' => 1781],
        );

        self::assertSame([], $updates);
    }
}
