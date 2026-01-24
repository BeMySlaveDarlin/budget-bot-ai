<?php

declare(strict_types=1);

namespace App\Service\Swoole;

use App\Service\Config\Config;
use DI\Attribute\Injectable;
use Swoole\Http\Server;

#[Injectable]
class SwooleServerFactory
{
    public function __construct(
        private Config $config
    ) {}

    public function create(): Server
    {
        $host = $this->config->get('swoole.host', '0.0.0.0');
        $port = $this->config->get('swoole.port', 9501);

        $server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $server->set($this->config->get('swoole.settings', []));

        return $server;
    }
}
