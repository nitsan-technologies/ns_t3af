<?php

declare(strict_types=1);

namespace NITSAN\NsT3Ai\Service\Ai;

/**
 * PHPStan stub for optional ns_t3ai dependency.
 */
final class SidebarPromptResolver
{
    /**
     * @param array{term?:string} $filter
     * @return list<array{uid:int,promptTitle:string,promptText:string,isBuiltin:bool}>
     */
    public function getAllPrompts(array $filter = []): array
    {
    }

    /**
     * @return list<array{uid:int,promptTitle:string,promptText:string,isBuiltin:bool}>
     */
    public function getBuiltinPrompts(): array
    {
    }
}
