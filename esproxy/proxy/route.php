<?php

/**
 * http路由分析处理类
 * @author jiaofuyou@qq.com
 * @date   2015-10-25
 * 
 * 使用第三方fast-route库
 * https://github.com/nikic/FastRoute
 */

class route{
    private $dispatcher;
    
    public function __construct($config) {
        $this->config = $config;
        //var_dump($this->config);        
        $this->dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
            foreach($this->config['auths'] as $id=>$route){
                log::prn_log(DEBUG, 'addRoute: '.  json_encode($route));
                $r->addRoute($route[0], $route[1], 'func_'.$id);
                $this->config['auths'][$id]['users']=[];
                $users = explode(',', $route[2]);
                foreach($users as $user){
                    if (substr($user,0,1)!='@') {
                        if (!in_array($user,$this->config['auths'][$id]['users'])) 
                            $this->config['auths'][$id]['users'][]=$user;
                    } else {
                        $users2 = explode(',',$this->config['groups'][substr($user,1)]);
                        foreach($users2 as $user) {
                            if (!in_array($user,$this->config['auths'][$id]['users'])) 
                                $this->config['auths'][$id]['users'][]=$user;
                        }
                    }    
                }
                if ( in_array('*', $this->config['auths'][$id]['users']) ) {
                    unset($this->config['auths'][$id]['users']);
                    $this->config['auths'][$id]['users'][0] = '*';
                }
            }
        });
    }   
    
    public function handel_route($method, $uri){
        $route_info = $this->dispatcher->dispatch($method, $uri);
        //log::prn_log(DEBUG, json_encode($route_info));
        switch ($route_info[0]) {
            case FastRoute\Dispatcher::NOT_FOUND:
                return 404;
                break;
            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                $allow_methods = $route_info[1];
                return 405;
                break;
            case FastRoute\Dispatcher::FOUND:
                return $this->config['auths'][intval(substr($route_info[1],5))]['users'];
                break;
        }
    }
}
