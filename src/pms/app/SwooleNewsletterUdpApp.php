<?php

namespace pms\app;
use Swoole\Server;

abstract class SwooleNewsletterUdpApp
{
    public function packet(Server $server, string $data, array $clientInfo){}
    public function receive(Server $server, int $fd, int $reactorId, string $data){}

}