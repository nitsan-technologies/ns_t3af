<?php

declare(strict_types=1);

namespace NITSAN\NsT3AA\Service\Ai;

/**
 * PHPStan stub for optional ns_t3aa dependency in ns_t3af.
 */
final class PromptContractRegistry
{
    /**
     * @return list<string>
     */
    public function getPromptTypes(): array
    {
    }

    public function has(string $promptType): bool
    {
    }

    public function getDefaultText(string $promptType): string
    {
    }

    /**
     * @return list<string>
     */
    public function getRequiredVariables(string $promptType): array
    {
    }

    public function getScope(string $promptType): string
    {
    }

    public function getLabel(string $promptType): string
    {
    }

    /**
     * @return list<string>
     */
    public function getPromptTypesForScope(string $scope): array
    {
    }

    public function textContainsRequiredVariables(string $promptType, string $promptText): bool
    {
    }
}
