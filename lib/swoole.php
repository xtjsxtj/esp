<?php

/**
 * Swoole封装类
 * @author jiaofuyou@qq.com
 * @date   2015-10-25
 * 
 * http://www.swoole.com/
 */

class swoole
{
    public static $info_dir='/var/local/';
    private $title;
    private $pid_file;
    private $shutdown=false;
    private $config;  
    private $on_func;
    public $serv;    
    
    private function my_set_process_name($title)
    {
        if (substr(PHP_VERSION,0,3) >= '5.5') {
            cli_set_process_title($title);
        } else {
            swoole_set_process_name($title);
        }
    }

    function my_onStart($serv)
    {
        //替换pid为当前进程的pid，因为daemonize模式父进程会被替换
        $this->createPidfile();
        $this->my_set_process_name("{$this->title}: master");
        Log::prn_log(DEBUG,"MasterPid={$serv->master_pid}|Manager_pid={$serv->manager_pid}");
        Log::prn_log(DEBUG,"Server: start.Swoole version is [".SWOOLE_VERSION."]");
    }

    function my_onManagerStart($serv)
    {
        $this->my_set_process_name("{$this->title}: manager");
    }

    function my_onShutdown($serv)
    {
        Log::prn_log(NOTICE,"Server: onShutdown");
        if (file_exists($this->pid_file)){
            unlink($this->pid_file);
            Log::prn_log(DEBUG, "delete pid file " . $this->pid_file);
        }
        $this->shutdown = true;
    }

    function my_onClose($serv, $fd, $from_id)
    {
        $this->recv_buf[$fd] = '';
        $this->package_len[$fd] = 0;

        $conninfo=$serv->connection_info($fd);
        Log::prn_log(DEBUG, "WorkerClose: client[$fd@{$conninfo['remote_ip']}]!");
    }

    function my_onConnect($serv, $fd, $from_id)
    {
        $conninfo=$serv->connection_info($fd);
        Log::prn_log(DEBUG, "WorkerConnect: client[$fd@{$conninfo['remote_ip']}]!");
    }

    function my_onWorkerStart($serv, $worker_id)
    {
        if($worker_id >= $serv->setting['worker_num'])
        {            
            $this->my_set_process_name("{$this->title}: tasker");
            Log::prn_log(DEBUG,"TaskerStart: WorkerId={$serv->worker_id}|WorkerPid={$serv->worker_pid}");
        }
        else
        {
            $this->my_set_process_name("{$this->title}: worker");
            Log::prn_log(DEBUG,"WorkerStart: WorkerId={$serv->worker_id}|WorkerPid={$serv->worker_pid}");
        }

        if ( isset($this->on_func['workerstart']) ) call_user_func($this->on_func['workerstart'], $serv, $worker_id);
    }

    function my_onRequest(swoole_http_request $request, swoole_http_response $response)
    {        
        call_user_func($this->on_func['request'], $this->serv, $request, $response); 
    }
    
    function my_onWorkerStop($serv, $worker_id)
    {
        Log::prn_log(NOTICE,"WorkerStop: WorkerId={$serv->worker_id}|WorkerPid=".posix_getpid());
    }

    function my_onWorkerError($serv, $worker_id, $worker_pid, $exit_code)
    {
        Log::prn_log(ERROR,"WorkerError: worker abnormal exit. WorkerId=$worker_id|WorkerPid=$worker_pid|ExitCode=$exit_code");
    }

    private function trans_listener($listen)
    {
        if(! is_array($listen))
        {
            $tmpArr = explode(":", $listen);
            $host = isset($tmpArr[1]) ? $tmpArr[0] : '0.0.0.0';
            $port = isset($tmpArr[1]) ? $tmpArr[1] : $tmpArr[0];

            $this->listen[] = array(
                'host' => $host,
                'port' => $port,
            );
            return true;
        }
        foreach($listen as $v)
        {
            $this->trans_listener($v);
        }
    }
    
    public function __construct()
    {        
        $config = server_conf::$config;
        if ( !isset($config['is_sington']) ) $config['is_sington'] = true;
        if ( !isset($config['worker_num']) ) $config['worker_num'] = 6;
        if ( !isset($config['daemonize']) ) $config['daemonize'] = true;        
        $this->config = $config;        
        
        $this->pid_file = self::$info_dir . "swoole_{$config['server_name']}.pid";
        $this->title = 'swoole_'.$config['server_name'];

        Log::$log_level = $config['log_level'];
        
        $this->trans_listener($config['listen']);

        $i=0;
        foreach($this->listen as $v) {
            if ($i==0) {
                log::prn_log(INFO, "listen: {$v['host']}:{$v['port']}");
                log::prn_log(NOTICE, "start http server");
                $this->serv = new swoole_http_server($v['host'],$v['port']);
            } else {
                log::prn_log(INFO, "listen: {$v['host']}:{$v['port']}");
                $this->serv->addlistener($v['host'],$v['port'],SWOOLE_SOCK_TCP);
            }
            
            $i++;
        }

        $this->serv->set($this->config);
        $this->serv->on('Start',        array($this, 'my_onStart'));
        $this->serv->on('Connect',      array($this, 'my_onConnect'));        
        $this->serv->on('Close',        array($this, 'my_onClose'));
        $this->serv->on('Shutdown',     array($this, 'my_onShutdown'));
        $this->serv->on('WorkerStart',  array($this, 'my_onWorkerStart'));
        $this->serv->on('WorkerStop',   array($this, 'my_onWorkerStop'));
        $this->serv->on('WorkerError',  array($this, 'my_onWorkerError'));
        $this->serv->on('ManagerStart', array($this, 'my_onManagerStart'));
        $this->serv->on('Request', array($this, 'my_onRequest'));
    }

    public function on($event, $func)
    {
        $this->on_func[$event]=$func;
    }

    //--检测pid是否已经存在
    private function checkPidfile(){

        if (!file_exists($this->pid_file)){
            return true;
        }
        $pid = file_get_contents($this->pid_file);
        $pid = intval($pid);
        if ($pid > 0 && posix_kill($pid, 0)){
            Log::prn_log(NOTICE, "the server is already started");
        }
        else {
            Log::prn_log(WARNING, "the server end abnormally, auto delete pidfile " . $this->pid_file);
            unlink($this->pid_file);
            return;
        }
        exit(1);

    }

    //----创建pid
    private function createPidfile(){
        if (!is_dir(self::$info_dir)) mkdir(self::$info_dir);
        $fp = fopen($this->pid_file, 'w') or die("cannot create pid file");
        fwrite($fp, posix_getpid());
        fclose($fp);
        Log::prn_log(DEBUG, "create pid file " . $this->pid_file);
    }

    public function start()
    {
        // 只能单例运行
        if ($this->config['is_sington']==true){
            $this->checkPidfile();
        }
        $this->createPidfile();
        
        if (!isset($this->on_func['request'])) {
            log::prn_log(ERROR, "must set on_request callback function!");
            exit;
        }
        
        $this->serv->start();
        if ( !$this->shutdown) log::prn_log(ERROR, "swoole start error: ".swoole_errno().','.swoole_strerror(swoole_errno()));
    }
}
