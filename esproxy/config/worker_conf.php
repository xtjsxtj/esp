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
