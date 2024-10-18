<?php
namespace Tanbolt\Database\Model;

interface CastAble
{
    /**
     * 设置配置信息, 实现函数有且只有一个
     * @param mixed $config
     * @return static
     */
    public function __config(...$config);

    /**
     * 重置字段 value
     * @param mixed $value
     * @return static
     */
    public function __setter($value);

    /**
     * 返回标量 用于 Access 的 toArray() 获取
     * @return scalar
     */
    public function __toScalar();

    /**
     * 返回字符串 用于 Model 数据库更新
     * @return string
     */
    public function __toString();
}
