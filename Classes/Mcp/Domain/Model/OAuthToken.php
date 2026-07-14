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

namespace NITSAN\NsT3AF\Mcp\Domain\Model;

use NITSAN\NsT3AF\Mcp\Domain\Enum\TokenType;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class OAuthToken
{
    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: (int) ($row['uid'] ?? 0),
            tokenType: TokenType::tryFrom((string) ($row['token_type'] ?? 'bearer')) ?? TokenType::Bearer,
            clientId: (string) ($row['client_id'] ?? ''),
            beUser: (int) ($row['be_user'] ?? 0),
            workspaceId: (int) ($row['workspace_id'] ?? 0),
            scope: (string) ($row['scope'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            accessTokenExpires: (int) ($row['access_token_expires'] ?? 0),
            refreshTokenExpires: (int) ($row['refresh_token_expires'] ?? 0),
            revoked: (int) ($row['revoked'] ?? 0) === 1,
            lastUsedAt: (int) ($row['last_used_at'] ?? 0),
            crdate: (int) ($row['crdate'] ?? 0),
            accessTokenHash: (string) ($row['access_token_hash'] ?? ''),
        );
    }

    public function __construct(
        public int $uid,
        public TokenType $tokenType,
        public string $clientId,
        public int $beUser,
        public int $workspaceId,
        public string $scope,
        public string $label,
        public int $accessTokenExpires,
        public int $refreshTokenExpires,
        public bool $revoked,
        public int $lastUsedAt,
        public int $crdate,
        public string $accessTokenHash = '',
    ) {}

    public function preview(): string
    {
        if ($this->accessTokenHash === '') {
            return '…';
        }

        return substr($this->accessTokenHash, 0, 8) . '…';
    }

    public function isActive(): bool
    {
        if ($this->revoked) {
            return false;
        }

        return $this->accessTokenExpires >= time();
    }

    public function expiresSoon(int $withinSeconds = 604800): bool
    {
        $remaining = $this->accessTokenExpires - time();

        return $remaining > 0 && $remaining <= $withinSeconds;
    }
}
