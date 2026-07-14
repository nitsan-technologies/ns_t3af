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

namespace NITSAN\NsT3AF\Provider\Contract;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;

/**
 * Contract every concrete provider adapter must satisfy.
 *
 * Two registration paths feed the {@see \NITSAN\NsT3AF\Provider\AdapterRegistry}:
 *
 *  1. **Symfony AI bridges** — registered automatically by
 *     {@see \NITSAN\NsT3AF\DependencyInjection\AdapterCompilerPass} for any
 *     installed `symfony/ai-*-platform` (or `lochmueller/seal-ai-*`) package.
 *  2. **Custom adapters** — implement this interface and let TYPO3 DI tag the
 *     service via the `_instanceof` rule in `Configuration/Services.yaml`.
 *
 * Implementations are expected to be stateless and safely cacheable as a single
 * shared service instance — the {@see Provider} value object passed to
 * {@see testConnection()}/{@see platform()} carries every per-record detail.
 *
 * @api This interface is part of the semver-stable extension surface.
 */
interface AdapterInterface
{
    /**
     * Stable adapter discriminator — e.g. `symfony.openai`, `symfony.anthropic`,
     * `custom.acme`. Used as the lookup key in {@see \NITSAN\NsT3AF\Provider\AdapterRegistry}.
     */
    public function getType(): string;

    /**
     * Human-readable label rendered in the backend module dropdown.
     */
    public function getDisplayName(): string;

    /**
     * Endpoint URL pre-filled into the drawer when the user picks this adapter.
     * Empty string when the adapter has no sensible default (custom on-prem LLMs).
     */
    public function getDefaultEndpoint(): string;

    /**
     * Capability list pre-checked in the drawer when the user picks this adapter.
     *
     * @return list<string> Subset of {@see \NITSAN\NsT3AF\Provider\Capability::ALL}.
     */
    public function getDefaultCapabilities(): array;

    /**
     * Probe the provider with the given credentials.
     *
     * MUST NOT throw — wrap any failure (network error, missing SDK, decryption
     * error, invalid key) in a {@see VerifyResult::failure()}. The caller
     * persists the result onto the provider row's `last_status*` columns.
     */
    public function testConnection(Provider $provider): VerifyResult;

    /**
     * The underlying platform handle for completion / streaming / embedding calls.
     *
     * Caller treats the return value as opaque; concrete services consume their
     * own provider type (Symfony AI `PlatformInterface`, custom client, …).
     *
     * @throws AdapterRuntimeException When the runtime SDK is not installed or
     *                                 cannot be instantiated for the configured vendor.
     */
    public function platform(Provider $provider): object;
}
