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
$http_worker = new Worker("http://0.0.0.0:8919");

// 4 processes
$http_worker->count = 4;

// Emitted when data received
$http_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

// run all workers
Worker::runAll();