<?php
namespace Tanbolt\Database\Driver;

use Exception;
use Tanbolt\Database\Connection;
use Tanbolt\Database\Schema\Table;
use Tanbolt\Database\Schema\Index;
use Tanbolt\Database\Schema\Column;
use Tanbolt\Database\Schema\Schema;
use Tanbolt\Database\Schema\Foreign;
use Tanbolt\Database\Exception\DatabaseException;

/**
 * Class Structure: 数据库结构构造器
 * @package Tanbolt\Database\Driver
 */
abstract class Structure
{
    /**
     * @var Connection
     */
    protected $connection = null;

    /**
     * @var array
     */
    protected $columnType = [];

    /**
     * 设置 Structure 连接的 Connection 对象
     * @param Connection $connection
     * @return Structure
     */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * 从 DDL 语句中提取 外键约束结构
     * @param string $tableName
     * @param string $query
     * @return Foreign[]
     */
    protected function getForeignersFromSql(string $tableName, string $query)
    {
        $query = str_replace("\n", ' ', $query);
        $foreigners = [];
        if (preg_match_all(
            '/(CONSTRAINT\s+(\S+)\s+)?FOREIGN\s+KEY\s?\(([^)]+)\)\s+REFERENCES\s+(\S+)\s?\(([^)]+)\)(([^),]+)|)[,)]/i',
            $query,
            $matches
        )) {
            $actionPattern = sprintf(
                '/(update|delete)\s+(%s|%s|%s|%s|%s)/i',
                Table::FOREIGN_ACTION_RESTRICT,
                Table::FOREIGN_ACTION_CASCADE,
                Table::FOREIGN_ACTION_NOACTION,
                Table::FOREIGN_ACTION_SETDEFAULT,
                Table::FOREIGN_ACTION_SETNULL
            );
            $actionPattern = str_replace(' ', '\s+', $actionPattern);
            foreach ($matches[0] as $key => $val) {
                $name = $this->preparedForeignerColumn($matches[2][$key]);
                $column = $this->preparedForeignerColumn($matches[3][$key], true);
                if (empty($name)) {
                    $name = Table::preparedIndexName($tableName, $column, 'foreign');
                }
                $foreigner = [
                    'name' => $name,
                    'column' => $column,
                    'table' => $this->preparedForeignerColumn($matches[4][$key]),
                    'reference' => $this->preparedForeignerColumn($matches[5][$key], true),
                    'onUpdate' => null,
                    'onDelete' => null,
                ];
                $matches[7][$key] = trim($matches[7][$key]);
                if (!empty($matches[7][$key]) && preg_match_all($actionPattern, $matches[7][$key], $actions)) {
                    foreach ($actions[0] as $k=>$v) {
                        $actions[1][$k] = strtolower(trim($actions[1][$k]));
                        $actions[2][$k] = strtoupper(preg_replace('/\s+/', ' ', trim($actions[2][$k])));
                        if ($actions[1][$k] == 'update') {
                            $foreigner['onUpdate'] = $actions[2][$k];
                        } elseif ($actions[1][$k] == 'delete') {
                            $foreigner['onDelete'] = $actions[2][$k];
                        }
                    }
                }
                $foreigners[$name] = new Foreign($foreigner);
            }
        }
        return $foreigners;
    }

    /**
     * 配合 getForeignersFromSql 方法, 解析外键约束字段
     * @param string $column
     * @param bool $forceArray
     * @return array|string
     */
    protected function preparedForeignerColumn(string $column, bool $forceArray = false)
    {
        $column = trim($column);
        if (empty($column)) {
            return '';
        }
        $fields = [];
        $columns = explode(',', $column);
        foreach ($columns as $key) {
            $key = trim(trim(trim(trim($key), '`'),'"'),"'");
            $key = ltrim(rtrim($key, ']'),'[');
            $fields[] = $key;
        }
        if ($forceArray || count($fields) > 1) {
            return $fields;
        }
        return $fields[0];
    }

