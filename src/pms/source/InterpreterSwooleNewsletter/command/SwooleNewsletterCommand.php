<?php

namespace pms\source\InterpreterSwooleNewsletter\command;

use pms\annotate\Inject;
use pms\app\handler\SwooleTcpHandler;
use pms\app\handler\SwooleUdpHandler;
use pms\app\TerminalCommandApp;
use pms\core\Initialization;
use pms\facade\Db;
use pms\facade\Path;
use pms\facade\RDb;
use pms\inject\TerminalInputInject;
use pms\inject\TerminalOutputInject;
use pms\interpreter\swooleNewsletter\ManagerOptions;
use pms\interpreter\swooleNewsletter\ServiceOptions;


class SwooleNewsletterCommand extends TerminalCommandApp
{
    protected string $name = "swoole-newsletter-server";

    protected string $description = "启动 swoole-newsletter 服务";

    #[Inject(TerminalInputInject::class)]
    protected TerminalInputInject $input;

    /**
     * @var TerminalOutputInject $output
     */
    #[Inject(TerminalOutputInject::class)]
    protected TerminalOutputInject $output;

    protected string $app;
    protected string $serverName = 'swoole-newsletter';

    public function entry(): void
    {

        if (count($this->argv) < 2) {
            $this->output->end($this->output->setColorStr(TERMINAL_COLOR_RED, "● 缺少参数,请输入: php cli swoole-newsletter-server <tcp项目名>"));
        }
        $this->app = $this->argv[1];

        /**
         * @var ManagerOptions $managerConfig
         */
        $manager = config("newsletter.apps.{$this->app}.manager");
        if ($manager === null) {
            $this->errorEnd("● 应用[$this->app]的管理端不存在,服务未启动。");
        }
        if (!($manager instanceof ManagerOptions)) {
            $this->errorEnd("● 应用[$this->app]的管理端配置未使用ManagerOptions,无法读取,服务未启动。");
        }


        Db::isPool(true);
        RDb::isPool(true);
        $managerServer = new \Swoole\WebSocket\Server($manager->host, $manager->port);
        $this->customShutDownHandler($managerServer);

        $managerConfig = $manager->get('config', []);
        $managerServer->set([
            'log_file' => Path::getRuntime("/log/interpreter/swoole-newsletter.$this->app.manager.log"),
            ...$managerConfig,
            'reload_async' => true,
        ]);

        $serviceMessage = $this->runService($managerServer);

        $managerServer->on('start', function ($server, ...$args) use ($manager, $serviceMessage) {
            $this->output->writeArrayBlock([
                $this->output->setBoldStr("● PHP $this->serverName 管理端"),
                '应用: ' . $this->app,
                '服务IP: ' . $manager->host,
                '服务端口: ' . $manager->port,
                '服务处理器: ' . $manager->handler,
                '服务处理事件: ' . !isset($manager->events) ? '[未设置]' : implode(',', $manager->events),
                sprintf('本机访问地址: <127.0.0.1:%s/>', $manager->port),
            ]);
            foreach ($serviceMessage as $value) {
                $this->output->writeArrayBlock($value);
            }
            $this->callEvent($server, 'start', $manager->handler, $args);
        });
        $managerServer->on('message', function ($server, ...$args) use ($manager) {
            $this->callEvent($server, 'message', $manager->handler, $args);
        });
        $managerServer->on('workerStart', function ($server, ...$args) use ($manager) {
            new Initialization(Path::getRoot());
            $this->initManagerHandler($server, $manager->handler);
            $this->callEvent($server, 'workerStart', $manager->handler, $args);
        });

        $managerEvent = array_filter(isset($manager->events) ? $manager->events : [], function ($value) {
            // 排除 start,message,workerStart,afterReload
            // 且强制跳过 afterReload （当客制化afterReload方法后将导致reload无法重载handler）
            return !in_array(strtoupper($value), ['START', 'MESSAGE', 'WORKERSTART', 'AFTERRELOAD']);
        });
        $this->serverOnEvents($managerServer, $managerEvent, $manager->handler);
        $managerServer->start();
    }


