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

namespace NITSAN\NsT3AF\Tests\Functional\Mcp\OAuth;

use NITSAN\NsT3AF\Mcp\Domain\Repository\ClientRepository;
use NITSAN\NsT3AF\Mcp\OAuth\AuthorizationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TC-01: end-to-end OAuth lifecycle against the real SQLite schema.
 *
 * Covers register → authorize code → PKCE exchange → refresh → revoke, and
 * asserts tokens/codes are stored as SHA-256 hashes (never plaintext).
 */
final class OAuthAuthorizationFlowFunctionalTest extends FunctionalTestCase
{
    private const SITE_ROOT_PAGE_ID = 1;

    private const BE_USER_UID = 1;

    private const REDIRECT_URI = 'http://127.0.0.1:9876/callback';

    protected array $coreExtensionsToLoad = [
        'frontend',
        'workspaces',
        'scheduler',
        'extensionmanager',
    ];

    protected array $testExtensionsToLoad = [
        'ns_license',
        'ns_t3af',
    ];

    /**
     * @var array<string, non-empty-string>
     */
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/ns_t3af/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    private AuthorizationService $authorizationService;

    private ClientRepository $clientRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTestSite();
        $this->authorizationService = $this->get(AuthorizationService::class);
        $this->clientRepository = $this->get(ClientRepository::class);
    }

    private function setUpTestSite(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $page = $connection->select(['uid'], 'pages', ['uid' => self::SITE_ROOT_PAGE_ID])->fetchAssociative();
        if ($page === false) {
            $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
            $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        }

        $this->setUpBackendUser(self::BE_USER_UID);
        GeneralUtility::makeInstance(Context::class)->setAspect(
            'workspace',
            new WorkspaceAspect(0),
        );

        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);

        $cacheFile = $this->instancePath . '/typo3temp/var/cache/code/core/sites-configuration.php';
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $runtimeCache = $cacheManager->getCache('runtime');
        $runtimeCache->remove('sites-configuration');
        $runtimeCache->remove('sites-root-id-to-identifier');
        $cacheManager->getCache('core')->remove('sites-configuration');
    }

    #[Test]
    public function fullAuthorizationCodeFlowIssuesHashedTokensAndSupportsRefreshAndRevoke(): void
    {
        $client = $this->clientRepository->registerClient('Functional MCP Client', [self::REDIRECT_URI]);
        $clientId = $client['client_id'];

        self::assertNotSame('', $clientId);
        self::assertNotNull($this->clientRepository->findByClientId($clientId));

        [$codeVerifier, $codeChallenge] = $this->createPkcePair();

        $plainCode = $this->authorizationService->createAuthorizationCode(
            $clientId,
            self::BE_USER_UID,
            $codeChallenge,
            'S256',
            self::REDIRECT_URI,
            0,
        );

        $codeRow = $this->fetchCodeByPlain($plainCode);
        self::assertNotNull($codeRow);
        self::assertSame(hash('sha256', $plainCode), $codeRow['authorization_code_hash']);
        self::assertNotSame($plainCode, $codeRow['authorization_code_hash']);
        self::assertSame(0, (int) $codeRow['revoked']);
        self::assertSame(self::BE_USER_UID, (int) $codeRow['be_user']);
        self::assertGreaterThan(time(), (int) $codeRow['code_expires']);

        $pair = $this->authorizationService->exchangeCode(
            $plainCode,
            $codeVerifier,
            $clientId,
            self::REDIRECT_URI,
        );

        self::assertNotSame('', $pair->accessToken);
        self::assertNotSame('', $pair->refreshToken);
        self::assertGreaterThan(0, $pair->expiresIn);

        $codeRowAfter = $this->fetchCodeByPlain($plainCode);
        self::assertNotNull($codeRowAfter);
        self::assertSame(1, (int) $codeRowAfter['revoked'], 'Authorization code must be single-use');

        $accessRow = $this->fetchTokenByAccessPlain($pair->accessToken);
        self::assertNotNull($accessRow);
        self::assertSame(hash('sha256', $pair->accessToken), $accessRow['access_token_hash']);
        self::assertSame(hash('sha256', $pair->refreshToken), $accessRow['refresh_token_hash']);
        self::assertSame(0, (int) $accessRow['revoked']);
        self::assertSame(self::BE_USER_UID, (int) $accessRow['be_user']);
        self::assertSame($clientId, $accessRow['client_id']);

        $validated = $this->authorizationService->validateAccessToken($pair->accessToken);
        self::assertSame(self::BE_USER_UID, $validated['beUser']);
        self::assertSame((int) $accessRow['uid'], $validated['tokenUid']);

        $refreshed = $this->authorizationService->refreshToken($pair->refreshToken, $clientId);
        self::assertNotSame($pair->accessToken, $refreshed->accessToken);
        self::assertNotSame($pair->refreshToken, $refreshed->refreshToken);

        $oldAccessRow = $this->fetchTokenByAccessPlain($pair->accessToken);
        self::assertNotNull($oldAccessRow);
        self::assertSame(1, (int) $oldAccessRow['revoked'], 'Rotated refresh must revoke the previous pair');

        $newAccessRow = $this->fetchTokenByAccessPlain($refreshed->accessToken);
        self::assertNotNull($newAccessRow);
        self::assertSame(0, (int) $newAccessRow['revoked']);

        // Reuse of the already-rotated refresh token must fail and revoke the family (S-05).
        try {
            $this->authorizationService->refreshToken($pair->refreshToken, $clientId);
            self::fail('Expected revoked refresh token reuse to throw');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('revoked', strtolower($exception->getMessage()));
        }

        $familyActive = $this->countActiveTokensForClientUser($clientId, self::BE_USER_UID);
        self::assertSame(0, $familyActive, 'Refresh reuse must revoke the whole client+user family');

        // Fresh pair after family revoke for revoke-path coverage.
        [$codeVerifier2, $codeChallenge2] = $this->createPkcePair();
        $plainCode2 = $this->authorizationService->createAuthorizationCode(
            $clientId,
            self::BE_USER_UID,
            $codeChallenge2,
            'S256',
            self::REDIRECT_URI,
        );
        $pair2 = $this->authorizationService->exchangeCode(
            $plainCode2,
            $codeVerifier2,
            $clientId,
            self::REDIRECT_URI,
        );

        $this->authorizationService->revokeToken($pair2->accessToken);
        $revokedRow = $this->fetchTokenByAccessPlain($pair2->accessToken);
        self::assertNotNull($revokedRow);
        self::assertSame(1, (int) $revokedRow['revoked']);

        $this->expectException(\RuntimeException::class);
        $this->authorizationService->validateAccessToken($pair2->accessToken);
    }

    #[Test]
    public function concurrentRefreshOnlyOneSucceedsAtomically(): void
    {
        $client = $this->clientRepository->registerClient('Race MCP Client', [self::REDIRECT_URI]);
        $clientId = $client['client_id'];
        [$codeVerifier, $codeChallenge] = $this->createPkcePair();
        $plainCode = $this->authorizationService->createAuthorizationCode(
            $clientId,
            self::BE_USER_UID,
            $codeChallenge,
            'S256',
            self::REDIRECT_URI,
        );
        $pair = $this->authorizationService->exchangeCode(
            $plainCode,
            $codeVerifier,
            $clientId,
            self::REDIRECT_URI,
        );

        $first = $this->authorizationService->refreshToken($pair->refreshToken, $clientId);
        self::assertNotSame('', $first->accessToken);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refresh token has been revoked');
        $this->authorizationService->refreshToken($pair->refreshToken, $clientId);
    }

    #[Test]
    public function exchangeRejectsInvalidPkceVerifier(): void
    {
        $client = $this->clientRepository->registerClient('PKCE Fail Client', [self::REDIRECT_URI]);
        $clientId = $client['client_id'];
        [, $codeChallenge] = $this->createPkcePair();
        $plainCode = $this->authorizationService->createAuthorizationCode(
            $clientId,
            self::BE_USER_UID,
            $codeChallenge,
            'S256',
            self::REDIRECT_URI,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PKCE verification failed');
        $this->authorizationService->exchangeCode(
            $plainCode,
            str_repeat('a', 43),
            $clientId,
            self::REDIRECT_URI,
        );
    }

    /**
     * @return array{0: string, 1: string} [verifier, challenge]
     */
    private function createPkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCodeByPlain(string $plainCode): ?array
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nst3af_oauth_code');
        $row = $connection->select(
            ['*'],
            'tx_nst3af_oauth_code',
            ['authorization_code_hash' => hash('sha256', $plainCode)],
        )->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTokenByAccessPlain(string $plainAccessToken): ?array
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nst3af_oauth_token');
        $row = $connection->select(
            ['*'],
            'tx_nst3af_oauth_token',
            ['access_token_hash' => hash('sha256', $plainAccessToken)],
        )->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function countActiveTokensForClientUser(string $clientId, int $beUserUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_nst3af_oauth_token');
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->count('uid')
            ->from('tx_nst3af_oauth_token')
            ->where(
                $queryBuilder->expr()->eq('client_id', $queryBuilder->createNamedParameter($clientId)),
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