    /**
     * 从已定义的 $columnType 提取出每个 type 的实际名称
     * ```
     * [
     *    Schema::TYPE_INT => 'int(~length~) ~unsigned~',
     *    Schema::TYPE_TINYINT => 'tinyint(~length~) ~unsigned~',
     * ]
     * =>
     * [
     *    Schema::TYPE_INT => 'int',
     *    Schema::TYPE_TINYINT => 'tinyint',
     * ]
     * ```
     * @return array
     */
    protected function preparedColumnTypeDefined()
    {
        if (!isset($this->columnTypeTemp)) {
            $this->columnTypeTemp = [];
            foreach ($this->columnType as $key => $val) {
                if (preg_match('/(\w+)(\(|\s)/', $val, $match)) {
                    $this->columnTypeTemp[$key] = strtolower($match[1]);
                } else {
                    $this->columnTypeTemp[$key] = strtolower($val);
                }
            }
        }
        return $this->columnTypeTemp;
    }

    /**
     * 修改数据表名称
     * @param string $from
     * @param string $to
     * @return bool
     */
    abstract public function rename(string $from, string $to);

    /**
     * 清空数据表 (返回清除条数)
     * @param string $tableName
     * @return int
     */
    abstract public function clear(string $tableName);

    /**
     * 删除数据表(不存在抛出异常)
     * @param string $tableName
     * @return bool
     */
    abstract public function drop(string $tableName);

    /**
     * 删除数据表(不存在返回 false)
     * @param string $tableName
     * @return bool
     */
    abstract public function dropIf(string $tableName);

    /**
     * 获取指定数据表的创建 SQL 语句
     * @param string $tableName
     * @return string
     */
    abstract public function createSql(string $tableName);

    /**
     * 获取指定数据表的字段结构
     * @param string $tableName
     * @return Column[]
     */
    abstract public function columns(string $tableName);

    /**
     * 获取指定数据表的索引结构
     * @param string $tableName
     * @return Index[]
     */
    abstract public function indexes(string $tableName);

    /**
     * 获取指定数据表的外键约束结构
     * @param string $tableName
     * @return Foreign[]
     */
    abstract public function foreigners(string $tableName);

    /**
     * 获取指定数据表约束其他表的外键合集
     * @param string $tableName
     * @return Foreign[]
     */
    abstract public function constraints(string $tableName);

    /**
     * 生成[创建/修改]数据表的语句
     * @param Table $table
     * @return array
     */
    abstract public function builder(Table $table);

    /**
     * Table 自动递增字段名称
     * @var bool
     */
    protected $autoIncrement = null;

    /**
     * Table primary 主键索引
     * @var Index
     */
    protected $primaryKey = null;

    /**
     * 当前表字段集合
     * @var Column[]
     */
    protected $columnsNow = [];

    /**
     * 当前表索引集合
     * @var Index[]
     */
    protected $indexesNow = [];

    /**
     * 当前表外键集合
     * @var Foreign[]
     */
    protected $foreignersNow = [];

    /**
     * 经过定义后的 字段集合
     * @var Column[]
     */
    protected $columnsMerge = [];

    /**
     * 经过定义后的 索引集合
     * @var Index[]
     */
    protected $indexesMerge = [];

    /**
     * 经过定义后的 外键集合
     * @var Foreign[]
     */
    protected $foreignersMerge = [];

    /**
     * 修改后的 字段/索引/外键 名称集合
     * @var array
     */
    protected $alterNames = [];

    /**
     * 修改字段 牵涉到其他表的外键约束
     * @var Foreign[]
     */
    protected $affectedConstraints = [];

    /**
     * 用于驱动 Foreign 自测时临时存储
     * @var array
     */
    private $tableTempForForeign = [];

    /**
     * 判断是否为 非字符串 或 空字符串
     * @param mixed $str
     * @return bool
     */
    protected static function emptyString($str)
    {
        return !is_string($str) || empty($str);
    }

    /**
     * 格式化为 数组
     * @param array|string $var
     * @return array
     */
    protected static function convertArray($var)
    {
        $arr = null;
        if (is_array($var)) {
            $arr = $var;
        } elseif (is_string($var)) {
            $arr = explode(',', $var);
        }
        if ($arr) {
            return array_unique(array_filter($arr));
        }
        return [];
    }

