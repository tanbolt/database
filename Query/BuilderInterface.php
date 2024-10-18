<?php
namespace Tanbolt\Database\Query;

interface BuilderInterface extends ExpressionInterface
{
    /**
     * 创建新的 Builder 对象
     * @param mixed $table
     * @return static
     */
    public function createBuilder($table = null);

    /**
     * 设置查询字段
     * - 直接指定查询字段: select('column', 'table.column', ['foo', 'bar as biz'])
     * - 查询所有字段: select() / select('*') 【默认】
     * - 不查询任何字段: select(null)
     *
     * 第三种使用方法比较特别, 即 Builder 返回 query 不包含 SELECT 部分
     * @param mixed $columns
     * @return static
     */
    public function select(...$columns);

    /**
     * get builder parameter value
     * @param string $key
     * @return mixed|null
     */
    function parameter(string $key);

    /**
     * @param array $queries
     * @return array
     */
    public function mergeBindings(array $queries);
}
