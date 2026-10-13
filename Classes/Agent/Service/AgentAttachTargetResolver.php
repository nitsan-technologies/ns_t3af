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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Agent\Contract\AgentAttachTargetResolverInterface;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Finds the one record (news article or content element) an attach request names, and the file field of it.
 * More than one match, or none, is not decided here: the model asks or answers as before.
 *
 * @internal
 */
final readonly class AgentAttachTargetResolver implements AgentAttachTargetResolverInterface
{
    private const NEWS_TABLE = 'tx_news_domain_model_news';

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    /**
     * @param array{kind: 'news'|'content', uid?: int, title?: string} $target
     * @return array{table: string, uid: int, fieldName: string}|null
     */
    public function resolve(array $target, int $openPageId): ?array
    {
        try {
            return $target['kind'] === 'news'
                ? $this->resolveNews($target)
                : $this->resolveContent($target, $openPageId);
        } catch (\Throwable) {
            // No EXT:news table, a broken TCA: leave the request to the model.
            return null;
        }
    }

    /**
     * @param array{kind: string, uid?: int, title?: string} $target
     * @return array{table: string, uid: int, fieldName: string}|null
     */
    private function resolveNews(array $target): ?array
    {
        $query = $this->connectionPool->getQueryBuilderForTable(self::NEWS_TABLE);
        $query->getRestrictions()->removeAll();
        $query->select('uid')->from(self::NEWS_TABLE)
            ->where($query->expr()->eq('deleted', 0), $query->expr()->eq('sys_language_uid', 0))
            ->setMaxResults(3);
        if (isset($target['uid'])) {
            $query->andWhere($query->expr()->eq('uid', $query->createNamedParameter((int) $target['uid'], Connection::PARAM_INT)));
        } else {
            $title = trim((string) ($target['title'] ?? ''));
            if ($title === '') {
                return null;
            }
            // Exact first (case-insensitive), then a unique contains match — titles from chat often differ in case.
            $query->andWhere($query->expr()->comparison(
                'LOWER(' . $query->quoteIdentifier('title') . ')',
                '=',
                $query->createNamedParameter(mb_strtolower($title)),
            ));
        }
        $rows = $query->executeQuery()->fetchAllAssociative();
        if (count($rows) !== 1 && !isset($target['uid'])) {
            $rows = $this->newsByTitleContains(trim((string) ($target['title'] ?? '')));
        }
        if (count($rows) !== 1) {
            return null;
        }

        return ['table' => self::NEWS_TABLE, 'uid' => (int) $rows[0]['uid'], 'fieldName' => 'fal_media'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function newsByTitleContains(string $title): array
    {
        if ($title === '') {
            return [];
        }
        $query = $this->connectionPool->getQueryBuilderForTable(self::NEWS_TABLE);
        $query->getRestrictions()->removeAll();
        $query->select('uid')->from(self::NEWS_TABLE)
            ->where(
                $query->expr()->eq('deleted', 0),
                $query->expr()->eq('sys_language_uid', 0),
                $query->expr()->like(
                    'LOWER(' . $query->quoteIdentifier('title') . ')',
                    $query->createNamedParameter('%' . $query->escapeLikeWildcards(mb_strtolower($title)) . '%'),
                ),
            )
            ->setMaxResults(3);

        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array{kind: string, uid?: int, title?: string} $target
     * @return array{table: string, uid: int, fieldName: string}|null
     */
    private function resolveContent(array $target, int $openPageId): ?array
    {
        $query = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $query->getRestrictions()->removeAll();
        $query->select('uid', 'CType')->from('tt_content')
            ->where($query->expr()->eq('deleted', 0), $query->expr()->eq('sys_language_uid', 0))
            ->setMaxResults(3);
        if (isset($target['uid'])) {
            $query->andWhere($query->expr()->eq('uid', $query->createNamedParameter((int) $target['uid'], Connection::PARAM_INT)));
        } else {
            if ($openPageId <= 0) {
                return null;
            }
            $title = trim((string) ($target['title'] ?? ''));
            if ($title === '') {
                return null;
            }
            $query->andWhere(
                $query->expr()->eq('pid', $query->createNamedParameter($openPageId, Connection::PARAM_INT)),
                $query->expr()->comparison(
                    'LOWER(' . $query->quoteIdentifier('header') . ')',
                    '=',
                    $query->createNamedParameter(mb_strtolower($title)),
                ),
            );
        }
        $rows = $query->executeQuery()->fetchAllAssociative();
        if (count($rows) !== 1 && !isset($target['uid']) && $openPageId > 0) {
            $rows = $this->contentByHeaderContains($openPageId, trim((string) ($target['title'] ?? '')));
        }
        if (count($rows) !== 1) {
            return null;
        }
        $fieldName = $this->contentFileField((string) $rows[0]['CType']);

        return $fieldName === null ? null : ['table' => 'tt_content', 'uid' => (int) $rows[0]['uid'], 'fieldName' => $fieldName];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentByHeaderContains(int $openPageId, string $title): array
    {
        if ($title === '') {
            return [];
        }
        $query = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $query->getRestrictions()->removeAll();
        $query->select('uid', 'CType')->from('tt_content')
            ->where(
                $query->expr()->eq('deleted', 0),
                $query->expr()->eq('sys_language_uid', 0),
                $query->expr()->eq('pid', $query->createNamedParameter($openPageId, Connection::PARAM_INT)),
                $query->expr()->like(
                    'LOWER(' . $query->quoteIdentifier('header') . ')',
                    $query->createNamedParameter('%' . $query->escapeLikeWildcards(mb_strtolower($title)) . '%'),
                ),
            )
            ->setMaxResults(3);

        return $query->executeQuery()->fetchAllAssociative();
    }

    private function contentFileField(string $cType): ?string
    {
        $usable = array_values(array_intersect(
            $this->tcaSchemaService->getFileFields('tt_content'),
            $this->tcaSchemaService->getShownFields('tt_content', $cType),
        ));
        if ($usable === []) {
            return null;
        }

        return self::pickField($cType, $usable);
    }

    /**
     * @param list<string> $usable file fields the element type shows
     */
    public static function pickField(string $cType, array $usable): ?string
    {
        if ($usable === []) {
            return null;
        }
        $preferred = $cType === 'textmedia' ? 'assets' : 'image';
        if (in_array($preferred, $usable, true)) {
            return $preferred;
        }

        return count($usable) === 1 ? $usable[0] : null;
    }
}
