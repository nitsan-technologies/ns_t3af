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

namespace NITSAN\NsT3AF\Service;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SchedulerCliTaskService
{
    private const SCHEDULER_REPOSITORY_CLASS = 'TYPO3\\CMS\\Scheduler\\Domain\\Repository\\SchedulerTaskRepository';
    private const SCHEDULABLE_TASK_CLASS = 'TYPO3\\CMS\\Scheduler\\Task\\ExecuteSchedulableCommandTask';

    private readonly ?object $schedulerTaskRepository;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SchedulerCliCommandCatalogService $catalog,
    ) {
        $this->schedulerTaskRepository = $this->resolveSchedulerTaskRepository();
    }

    /**
     * @param array{status?:string} $filters
     * @return list<array<string, mixed>>
     */
    public function listTasks(array $filters): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_scheduler_task');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'description', 'disable', 'deleted', 'nextexecution', 'lastexecution_time', 'lastexecution_failure', 'serialized_task_object')
            ->from('tx_scheduler_task')
            ->where($qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $tasks = [];
        $status = trim((string) ($filters['status'] ?? 'all'));

        foreach ($rows as $row) {
            $task = $this->hydrateCommandTask($row);
            if ($task === null) {
                continue;
            }
            $command = $this->resolveTaskCommand($task);
            if ($command === '' || !$this->catalog->isSchedulerCliCommand($command)) {
                continue;
            }
            $execution = method_exists($task, 'getExecution') ? $task->getExecution() : null;
            $cronExpression = (is_object($execution) && method_exists($execution, 'getCronCmd'))
                ? (string) $execution->getCronCmd()
                : '';
            $interval = (is_object($execution) && method_exists($execution, 'getInterval'))
                ? (int) $execution->getInterval()
                : 0;
            $meta = $this->catalog->findByCommand($command);
            $record = [
                'uid' => (int) $row['uid'],
                'description' => (string) ($row['description'] ?? ''),
                'command' => $command,
                'commandName' => (string) ($meta['name'] ?? $command),
                'category' => (string) ($meta['category'] ?? 'Custom'),
                'arguments' => $task->getArguments(),
                'options' => $task->getOptions(),
                'optionValues' => $task->getOptionValues(),
                'disabled' => (int) ($row['disable'] ?? 0),
                'nextexecution' => (int) ($row['nextexecution'] ?? 0),
                'lastexecution' => (int) ($row['lastexecution_time'] ?? 0),
                'hasFailure' => trim((string) ($row['lastexecution_failure'] ?? '')) !== '' ? 1 : 0,
                'cronExpression' => $cronExpression,
                'interval' => $interval,
                'scheduleType' => $cronExpression !== '' ? 'cron' : 'interval',
            ];

            if ($status === 'enabled' && (int) $record['disabled'] === 1) {
                continue;
            }
            if ($status === 'disabled' && (int) $record['disabled'] === 0) {
                continue;
            }
            if ($status === 'failing' && (int) $record['hasFailure'] !== 1) {
                continue;
            }

            $tasks[] = $record;
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{ok:int,output:string,error:string}
     */
    public function runCommand(string $commandIdentifier, array $arguments = []): array
    {
        try {
            $commandRegistry = GeneralUtility::makeInstance(CommandRegistry::class);
            $command = $commandRegistry->getCommandByIdentifier($commandIdentifier);
            $input = new ArrayInput($arguments);
            $input->setInteractive(false);
            $output = new BufferedOutput();
            $code = $command->run($input, $output);

            return [
                'ok' => $code === 0 ? 1 : 0,
                'output' => trim($output->fetch()),
                'error' => $code === 0 ? '' : 'Exit code: ' . $code,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => 0,
                'output' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCommandTask(array $row): ?object
    {
        if ($this->schedulerTaskRepository === null) {
            return null;
        }
        try {
            $task = $this->schedulerTaskRepository->findByUid((int) ($row['uid'] ?? 0));
        } catch (\Throwable) {
            return null;
        }
        if (!$this->isSchedulableCommandTask($task)) {
            return null;
        }

        return $task;
    }

    private function resolveSchedulerTaskRepository(): ?object
    {
        if (!class_exists(self::SCHEDULER_REPOSITORY_CLASS)) {
            return null;
        }

        try {
            return GeneralUtility::makeInstance(self::SCHEDULER_REPOSITORY_CLASS);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSchedulableCommandTask(mixed $task): bool
    {
        return is_object($task) && class_exists(self::SCHEDULABLE_TASK_CLASS) && is_a($task, self::SCHEDULABLE_TASK_CLASS);
    }

    private function resolveTaskCommand(object $task): string
    {
        if (method_exists($task, 'getTaskType')) {
            return trim((string) $task->getTaskType());
        }
        if (method_exists($task, 'getCommandIdentifier')) {
            return trim((string) $task->getCommandIdentifier());
        }
        if (method_exists($task, 'getTaskParameters')) {
            $params = $task->getTaskParameters();
            if (is_array($params) && isset($params['commandIdentifier']) && is_scalar($params['commandIdentifier'])) {
                return trim((string) $params['commandIdentifier']);
            }
        }

        return '';
    }
}
