<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Transport;

use Rush\Tool\Schema\Config\DatabaseConfig;
use Rush\Tool\Schema\Config\TargetConfig;
use Rush\Tool\Schema\Exception\TransportException;
use Rush\Tool\Schema\Paths\SchemaPaths;


final class AgentClient implements AgentTransportInterface
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly DatabaseConfig $database,
        private readonly TargetConfig $target,
    ) {
    }


    /**
     * @return array<string, mixed>
     */
    public function request(string $action, string $sql = ''): array
    {
        $payload = [
            'action' => $action,
            'connection' => $this->connection(),
        ];

        if ($sql !== '') {
            $payload['sql'] = $sql;
        }

        return $this->target->isSsh()
            ? $this->ssh($payload)
            : $this->http($payload);
    }


    /** @return array<string, mixed> */
    private function connection(): array
    {
        if ($this->database->driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'dsn' => 'sqlite:'. $this->target->database,
                'database' => $this->target->database,
                'user' => null,
                'password' => null,
            ];
        }

        return [
            'driver' => 'mysql',
            'dsn' => 'mysql:host='. $this->target->host
                .';port='. ($this->target->port ?? 3306)
                .';dbname='. $this->target->database
                .';charset=utf8mb4',
            'database' => $this->target->database,
            'host' => $this->target->host,
            'port' => $this->target->port ?? 3306,
            'user' => $this->target->user,
            'password' => $this->target->password,
        ];
    }


    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function ssh(array $payload): array
    {
        $script = AgentBundler::build($payload);
        $command = $this->sshCommand();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $env = null;

        if ($this->target->sshPasswordFile !== null && $this->target->sshPasswordFile !== '' ) {
            $passwordFile = $this->resolve($this->target->sshPasswordFile);

            if (!is_file($passwordFile) || !is_readable($passwordFile)) {
                throw new TransportException("schema: ssh password file not readable: {$passwordFile}");
            }

            $password = (string) file_get_contents($passwordFile);

            if ($password === '') {
                throw new TransportException("schema: ssh password file is empty: {$passwordFile}");
            }

            $env = array_merge(getenv(), ['SSHPASS' => $password]);
        }

        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if ( ! is_resource($process)) {
            throw new TransportException('schema: could not start ssh agent');
        }

        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new TransportException('schema: ssh agent failed'. (is_string($stderr) && trim($stderr) !== '' ? ': '. trim($stderr) : ''));
        }

        return $this->decode($stdout);
    }


    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function http(array $payload): array
    {
        $url = (string) $this->target->url;
        $token = $this->token();
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $verify = $this->target->secure;

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => ! $verify,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new TransportException("schema: http agent request failed for {$url}");
        }

        return $this->decode($response);
    }


    /** @return list<string> */
    private function sshCommand(): array
    {
        $identity = $this->target->sshIdentity;
        $password = $this->target->sshPasswordFile !== null && $this->target->sshPasswordFile !== '';
        $command = $password ? ['sshpass', '-e'] : [];
        $command = [
            ...$command,
            'ssh',
            '-p', (string) ($this->target->sshPort ?? 22),
        ];

        if ($password) {
            $command = [...$command, '-o', 'BatchMode=no', '-o', 'StrictHostKeyChecking=accept-new', '-o', 'PreferredAuthentications=password', '-o', 'PubkeyAuthentication=no', '-o', 'NumberOfPasswordPrompts=1'];
        } else {
            $command = [...$command, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new'];
        }

        $command = [...$command, '-o', 'ConnectTimeout=15'];

        if ($identity !== null && $identity !== '' && ! $password) {
            $command[] = '-i';
            $command[] = $this->resolve($identity);
        }

        $command[] = ($this->target->sshUser ?? '').'@'.($this->target->sshHost ?? '');
        $command[] = escapeshellarg((string) ($this->target->phpBinary ?? 'php')) .' -d display_errors=stderr';

        return $command;
    }


    private function token(): string
    {
        $file = $this->resolve((string) $this->target->tokenFile);

        if ( ! is_file($file) || ! is_readable($file)) {
            throw new TransportException("schema: token file not readable: {$file}");
        }

        $token = trim((string) file_get_contents($file));

        if ($token === '') {
            throw new TransportException("schema: token file is empty: {$file}");
        }

        return $token;
    }


    private function resolve(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return rtrim($this->projectRoot, '/') .'/'. ltrim($path, '/');
    }


    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $raw): array
    {
        if ( ! is_string($raw) || trim($raw) === '') {
            throw new TransportException('schema: agent returned an empty response');
        }

        $data = json_decode(trim($raw), true);

        if ( ! is_array($data)) {
            throw new TransportException('schema: agent returned invalid JSON: '. trim($raw));
        }

        if (($data['ok'] ?? false) !== true) {
            $error = $data['error'] ?? 'unknown agent error';

            throw new TransportException('schema: agent error: '. (is_string($error) ? $error : 'unknown'));
        }

        return $data;
    }
}
