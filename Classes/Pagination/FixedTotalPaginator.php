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

namespace NITSAN\NsT3AF\Pagination;

use TYPO3\CMS\Core\Pagination\AbstractPaginator;

/**
 * Paginator for DB-offset results: total count is known, items are pre-fetched for the current page.
 */
final class FixedTotalPaginator extends AbstractPaginator
{
    /**
     * @param array<int, mixed> $paginatedItems
     */
    public function __construct(
        private readonly int $totalItems,
        private readonly array $paginatedItems,
        int $currentPageNumber = 1,
        int $itemsPerPage = 10,
    ) {
        $this->setCurrentPageNumber($currentPageNumber);
        $this->setItemsPerPage($itemsPerPage);
        $this->updateInternalState();
    }

    /**
     * @return iterable<int, mixed>
     */
    public function getPaginatedItems(): iterable
    {
        return $this->paginatedItems;
    }

    protected function updatePaginatedItems(int $itemsPerPage, int $offset): void
    {
        // Items are already loaded for the current DB page.
    }

    protected function getTotalAmountOfItems(): int
    {
        return $this->totalItems;
    }

    protected function getAmountOfItemsOnCurrentPage(): int
    {
        return count($this->paginatedItems);
    }
}
