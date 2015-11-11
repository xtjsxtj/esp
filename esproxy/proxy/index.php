<?php

/**
 * PHP Swoole Server守护进程
 * @author jiaofuyou@qq.com
 * @date 2015-10-25
 */

define('BASE_PATH', __DIR__);
require_once BASE_PATH.'/../lib/autoload.php';
require_once BASE_PATH.'/../config/server_conf.php';

$server = new swoole();
$server->on('workerstart', 'workerstart');
$server->on('request', 'request');
$server->start();
        
function workerstart($serv, $worker_id){
    global $worker_conf;
    global $route;
    
    require_once BASE_PATH.'/../config/worker_conf.php'; 
    require_once BASE_PATH.'/request.php';
    require_once BASE_PATH.'/route.php';
    
    $worker_conf = worker_conf::$config;
    Log::$log_level = $worker_conf['log_level'];
    Log::prn_log(DEBUG, 'log_level change to '.Log::$log_level);    
    
    $route = new route($worker_conf);    
}

function request($serv,$request,$response){
    $req = new request();
    call_user_func(array($req,'handle_request'), $serv,$request,$response);
}
