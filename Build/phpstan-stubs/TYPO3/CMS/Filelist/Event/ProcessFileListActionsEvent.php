<?php

declare(strict_types=1);

namespace TYPO3\CMS\Filelist\Event;

use TYPO3\CMS\Core\Resource\ResourceInterface;

/**
 * PHPStan stub: typo3/cms-filelist is optional and not in this package's .Build vendor.
 */
final class ProcessFileListActionsEvent
{
    public function getResource(): ResourceInterface {}

    public function isFile(): bool {}

    /**
     * @return array<int|string, mixed>
     */
    public function getActionItems(): array {}

    /**
     * @param array<int|string, mixed> $actionItems
     */
    public function setActionItems(array $actionItems): void {}
}
