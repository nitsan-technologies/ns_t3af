<?php

declare(strict_types=1);

namespace TYPO3\CMS\Filelist\Event;

use TYPO3\CMS\Core\Resource\ResourceInterface;

/**
 * PHPStan stub: typo3/cms-filelist is optional and not in this package's .Build vendor.
 * Covers both v12/v13 (action items) and v14+ (Buttons API).
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

    /**
     * @param object|null $action
     */
    public function setAction(
        $action,
        string $actionName,
        object|string $group = 'secondary',
        string $before = '',
        string $after = '',
    ): void {}

    public function hasAction(string $actionName, object|string|null $group = null): bool {}

    /**
     * @return object|null
     */
    public function getAction(string $actionName, object|string|null $group = null) {}

    public function removeAction(string $actionName, object|string|null $group = null): void {}
}