    protected function runService(\Swoole\WebSocket\Server $managerServer): array
    {
        $services = config("newsletter.apps.{$this->app}.services");
        if ($services === null) {
            return [];
        }
        $serviceMessage = [];
        foreach ($services as $key => $service) {
            if (!($service instanceof ServiceOptions)) {
                $this->errorEnd("● 应用[$this->app]的服务[$key]配置未使用ServiceOptions,无法读取,服务未启动。");
            }

            $prot = $managerServer->addlistener($service->host, $service->port, $service->type);
            if (!$prot) {
                $this->errorEnd("● 应用[$this->app]的服务[$key]端口$service->port 与其他端口冲突,服务未启动。");
            }
            $serviceConfig = $service->get('config', []);

            $prot->set([
                ...$serviceConfig,
                'reload_async' => true,
            ]);
            $protocol = "TCP";
            $handler = $service->handler;
            switch ($service->type) {
                case SWOOLE_SOCK_TCP:
                case SWOOLE_TCP:
                case SWOOLE_SOCK_TCP6:
                case SWOOLE_TCP6:
                    /**
                     * @var SwooleTcpHandler $handlerExample
                     */
                    $this->serverOnEvents($prot, ['connect', 'receive', 'close'], $handler);
                    break;
                case SWOOLE_SOCK_UDP:
                case SWOOLE_UDP:
                case SWOOLE_SOCK_UDP6:
                case SWOOLE_UDP6:
                    /**
                     * @var SwooleUdpHandler $handlerExample
                     */
                    $this->serverOnEvents($prot, ['packet', 'receive'], $handler);
                    $protocol = "UDP";
                    break;
            }

            $serviceMessage[] = [
                $this->output->setBoldStr("● PHP $this->serverName 服务"),
                '应用: ' . $this->app,
                '基础协议: ' . $protocol,
                '服务名称: ' . $key,
                '服务IP: ' . $service->host,
                '服务端口: ' . $service->port,
                '服务处理器: ' . $service->handler,
                sprintf('本机访问地址: <127.0.0.1:%s/>', $service->port),
            ];
        }
        return $serviceMessage;

    }


    public function callEvent($server, $event, $handlerClass, $args): void
    {
        try {
            if (!isset($server->{$handlerClass})) {
                $this->initManagerHandler($server, $handlerClass);
            }
            if ($server->{$handlerClass} !== false && $this->isPublicMethod($server->{$handlerClass}, $event)) {
                $server->{$handlerClass}->{$event}($server, ...$args);
            }
        } catch (\Throwable $e) {
            $this->jsonError($e, $event);
        }
        $this->connectDestruct();
    }

    public function serverOnEvents($prot, $events, $handlerClass): void
    {
        foreach ($events as $event) {
            $prot->on($event, function ($server, ...$args) use ($handlerClass, $event) {
                $this->callEvent($server, $event, $handlerClass, $args);
            });
        }
    }


    public function initManagerHandler($server, $handler)
    {
        if (!empty($handler) && class_exists($handler)) {
            $server->{$handler} = new $handler($this->app);
        } else {
            $server->{$handler} = false;
        }
    }


    public function errorEnd($e): void
    {
        $this->output->end($e);
        die;
    }

    public function jsonError(\Throwable $e, $method): void
    {
        $this->output->printJsonArray([
            'app' => $this->app,
            'method' => $method,
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'time' => date('Y-m-d H:i:s', time())
        ]);
    }

    public function connectDestruct(): void
    {
        try {
            $connector = Db::getInstance();
            if (!empty($connector)) {
                foreach ($connector as $value) {
                    $value->close();
                }
            }
            $connector = RDb::getInstance();
            if (!empty($connector)) {
                foreach ($connector as $value) {
                    $value->close();
                }
            }
        } catch (\Throwable $e) {
        }
    }


    public function methodExistsCaseInsensitive($object, $method): bool
    {
        $methods = array_map('strtolower', get_class_methods($object));
        return in_array(strtolower($method), $methods);
    }

    public function isPublicMethod($object, $method): bool
    {
        return $this->methodExistsCaseInsensitive($object, $method)
            && is_callable([$object, $method]);
    }

    protected function customShutDownHandler($managerServer): void
    {
        register_shutdown_function(function () use ($managerServer) {
            $error = error_get_last();
            if (!empty($error)) {
                swoole_clear_error();
                $managerServer->shutdown();
                exit;
            }
        });
    }

}