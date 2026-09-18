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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TcaSchemaServiceRelationFieldsTest extends TestCase
{
    private TcaSchemaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TcaSchemaService();
        $GLOBALS['TCA'] = [
            'pages' => [
                'ctrl' => [
                    'label' => 'title',
                    'tstamp' => 'tstamp',
                ],
                'columns' => [
                    'title' => [
                        'label' => 'Title',
                        'config' => ['type' => 'input'],
                    ],
                    'categories' => [
                        'label' => 'Categories',
                        'config' => ['type' => 'category'],
                    ],
                    'authors' => [
                        'label' => 'Authors',
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'tx_blog_domain_model_author',
                            'MM' => 'tx_blog_post_author_mm',
                        ],
                    ],
                    'tags' => [
                        'label' => 'Tags',
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'tx_blog_domain_model_tag',
                            'MM' => 'tx_blog_tag_mm',
                        ],
                    ],
                    'og_image' => [
                        'label' => 'OG image',
                        'config' => ['type' => 'file', 'allowed' => 'common-image-types'],
                    ],
                    'shortcut' => [
                        'label' => 'Shortcut',
                        'config' => ['type' => 'group', 'allowed' => 'pages'],
                    ],
                ],
            ],
            'tt_content' => [
                'ctrl' => ['label' => 'header'],
                'columns' => [
                    'header' => [
                        'label' => 'Header',
                        'config' => ['type' => 'input'],
                    ],
                    'assets' => [
                        'label' => 'Assets',
                        'config' => ['type' => 'file'],
                    ],
                    'nitsan_nsfaq_faq' => [
                        'label' => 'FAQ',
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'nitsan_nsfaq_faq',
                            'foreign_field' => 'foreign_table_parent_uid',
                        ],
                    ],
                ],
            ],
            'nitsan_nsfaq_faq' => [
                'ctrl' => ['label' => 'title'],
                'columns' => [
                    'title' => [
                        'label' => 'Title',
                        'config' => ['type' => 'input'],
                    ],
                    'foreign_table_parent_uid' => [
                        'config' => ['type' => 'passthrough'],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function categoryAndMmSelectAreWritableUidListFields(): void
    {
        $writable = $this->service->getWritableFields('pages');
        self::assertContains('categories', $writable);
        self::assertContains('authors', $writable);
        self::assertContains('tags', $writable);
        self::assertContains('title', $writable);
        self::assertNotContains('og_image', $writable);

        $relations = $this->service->getRelationUidListFields('pages');
        self::assertSame(['categories', 'authors', 'tags'], $relations);

        $readable = $this->service->getReadableRelationUidListFields('pages');
        self::assertSame(['categories', 'authors', 'tags'], $readable);
    }

    #[Test]
    public function fileAndCollectionAppearInSchemaWithWriteHints(): void
    {
        $schema = $this->service->getFieldsSchema('pages');
        $byName = [];
        foreach ($schema['fields'] as $field) {
            $byName[$field['name']] = $field;
        }

        self::assertArrayHasKey('og_image', $byName);
        self::assertSame('file', $byName['og_image']['type']);
        self::assertSame('file_references', $byName['og_image']['writableAs']);

        self::assertArrayHasKey('categories', $byName);
        self::assertSame('uid_list', $byName['categories']['writableAs']);

        $ttSchema = $this->service->getFieldsSchema('tt_content');
        $ttByName = [];
        foreach ($ttSchema['fields'] as $field) {
            $ttByName[$field['name']] = $field;
        }
        self::assertArrayHasKey('nitsan_nsfaq_faq', $ttByName);
        self::assertSame('collection', $ttByName['nitsan_nsfaq_faq']['type']);
        self::assertSame('nitsan_nsfaq_faq', $ttByName['nitsan_nsfaq_faq']['foreignTable']);
        self::assertFalse($ttByName['nitsan_nsfaq_faq']['writable']);
    }

    #[Test]
    public function describeIgnoredCollectionUsesChildTableConvention(): void
    {
        $detail = $this->service->describeIgnoredField('tt_content', 'nitsan_nsfaq_faq');
        self::assertSame('collection', $detail['reason']);
        self::assertSame('nitsan_nsfaq_faq', $detail['foreignTable'] ?? null);
        self::assertStringContainsString('foreign_table_parent_uid', $detail['hint']);
    }

    #[Test]
    public function describeIgnoredFileFieldPointsToReferenceApis(): void
    {
        $detail = $this->service->describeIgnoredField('pages', 'og_image');
        self::assertSame('file_field', $detail['reason']);
        self::assertStringContainsString('file_reference_add', $detail['hint']);
    }
}
