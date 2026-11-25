<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Session;

use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Exception\TransportException;
use Rush\Tool\Schema\Transport\AgentTransportInterface;


final class RemoteSession implements SessionInterface
{
    /** @var array<string, mixed>|null */
    private ?array $inspection = null;


    public function __construct(
        private readonly AgentTransportInterface $client,
    ) {
    }


    public function inspect(): SchemaState
    {
        $state = $this->inspectResponse()['state'] ?? null;

        if ( ! is_array($state)) {
            throw new TransportException('schema: agent did not return a schema state');
        }

        /** @var array<string, mixed> $state */
        return SchemaState::fromArray($state);
    }


    public function version(): string
    {
        $version = $this->inspectResponse()['version'] ?? null;

        return is_string($version) ? $version : '';
    }


    /** @return list<string> */
    public function unsupportedObjects(): array
    {
        $unsupported = $this->inspectResponse()['unsupported'] ?? null;

        if ( ! is_array($unsupported)) {
            return [];
        }

        return array_values(array_filter($unsupported, static fn (mixed $item): bool => is_string($item)));
    }


    public function apply(string $sql): void
    {
        $this->client->request('apply', $sql);
        $this->inspection = null;
    }


    public function backup(): string
    {
        $response = $this->client->request('backup');
        $path = $response['path'] ?? null;

        if ( ! is_string($path) || $path === '') {
            throw new TransportException('schema: agent did not return a backup path');
        }

        return $path;
    }


    /** @return array<string, mixed> */
    private function inspectResponse(): array
    {
        return $this->inspection ??= $this->client->request('inspect');
    }
}
