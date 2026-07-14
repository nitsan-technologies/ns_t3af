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

namespace NITSAN\NsT3AF\Mcp\Server\Session;

use Mcp\Server\Session\SessionStoreInterface;
use NITSAN\NsT3AF\Mcp\Domain\Repository\SessionRepository;
use Symfony\Component\Uid\Uuid;

readonly class DatabaseSessionStore implements SessionStoreInterface
{
    /** @param positive-int $ttlSeconds */
    public function __construct(private SessionRepository $repository, private int $ttlSeconds) {}

    public function exists(Uuid $id): bool
    {
        $sessionId = $id->toRfc4122();
        $row = $this->repository->findBySessionId($sessionId);
        if ($row === null) {
            return false;
        }

        $now = time();
        if ($row['last_activity'] < $now - $this->ttlSeconds) {
            $this->repository->delete($sessionId);

            return false;
        }

        $this->repository->touch($sessionId, $now);

        return true;
    }

    public function read(Uuid $id): string|false
    {
        $sessionId = $id->toRfc4122();
        $row = $this->repository->findBySessionId($sessionId);
        if ($row === null) {
            return false;
        }

        $now = time();
        if ($row['last_activity'] < $now - $this->ttlSeconds) {
            $this->repository->delete($sessionId);

            return false;
        }

        $this->repository->touch($sessionId, $now);

        return $row['data'];
    }

    public function write(Uuid $id, string $data): bool
    {
        $this->repository->upsert($id->toRfc4122(), $data, time());

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        return $this->repository->delete($id->toRfc4122());
    }

    /** @return Uuid[] */
    public function gc(): array
    {
        $this->repository->deleteExpired(time() - $this->ttlSeconds);

        return [];
    }
}
