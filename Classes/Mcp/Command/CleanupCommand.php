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

namespace NITSAN\NsT3AF\Mcp\Command;

use NITSAN\NsT3AF\Mcp\Domain\Repository\CodeRepository;
use NITSAN\NsT3AF\Mcp\Domain\Repository\SessionRepository;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Mcp\OAuth\RateLimitService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
#[AsCommand(name: 't3af:mcp:cleanup|nst3af:mcp:cleanup', description: 'Remove expired OAuth tokens/codes and stale MCP sessions')]
class CleanupCommand extends Command
{
    private const DEFAULT_SESSION_LIFETIME = 86400;

    private readonly int $sessionLifetime;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly CodeRepository $codeRepository,
        private readonly RateLimitService $rateLimitService,
        private readonly SessionRepository $sessionRepository,
        ExtensionSettingsService $extensionSettingsService,
    ) {
        parent::__construct();

        $config = $extensionSettingsService->getAll('ns_t3af');
        $sessionLifetime = $config['sessionLifetime'] ?? null;
        $resolved = is_numeric($sessionLifetime) ? (int) $sessionLifetime : self::DEFAULT_SESSION_LIFETIME;
        $this->sessionLifetime = $resolved > 0 ? $resolved : self::DEFAULT_SESSION_LIFETIME;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deletedTokens = $this->tokenRepository->deleteExpiredAndRevoked();
        $io->writeln(sprintf('Deleted %d expired/revoked OAuth tokens.', $deletedTokens));

        $deletedCodes = $this->codeRepository->deleteExpired();
        $io->writeln(sprintf('Deleted %d expired OAuth codes.', $deletedCodes));

        $deletedSessions = $this->sessionRepository->deleteExpired(time() - $this->sessionLifetime);
        $io->writeln(sprintf('Deleted %d stale MCP sessions.', $deletedSessions));

        $deletedRateLimits = $this->rateLimitService->deleteExpiredEntries();
        $io->writeln(sprintf('Deleted %d expired rate limit entries.', $deletedRateLimits));

        $io->success('Cleanup completed.');

        return Command::SUCCESS;
    }
}