    /**
     * @param $type
     * @param $default
     * @return bool|string
     */
    protected static function currentTimestamp($type, $default)
    {
        if ($type !== Schema::TYPE_DATETIME && $type !== Schema::TYPE_TIMESTAMP) {
            return false;
        }
        if (Table::CURRENT_TIMESTAMP === $default || false === $default) {
            return Table::CURRENT_TIMESTAMP;
        }
        return false;
    }

    /**
     * 表修改 事务开始
     * @param string $tableName
     * @return bool
     * @throws Exception
     */
    public function builderTransaction(string $tableName)
    {
        return $this->connection->beginTransaction();
    }

    /**
     * 表修改 事务合并
     * @param string $tableName
     * @return bool
     */
    public function builderCommit(string $tableName)
    {
        return $this->connection->commit();
    }

    /**
     * 表修改 事务回滚
     * @param string $tableName
     * @return bool
     */
    public function builderRollback(string $tableName)
    {
        return $this->connection->rollBack();
    }

    /**
     * 整理校验 Table
     * @param Table $table
     * @return Table
     * @throws Exception
     */
    protected function preparedTable(Table $table)
    {
        $this->alterNames = [];
        $this->tableTempForForeign = [];
        // 当修改表结构前, 需要先获取当前表结构, 若当前设置为不执行, 需要先强制执行, 之后在恢复 pretend 状态
        $pretend = $this->connection->pretending();
        if (!$table->create && $table->name && (true === $pretend || false === $pretend)) {
            $this->connection->pretending(0);
            try {
                $this->preparedTableScheme($table);
                $this->connection->pretendingRevert();
            } catch (Exception $e) {
                $this->connection->pretendingRevert();
                throw $e;
            }
        } else {
            $this->preparedTableScheme($table);
        }
        return $table;
    }

    /**
     * 处理 Table 的字段/索引/外键
     * @param Table $table
     * @return $this
     */
    protected function preparedTableScheme(Table $table)
    {
        $this->preparedColumn($table);
        $this->preparedIndex($table);
        $this->preparedForeign($table);
        return $this;
    }

    /**
     * 处理字段
     * @param Table $table
     * @return Table
     */
    protected function preparedColumn(Table $table)
    {
        if ($table->create && !count($table->columns)) {
            throw new DatabaseException('Can not Create table, at least one column definition was expected.');
        }
        $this->autoIncrement = null;
        $this->columnsMerge = [];
        $this->alterNames['column'] = [];
        $this->columnsNow = !$table->create && $table->name ? $this->columns($table->name) : [];

        // 处理本次操作的字段
        $columnsNow = $this->columnsNow;
        foreach ($table->columns as $name => $column) {
            $this->checkPrepared($table, $columnsNow, $column, $name);
            if ('alter' === $column->command) {
                foreach (['type', 'length', 'unsigned', 'auto', 'null', 'collation', 'comment'] as $item) {
                    if (null === $column[$item]) {
                        $column[$item] = $columnsNow[$name][$item];
                    }
                }
                // default 可以设置为 null 特别处理
                if (false === $column->default) {
                    $column->default = $columnsNow[$name]->default;
                }
                // 若 realType 和 type 皆未指定, 则设定 realType 为当前字段 realType, 否则留空, 由后续程序根据 type 拼凑
                if (self::emptyString($column->realType) && self::emptyString($column->type)) {
                    $column->realType = $columnsNow[$name]->realType;
                }
            }
            if ($column->command !== 'drop' && self::emptyString($column->realType)) {
                if (empty($column->type)) {
                    throw new DatabaseException('Column ['.$name.'] type not defined');
                }
                if (!array_key_exists($column->type, $this->columnType)) {
                    throw new DatabaseException('Column ['.$name.'] type ['.$column->type.'] not support');
                }
            }
            $this->alterNames['column'][$name] = 'drop' === $column->command ? null : $column->rename;
            $table->columns[$name] = $column;
        }
        // 经过本次操作之后 的 表字段
        $auto = 0;
        $columnsAlter = $table->columns;
        foreach ($columnsNow as $name => $column) {
            $drop = false;
            if (array_key_exists($name, $columnsAlter)) {
                if ('alter' === $columnsAlter[$name]->command) {
                    $this->columnsMerge[$name] = $columnsAlter[$name];
                } elseif ('drop' === $columnsAlter[$name]->command) {
                    $drop = true;
                }
                unset($columnsAlter[$name]);
            } else {
                $column->rename = $name;
                $this->columnsMerge[$name] = $column;
                $this->alterNames['column'][$name] = $name;
            }
            if (!$drop && $this->columnsMerge[$name]->auto) {
                $this->autoIncrement = $this->columnsMerge[$name]->rename;
                if (++$auto > 1) {
                    break;
                }
            }
        }
        foreach ($columnsAlter as $column) {
            if ($column->auto) {
                $this->autoIncrement = $column->rename;
                if (++$auto > 1) {
                    break;
                }
            }
        }
        if ($auto > 1) {
            throw new DatabaseException('Table can have only a auto increment column, given more than one.');
        }
        $this->columnsMerge = array_merge($this->columnsMerge, $columnsAlter);
        return $table;
    }

