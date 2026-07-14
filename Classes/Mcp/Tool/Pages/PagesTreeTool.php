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

namespace NITSAN\NsT3AF\Mcp\Tool\Pages;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

readonly class PagesTreeTool implements McpNonAiToolInterface
{
    private const MAX_DEPTH = 10;

    private const MAX_NODES = 500;

    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'pages_tree',
        description: 'Get the page tree hierarchy starting from a given page ID. Returns nested structure with children. Use depth to control how deep to traverse (max 10).',
    )]
    public function execute(int $pid = 0, int $depth = 3): string
    {
        $depth = min(max($depth, 1), self::MAX_DEPTH);

        $translationConfig = $this->tcaSchemaService->getTranslationConfig('pages');
        $fields = $this->tcaSchemaService->getListFields('pages');

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        $nodeCount = 0;
        $tree = $this->buildTree($pid, $depth, $nodeCount, $fields);

        return json_encode(['tree' => $tree, 'totalNodes' => $nodeCount], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function buildTree(int $pid, int $remainingDepth, int &$nodeCount, array $fields): array
    {
        if ($remainingDepth <= 0 || $nodeCount >= self::MAX_NODES) {
            return [];
        }

        $result = $this->recordService->findByPid('pages', $pid, self::MAX_NODES, 0, $fields);

        $tree = [];
        foreach ($result['records'] as $page) {
            if ($nodeCount >= self::MAX_NODES) {
                break;
            }

            $nodeCount++;
            $uid = (int) ($page['uid'] ?? 0);
            $page['children'] = $this->buildTree($uid, $remainingDepth - 1, $nodeCount, $fields);
            $tree[] = $page;
        }

        return $tree;
    }
}
