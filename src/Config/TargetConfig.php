<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Config;


final class TargetConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $transport,
        public readonly string $database,
        public readonly string $driver,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $user = null,
        public readonly ?string $password = null,
        public readonly bool $secure = true,
        public readonly ?string $url = null,
        public readonly ?string $tokenFile = null,
        public readonly ?string $sshHost = null,
        public readonly ?int $sshPort = null,
        public readonly ?string $sshUser = null,
        public readonly ?string $sshIdentity = null,
        public readonly ?string $sshPasswordFile = null,
        public readonly ?string $phpBinary = null,
    ) {
    }


    public function isLocal(): bool
    {
        return $this->transport === 'local';
    }


    public function isSsh(): bool
    {
        return $this->transport === 'ssh';
    }


    public function isHttp(): bool
    {
        return $this->transport === 'http';
    }
}
