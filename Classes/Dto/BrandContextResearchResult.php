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

namespace NITSAN\NsT3AF\Dto;

/**
 * Structured auto-research response for the AI Context drawer.
 *
 * @internal
 */
final readonly class BrandContextResearchResult
{
    /** @var list<string> */
    public const MANUAL_REQUIRED = [
        'content_rules',
        'forbidden_words',
        'sample_content',
        'compliance_notes',
        'document_upload',
    ];

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $confidence
     * @param list<string>         $manualRequired
     */
    public function __construct(
        public array $fields,
        public array $confidence,
        public array $manualRequired,
        public bool $contentFetched = false,
        public ?string $fetchNotice = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => true,
            'fields' => $this->fields,
            'confidence' => $this->confidence,
            'manualRequired' => $this->manualRequired,
            'contentFetched' => $this->contentFetched,
            'fetchNotice' => $this->fetchNotice,
        ];
    }
}
