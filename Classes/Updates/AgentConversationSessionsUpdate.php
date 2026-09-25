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

namespace NITSAN\NsT3AF\Updates;

use NITSAN\NsT3AF\Domain\Repository\AgentConversationRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Turns the old one-conversation-per-page rows into sessions: uuid, title, activity and
 * message count. Also drops the old UNIQUE key on (user, module, page) if the schema
 * update left it, so a page can hold several conversations.
 */
#[UpgradeWizard('nst3afAgentConversationSessions')]
final class AgentConversationSessionsUpdate implements UpgradeWizardInterface
{
    private const TITLE_LENGTH = 60;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'AI Foundation: AI Agent conversations as sessions';
    }

    public function getDescription(): string
    {
        return 'Gives every existing AI Agent conversation a session id and a title (its first message),'
            . ' so it appears in the new conversation list. Several conversations per page become possible.';
    }

    public function executeUpdate(): bool
    {
        $this->dropLegacyUniqueScopeKey();

        $connection = $this->connection();
        $qb = $connection->createQueryBuilder();
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'messages', 'tstamp', 'page_id')
            ->from(AgentConversationRepository::TABLE)
            ->where($qb->expr()->eq('session_uuid', $qb->createNamedParameter('')))
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $messages = json_decode((string) ($row['messages'] ?? ''), true);
            $messages = is_array($messages) ? array_values(array_filter($messages, 'is_array')) : [];
            $connection->update(
                AgentConversationRepository::TABLE,
                [
                    'session_uuid' => AgentConversationRepository::newUuid(),
                    'title' => self::titleFromMessages($messages, (int) $row['page_id']),
                    'message_count' => count($messages),
                    'last_activity' => (int) $row['tstamp'],
                ],
                ['uid' => (int) $row['uid']],
            );
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        if (!$this->hasColumn('session_uuid')) {
            return false;
        }
        $qb = $this->connection()->createQueryBuilder();
        $qb->getRestrictions()->removeAll();
        $count = (int) $qb->count('uid')
            ->from(AgentConversationRepository::TABLE)
            ->where($qb->expr()->eq('session_uuid', $qb->createNamedParameter('')))
            ->executeQuery()
            ->fetchOne();

        return $count > 0 || $this->hasLegacyUniqueScopeKey();
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    /**
     * First user message, whitespace collapsed, cut to 60 characters.
     *
     * @param list<array<mixed>> $messages
     */
    public static function titleFromMessages(array $messages, int $pageId): string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($message['content'] ?? '')));
            if ($text !== '') {
                return mb_strlen($text) > self::TITLE_LENGTH ? rtrim(mb_substr($text, 0, self::TITLE_LENGTH - 1)) . '…' : $text;
            }
        }

        return $pageId > 0 ? 'Conversation on page ' . $pageId : 'Conversation';
    }

    private function hasColumn(string $column): bool
    {
        $columns = $this->connection()->createSchemaManager()->listTableColumns(AgentConversationRepository::TABLE);

        return isset($columns[$column]);
    }

    private function hasLegacyUniqueScopeKey(): bool
    {
        $indexes = $this->connection()->createSchemaManager()->listTableIndexes(AgentConversationRepository::TABLE);

        return isset($indexes['agent_conv_scope']) && $indexes['agent_conv_scope']->isUnique();
    }

    private function dropLegacyUniqueScopeKey(): void
    {
        if (!$this->hasLegacyUniqueScopeKey()) {
            return;
        }
        $connection = $this->connection();
        $table = $connection->quoteIdentifier(AgentConversationRepository::TABLE);
        $connection->executeStatement('ALTER TABLE ' . $table . ' DROP INDEX ' . $connection->quoteIdentifier('agent_conv_scope'));
        $connection->executeStatement(
            'ALTER TABLE ' . $table . ' ADD INDEX ' . $connection->quoteIdentifier('agent_conv_scope')
            . ' (be_user_uid, module_route, page_id, deleted, last_activity)',
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(AgentConversationRepository::TABLE);
    }
}
