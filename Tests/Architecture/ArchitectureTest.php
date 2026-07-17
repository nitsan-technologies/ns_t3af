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

namespace NITSAN\NsT3AF\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\PHPat;

/**
 * Static layered-boundary tests, run as PHPStan rules via phpat.
 *
 * Each `test*()` method returns a `Rule` evaluated against the codebase during
 * `composer stan`. Failures surface like any other PHPStan diagnostic.
 *
 * Layering enforced (top → bottom may depend on lower; never the other way):
 *
 *   Controller / Updates  ──► Service / Domain / Provider (registry)
 *                              ▲
 *                              │   never the SymfonyAi bridge directly
 *                              └── enforced below
 *
 * @internal
 */
final class ArchitectureTest
{
    /**
     * Backend controllers must reach providers via {@see \NITSAN\NsT3AF\Provider\AdapterRegistry}
     * — never by importing a concrete adapter implementation.
     */
    public function testControllersDoNotImportAdaptersDirectly(): \PHPat\Test\Builder\Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Controller'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Provider\\SymfonyAi'))
            ->because('Controllers must go through AdapterRegistry, not concrete bridge classes.');
    }

    /**
     * The semver-stable public API surface (`Api\`) must not depend on internal
     * implementation namespaces — child extensions consume only `Api\`.
     */
    public function testPublicApiHasNoInternalDependencies(): \PHPat\Test\Builder\Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Api'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('NITSAN\\NsT3AF\\Service'),
                Selector::inNamespace('NITSAN\\NsT3AF\\Provider\\SymfonyAi'),
                Selector::inNamespace('NITSAN\\NsT3AF\\DependencyInjection'),
                Selector::inNamespace('NITSAN\\NsT3AF\\Controller'),
                Selector::inNamespace('NITSAN\\NsT3AF\\Updates'),
            )
            ->because('Public API must remain decoupled from internal layers to keep semver guarantees.');
    }

    /**
     * Domain models are pure value objects — they must not depend on
     * services, controllers, or repositories.
     */
    public function testDomainModelsHaveNoOutgoingDependencies(): \PHPat\Test\Builder\Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Domain\\Model'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('NITSAN\\NsT3AF\\Controller'),
                Selector::inNamespace('NITSAN\\NsT3AF\\Service'),
                Selector::inNamespace('NITSAN\\NsT3AF\\Domain\\Repository'),
                Selector::inNamespace('NITSAN\\NsT3AF\\Updates'),
            )
            ->because('Domain models are leaf value objects — they must not import higher layers.');
    }

    /**
     * Events are dispatched DTOs — they must not depend on the services that
     * dispatch them, or runtime infrastructure.
     */
    public function testEventsHaveNoOutgoingDependencies(): \PHPat\Test\Builder\Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Event'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('NITSAN\\NsT3AF\\Service'),
                Selector::inNamespace('NITSAN\\NsT3AF\\Controller'),
                Selector::inNamespace('NITSAN\\NsT3AF\\DependencyInjection'),
            )
            ->because('Event objects must stay thin — listeners look up services themselves.');
    }

    /**
     * Credits layer must proxy through HTTP only — never import concrete adapters.
     */
    public function testCreditsDoNotImportProviderAdapters(): \PHPat\Test\Builder\Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Credits'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Provider'))
            ->because('T3Planet Credits must not bypass the proxy by calling adapters directly.');
    }

    public function testMcpToolsDoNotImportControllers(): \PHPat\Test\Builder\Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Mcp\\Tool'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Controller'))
            ->because('MCP tools must stay transport-agnostic and not depend on backend controllers.');
    }

    public function testPublicApiDoesNotImportMcp(): \PHPat\Test\Builder\Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Api'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('NITSAN\\NsT3AF\\Mcp'))
            ->because('Public API must remain independent from MCP server internals.');
    }
}
