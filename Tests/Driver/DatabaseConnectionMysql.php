<?php
require_once __DIR__.'/DatabaseConnectionBasic.php';

class DatabaseConnectionMysql extends DatabaseConnectionBasic
{
    // 若数据库创建语句较为特殊, 可重置 该函数
    protected function createTableSql($tableName)
    {
        return
            "CREATE TABLE `{$tableName}` (
            `id`  INT AUTO_INCREMENT,
            `rid` mediumint UNIQUE,
            `cid` mediumint,
            PRIMARY KEY (`id`),
            FOREIGN KEY (cid) REFERENCES {$tableName} (rid)
        )";
    }


}
