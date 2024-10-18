<?php
namespace Tanbolt\Database\Driver\Sqlite;

use PDO;
use Exception;
use PDOException;
use Tanbolt\Database\Schema\Index;
use Tanbolt\Database\Schema\Table;
use Tanbolt\Database\Schema\Column;
use Tanbolt\Database\Schema\Schema;
use Tanbolt\Database\Exception\DatabaseException;
use Tanbolt\Database\Driver\Structure as StructureDriver;

class Structure extends StructureDriver
{
    /**
     * 类型映射
     * @var array
     */
    protected $columnType = [
        Schema::TYPE_INT => '~unsigned~ int(~length~)',
        Schema::TYPE_TINYINT => '~unsigned~ tinyint(~length~)',
        Schema::TYPE_SMALLINT => '~unsigned~ smallint(~length~)',
        Schema::TYPE_MEDIUMINT => '~unsigned~ mediumint(~length~)',
        Schema::TYPE_BIGINT => '~unsigned~ bigint(~length~)',
        Schema::TYPE_BOOL => 'tinyint(1)',

        Schema::TYPE_FLOAT => '~unsigned~ float(~length~)',
        Schema::TYPE_DOUBLE => '~unsigned~ double(~length~)',
        Schema::TYPE_DECIMAL => '~unsigned~ decimal(~length~)',

        Schema::TYPE_CHAR => 'char(~length~)',
        Schema::TYPE_VARCHAR => 'varchar(~length~)',
        Schema::TYPE_TEXT => 'text',
        Schema::TYPE_MEDIUMTEXT => 'mediumtext',
        Schema::TYPE_LONGTEXT => 'longtext',
        Schema::TYPE_JSON => 'text',
        Schema::TYPE_BLOB => 'blob',
        Schema::TYPE_ENUM => 'character CHECK( `~name~` IN (~length~) )',

        Schema::TYPE_DATE => 'date',
        Schema::TYPE_TIME => 'time(~length~)',
        Schema::TYPE_DATETIME => 'datetime(~length~)',
        Schema::TYPE_TIMESTAMP => 'timestamp(~length~)',
    ];

    /**
     * 特殊字段
     * @var array
     */
    private $enumColumns;

    /**
     * 事务开始前 记录约束检查是否开启
     * @var bool
     */
    private $transactionCheckForeign;

    /**
     * 简单的转义字符串函数
     * @param array|string $var
     * @return array|string
     */
    protected static function escape($var)
    {
        if(is_array($var)) {
            return array_map(__METHOD__, $var);
        }
        if(!empty($var) && is_string($var)) {
            return str_replace("'", "''", $var);
        }
        return $var;
    }