    /**
     * 处理索引
     * @param Table $table
     * @return Table
     */
    protected function preparedIndex(Table $table)
    {
        $this->primaryKey = null;
        $this->indexesMerge = [];
        $this->alterNames['index'] = [];
        $this->indexesNow = !$table->create && $table->name ? $this->indexes($table->name) : [];

        // 处理本次操作的索引
        $indexesNow = $this->indexesNow;
        foreach ($table->indexes as $name => $index) {
            $this->checkPrepared($table, $indexesNow, $index, $name, 'index');
            if ('add' === $index->command) {
                $index->implicit = false;
                $index->column = $this->actualIndexColumns($index);
            } elseif ('alter' === $index->command) {
                // 未指定 index type
                if (null === $index->type) {
                    $index->type = $indexesNow[$name]->type;
                    $index->primary = $indexesNow[$name]->primary;
                    $index->unique = $indexesNow[$name]->unique;
                }
                if (null === $index->column) {
                    $index->column = $indexesNow[$name]->column;
                }
                $index->column = $this->actualIndexColumns($index);
            } else {
                $index->implicit = $indexesNow[$name]->implicit;
            }
            $this->alterNames['index'][$name] = 'drop' === $index->command ? null : $index->rename;
            $table->indexes[$name] = $index;
        }
        // 经过本次操作之后 的 表索引
        $primary = 0;
        $indexesAlter = $table->indexes;
        foreach ($indexesNow as $name => $index) {
            $drop = false;
            if (array_key_exists($name, $indexesAlter)) {
                if ('alter' === $indexesAlter[$name]->command) {
                    $this->indexesMerge[$name] = $indexesAlter[$name];
                } elseif ('drop' === $indexesAlter[$name]->command) {
                    $drop = true;
                }
                unset($indexesAlter[$name]);
            } else {
                $index->rename = $name;
                $index->column = $this->actualIndexColumns($index);
                $this->indexesMerge[$name] = $index;
                $this->alterNames['index'][$name] = $name;
            }
            if (!$drop && $this->indexesMerge[$name]->primary) {
                $this->primaryKey = $this->indexesMerge[$name];
                if (++$primary > 1) {
                    break;
                }
            }
        }
        foreach ($indexesAlter as $index) {
            if ($index->primary) {
                $this->primaryKey = $index;
                if (++$primary > 1) {
                    break;
                }
            }
        }
        if ($primary > 1) {
            throw new DatabaseException('Table `'.$table->name.'` has more than one primary key.');
        }
        $this->indexesMerge = array_merge($this->indexesMerge, $indexesAlter);
        return $table;
    }

