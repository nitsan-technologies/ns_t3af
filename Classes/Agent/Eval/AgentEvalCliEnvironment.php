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

namespace NITSAN\NsT3AF\Agent\Eval;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Session\UserSessionManager;

/**
 * A backend environment for agent turns on the command line.
 *
 * The agent keeps prepared changes in the backend user session and some tools read the
 * current request, so the eval gets a backend user (the _cli_ admin) with a short-lived
 * session, a backend request and the user's language. {@see self::end()} removes the session.
 *
 * @internal
 */
final class AgentEvalCliEnvironment
{
    /** @var (\Closure(): void)|null */
    private ?\Closure $cleanup = null;

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function start(): BackendUserAuthentication
    {
        Bootstrap::initializeBackendAuthentication();
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user for the eval (the _cli_ user could not log in).', 1790330101);
        }

        $request = new ServerRequest('https://agent-eval.localhost/typo3/', 'GET', null, [], [
            'HTTP_HOST' => 'agent-eval.localhost',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3/',
        ]);
        $request = $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($user);

        $this->startSession($user);

        return $user;
    }

    public function end(): void
    {
        if ($this->cleanup !== null) {
            ($this->cleanup)();
            $this->cleanup = null;
        }
    }

    private function startSession(BackendUserAuthentication $user): void
    {
        $property = new \ReflectionProperty(AbstractUserAuthentication::class, 'userSession');
        if ($property->getValue($user) !== null) {
            return;
        }
        $manager = UserSessionManager::create('BE');
        $user->initializeUserSessionManager($manager);
        $session = $manager->elevateToFixatedUserSession($user->getSession(), (int) ($user->user['uid'] ?? 0));
        $property->setValue($user, $session);
        $this->cleanup = static function () use ($manager, $session): void {
            try {
                $manager->removeSession($session);
            } catch (\Throwable) {
                // The session expires on its own.
            }
        };
    }
}
