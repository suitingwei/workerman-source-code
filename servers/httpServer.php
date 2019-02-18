<?php
/**
 * Created by PhpStorm.
 * User: sui
 * Date: 2019/1/7
 * Time: 10:09
 */
require_once __DIR__ . '/../vendor/autoload.php';
use Workerman\Worker;

// #### http worker ####
$http_worker = new Worker("http://0.0.0.0:8998");

// 4 processes
$http_worker->count = 4;

// Emitted when data received
$http_worker->onMessage = function(\Workerman\Connection\TcpConnection $connection, $data)
{
    $connection->send(
        sprintf("WorkerId:%s\nId:%s\n",$connection->worker->workerId,$connection->worker->id)
    );
};

// #### http worker ####
$httpServer2 = new Worker("http://0.0.0.0:8999");

// 4 processes
$httpServer2->count = 4;

// Emitted when data received
$httpServer2->onMessage = function(\Workerman\Connection\TcpConnection $connection, $data)
{
    $e = new \Exception;
    $backTrace = ($e->getTraceAsString());
    $connection->send(sprintf("The debug backtrace is :\n%s", ($backTrace)));
    
    Worker::log(sprintf("The debug backtrace for connection->onMessage is :%s",($backTrace)));
};

Worker::$logFile ='/tmp/workerman.debug.log';
Worker::runAll();
