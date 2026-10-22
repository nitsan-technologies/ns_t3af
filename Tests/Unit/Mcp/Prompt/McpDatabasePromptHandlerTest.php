<?php

declare(strict_types=1);

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Prompt;

use Mcp\Exception\PromptGetException;
use Mcp\Server\ClientGateway;
use NITSAN\NsT3AF\Mcp\Prompt\McpDatabasePromptHandler;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpDatabasePromptHandlerTest extends TestCase
{
    #[Test]
    public function rejectsUnknownPageUidWithPromptGetException(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('parentPageExists')->willReturnMap([
            [1, true],
            [999999, false],
        ]);

        $handler = new McpDatabasePromptHandler(
            'Audit page {{pid}}',
            [['name' => 'pid', 'required' => true]],
            $recordService,
        );

        $handler->get(['pid' => '1'], $this->createMock(ClientGateway::class));

        $this->expectException(PromptGetException::class);
        $this->expectExceptionMessage('Page 999999 was not found');
        $handler->get(['pid' => '999999'], $this->createMock(ClientGateway::class));
    }

    #[Test]
    public function rejectsNonNumericPid(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $handler = new McpDatabasePromptHandler(
            'Audit page {{pid}}',
            [['name' => 'pid', 'required' => true]],
            $recordService,
        );

        $this->expectException(PromptGetException::class);
        $this->expectExceptionMessage('must be a positive page UID');
        $handler->get(['pid' => 'abc'], $this->createMock(ClientGateway::class));
    }
}
