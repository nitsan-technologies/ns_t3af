<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


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

namespace NITSAN\NsT3AF\Mcp\Command;

use Doctrine\DBAL\ParameterType;
use Mcp\Server\Transport\StdioTransport;
use NITSAN\NsT3AF\Mcp\Authentication\BackendUserBootstrap;
use NITSAN\NsT3AF\Mcp\Logging\StderrLogger;
use NITSAN\NsT3AF\Mcp\Server\McpServerFactory;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use Psr\Log\LoggerInterface;

use const STDERR;
use const STDIN;
use const STDOUT;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(name: 't3af:mcp:serve|mcp:server|nst3af:mcp:serve', description: 'Start the MCP server using stdio transport for local AI tool integration')]
class McpServeCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly BackendUserBootstrap $backendUserBootstrap,
        private readonly McpServerFactory $mcpServerFactory,
        private readonly WorkspaceListService $workspaceListService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Backend username to authenticate as', 'admin')
            ->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, 'Workspace UID (div0=live)', '0')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Transport type', 'stdio')
            ->addOption(
                'no-startup-message',
                null,
                InputOption::VALUE_NONE,
                'Suppress startup diagnostics on stderr (use when an MCP client spawns this command)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $username */
        $username = $input->getOption('user');
        $workspaceId = (int) $input->getOption('workspace');
        $transport = (string) $input->getOption('transport');
        $showStartup = !(bool) $input->getOption('no-startup-message');
        $verbose = $output->isVerbose();
        $veryVerbose = $output->isVeryVerbose();

        if ($transport !== 'stdio') {
            $this->logStderr($output, '[ERROR] Only transport "stdio" is supported. Got: ' . $transport);

            return Command::FAILURE;
        }

        $beUserUid = $this->resolveBackendUserUid($username);
        if ($beUserUid === null) {
            $this->logStderr($output, sprintf(
                '[ERROR] Backend user "%s" not found or disabled.',
                $username,
            ));
            $this->logStderr($output, '        List users: ddev exec typo3 backend:user:list');
            $this->logStderr($output, '        Or run:     ddev exec typo3 setup');

            return Command::FAILURE;
        }

        if ($showStartup) {
            $workspaceTitle = $this->workspaceListService->resolveTitle($workspaceId);
            $this->logStderr($output, 'TYPO3 MCP Server (stdio) — AI Foundation v' . McpServerFactory::VERSION);
            $this->logStderr($output, sprintf('  User:       %s (uid %d)', $username, $beUserUid));
            $this->logStderr($output, sprintf('  Workspace:  %d (%s)', $workspaceId, $workspaceTitle));
            if ($workspaceId > 0 && str_starts_with($workspaceTitle, 'Workspace #')) {
                $this->logStderr($output, '  Warning:    workspace uid not found in sys_workspace — using live fallback overlay');
            }
        }

        try {
            if ($verbose) {
                $this->logStderr($output, '[debug] Bootstrapping backend user context…');
            }

            $backendUser = $this->backendUserBootstrap->bootstrap($beUserUid, $workspaceId);

            if ($showStartup || $verbose) {
                $groupCount = count($backendUser->userGroups);
                $this->logStderr($output, sprintf('  BE groups:  %d', $groupCount));
            }

            $toolNames = $this->mcpServerFactory->listToolNames();

            if ($verbose) {
                $this->logStderr($output, '[debug] Building MCP server…');
                foreach ($toolNames as $toolName) {
                    $this->logStderr($output, '  [debug] tool: ' . $toolName);
                }
            }

            $server = $this->mcpServerFactory->create();

            if ($showStartup) {
                $this->logStderr($output, sprintf(
                    '  Tools:      %s',
                    $toolNames !== [] ? implode(', ', $toolNames) : '(none registered)',
                ));
                $this->logStderr($output, '  Transport:  stdin/stdout (JSON-RPC MCP protocol)');
                $this->logStderr($output, '  Status:     idle — waiting for an MCP client on stdin (this is normal)');
                $this->logStderr($output, '  Connect:    point Cursor/Claude at this command (see MCP Server → Local CLI tab)');
                if ($verbose) {
                    $this->logStderr($output, '  Manual test: pipe an initialize request, e.g.');
                    $this->logStderr($output, '    printf \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}\\n\' \\');
                    $this->logStderr($output, '      | ddev exec typo3 nst3af:mcp:serve --no-startup-message -u admin -w 0');
                }
                $this->logStderr($output, '  Stop:       Ctrl+C');
                $this->logStderr($output, '');
            }

            $transportLogger = $verbose
                ? new StderrLogger($this->logger)
                : $this->logger;

            $stdioTransport = new StdioTransport(STDIN, STDOUT, $transportLogger);

            if ($verbose) {
                $this->logStderr($output, '[debug] Entering MCP event loop (no output until a client sends JSON-RPC)…');
            }

            $exitCode = $server->run($stdioTransport);
            if ($showStartup) {
                $this->logStderr($output, sprintf('MCP server stopped (exit code %d).', $exitCode));
            }

            return $exitCode;
        } catch (\Throwable $exception) {
            $this->logStderr($output, '[ERROR] ' . $exception->getMessage());

            if ($veryVerbose) {
                $this->logStderr($output, $exception->getTraceAsString());
            } elseif ($verbose && $exception->getPrevious() !== null) {
                $this->logStderr($output, '[caused by] ' . $exception->getPrevious()->getMessage());
            }

            $this->logger->error('MCP stdio server failed', ['exception' => $exception]);

            return Command::FAILURE;
        }
    }

    /**
     * MCP uses stdout for the protocol — diagnostics must go to stderr only.
     */
    private function logStderr(OutputInterface $output, string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message . PHP_EOL);
            return;
        }

        $output->writeln($message);
    }

    private function resolveBackendUserUid(string $username): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array{uid: int|string}|false $row */
        $row = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int) $row['uid'];
    }
}
