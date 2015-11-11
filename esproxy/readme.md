Elasticsearch Proxy (ESP)
=========================

**简介**
--------
- 由于Elasticsearch没有提供权限管理功能（官方shield又收费），特开发此Proxy，可以针对Elasticsearch Rest的路径设置权限，可以指定信任IP，支持用户，群组授权
- 当前版本0.01试用版。
- 框架基于PHP-Swoole扩展开发，用fast-route库来做http route处理。

**安装运行**
-----------
环境：linux2.6+、php5.5+、mysql5.5+、swoole1.7.20+  
下载：https://github.com/xtjsxtj/esp
```
tar zxvf esp.zip  
cd esp  
./bin/esp start  

查看当前server进程状态：
./bin/esp status
```

**配置文件**
-----------

系统级配置文件，全局生效，不能reload，只能restart  
server_conf.php  

```php
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
```

工作进程配置文件，支持reload
worker_conf.php

```php
<?php

class worker_conf{
    public static $config=array(
        'log_level' => NOTICE,
        'es_url' => 'http://localhost:9200',
        'trust_ip' => [
            '127.0.0.1'
        ],
        'groups' => [
            'cpyf' => 'jfy,zyw',
        ],        
        'users' => [
            'jfy' => '123456',
            'zyw' => '123456',
        ],
        'auths' => [
            ['OPTIONS', '/{param:.+}', '*'], 
            ['GET',     '/{param:.+}', '*'],            
            ['POST',    '/{param1}/_search', '*'],            
            ['POST',    '/{param1}/{param2}/_search', '*'],            
            ['PUT',     '/kibana-int/{param:.+}', '*'],            
            ['DELETE',  '/kibana-int/{param:.+}', '@cpyf'],
        ],
    );
}
```

针对Elasticsearch的访问权限配置上，只需要修改worker_conf配置文件即可。

配置文件一看上去就应该明白了：
* es_url，后端Elasticsearch http地址
* trust_ip，信任的IP列表，不做任何权限限制
* groups，用户组列表，组下可包含多个用户，用户必须存在于users配置中
* users，用户列表，用户名 => 密码
* auths，访问详细rest路径权限设置 

    ``` 
      method restpath users
    ```
    
    * method 支持数组方式 ["GET","POST"]
    * restpath 访问Elasticsearch的具体路径，支持正则表达式，详情参见：https://github.com/nikic/FastRoute
    * user 授权访问的用户
        * 多个用户以","分隔，用户组以@开头，如：jfy,@cpyf表示用户jfy和用户组cpyf中的所有用户都可以访问
        * "*"表示所有用户均可以访问
        * 当用户列表中指明用户或组时，http header中必须包括Basic Auth用户和密码信息：

            ```
            Authorization: Basic amZ5OjttMzQ1Ng==
            ```
    
