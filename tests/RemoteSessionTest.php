<?php declare(strict_types=1);

use Rush\Tool\Schema\Domain\SchemaState;
use Rush\Tool\Schema\Exception\TransportException;
use Rush\Tool\Schema\Session\RemoteSession;
use Rush\Tool\Schema\Transport\AgentTransportInterface;


function fakeAgent(): AgentTransportInterface
{
    return new class implements AgentTransportInterface {
        /** @var list<array{action: string, sql: string}> */
        public array $calls = [];

        public function request(string $action, string $sql = ''): array
        {
            $this->calls[] = ['action' => $action, 'sql' => $sql];

            return match ($action) {
                'inspect' => [
                    'ok' => true,
                    'version' => '3.46.1',
                    'unsupported' => ['trigger trg'],
                    'state' => (new SchemaState([], []))->toArray(),
                ],
                'apply' => ['ok' => true],
                'backup' => ['ok' => true, 'path' => '/tmp/backup.sqlite'],
                default => ['ok' => false, 'error' => 'unknown'],
            };
        }
    };
}


test('remote session maps agent responses', function () {
    $agent = fakeAgent();
    $session = new RemoteSession($agent);

    expect($session->inspect())->toBeInstanceOf(SchemaState::class)
        ->and($session->version())->toBe('3.46.1')
        ->and($session->unsupportedObjects())->toBe(['trigger trg'])
        ->and($session->backup())->toBe('/tmp/backup.sqlite');

    $session->apply('CREATE TABLE users (id INTEGER PRIMARY KEY)');
    expect($agent->calls)->toContain(['action' => 'apply', 'sql' => 'CREATE TABLE users (id INTEGER PRIMARY KEY)']);
});


test('remote session fails when agent omits state or backup', function () {
    $empty = new class implements AgentTransportInterface {
        public function request(string $action, string $sql = ''): array
        {
            return ['ok' => true];
        }
    };

    $session = new RemoteSession($empty);

    expect(fn () => $session->inspect())->toThrow(TransportException::class, 'did not return a schema state')
        ->and(fn () => $session->backup())->toThrow(TransportException::class, 'did not return a backup path');
});
