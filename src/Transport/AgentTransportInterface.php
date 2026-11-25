<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Transport;


interface AgentTransportInterface
{
    /**
     * @return array<string, mixed>
     */
    public function request(string $action, string $sql = ''): array;
}
