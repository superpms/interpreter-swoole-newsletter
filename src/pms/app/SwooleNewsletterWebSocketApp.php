<?php

namespace pms\app;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
abstract class SwooleNewsletterWebSocketApp{

    public function message(Server $server, Frame $frame){}
    public function open(Server $server, Request $request){}
    public function disconnect(Server $server, int $fd){}
    public function close(Server $server, int $fd, int $reactorId){}

}