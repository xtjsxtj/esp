<?php

class server_conf {
    public static $config=array(
        'server_name' => 'es_proxy',  //server名称
        'log_level' => NOTICE,        //跟踪级别TRACE,DEBUG,INFO,NOTICE,WARNING,ERROR
        'listen' => 9501,             //listen监听端口
        'worker_num' => 1,            //工作进程数
        'daemonize' => true,          //是否以守护进程方式运行
        'log_file' => '/home/jfy/testprog/esproxy/proxy/index.log',  //log文件
    );   
}
