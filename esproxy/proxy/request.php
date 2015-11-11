<?php

/**
 * Elasticsearch Proxy http请求处理
 * @author jiaofuyou@qq.com
 * @date   2015-11-11
 */

class request {
    private function response($response, $status, $result, $header = array())
    {
        if ( $status <> 200 ) {
            Log::prn_log(ERROR, "$status $result");
            $response->status($status);
            $result = json_encode(array('error'=>$result, 'status'=>$status));
        }

        Log::prn_log(INFO, "RESPONSE $result");

        foreach($header as $key => $val) $response->header($key, $val);
        $response->end($result);    
    }

    private function http($url, $content, $headers, $method = 'POST') {
        $opts = array(
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        );
        $opts[CURLOPT_URL] = $url;      
        $opts[CURLOPT_CUSTOMREQUEST] = $method;  //实现OPTIONS,DELETE,PUT等特殊methods
        if (strtoupper($method) == 'POST') {
            //$opts[CURLOPT_POST] = true;                  
            $opts[CURLOPT_POSTFIELDS] = $content;
        }
        if (strtoupper($method) == 'PUT') {
            $opts[CURLOPT_POSTFIELDS] = $content;
        }        
        if (strtoupper($method) == 'DELETE') {
            $opts[CURLOPT_POSTFIELDS] = $content;
        }                
        foreach($headers as $key=>$val) $opts[CURLOPT_HTTPHEADER][] = "$key: $val";
        
        $opts[CURLOPT_HTTPHEADER][] = 'Expect:'; 
        //Disable Expect: header (elasticsearch does not support it)
        //当要POST的数据大于1024字节的时候, curl并不会直接就发起POST请求, 而是会分为俩步
        //  1. 发送一个请求, 包含一个Expect:100-continue, 询问Server使用愿意接受数据
        //  2. 接收到Server返回的100-continue应答以后, 才把数据POST给Server
        //这样就有了一个问题, 并不是所有的Server都会正确应答100-continue, 比如elasticsearch
        
        $opts[CURLOPT_HTTPHEADER][] = 'Transfer-Encoding:';
        
        //var_dump($opts);

        /* 初始化并执行curl请求 */
        $ch = curl_init();
        curl_setopt_array($ch, $opts);

        $data = curl_exec($ch);        

        $error = curl_error($ch);
        curl_close($ch);

        //发生错误，抛出异常
        if ($error)
            throw new \Exception('请求发生错误：' . $error);

        return $data;
    }

    private function http_pass($serv,$request,$response){
        global $worker_conf;       
        
        $uri = $request->server['request_uri'];        
        $url = $worker_conf['es_url'].$uri;
        $method = $request->server['request_method'];
        $content = $request->rawContent();                
        if ($content==false) $content = '';
        $header = $request->header;
        
        $result = $this->http($url, $content, $header, $method);
        //var_dump($result);
        list($headerstr,$result) = explode("\r\n\r\n", $result);
        log::prn_log(INFO, "RESPONSE $result");
        $headers = explode("\r\n", $headerstr);
        $status = substr($headers[0],9,3);
        log::prn_log(INFO, "http backend status: $status");
        if (($status!=200)&&($status!=201)){
            log::prn_log(NOTICE, "REQUEST $method $uri $content");
            log::prn_log(NOTICE, "RESPONSE $status $result");
        }
        
        $response->status($status);
        for($i=1;$i<count($headers);$i++) {
            list($key,$value) = explode(':', $headers[$i], 2);
            $value = trim($value);
            //echo "$key: $value\n";
            $response->header($key, $value);
        }

        $response->end($result);
    }
    
    public function handle_request($serv,$request,$response){
        global $worker_conf;
        global $route;        
        
        //var_dump($request);
        
        $method = $request->server['request_method'];
        $uri = $request->server['request_uri'];        
        $header = $request->header;
        $content = $request->rawContent();    

        Log::prn_log(INFO, "REQUEST $method $uri $content");
        
        if (in_array($request->server['remote_addr'], $worker_conf['trust_ip'])) {
            log::prn_log(INFO, "trust ip: {$request->server['remote_addr']}");
            return $this->http_pass($serv,$request,$response);
        }

        $route_info = $route->handel_route($method, $uri);        
        if ( $route_info === 405 ) {
            Log::prn_log(NOTICE, "REQUEST $method $uri $content");
            return $this->response($response, 405, 'Method Not Allowed, ' . $request->server['request_method']);     
        }
        if ( $route_info === 404 ) {
            Log::prn_log(NOTICE, "REQUEST $method $uri $content");
            return $this->response($response, 404, "$uri is not found!");
        }        
        
        $auth_users = $route_info;
        if (in_array('*', $auth_users)) {
            log::prn_log(INFO, "pass user: *");
            return $this->http_pass($serv,$request,$response);
        }        
        if ( !isset($header['authorization']) ) {
            Log::prn_log(NOTICE, "REQUEST $method $uri $content");
            return $this->response($response, 401, 'Unauthorized', ['WWW-Authenticate'=>'Basic realm=Elasticsearch Auth']);     
        }        
        if ( substr($header['authorization'],0,6) != 'Basic ' ) {
            Log::prn_log(NOTICE, "REQUEST $method $uri $content");
            return $this->response($response, 401, 'Unauthorized, only support Basic auth');     
        }
        $authorization = base64_decode(substr($header['authorization'],6));
        list($user,$passwd) = explode(':', $authorization, 2);
        if ( !in_array($user, $auth_users) ) {
            Log::prn_log(NOTICE, "REQUEST $method $uri $content");
            return $this->response($response, 401, "Unauthorized, invalid user"); 
        }        
        if ( !isset($worker_conf['users'][$user]) ){
            Log::prn_log(NOTICE, "REQUEST $method $uri $content");
            return $this->response($response, 401, "Unauthorized, user is not found"); 
        }
        if ( $worker_conf['users'][$user] != $passwd ){
            Log::prn_log(NOTICE, "REQUEST $method $uri $content");
            return $this->response($response, 401, "Unauthorized, user passwd error!"); 
        }
        log::prn_log(INFO, "Basic auth pass");
        
        return $this->http_pass($serv,$request,$response);
    }    
}
