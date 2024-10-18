<?php

return [
    'test' => true,

    // 是否做连接测试
    'connection' => true,

    // 是否做结构测试
    'schema' => true,

    // 是否做语句测试
    'builder' => true,

    'config' => [
        'driver' => 'sqlite',
        'dbname' => __DIR__ .'/../Fixtures/db',
        //'dis_foreign' => true
        'options' => [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ],
    ],
];
