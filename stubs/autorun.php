<?php
namespace pms;


if(class_exists('pms\facade\TerminalCommand')){
    facade\TerminalCommand::install(
        'swoole-newsletter-server',
        source\InterpreterSwooleNewsletter\command\SwooleNewsletterCommand::class
    );
}