    /**
     * 处理外键约束
     * @param Table $table
     * @return Table
     */
    protected function preparedForeign(Table $table)
    {
        if ($this->connection->disableForeign && count($table->foreigners)) {
            throw new DatabaseException('Foreign key constraint is disabled');
        }
        $this->foreignersMerge = [];
        $this->alterNames['foreign'] = [];
        $this->foreignersNow = !$table->create && $table->name ? $this->foreigners($table->name) : [];

        // 处理本次操作的外键约束
        $foreignNow = $this->foreignersNow;
        foreach ($table->foreigners as $name => $foreign) {
            $this->checkPrepared($table, $foreignNow, $foreign, $name, 'foreign');
            if ('alter' === $foreign->command) {
                // 同步当前属性
                foreach (['column', 'table', 'reference', 'onUpdate', 'onDelete'] as $item) {
                    if (null === $foreign[$item]) {
                        $foreign[$item] = $foreignNow[$name][$item];
                    }
                }
            }
            if ('drop' !== $foreign->command) {
                $foreign->column = $this->actualIndexColumns($foreign, 'Foreign');
                if (self::emptyString($foreign->table)) {
                    $foreign->table = $table->name;
                }
                if ($foreign->table === $table->name) {
                    $foreign->reference = $this->actualIndexColumns($foreign, 'Foreign', 'reference');
                } else {
                    $foreign->reference = self::convertArray($foreign->reference);
                    if (empty($foreign->reference)) {
                        throw new DatabaseException('Foreign [' . $name . '] reference not defined');
                    }
                }
                if (count($foreign->column) < 1 || count($foreign->column) != count($foreign->reference)) {
                    throw new DatabaseException(sprintf(
                        'Foreign [%s] key [%s] and table reference [%s] don\'t match',
                        $name, join(',', $foreign->column), join(',', $foreign->reference)
                    ));
                }
            }
            $this->alterNames['foreign'][$name] = 'drop' === $foreign->command ? null : $foreign->rename;
            $table->foreigners[$name] = $foreign;
        }
        // 经过本次操作之后 的 表外键约束
        $foreignAlter = $table->foreigners;
        foreach ($foreignNow as $name => $foreign) {
            if (array_key_exists($name, $foreignAlter)) {
                if ('alter' === $foreignAlter[$name]->command) {
                    $this->foreignersMerge[$name] = $foreignAlter[$name];
                }
                unset($foreignAlter[$name]);
            } else {
                $foreign->rename = $name;
                $foreign->column = $this->actualIndexColumns($foreign, 'Foreign', 'column', true);
                if (strtolower($foreign->table) === strtolower($table->name)) {
                    $foreign->reference = $this->actualIndexColumns($foreign, 'Foreign', 'reference', true);
                }
                $this->foreignersMerge[$name] = $foreign;
                $this->alterNames['foreign'][$name] = $name;
            }
        }
        $this->foreignersMerge = array_merge($this->foreignersMerge, $foreignAlter);
        return $this->checkConstraint($table);
    }

    /**
     * 在修改之后, 实际字段名称集合
     * @param Index|Foreign $index
     * @param string $type
     * @param string $key
     * @param bool $foreign
     * @return array
     */
    protected function actualIndexColumns($index, string $type = 'Index', string $key = 'column', bool $foreign = false)
    {
        $columns = self::convertArray('reference' === $key ? $index->reference : $index->column);
        if ('drop' === $index->command) {
            return $columns;
        }
        if (empty($columns)) {
            throw new DatabaseException($type . ' ['.$index->name.'] '.$key.' not defined');
        }
        $newColumns = [];
        $columnNames = $this->alterNames['column'];
        foreach ($columns as $column) {
            if (in_array($column, $columnNames)) {
                $newColumns[] = $column;
            } elseif (array_key_exists($column, $columnNames)) {
                if ($columnNames[$column]) {
                    $newColumns[] = $columnNames[$column];
                } elseif ($foreign) {
                    throw new DatabaseException(
                        'Cannot drop column ['.$column.']: needed in a foreign key constraint'
                    );
                }
            } else {
                throw new DatabaseException($type . ' ['.$index->name.'] '.$key.' ['.$column.'] not exist.');
            }
        }
        return $newColumns;
    }

