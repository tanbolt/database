<?php
namespace Tanbolt\Database\Schema;

use Throwable;
use Exception;
use Tanbolt\Database\Connection;
use Tanbolt\Database\Driver\Structure;
use Tanbolt\Database\Exception\DatabaseException;

/**
 * Class Schema: 转换已存在的数据表为对象
 * @package Tanbolt\Database\Schema
 */
class Schema
{
    //COLUMN_TYPE: 数字
    const TYPE_INT = 'int';
    const TYPE_TINYINT = 'tinyint';
    const TYPE_SMALLINT = 'smallint';
    const TYPE_MEDIUMINT = 'mediumint';
    const TYPE_BIGINT = 'bigint';
    const TYPE_BOOL = 'bool';

    //COLUMN_TYPE: 浮点数
    const TYPE_FLOAT = 'float';
    const TYPE_DOUBLE = 'double';
    const TYPE_DECIMAL = 'decimal';

    //COLUMN_TYPE: 文本
    const TYPE_CHAR = 'char';
    const TYPE_VARCHAR = 'varchar';
    const TYPE_TEXT = 'text';
    const TYPE_MEDIUMTEXT = 'mediumtext';
    const TYPE_LONGTEXT = 'longtext';
    const TYPE_JSON = 'json';
    const TYPE_BLOB = 'blob';
    const TYPE_ENUM = 'enum';

    //COLUMN_TYPE: 时间
    const TYPE_DATE = 'date';
    const TYPE_TIME = 'time';
    const TYPE_DATETIME = 'datetime';
    const TYPE_TIMESTAMP = 'timestamp';

    /**
     * 连接器
     * @var Connection
     */
    protected $connection;

    /**
     * 结构生成器驱动
     * @var Structure
     */
    protected $structure;

    /**
     * 表名称
     * @var string
     */
    protected $tableName;

    /**
     * 是否使用数据表前缀
     * @var bool
     */
    protected $withoutPrefix;

    /**
     * 创建 Schema 对象
     * @param Connection $connection
     * @param string $table
     * @param bool $withoutPrefix
     */
    public function __construct(Connection $connection, string $table, bool $withoutPrefix = false)
    {
        $this->connection = $connection;
        $this->withoutPrefix = $withoutPrefix;
        $this->structure = $connection->driver->structure->setConnection($this->connection);
        $this->setTable($table);
    }

    /**
     * 获取当前 Connection 对象
     * @return Connection
     */
    public function connect()
    {
        return $this->connection;
    }

    /**
     * 设置数据表名称
     * @param string $table
     * @return $this
     */
    public function setTable(string $table)
    {
        $this->tableName = $table;
        return $this;
    }

    /**
     * 获取数据表名称
     * @return string
     */
    public function getTable()
    {
        return $this->tableName;
    }

    /**
     * 真实数据表名称 (包含前缀)
     * @return string
     */
    public function realTable()
    {
        return ($this->withoutPrefix ? '' : ($this->connection->prefix ?: '')) . $this->tableName;
    }

    /**
     * 获取创建表的 SQL 语句
     * @return string
     * @throws Exception
     */
    public function createSql()
    {
        return $this->onTableExist()->structure->createSql($this->realTable());
    }

    /**
     * table 数据表是否包含 names 字段
     * @param array|string $names 若指定为数组, 将会在数据表包含所有字段时返回 true
     * @return bool
     * @throws Exception
     */
    public function hasColumn($names)
    {
        $columns = $this->columns();
        if (is_array($names)) {
            foreach ($names as $name) {
                if (!isset($columns[$name])) {
                    return false;
                }
            }
            return true;
        }
        return isset($columns[$names]);
    }

    /**
     * 获取表 字段结构
     * @return Column[]
     * @throws Exception
     */
    public function columns()
    {
        return $this->onTableExist()->structure->columns($this->realTable());
    }

    /**
     * 获取表 索引结构
     * @return Index[]
     * @throws Exception
     */
    public function indexes()
    {
        return $this->onTableExist()->structure->indexes($this->realTable());
    }

    /**
     * 获取表 外键约束结构
     * @return Foreign[]
     * @throws Exception
     */
    public function foreigners()
    {
        return $this->onTableExist()->structure->foreigners($this->realTable());
    }

    /**
     * 获取数据表作为约束的外键
     * @return Foreign[]
     * @throws Exception
     */
    public function constraints()
    {
        return $this->onTableExist()->structure->constraints($this->realTable());
    }

    /**
     * 修改表名称
     * @param string $name
     * @return $this
     * @throws Exception
     */
    public function rename(string $name)
    {
        $this->onTableExist()->structure->rename($this->realTable(), ($this->connection->prefix ?: '') . $name);
        $this->tableName = $name;
        return $this;
    }

    /**
     * 清空表
     * @return int 返回清空条数
     * @throws Exception
     */
    public function clear()
    {
        return $this->onTableExist()->structure->clear($this->realTable());
    }

    /**
     * 删除表
     * @return $this
     * @throws Exception
     */
    public function drop()
    {
        $this->onTableExist()->structure->drop($this->realTable());
        return $this;
    }

    /**
     * 删除表（如果存在）
     * @return $this
     */
    public function dropIf()
    {
        $this->structure->dropIf($this->realTable());
        return $this;
    }

    /**
     * 表未创建 不能使用的函数
     * @return  $this
     * @throws Exception
     */
    protected function onTableExist()
    {
        $pretend = $this->connection->pretending();
        if ((0 === $pretend || 1 === $pretend) && !$this->connection->hasTable($this->realTable())) {
            throw new DatabaseException(sprintf("Table '%s' doesn't exist", $this->realTable()));
        }
        return $this;
    }

    /**
     * 获取 创建/修改 表的 SQL 语句
     * @param callable $call
     * @param bool $create
     * @return array
     */
    public function getSql(callable $call, bool $create = false)
    {
        $tableName = $this->realTable();
        $table = new Table([], $create);
        call_user_func($call, $table->name($tableName));
        return $this->structure->builder($table->name($tableName));
    }

    /**
     * 执行具体语句
     * @param array|string $queries
     * @return $this
     * @throws Exception
     */
    protected function executeStatement($queries)
    {
        if (!is_array($queries)) {
            $queries = [$queries];
        }
        foreach ($queries as $query) {
            $this->connection->statement($query);
        }
        return $this;
    }

    /**
     * 新建数据库
     * @param callable $call
     * @return $this
     * @throws Throwable
     */
    public function create(callable $call)
    {
        $queries = $this->getSql($call, true);
        $tableName = $this->realTable();
        if ($this->connection->hasTable($tableName)) {
            throw new DatabaseException("Table '".$tableName."' already exists.");
        }
        try {
            $this->executeStatement($queries);
        } catch (Throwable $e) {
            $this->structure->dropIf($tableName);
            throw $e;
        }
        return $this;
    }

    /**
     * 修改数据表
     * @param callable $call
     * @return $this
     * @throws Throwable
     */
    public function alter(callable $call)
    {
        $queries = $this->getSql($call);
        $tableName = $this->realTable();
        try {
            $this->structure->builderTransaction($tableName);
            $this->executeStatement($queries);
            $this->structure->builderCommit($tableName);
        } catch (Throwable $e) {
            $this->structure->builderRollback($tableName);
            throw $e;
        }
        return $this;
    }
}
