<?php
require_once __DIR__.'/DatabaseSchemaBasic.php';

class DatabaseSchemaSqlite extends DatabaseSchemaBasic
{
    public function testSqliteSchemaFeature()
    {
        // 失败: (创建/修改)表 - 指定 auto / primary, 但 primary 与 auto 不完全相等
        $this->createTablePrimaryNotEqualsAutoColumn(false);
        $this->alterTablePrimaryNotEqualsAutoColumn(false);

        // 成功: (创建/修改)外键 - (同/异)表 - 类型不匹配, 索引完全相同
        $this->createTableForeignNotEqualsTypeInSameTable(true);
        $this->createTableForeignNotEqualsType(true);
        $this->alterTableForeignNotEqualsTypeInSameTable(true);
        $this->alterTableForeignNotEqualsType(true);

        // 失败: (创建/修改)外键 - (同/异)表 - 类型匹配, 索引不完全相同
        $this->createTableForeignNotEqualsIndexInSameTable(false);
        $this->createTableForeignNotEqualsIndex(false);
        $this->alterTableForeignNotEqualsIndexInSameTable(false);
        $this->alterTableForeignNotEqualsIndex(false);
    }







}