    /**
     * column / index / foreign 基本检测
     * @param Table $table
     * @param Column[]|Index[]|Foreign[] $now
     * @param Column|Index|Foreign $column
     * @param string $name
     * @param string $type
     */
    protected function checkPrepared(Table $table, array $now, $column, string $name, string $type = 'column')
    {
        if (self::emptyString($column->name) || $column->name !== $name) {
            throw new DatabaseException($type . ' name not defined');
        }
        if ($table->create && 'add' !== $column->command) {
            throw new DatabaseException('Could not '.$column->command.' '.$type.' when create table.');
        }
        if (!$table->create && 'add' !== $column->command && !array_key_exists($name, $now)) {
            throw new DatabaseException($column->command.' '.$type.' `'.$table->name.'`.`'.$name.'` not exist');
        }
        $column->rename = 'alter' !== $column->command || self::emptyString($column->rename) ? $name : $column->rename;
        if (!$table->create && 'drop' !== $column->command) {
            $realName = 'add' === $column->command || $column->rename !== $column->name ? $column->rename : null;
            if ($realName && (array_key_exists($realName, $now) || in_array($realName, $this->alterNames[$type]))) {
                throw new DatabaseException(
                    'add' === $column->command ?
                        'Add '.$type.' `'.$table->name.'`.`'.$realName.'` already exist' :
                        'Rename '.$type.' `'.$table->name.'`.`'.$name.'` to `'.
                        $table->name.'`.`'.$realName.'` already exist'
                );
            }
        }
    }

    /**
     * 检测外键约束是否合法
     * @param Table $table
     * @return Table
     */
    protected function checkConstraint(Table $table)
    {
        // 检查当前表外键约束 是否合法, 即使无修改也要检查, 因为字段/索引的修改也会影响到外键约束
        foreach ($this->foreignersMerge as $foreign) {
            $this->checkConstraintForeign($table, $foreign);
        }
        if ($table->create) {
            return $table;
        }
        $this->affectedConstraints = [];
        // 检查其他相关表的外键约束 在本次修改之后是否合法
        $columnNames = $this->alterNames['column'];
        foreach ($this->constraints($table->name) as $constraint) {
            $affected = false;
            // 约束字段对应的外键字段 在本次 修改/删除之后 是否可用
            $newReference = [];
            foreach ($constraint->reference as $key => $reference) {
                if (!array_key_exists($reference, $columnNames) || null === $columnNames[$reference]) {
                    throw new DatabaseException(
                        'Cannot drop column ['.$reference.']: needed in a foreign key constraint at table '
                        .$constraint->table
                    );
                } elseif ('alter' === $this->columnsMerge[$reference]->command) {
                    $column = $this->getTableTempForForeign($constraint->table, 'column', $constraint->column[$key]);
                    if (!$this->checkConstraintType($this->columnsMerge[$reference], $column)) {
                        // todo: 这里测试一下
                        throw new DatabaseException(sprintf(
                            'Cannot change column [%s]: used in a foreign key constraint at table [%s]',
                            $reference, $table->name, $constraint->table
                        ));
                    }
                    // 不在同一表 且修改了名称
                    if (strtolower($constraint->table) !== strtolower($table->name) &&
                        $this->columnsMerge[$reference]->name !== $this->columnsMerge[$reference]->rename) {
                        $affected = true;
                    }
                }
                $newReference[] = $columnNames[$reference];
            }
            // 外键字段在当前表的索引 在本次 修改/删除之后 是否可用
            if (!$this->checkConstraintReference($this->indexesMerge, $newReference)) {
                throw new DatabaseException(sprintf(
                    'needed in a foreign key constraint [%s] of table [%s]',
                    join(',', $newReference), $constraint->table
                ));
            }
            $constraint->reference = $newReference;
            if ($affected) {
                $this->affectedConstraints[] = $constraint;
            }
        }
        return $table;
    }

