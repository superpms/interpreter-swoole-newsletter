<?php

namespace pms\app;
use Swoole\Server;

abstract class SwooleNewsletterTcpApp
{
    public function connect(Server $server, int $fd, int $reactorId){}
    public function receive(Server $server, int $fd, int $reactorId, string $data){}
    public function close(Server $server, int $fd, int $reactorId){}

}