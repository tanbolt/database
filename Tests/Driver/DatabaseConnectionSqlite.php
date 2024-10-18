<?php
require_once __DIR__.'/DatabaseConnectionBasic.php';

class DatabaseConnectionSqlite extends DatabaseConnectionBasic
{
    // 若数据库创建语句较为特殊, 可重置 该函数
    protected function createTableSql($tableName)
    {
        return parent::createTableSql($tableName);
    }






}