    /**
     * 修改表时, 表修改语句 与 外键修改语句 是分开执行的, 即使外键语句出错抛出错误, 表修改也会执行,
     * 为避免这种非原子化的情况出现，提前进行部分校验。
     * @param Table $table
     * @param Foreign $foreign
     * @return bool
     */
    protected function checkConstraintForeign(Table $table, Foreign $foreign)
    {
        $columns = $this->nowIndexColumns($foreign->column);
        if (strtolower($foreign->table) === strtolower($table->name)) {
            $compareTable = [
                'column' => $this->columnsMerge,
                'index' => $this->indexesMerge,
            ];
            $references = $this->nowIndexColumns($foreign->reference);
        } else {
            $compareTable = $this->getTableTempForForeign($foreign->table);
            $references = $foreign->reference;
        }
        // 确定 约束字段/外键字段 皆存在且类型匹配, column reference 在同一表, 此处略过存在性校验
        foreach ($columns as $key => $item) {
            if (strtolower($foreign->table) !== strtolower($table->name)) {
                if (!array_key_exists($references[$key], $compareTable['column'])) {
                    throw new DatabaseException(sprintf(
                        'Foreign key constraint [%s] reference [%s] not exist in table [%s].',
                        $foreign->name, $references[$key], $foreign->table
                    ));
                }
            }
            if (!$this->checkConstraintType($this->columnsMerge[$item], $compareTable['column'][$references[$key]])) {
                throw new DatabaseException(sprintf(
                    'Foreign key constraint [%s] key [`%s`.`%s`] and reference [`%s`.`%s`] don\'t match.',
                    $foreign->name, $table->name, $item, $foreign->table, $references[$key]
                ));
            }
        }
        // 约束字段 索引检查
        if (!$this->checkConstraintKey($foreign, $this->indexesMerge, $foreign->column)) {
            throw new DatabaseException(sprintf(
                'Foreign key constraint [%s] key index not exist.',
                $foreign->name
            ));
        }
        // 外键字段 索引检查
        if ($table->create && strtolower($foreign->table) === strtolower($table->name) && count($foreign->reference) < 2
            && $this->autoIncrement && $foreign->reference[0] === $this->autoIncrement
        ) {
            $hasIndex = true;
        } else {
            $hasIndex = $this->checkConstraintReference($compareTable['index'], $foreign->reference);
        }
        if (!$hasIndex) {
            throw new DatabaseException(sprintf(
                'Foreign key constraint [%s] reference index not exist in table [%s].',
                $foreign->name, $foreign->table
            ));
        }
        return true;
    }

    /**
     * 获得表修改前 当前字段名称集合
     * @param array $columns
     * @return array
     */
    protected function nowIndexColumns(array $columns)
    {
        $newColumns = [];
        $columnNames = $this->alterNames['column'];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $columnNames) && $tmp = array_search($column, $columnNames)) {
                $newColumns[] = $tmp;
            } else {
                $newColumns[] = $column;
            }
        }
        return $newColumns;
    }

    /**
     * 获取指定表的 column 或 index
     * @param string $tableName
     * @param string $type
     * @param ?string $key
     * @return array|Column|Index|null
     */
    protected function getTableTempForForeign(string $tableName, string $type = 'all', string $key = null)
    {
        if (!array_key_exists($tableName, $this->tableTempForForeign)) {
            $this->tableTempForForeign[$tableName] = ['column' => null, 'index' => null];
        }
        if (null === $this->tableTempForForeign[$tableName]['column'] && ('all' === $type || 'column' === $type)) {
            $this->tableTempForForeign[$tableName]['column'] = $this->columns($tableName);
        }
        if (null === $this->tableTempForForeign[$tableName]['index'] && ('all' === $type || 'index' === $type)) {
            $this->tableTempForForeign[$tableName]['index'] = $this->indexes($tableName);
        }
        if ('index' === $type || 'column' === $type) {
            if (null === $key) {
                return $this->tableTempForForeign[$tableName][$type];
            }
            return array_key_exists($key, $this->tableTempForForeign[$tableName][$type]) ?
                $this->tableTempForForeign[$tableName][$type][$key] : null;
        }
        return $this->tableTempForForeign[$tableName];
    }

    /**
     * 约束字段 索引检查
     * @param Foreign $foreign
     * @param Index[] $indexes
     * @param array $column
     * @return bool
     */
    protected function checkConstraintKey(Foreign $foreign, array $indexes, array $column)
    {
        return true;
    }

    /**
     * 外键字段 索引检查
     * @param Index[] $indexes
     * @param array $reference
     * @return bool
     */
    protected function checkConstraintReference(array $indexes, array $reference)
    {
        return true;
    }

    /**
     * 比较[约束字段] - [外键字段] 类型是否匹配
     * @param Column $key
     * @param Column $reference
     * @return bool
     */
    protected function checkConstraintType(Column $key, Column $reference)
    {
        return true;
    }
}