    /**
     * 从 columns 提取 default 值需要反转义一下
     * @param mixed $var
     * @return string
     */
    protected static function unEscape($var)
    {
        if (null === $var) {
            return null;
        }
        if (is_numeric($var)) {
            return $var;
        }
        if (is_string($var)) {
            return str_replace("''", "'", substr($var, 1, -1));
        }
        return $var;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function rename(string $from, string $to)
    {
        return (bool) $this->connection->statement('ALTER TABLE `'.$from.'` RENAME TO `'.$to.'`;');
    }

    /**
     * 清空数据表: 需要注意 当前表如果是外键约束表 而表中不包含 约束值 是可以成功情况的, 这与 Mysql 不同
     * @inheritDoc
     * @throws Exception
     */
    public function clear(string $tableName)
    {
        $pretend = $this->connection->pretending();
        $statement = 'DELETE FROM `'.$tableName.'`';
        $tables = array_map('strtolower', ($this->connection->allTable(false) ?: []));
        if ((0 === $pretend || 1 === $pretend) && $this->hasForeignReferences($tableName, $statement, $tables)) {
            return false;
        }
        $count = $this->connection->execute($statement);
        if ((0 === $pretend || 1 === $pretend) && false === $count) {
            return false;
        }
        if (in_array('sqlite_sequence', $tables)) {
            $this->connection->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = '".$tableName."'");
        }
        $this->connection->statement('VACUUM');
        return $count;
    }

    /**
     * 删除数据表: 与清空表类似 外键约束表现与 mysql 不同
     * @inheritDoc
     * @throws Exception
     */
    public function drop(string $tableName)
    {
        $pretend = $this->connection->pretending();
        $statement = 'DROP TABLE `'.$tableName.'`';
        if ((0 === $pretend || 1 === $pretend) && $this->hasForeignReferences($tableName, $statement)) {
            return false;
        }
        return (bool) $this->connection->statement($statement);
    }

    /**
     * 删除数据表: 与清空表类似 外键约束表现与 mysql 不同
     * @inheritDoc
     * @throws Exception
     */
    public function dropIf(string $tableName)
    {
        $pretend = $this->connection->pretending();
        $statement = 'DROP TABLE `'.$tableName.'`';
        if ((0 === $pretend || 1 === $pretend) && $this->hasForeignReferences($tableName, $statement)) {
            return true;
        }
        return (bool) $this->connection->statement($statement);
    }

    /**
     * 检测外键约束 当存在外键不能清空或删除表
     * @param string $checkTable
     * @param string $query
     * @param ?array $tables
     * @return bool
     * @throws Exception
     */
    protected function hasForeignReferences(string $checkTable, string $query, array $tables = null)
    {
        $checkTable = strtolower($checkTable);
        if (null === $tables) {
            $tables = array_map('strtolower', ($this->connection->allTable(false) ?: []));
        }
        if (!in_array($checkTable, $tables)) {
            return true;
        }
        if (!$this->connection->isCheckForeign()) {
            return false;
        }
        foreach ($tables as $table) {
            if ('sqlite_sequence' == $table || $table === $checkTable) {
                continue;
            }
            $foreign = $this->connection->fetchOne("PRAGMA foreign_key_list('$table')", [], PDO::FETCH_ASSOC);
            if ($foreign && strtolower($foreign['table']) === $checkTable) {
                throw new PDOException(sprintf(
                    'Integrity constraint violation: foreign key constraint %s REFERENCES %s, SQL execute failed: %s',
                    '`'.$table.'`.`'.$foreign['from'].'`',
                    '`'.$checkTable.'`.`'.$foreign['to'].'`',
                    $query
                ));
            }
        }
        return false;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function createSql(string $tableName)
    {
        $lists = $this->connection->fetchAll(
            "SELECT `sql` FROM `sqlite_master` WHERE (`type`='table' AND `name` LIKE ?) OR (`type`='index' AND `tbl_name` LIKE ?)",
            [$tableName, $tableName], PDO::FETCH_ASSOC
        ) ?: [];
        $query = [];
        foreach ($lists as $list) {
            if (($sql = trim($list['sql'])) == '') {
                continue;
            }
            $query[] = $sql;
        }
        if (count($query)) {
            return join(';', $query).';';
        }
        return null;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function columns(string $tableName)
    {
        /** @var Column[] $structures */
        $structures = [];
        // 通过 createSql 正则尝试获取 comments enum 类型 length
        $this->enumColumns = null;
        $comments = self::getComments($tableSql = $this->getTableSql($tableName));
        $columns = $this->connection->fetchAll("PRAGMA table_info ('$tableName')", [], PDO::FETCH_CLASS) ?: [];
        $pkCount = 0;
        $pkColumn = null;
        foreach ($columns as $column) {
            $structures[$column->name] = new Column([
                'name'=> $column->name,
                'realType' => $column->type,
                'type' => null,
                'length' => null,
                'unsigned' => false,
                'auto' => false,
                'null' => '1' !== $column->notnull,
                'default' => static::unEscape($column->dflt_value),
                'comment' => $comments[$column->name] ?? null,
            ]);
            if ($column->pk) {
                $pkColumn = $column->name;
                $pkCount++;
            }
        }
        $structures = array_map([$this, 'preparedDescribeType'], $structures);
        // 只有一个 pk 字段, 尝试判断其是否为 auto 字段
        if ($pkColumn && $pkCount < 2 && self::checkIfAutoincrement($tableSql, $pkColumn)) {
            $structures[$pkColumn]['auto'] = true;
            if (strtoupper(trim($structures[$pkColumn]->realType)) == 'INTEGER') {
                $structures[$pkColumn]->type = Schema::TYPE_INT;
            }
        }
        // 针对 enum 特殊处理
        if ($this->enumColumns) {
            foreach ($this->enumColumns as $enum) {
                if (preg_match('/check\s*\(\s*(`|)'.$enum.'(`|)\s*IN\s*\((.+)\)\s*\)/i', $tableSql, $match)) {
                    $structures[$enum]->length($match[3]);
                }
            }
        }
        return $structures;
    }

    /**
     * 获取创建表的 DDL 语句(不含索引外键)
     * @param string $tableName
     * @return string
     * @throws Exception
     */
    protected function getTableSql(string $tableName)
    {
        $row = $this->connection->fetchOne(
            "SELECT `sql` FROM `sqlite_master` WHERE `type`='table' AND `name` LIKE ?", [$tableName], PDO::FETCH_ASSOC
        );
        return is_array($row) ? $row['sql'] : null;
    }

    /**
     * 从 DDL 语句中获取创建SQL中的注释
     * @param string $tableSql
     * @return array
     */
    protected static function getComments(string $tableSql)
    {
        $comments = [];
        $tableSql = preg_replace('/create(\s*)table(.*?)\(/i', '', $tableSql);
        if (preg_match_all('/(\s*)(`|)(\w+)(`|)\s(.*?)--(.*+)/i', $tableSql, $matches)) {
            foreach ($matches[3] as $key => $column) {
                $column = trim($column);
                if (preg_match('/{type:([^}]*?)}(.*+)/', $matches[6][$key], $match)) {
                    $comments[$column] = [trim($match[1]), trim($match[2])];
                } else {
                    $comments[$column] = trim($matches[6][$key]);
                }
            }
        }
        return $comments;
    }

    /**
     * 检查 $table 表中的 $column 字段是否为自增字段
     * @param string $tableSql
     * @param string $pkColumn
     * @return bool
     */
    protected static function checkIfAutoincrement(string $tableSql, string $pkColumn)
    {
        $tableSql = str_replace(["\r\n", "\t", "\n"], '  ', $tableSql);
        return preg_match(sprintf(
            '/(,|\()(\s*)(`|)%s(`|)\s([^,]*?)%s([^,]*?),/is',$pkColumn,'AUTOINCREMENT'
        ), $tableSql. ',');
    }

    /**
     * 由 column type string 匹配对应的 typeName typeLength typeAttribute
     * ex: int(10) unsigned => [int, 10, unsigned]
     * @param Column $column
     * @return Column
     */
    protected function preparedDescribeType(Column $column)
    {
        $columnTypeTemp = $this->preparedColumnTypeDefined();
        if (is_array($column->comment)) {
            $column->realType = $column->comment[0];
            $column->comment = $column->comment[1];
        }
        if (preg_match('/((.+)\s)?(\w+)(\s*\(([^)]*?)\))?/', $column->realType, $match)) {
            $type = strtolower($match[3]);
            if ($typeName = array_search($type, $columnTypeTemp)) {
                $column->type($typeName)
                    ->length(isset($match[5]) ? trim($match[5]) : null)
                    ->unsigned(strpos(strtolower($match[2]), 'unsigned') !== false);
                if (Schema::TYPE_ENUM === $typeName) {
                    if (!$this->enumColumns) {
                        $this->enumColumns = [];
                    }
                    $this->enumColumns[] = $column->name;
                }
            }
        }
        return $column;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function indexes(string $tableName)
    {
        // 查找 pk 类型
        $primaryTemp = [];
        $columns = $this->connection->fetchAll("PRAGMA table_info('$tableName')", [], PDO::FETCH_CLASS) ?: [];
        foreach ($columns as $column) {
            if ($column->pk) {
                $primaryTemp[$column->name] = $column->type;
            }
        }
        // 只有一个字段的主键 且字段为INTEGER类型, 将不会创建隐式索引, 为了所有驱动保持一致, 此处强制添加上
        $primary = null;
        $primaryColumn = null;
        if (count($primaryTemp) < 2 && strtoupper(current($primaryTemp)) === 'INTEGER') {
            $primary = new Index([
                'name' => 'PRIMARY',
                'type' => 'PRIMARY',
                'implicit' => true,
                'column' => [key($primaryTemp)],
            ]);
        } else {
            $primaryColumn = array_keys($primaryTemp);
            sort($primaryColumn);
        }
        // 缓存隐式索引 (sql 为空的索引即隐式添加)
        $implicit = [];
        $items = $this->connection->fetchAll(
            "SELECT * FROM `sqlite_master` WHERE `type`='index' AND `tbl_name` LIKE ?", [$tableName], PDO::FETCH_CLASS
        ) ?: [];
        foreach ($items as $item) {
            $implicit[$item->name] = empty($item->sql);
        }
        // 查询所有索引
        $indexLists = [];
        $findPrimary = false;
        $items = $this->connection->fetchAll("pragma index_list('$tableName')", [], PDO::FETCH_CLASS) ?: [];
        foreach ($items as $item) {
            $columns = $this->connection->fetchAll(
                "pragma index_info('".$item->name."')", [], PDO::FETCH_COLUMN, 2
            ) ?: [];
            // 检测是否为 PRIMARY 索引
            $columnsTemp = null;
            if (!$findPrimary && $primaryColumn && $item->unique) {
                $columnsTemp = $columns;
                sort($columnsTemp);
            }
            if ($columnsTemp && $columnsTemp === $primaryColumn) {
                $name = $type = 'PRIMARY';
                $implicit = true;
                $findPrimary = true;
            } else {
                $name = $item->name;
                $type = $item->unique ? 'UNIQUE' : 'INDEX';
                $implicit = isset($implicit[$item->name]) && $implicit[$item->name];
            }
            $index = [
                'name' => $name,
                'type' => $type,
                'implicit' => $implicit,
                'column' =>  $columns,
            ];
            $indexLists[$name] = new Index($index);
        }
        if ($primary) {
            $indexLists['PRIMARY'] = $primary;
        }
        return array_reverse($indexLists);
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function foreigners(string $tableName)
    {
        return $this->getForeignersFromSql($tableName, $this->createSql($tableName));
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function constraints(string $tableName)
    {
        $lists = $this->connection->fetchAll(
            "SELECT `sql`,`tbl_name` FROM `sqlite_master` WHERE `type`='table'", [], PDO::FETCH_CLASS
        ) ?: [];
        $constraints = [];
        foreach ($lists as $list) {
            $foreigners = $this->getForeignersFromSql($list->tbl_name, $list->sql);
            foreach ($foreigners as $foreign) {
                if ($foreign->table === $tableName) {
                    $constraints[] = $foreign->table($list->tbl_name);
                }
            }
        }
        return $constraints;
    }

    /**
     * 表修改 事务开始  先关闭外键检查
     * @param string $tableName
     * @return bool
     * @throws Exception
     */
    public function builderTransaction(string $tableName)
    {
        $this->transactionCheckForeign = $this->connection->isCheckForeign();
        if ($this->transactionCheckForeign) {
            $this->connection->checkForeign(false);
        }
        return parent::builderTransaction($tableName);
    }

    /**
     * 表修改 事务合并  开启外键检查
     * @param string $tableName
     * @return bool
     */
    public function builderCommit(string $tableName)
    {
        if ($this->transactionCheckForeign) {
            $result = $this->connection->commit();
            $this->connection->checkForeign(true);
            return $result;
        }
        return parent::builderCommit($tableName);
    }

    /**
     * 表修改 事务回滚  开启外键检查
     * @param string $tableName
     * @return bool
     */
    public function builderRollback(string $tableName)
    {
        if ($this->transactionCheckForeign) {
            $this->connection->checkForeign(true);
        }
        return parent::builderRollback($tableName);
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function builder(Table $table)
    {
        $query = [];
        $table = $this->preparedTable($table);
        $query = array_merge($query, $this->builderTable($table, true));
        if (!$table->create) {
            $query = array_merge($query, $this->builderAffectedTable($table->name));
        }
        return $query;
    }

    /**
     * Table 复制临时表 -> 创建表 -> 转移数据 -> 删除临时表
     * @param Table $table
     * @param bool $initialize
     * @return array
     */
    protected function builderTable(Table $table, bool $initialize = false)
    {
        $query = [];
        if (!$table->create) {
            $query[] = 'CREATE TABLE `tanboltSqlite_temp_table` AS SELECT * FROM `'.$table->name.'`;';
            $query[] = 'DROP TABLE `'.$table->name.'`;';
        }
        $indexes = $this->builderIndex($table, $initialize, $primary);
        $foreigners = $this->builderForeign($table, $initialize);
        $columns = static::sortColumn($initialize ? $this->columnsMerge : $table->columns);
        $extraCount = count($foreigners) + (null === $primary ? 0 : 1);
        if ($primary !== null) {
            array_unshift($foreigners, $primary);
        }

        // create 准备
        $createColumn = $columnChange = [];
        $columnCount = count($columns);
        foreach ($columns as $column) {
            $columnCount--;
            $createColumn[] = sprintf('`%s` %s',
                ($column->rename ?: $column->name), $this->builderColumnType($column, !$extraCount && !$columnCount)
            );
            if ($column->command !== 'add') {
                $columnChange[$column->name] = ($column->rename ?: $column->name);
            }
        }
        if (count($foreigners)) {
            $createColumn[] = join(",\n", $foreigners);
        }
        $create = 'CREATE TABLE `'.$table->name.'` ';
        $create .= '(' . "\n" . join("\n", $createColumn) . "\n);";

        $query = array_merge($query, [$create], $indexes);
        if (!$table->create) {
            $query[] = sprintf(
                'INSERT INTO `%s` (`%s`) SELECT `%s` FROM `tanboltSqlite_temp_table`;',
                $table->name,
                join('`,`', $columnChange),
                join('`,`', array_keys($columnChange))
            );
            $query[] = 'DROP TABLE `tanboltSqlite_temp_table`;';
        }
        return $query;
    }

    /**
     * 如果修改字段刚好做为外键字段, 需同时修改其约束表的外键约束
     * @param $tableName
     * @return array
     * @throws Exception
     */
    protected function builderAffectedTable($tableName)
    {
        $tables = [];
        foreach ($this->affectedConstraints as $constraint) {
            $affectedTable = $constraint->table;
            if (!isset($tables[$affectedTable])) {
                $table = new Table([], false);
                $table->name($affectedTable);
                $table->columns = $this->columns($affectedTable);
                $table->indexes = $this->indexes($affectedTable);
                $table->foreigners = $this->foreigners($affectedTable);
                $tables[$affectedTable] = $table;
            }
            $tables[$affectedTable]->foreigners[$constraint->name] = $constraint->table($tableName);
        }
        $query = [];
        foreach ($tables as $table) {
            $query = array_merge($query, $this->builderTable($table));
        }
        return $query;
    }

    /**
     * 对字段重新排序
     * @param Column[] $columns
     * @return Column[]
     */
    protected static function sortColumn(array $columns)
    {
        // 判断是否有 after 字段并进行重新排序
        $fields = [];
        foreach ($columns as $name => $column) {
            if ($column->after == -1) {
                $column->after = null;
                $columns = array_merge([$name => $column], $columns);
            } elseif (!empty($column['after'])) {
                $fields[$name] = $column['after'];
            }
        }
        if (count($fields)) {
            foreach ($fields as $name => $sort) {
                $columns = self::array_after($columns, $name, $sort);
            }
        }
        return $columns;
    }

    /**
     * 移动数组中某个单元
     * @param array $array
     * @param string $key
     * @param string $after
     * @return array
     */
    protected static function array_after(array $array, string $key, string $after)
    {
        if (!isset($array[$key]) || !isset($array[$after])) {
            return $array;
        }
        $insert = [$key => $array[$key]];
        unset($array[$key]);
        $pos = array_search($after, array_keys($array));
        return array_merge(array_slice($array, 0, $pos + 1), $insert, array_slice($array, $pos + 1));
    }

    /**
     * 处理字段: 字段属性
     * @param Column $column
     * @param bool $last
     * @return string
     */
    protected function builderColumnType(Column $column, bool $last)
    {
        if (empty($column->realType)) {
            $realType = $this->columnType[$column->type];
            if (strpos($realType, '~length~') !== false) {
                if ($column->length !== null) {
                    if (is_array($column->length)) {
                        $column->length = "'".join("','", static::escape($column->length))."'";
                    }
                    $realType = str_replace('~length~', $column->length, $realType);
                } else {
                    $realType = str_replace('(~length~)', '', $realType);
                }
            }
            if (strpos($realType, '~unsigned~') !== false) {
                if ($column->unsigned) {
                    $realType = str_replace('~unsigned~', 'unsigned', $realType);
                } else {
                    $realType = str_replace('~unsigned~', '', $realType);
                }
            }
            if (strpos($realType, '~name~') !== false) {
                $realType = str_replace('~name~', ($column->rename ?: $column->name), $realType);
            }
        } else {
            $realType = $column->realType;
        }
        $comments = '';
        // auto 类型必须为 INTEGER, 将指定类型存放到 comments 中避免信息丢失
        if ($column->auto && strtoupper(trim($realType)) !== 'INTEGER') {
            $comments .= ' {type:'.$realType.'}';
            $realType = 'INTEGER';
        }
        if (!empty($column->comment)) {
            $comments .= ' '.$column->comment;
        }
        $default = '';
        if (null === $column->default) {
            $default = ' DEFAULT NULL';
            $column->null = true;
        } elseif (is_numeric($column->default)) {
            $default = ' DEFAULT '.$column->default;
        } else {
            if ($timestamp = self::currentTimestamp($column->type, $column->default)) {
                $default = ' DEFAULT '.$timestamp;
            } elseif (is_string($column->default)) {
                $default = " DEFAULT '".static::escape($column->default)."'";
            }
        }
        if ($column->null) {
            $realType .= ' NULL';
        } else {
            $realType .= ' NOT NULL';
        }
        if ($column->auto) {
            $realType .= ' PRIMARY KEY AUTOINCREMENT';
        } else {
            $realType .= $default;
        }
        if (!$last) {
            $realType .= ',';
        }
        if (!empty($comments)) {
            $realType .= ' --'.$comments;
        }
        return $realType;
    }

    /**
     * 处理索引
     * @param Table $table
     * @param bool $initialize
     * @param null $primary
     * @return array
     */
    protected function builderIndex(Table $table, bool $initialize = false, &$primary = null)
    {
        $query = [];
        if ($initialize) {
            if ($this->autoIncrement && $this->primaryKey &&
                (count($this->primaryKey->column) !== 1 || $this->primaryKey->column[0] !== $this->autoIncrement)
            ) {
                throw new DatabaseException('there can be only one auto column and it must be defined as a key.');
            }
            $indexes = $this->indexesMerge;
        } else {
            $indexes = $table->indexes;
        }
        foreach ($indexes as $index) {
            if (!count($index->column)) {
                continue;
            }
            if ($index->primary) {
                if ($this->autoIncrement && count($index->column) === 1 && $index->column[0] === $this->autoIncrement) {
                    continue;
                }
                $primary = 'PRIMARY KEY (`' . join('`,`', $index->column) . '`)';
            } else {
                $query[] = sprintf(
                    'CREATE%s INDEX `%s` ON `%s` (`%s`);',
                    ($index->unique ? ' UNIQUE' : ''),
                    ($index->rename ?: $index->name),
                    $table->name,
                    join('`,`', $index->column)
                );
            }
        }
        return $query;
    }

    /**
     * 处理外键
     * @param Table $table
     * @param bool $initialize
     * @return array
     */
    protected function builderForeign(Table $table, bool $initialize = false)
    {
        $query = [];
        $foreigners = $initialize ? $this->foreignersMerge : $table->foreigners;
        foreach ($foreigners as $foreign) {
            $statement = sprintf(
                'CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)',
                ($foreign->rename ?: $foreign->name),
                join('`,`', $foreign->column), $foreign->table, join('`,`', $foreign->reference)
            );
            if (!empty($foreign->onDelete)) {
                $statement .= ' ON DELETE '.$foreign->onDelete;
            }
            if (!empty($foreign->onUpdate)) {
                $statement .= ' ON UPDATE '.$foreign->onUpdate;
            }
            $query[] = $statement;
        }
        return $query;
    }

    /**
     * @inheritDoc
     */
    protected function checkConstraintReference(array $indexes, array $reference)
    {
        $hasIndex = false;
        sort($reference);
        $count = count($reference);
        foreach($indexes as $index) {
            if (!$index->primary && !$index->unique) {
                continue;
            }
            if ($count === count($column = $index->column)) {
                sort($column);
                if ($column === $reference) {
                    $hasIndex = true;
                    break;
                }
            }
        }
        return $hasIndex;
    }
}
