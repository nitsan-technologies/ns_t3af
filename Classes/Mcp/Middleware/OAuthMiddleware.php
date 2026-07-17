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

namespace NITSAN\NsT3AF\Mcp\Middleware;

use Doctrine\DBAL\ParameterType;

use const JSON_THROW_ON_ERROR;

use NITSAN\NsT3AF\Mcp\Domain\Repository\ClientRepository;
use NITSAN\NsT3AF\Mcp\OAuth\AuthorizationService;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthAuthorizePageRenderer;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthClientLabelResolver;
use NITSAN\NsT3AF\Mcp\OAuth\RateLimitService;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;

readonly class OAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthorizationService $authorizationService,
        private ClientRepository $clientRepository,
        private ConnectionPool $connectionPool,
        private PasswordHashFactory $passwordHashFactory,
        private McpPathProvider $pathProvider,
        private RateLimitService $rateLimitService,
        private AdvancedSettingsService $advancedSettingsService,
        private OAuthAuthorizePageRenderer $authorizePageRenderer,
        private OAuthClientLabelResolver $clientLabelResolver,
        private WorkspacePreferenceService $workspacePreferenceService,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        $authorizePath = $this->pathProvider->getAuthorizePath();
        $tokenPath = $this->pathProvider->getTokenPath();
        $registerPath = $this->pathProvider->getRegisterPath();
        $revokePath = $this->pathProvider->getRevokePath();
        $metadataPath = $this->pathProvider->getMetadataPath();
        $resourceMetadataPath = $this->pathProvider->getResourceMetadataPath();

        $rateLimitEndpoint = match (true) {
            $path === $authorizePath && $method === 'POST' => 'authorize_post',
            $path === $authorizePath && $method === 'GET' => 'authorize_get',
            $path === $tokenPath && $method === 'POST' => 'token_post',
            $path === $registerPath && $method === 'POST' => 'register_post',
            $path === $revokePath && $method === 'POST' => 'revoke_post',
            default => null,
        };

        if ($rateLimitEndpoint !== null) {
            $retryAfter = $this->rateLimitService->check($this->resolveIpAddress($request), $rateLimitEndpoint);
            if ($retryAfter !== null) {
                return $this->createJsonResponse(429, [
                    'error' => 'too_many_requests',
                    'error_description' => 'Rate limit exceeded. Try again later.',
                ])->withHeader('Retry-After', (string) $retryAfter);
            }
        }

        return match (true) {
            $path === $metadataPath && $method === 'GET' => $this->handleMetadata($request),
            $path === $resourceMetadataPath && $method === 'GET' => $this->handleResourceMetadata($request),
            $path === $authorizePath && $method === 'GET' => $this->handleAuthorizeGet($request),
            $path === $authorizePath && $method === 'POST' => $this->handleAuthorizePost($request),
            $path === $tokenPath && $method === 'POST' => $this->handleToken($request),
            $path === $registerPath && $method === 'POST' => $this->handleRegister($request),
            $path === $revokePath && $method === 'POST' => $this->handleRevoke($request),
            default => $handler->handle($request),
        };
    }

    private function handleMetadata(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $issuer = $baseUrl . $this->pathProvider->getBasePath();

        $metadata = [
            'issuer' => $issuer,
            'authorization_endpoint' => $baseUrl . $this->pathProvider->getAuthorizePath(),
            'token_endpoint' => $baseUrl . $this->pathProvider->getTokenPath(),
            'registration_endpoint' => $baseUrl . $this->pathProvider->getRegisterPath(),
            'revocation_endpoint' => $baseUrl . $this->pathProvider->getRevokePath(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ];

        return $this->createJsonResponse(200, $metadata);
    }

    private function handleResourceMetadata(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $resource = $baseUrl . $this->pathProvider->getBasePath();

        $metadata = [
            'resource' => $resource,
            'authorization_servers' => [$resource],
        ];

        return $this->createJsonResponse(200, $metadata);
    }

    private function resolveBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUrl .= ':' . $uri->getPort();
        }

        return $baseUrl;
    }

    private function handleAuthorizeGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $params */
        $params = $request->getQueryParams();

        $error = $this->validateAuthorizeParams($params);
        if ($error !== null) {
            return $this->createJsonResponse(400, ['error' => 'invalid_request', 'error_description' => $error]);
        }

        $clientId = is_string($params['client_id'] ?? null) ? $params['client_id'] : '';
        $redirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';
        $clientName = $this->clientLabelResolver->resolve(
            $clientId,
            '',
            $redirectUri !== '' ? $redirectUri : null,
        );

        $csrfToken = bin2hex(random_bytes(32));

        $html = $this->authorizePageRenderer->render($clientName, $params, $csrfToken);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "frame-ancestors 'none'")
            ->withHeader('Set-Cookie', sprintf(
                'mcp_csrf=%s; Path=%s; HttpOnly; SameSite=Strict; Secure; Max-Age=600',
                $csrfToken,
                $this->pathProvider->getOAuthCookiePath(),
            ))
            ->withBody($this->streamFactory->createStream($html));
    }

    private function handleAuthorizePost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = $request->getParsedBody() ?? [];

        $csrfToken = (string) ($body['csrf_token'] ?? '');
        $cookieCsrf = $this->extractCsrfFromCookie($request);
        if ($csrfToken === '' || !hash_equals($cookieCsrf, $csrfToken)) {
            return $this->createJsonResponse(403, ['error' => 'invalid_request', 'error_description' => 'CSRF validation failed']);
        }

        $clientId = (string) ($body['client_id'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');

        // Re-validate redirect_uri against registered client URIs to prevent POST manipulation
        if ($redirectUri === '' || !$this->clientRepository->validateRedirectUri($clientId, $redirectUri)) {
            return $this->createJsonResponse(400, ['error' => 'invalid_request', 'error_description' => 'Invalid redirect_uri']);
        }

        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $codeChallenge = (string) ($body['code_challenge'] ?? '');
        $codeChallengeMethod = (string) ($body['code_challenge_method'] ?? '');
        $state = (string) ($body['state'] ?? '');

        $beUserUid = $this->authenticateBackendUser($username, $password);
        if ($beUserUid === null) {
            $clientName = $this->clientLabelResolver->resolve(
                $clientId,
                '',
                $redirectUri,
            );

            $newCsrfToken = bin2hex(random_bytes(32));
            $params = [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => $codeChallengeMethod,
                'state' => $state,
                'response_type' => 'code',
            ];

            $html = $this->authorizePageRenderer->render($clientName, $params, $newCsrfToken, 'Invalid username or password.');

            return $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('Content-Security-Policy', "frame-ancestors 'none'")
                ->withHeader('Set-Cookie', sprintf(
                    'mcp_csrf=%s; Path=%s; HttpOnly; SameSite=Strict; Secure; Max-Age=600',
                    $newCsrfToken,
                    $this->pathProvider->getOAuthCookiePath(),
                ))
                ->withBody($this->streamFactory->createStream($html));
        }

        try {
            $workspaceId = $this->workspacePreferenceService->getForUser($beUserUid);
            $code = $this->authorizationService->createAuthorizationCode(
                $clientId,
                $beUserUid,
                $codeChallenge,
                $codeChallengeMethod,
                $redirectUri,
                $workspaceId,
            );
        } catch (\RuntimeException $e) {
            return $this->createJsonResponse(400, ['error' => 'server_error', 'error_description' => $e->getMessage()]);
        }

        $redirectTarget = $redirectUri . '?' . http_build_query(array_filter([
            'code' => $code,
            'state' => $state !== '' ? $state : null,
        ]));

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $redirectTarget)
            ->withHeader('Set-Cookie', sprintf(
                'mcp_csrf=; Path=%s; HttpOnly; SameSite=Strict; Secure; Max-Age=0',
                $this->pathProvider->getOAuthCookiePath(),
            ));
    }

    private function handleToken(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = $request->getParsedBody() ?? [];
        $grantType = (string) ($body['grant_type'] ?? '');

        try {
            $tokenPair = match ($grantType) {
                'authorization_code' => $this->authorizationService->exchangeCode(
                    code: (string) ($body['code'] ?? ''),
                    codeVerifier: (string) ($body['code_verifier'] ?? ''),
                    clientId: (string) ($body['client_id'] ?? ''),
                    redirectUri: (string) ($body['redirect_uri'] ?? ''),
                ),
                'refresh_token' => $this->authorizationService->refreshToken(
                    refreshToken: (string) ($body['refresh_token'] ?? ''),
                    clientId: (string) ($body['client_id'] ?? ''),
                ),
                default => throw new \RuntimeException('Unsupported grant type', 1712100040),
            };
        } catch (\RuntimeException $e) {
            return $this->createJsonResponse(400, ['error' => 'invalid_grant', 'error_description' => $e->getMessage()]);
        }

        return $this->createJsonResponse(200, [
            'access_token' => $tokenPair->accessToken,
            'token_type' => $tokenPair->tokenType,
            'expires_in' => $tokenPair->expiresIn,
            'refresh_token' => $tokenPair->refreshToken,
        ]);
    }

    private function handleRegister(ServerRequestInterface $request): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'Content-Type must be application/json'],
            );
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);

        $clientName = is_string($body['client_name'] ?? null) ? $body['client_name'] : 'MCP Client';

        $redirectUris = [];
        if (is_array($body['redirect_uris'] ?? null)) {
            foreach ($body['redirect_uris'] as $uri) {
                if (is_string($uri) && $uri !== '') {
                    $redirectUris[] = $uri;
                }
            }
        }

        if ($redirectUris === []) {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'At least one redirect_uri is required'],
            );
        }

        $clientName = $this->clientLabelResolver->normalizeClientName($clientName, $redirectUris);

        $client = $this->clientRepository->registerClient($clientName, $redirectUris);

        return $this->createJsonResponse(201, [
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'redirect_uris' => $client['redirect_uris'],
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    private function handleRevoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = $request->getParsedBody() ?? [];
        $token = (string) ($body['token'] ?? '');

        if ($token === '') {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'token parameter is required'],
            );
        }

        $this->authorizationService->revokeToken($token);

        // RFC 7009: always return 200 OK regardless of whether the token was found
        return $this->createJsonResponse(200, []);
    }

    /** @param array<string, mixed> $params */
    private function validateAuthorizeParams(array $params): ?string
    {
        if (($params['response_type'] ?? '') !== 'code') {
            return 'response_type must be "code"';
        }

        $clientId = is_string($params['client_id'] ?? null) ? $params['client_id'] : '';
        if ($clientId === '') {
            return 'client_id is required';
        }

        $client = $this->clientRepository->findByClientId($clientId);
        if ($client === null) {
            return 'Unknown client_id';
        }

        $redirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';
        if ($redirectUri === '') {
            return 'redirect_uri is required';
        }

        if (!$this->clientRepository->validateRedirectUri($clientId, $redirectUri)) {
            return 'Invalid redirect_uri';
        }

        if ($this->advancedSettingsService->enforcePkce()) {
            if (($params['code_challenge_method'] ?? '') !== 'S256') {
                return 'code_challenge_method must be "S256"';
            }

            if (($params['code_challenge'] ?? '') === '') {
                return 'code_challenge is required';
            }
        }

        return null;
    }

    private function authenticateBackendUser(string $username, #[\SensitiveParameter] string $password): ?int
    {
        if ($username === '' || $password === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        /** @var array{uid: int|string, password: string}|false $row */
        $row = $queryBuilder
            ->select('uid', 'password')
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

        $hashInstance = $this->passwordHashFactory->get($row['password'], 'BE');
        if (!$hashInstance->checkPassword($password, $row['password'])) {
            return null;
        }

        return (int) $row['uid'];
    }

    private function resolveIpAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRemoteAddress() : '';
    }

    private function extractCsrfFromCookie(ServerRequestInterface $request): string
    {
        $cookies = $request->getCookieParams();

        return is_string($cookies['mcp_csrf'] ?? null) ? $cookies['mcp_csrf'] : '';
    }

    /**
     * OAuth JSON responses intentionally omit Access-Control-Allow-Origin.
     *
     * MCP OAuth clients are native/desktop (PKCE public clients), not browser
     * SPAs. A wildcard ACAO on token/register/revoke widened the CSRF/abuse
     * surface for dynamic registration and revocation (S-04).
     *
     * @param array<string, mixed> $data
     */
    private function createJsonResponse(int $statusCode, array $data): ResponseInterface
    {
        $body = $this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($body);
    }
}
