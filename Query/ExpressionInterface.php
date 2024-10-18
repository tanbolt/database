<?php
namespace Tanbolt\Database\Query;

interface ExpressionInterface
{
    /**
     * 获取 SQL query 语句
     * @return ?string
     */
    public function query();

    /**
     * 获取 SQL query 语句的绑定数据
     * @return ?array
     */
    public function getBindings();

    /**
     * 获取别名 比如 SQL 语句中的 Table 别名
     * @return ?string
     */
    public function getAlias();
}
