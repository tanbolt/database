<?php

return [
    // 是否测试
    'test' => true,

    // 是否做连接测试
    'connection' => true,

    // 是否做结构测试
    'schema' => true,

    // 是否做语句测试
    'builder' => true,

    'config' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'dbname' => 'zencart',
        'username' => 'root',
        'password' => '12345678',
        'prefix' => 'db_',
        'options' => [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ],
    ],
];
