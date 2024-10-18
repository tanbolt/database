<?php
require_once __DIR__.'/DatabaseSchemaBasic.php';

class DatabaseSchemaMysql extends DatabaseSchemaBasic
{
    public function testMysqlSchemaFeature()
    {
        // 成功: (创建/修改)表 - 指定 auto / primary,   但 primary 与 auto 不完全相等
        $this->createTablePrimaryNotEqualsAutoColumn(true);
        $this->alterTablePrimaryNotEqualsAutoColumn(true);

        // 失败: (创建/修改)外键 - (同/异)表 - 类型不匹配, 索引完全相同
        $this->createTableForeignNotEqualsTypeInSameTable(false);
        $this->createTableForeignNotEqualsType(false);
        $this->alterTableForeignNotEqualsTypeInSameTable(false);
        $this->alterTableForeignNotEqualsType(false);

        // 成功: (创建/修改)外键 - (同/异)表 - 类型匹配, 索引不完全相同
        $this->createTableForeignNotEqualsIndexInSameTable(true);
        $this->createTableForeignNotEqualsIndex(true);
        $this->alterTableForeignNotEqualsIndexInSameTable(true);
        $this->alterTableForeignNotEqualsIndex(true);
    }

}
