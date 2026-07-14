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

namespace NITSAN\NsT3AF\Mcp\OAuth;

use const JSON_THROW_ON_ERROR;

use NITSAN\NsT3AF\Mcp\Domain\Model\OAuthToken;
use NITSAN\NsT3AF\Mcp\Domain\Repository\ClientRepository;

/**
 * Resolves human-readable MCP OAuth client names (Cursor, Claude Desktop, etc.).
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class OAuthClientLabelResolver
{
    private const GENERIC_NAMES = [
        'OAuth client token',
        'MCP Client',
        'Unknown',
    ];

    /** @var list<array{pattern: string, label: string}> */
    private const REDIRECT_PATTERNS = [
        ['pattern' => 'anysphere.cursor-mcp', 'label' => 'Cursor'],
        ['pattern' => 'cursor://', 'label' => 'Cursor'],
        ['pattern' => 'claude', 'label' => 'Claude Desktop'],
        ['pattern' => 'vscode', 'label' => 'VS Code'],
        ['pattern' => 'visualstudio', 'label' => 'VS Code'],
        ['pattern' => 'windsurf', 'label' => 'Windsurf'],
        ['pattern' => 'manus', 'label' => 'Manus'],
        ['pattern' => 'n8n', 'label' => 'n8n'],
    ];

    public function __construct(
        private ClientRepository $clientRepository,
    ) {}

    public function resolveForToken(OAuthToken $token): string
    {
        return $this->resolve($token->clientId, $token->label);
    }

    public function resolve(string $clientId, string $tokenLabel = '', ?string $redirectUri = null): string
    {
        if ($this->isMeaningfulLabel($tokenLabel)) {
            return trim($tokenLabel);
        }

        $client = $clientId !== '' ? $this->clientRepository->findByClientId($clientId) : null;

        return $this->resolveFromClientRecord($client, $redirectUri);
    }

    /**
     * @param array{client_id?: string, client_name?: string, redirect_uris?: string}|null $client
     */
    public function resolveFromClientRecord(?array $client, ?string $redirectUri = null): string
    {
        $redirectUris = $this->extractRedirectUris($client, $redirectUri);
        $inferred = $this->inferFromRedirectUris($redirectUris);
        if ($inferred !== null) {
            return $inferred;
        }

        $clientName = trim((string) ($client['client_name'] ?? ''));
        if ($clientName !== '' && !$this->isGenericName($clientName)) {
            return $clientName;
        }

        $clientId = (string) ($client['client_id'] ?? '');
        if ($clientId !== '') {
            return substr($clientId, 0, 8) . '…';
        }

        return 'Unknown client';
    }

    /**
     * @param list<string> $redirectUris
     */
    public function normalizeClientName(string $clientName, array $redirectUris): string
    {
        $trimmed = trim($clientName);
        if ($trimmed !== '' && !$this->isGenericName($trimmed)) {
            return $trimmed;
        }

        return $this->inferFromRedirectUris($redirectUris) ?? ($trimmed !== '' ? $trimmed : 'MCP Client');
    }

    /**
     * @param list<string> $redirectUris
     */
    public function inferFromRedirectUris(array $redirectUris): ?string
    {
        foreach ($redirectUris as $uri) {
            $lower = strtolower($uri);
            foreach (self::REDIRECT_PATTERNS as $entry) {
                if (str_contains($lower, strtolower($entry['pattern']))) {
                    return $entry['label'];
                }
            }
        }

        return null;
    }

    private function isMeaningfulLabel(string $label): bool
    {
        $trimmed = trim($label);

        return $trimmed !== '' && !$this->isGenericName($trimmed);
    }

    private function isGenericName(string $name): bool
    {
        return in_array(trim($name), self::GENERIC_NAMES, true);
    }

    /**
     * @param array{redirect_uris?: string}|null $client
     * @return list<string>
     */
    private function extractRedirectUris(?array $client, ?string $redirectUri): array
    {
        $uris = [];
        if ($redirectUri !== null && $redirectUri !== '') {
            $uris[] = $redirectUri;
        }

        if ($client !== null && isset($client['redirect_uris'])) {
            /** @var list<string>|null $decoded */
            $decoded = json_decode((string) $client['redirect_uris'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $uri) {
                    if (is_string($uri) && $uri !== '') {
                        $uris[] = $uri;
                    }
                }
            }
        }

        return array_values(array_unique($uris));
    }
}
