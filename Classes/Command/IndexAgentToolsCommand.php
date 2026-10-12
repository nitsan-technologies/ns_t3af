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

namespace NITSAN\NsT3AF\Command;

use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 't3af:agent:index-tools',
    description: 'Rebuild / warm the AI Agent semantic tool embedding index',
)]
final class IndexAgentToolsCommand extends Command
{
    private const MAX_REASON_LENGTH = 200;

    /** Status codes that point at a wrong or missing embedding model rather than credentials or limits. */
    private const MODEL_STATUS_CODES = [400, 404, 422];

    public function __construct(
        private readonly AgentToolIndexInterface $toolIndex,
        private readonly EmbeddingSourceResolver $embeddingSourceResolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->toolIndex->rebuild();
            $io->success('Agent tool embedding index rebuilt.');

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($this->failureMessage($exception));

            return Command::FAILURE;
        }
    }

    private function failureMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        $status = self::httpStatus($message);
        $reason = self::shortReason($message);

        if (!in_array($status, self::MODEL_STATUS_CODES, true)) {
            return 'Failed to rebuild agent tool index: ' . $reason;
        }

        return sprintf(
            'Failed to rebuild agent tool index: the provider rejected the embedding request for model "%s" (HTTP %d). '
            . 'Set an embedding model for the provider in AI Foundation > AI Providers.',
            $this->embeddingModelId(),
            $status,
        );
    }

    private function embeddingModelId(): string
    {
        try {
            return $this->embeddingSourceResolver->modelIdFor($this->embeddingSourceResolver->resolve());
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private static function httpStatus(string $message): int
    {
        if (preg_match('/(?:response code|HTTP\/\d(?:\.\d)?)\s+(\d{3})/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Drops a raw JSON response body so the console shows one readable line.
     */
    private static function shortReason(string $message): string
    {
        $withoutBody = preg_replace('/\s*:?\s*"?(?:\{|\[\s*[{"]).*$/s', '', $message) ?? $message;
        $reason = trim((string) preg_replace('/\s+/', ' ', $withoutBody));
        if ($reason === '') {
            $reason = trim((string) preg_replace('/\s+/', ' ', $message));
        }

        return mb_strlen($reason) > self::MAX_REASON_LENGTH
            ? mb_substr($reason, 0, self::MAX_REASON_LENGTH - 1) . '…'
            : $reason;
    }
}
