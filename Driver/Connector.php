<?php
namespace Tanbolt\Database\Driver;

use PDO;

/**
 * Class Connector: 数据库连接驱动
 * @package Tanbolt\Database\Driver
 */
abstract class Connector
{
    // Select 默认返回形式(数组)
    protected $defaultFetchMode = PDO::FETCH_ASSOC;

    // 保持统一，以下 PDO options 不可外部定义
    protected $options = [

        // 出现错误抛出异常 而不是返回 bool
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

        // 大小写不强制转换
        PDO::ATTR_CASE => PDO::CASE_NATURAL,

        // NULL empty 不强制转换
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,

        // 预处理以驱动支持度为准
        PDO::ATTR_EMULATE_PREPARES => false,

        // 不要自动将数字转为字符串
        PDO::ATTR_STRINGIFY_FETCHES => 0,

        // 不要自动将数字转为字符串
        // PDO::ATTR_AUTOCOMMIT => false,
    ];

    /**
     * 处理并返回数据库 连接选项
     * @param array $option
     * @return array
     */
    public function preparedOption(array $option)
    {
        $option = array_diff_key($this->options, $option) + $option;
        if (!isset($option[PDO::ATTR_DEFAULT_FETCH_MODE])) {
            $option[PDO::ATTR_DEFAULT_FETCH_MODE] = $this->defaultFetchMode;
        }
        return $option;
    }

    /**
     * 数据库连接后置处理
     * @param PDO $pdo
     * @param array $config
     * @param array $options
     * @return bool
     */
    public function afterConnect(PDO $pdo, array $config, array $options)
    {
        return true;
    }

    /**
     * 处理数据库连接信息
     * @param array $config
     * @return array
     */
    abstract public function preparedServer(array $config);

    /**
     * 返回查询数据库版本的语句
     * @return string|array
     */
    abstract public function versionStatement();

    /**
     * 启用/禁用外键约束检查
     * @param PDO $pdo
     * @param bool $check
     * @return bool
     */
    abstract public function checkForeign(PDO $pdo, bool $check);

    /**
     * 返回查询所有 table 的语句
     * @param ?string $prefix
     * @return string|array
     */
    abstract public function tablesStatement(string $prefix = null);

    /**
     * 插入多条数据, 使用 lastId 获取最后一个ID, 不同驱动可能不同
     * 有些返回的是这多条数据插入的第一条数据ID 如 mysql
     * 有些则返回插入后,最后一条数据ID 如 sqlite
     * @return bool
     */
    abstract public function isAbsoluteLastId();
}
