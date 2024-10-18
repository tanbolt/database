<?php
namespace Tanbolt\Database\Driver\Mysql;

use PDO;
use Exception;
use Tanbolt\Database\Schema\Table;
use Tanbolt\Database\Schema\Index;
use Tanbolt\Database\Schema\Column;
use Tanbolt\Database\Schema\Foreign;
use Tanbolt\Database\Schema\Schema;
use Tanbolt\Database\Exception\DatabaseException;
use Tanbolt\Database\Driver\Structure as StructureDriver;

class Structure extends StructureDriver
{
    /*
     * Mysql DLL 是否支持回滚
     * 5.7 之前至少是不支持的, 若不支持, 则会在SQL语句执行前, 尽可能判断语句是否正确
     * 避免在出错时 无法回滚, 而数据表进行部分修改
     */
    const SUPPORT_DDL_ROLLBACK = false;

    /**
     * 类型映射
     * @var array
     */
    protected $columnType = [
        Schema::TYPE_INT => 'int(~length~) ~unsigned~',
        Schema::TYPE_TINYINT => 'tinyint(~length~) ~unsigned~',
        Schema::TYPE_SMALLINT => 'smallint(~length~) ~unsigned~',
        Schema::TYPE_MEDIUMINT => 'mediumint(~length~) ~unsigned~',
        Schema::TYPE_BIGINT => 'bigint(~length~) ~unsigned~',
        Schema::TYPE_BOOL => 'tinyint(1)',

        Schema::TYPE_FLOAT => 'float(~length~) ~unsigned~',
        Schema::TYPE_DOUBLE => 'double(~length~) ~unsigned~',
        Schema::TYPE_DECIMAL => 'decimal(~length~) ~unsigned~',

        Schema::TYPE_CHAR => 'char(~length~) ~charset~',
        Schema::TYPE_VARCHAR => 'varchar(~length~) ~charset~',
        Schema::TYPE_TEXT => 'text ~charset~',
        Schema::TYPE_MEDIUMTEXT => 'mediumtext ~charset~',
        Schema::TYPE_LONGTEXT => 'longtext ~charset~',
        Schema::TYPE_JSON => 'text ~charset~',
        Schema::TYPE_BLOB => 'blob',
        Schema::TYPE_ENUM => 'enum(~length~) ~charset~',

        Schema::TYPE_DATE => 'date',
        Schema::TYPE_TIME => 'time(~length~)',
        Schema::TYPE_DATETIME => 'datetime(~length~)',
        Schema::TYPE_TIMESTAMP => 'timestamp(~length~)',
    ];

    /**
     * 转义字符串 近似于 mysql_real_escape_string
     * @param array|string $var
     * @return array|string
     * @link http://php.net/manual/zh/function.mysql-real-escape-string.php#101248
     */
    protected static function escape($var)
    {
        if(is_array($var)) {
            return array_map(__METHOD__, $var);
        }
        if(!empty($var) && is_string($var)) {
            return str_replace(
                ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
                ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
                $var
            );
        }
        return $var;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function rename(string $from, string $to)
    {
        return (bool) $this->connection->statement('RENAME TABLE `'.$from.'` TO `'.$to.'`;');
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function clear(string $tableName)
    {
        $pretend = $this->connection->pretending();
        $count = (true === $pretend || false === $pretend) ? 1 : $this->connection->fetchOne(
            "SELECT COUNT(*) AS d FROM `{$tableName}`", [], PDO::FETCH_COLUMN, 0
        );
        if ($this->connection->statement('TRUNCATE `' . $tableName . '`')) {
            return $count;
        }
        return false;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function drop(string $tableName)
    {
        return (bool) $this->connection->statement('DROP TABLE `' . $tableName . '`');
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function dropIf(string $tableName)
    {
        return (bool) $this->connection->statement('DROP TABLE IF EXISTS `' . $tableName. '`');
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function createSql(string $tableName)
    {
        $row = $this->connection->fetchOne('SHOW CREATE TABLE `' . $tableName . '`', [], PDO::FETCH_BOTH);
        return is_array($row) ? $row[1] : null;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function columns(string $tableName)
    {
        $structures = [];
        $columns = $this->connection->fetchAll('SHOW FULL COLUMNS FROM `' . $tableName . '`', [], PDO::FETCH_CLASS);
        $columns = is_array($columns) ? $columns : [];
        foreach ($columns as $column) {
            // 未指定 default 值, 且字段接受 null 才为 null,  否则认为未指定
            $default = $column->Default;
            if (null === $default && 'NO' === $column->Null) {
                $default = false;
            }
            $structures[$column->Field] = new Column([
                'name' => $column->Field,
                'realType' => $column->Type,
                'type' => null,
                'length' => null,
                'unsigned' => false,
                'auto' => false,
                'null' => 'YES' === $column->Null,
                'collation' => $column->Collation,
                'default' => $default,
                'comment' => $column->Comment,
            ]);
            if (strpos($column->Extra, 'auto_increment') !== false) {
                $structures[$column->Field]['auto'] = true;
            }
        }
        return array_map([$this, 'preparedDescribeType'], $structures);
    }

    /**
     * 由 column type string 匹配对应的 typeName typeLength typeAttribute
     * ex: int(10) unsigned => [int, 10, unsigned]
     * @param Column $column
     * @return Column
     */
    protected function preparedDescribeType(Column $column)
    {
        $columnTypeDefined = $this->preparedColumnTypeDefined();
        if (preg_match('/(\w+)(\(([^)]*?)\))?(\s(.+))?/', $column->realType, $match)) {
            $type = strtolower($match[1]);
            if ($typeName = array_search($type, $columnTypeDefined)) {
                $column->type($typeName)
                    ->length(isset($match[3]) ? trim($match[3]) : null)
                    ->unsigned(isset($match[5]) && strpos(strtolower($match[5]), 'unsigned') !== false);
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
        $indexes = [];
        $columns = $this->connection->fetchAll('SHOW INDEX FROM `' . $tableName . '`', [], PDO::FETCH_CLASS);
        $columns = is_array($columns) ? $columns : [];
        foreach ($columns as $column) {
            $keyName = $column->Key_name;
            if ($column->Index_type == 'BTREE') {
                if (strtoupper($keyName) == 'PRIMARY') {
                    $type = 'PRIMARY';
                } elseif ($column->Non_unique > 0) {
                    $type = 'INDEX';
                } else {
                    $type = 'UNIQUE';
                }
            } else {
                $type = $column->Index_type;
            }
            if (!isset($indexes[$keyName])) {
                $indexes[$keyName] = [
                    'name' => $keyName,
                    'type' => $type,
                    'column' => [
                        $column->Column_name,
                    ],
                ];
            } else {
                array_push($indexes[$keyName]['column'], $column->Column_name);
            }
        }
        return array_map(function ($column) {
            return new Index($column);
        }, $indexes);
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
        $lower = false;
        // LOWER_CASE_TABLE_NAMES===1 时, 数据表名会存储为小写
        $test = $this->connection->fetchOne("SHOW SESSION VARIABLES LIKE 'LOWER_CASE_TABLE_NAMES';", [], PDO::FETCH_ASSOC);
        if (is_array($test) && (strtolower($test['Value']) === 'on' || $test['Value'] * 1 === 1)) {
            $lower = true;
        }
        $config = $this->connection->config;
        $tableName = $lower ? strtolower($tableName) : $tableName;
        $dbname = $lower ? strtolower($config['dbname']) : $config['dbname'];
        $this->connection->pdo->exec('use `information_schema`');
        $lists = $this->connection->fetchAll(
            'SELECT distinct `TABLE_NAME` FROM `REFERENTIAL_CONSTRAINTS` WHERE `CONSTRAINT_SCHEMA` = ?
            AND `REFERENCED_TABLE_NAME` = ? AND `REFERENCED_TABLE_NAME` != `TABLE_NAME`',
            [$dbname, $tableName], PDO::FETCH_CLASS
        );
        $this->connection->pdo->exec('use `'.$dbname.'`');
        $constraints = [];
        if (!is_array($lists)) {
            $lists = [];
        }
        foreach ($lists as $list) {
            $foreigners = $this->foreigners($list->TABLE_NAME);
            foreach ($foreigners as $foreign) {
                if ($foreign->table === $tableName) {
                    $constraints[] = $foreign->table($list->TABLE_NAME);
                }
            }
        }
        return $constraints;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function builder(Table $table)
    {
        $table = $this->preparedTable($table);
        $columns = $this->builderColumn($table);
        $indexes = $this->builderIndex($table);
        $foreigners = static::builderForeign($table, $specialForeigners);
        $encoding = self::builderTableEncoding($table);
        if ($table->create) {
            $query = 'CREATE TABLE `'.$table->name.'` (';
            if (!count($columns)) {
                throw new DatabaseException('At least one column definition was expected.');
            }
            $query .= "\n" . join(",\n", $columns);
            if (count($indexes)) {
                $query .= ",\n".join(",\n", $indexes);
            }
            if (count($foreigners)) {
                $query .= ",\n".join(",\n", $foreigners);
            }
            $query .= "\n)" . $encoding.';';
            if (count($specialForeigners)) {
                return array_merge([$query], $specialForeigners);
            }
            return [$query];
        }
        // 如果有修改/删除外键的命令  先执行 删除外键功能, 因为字段修改受外键限制
        $query = $specialForeigners;
        // 再执行表修改
        $alter = [];
        if (!empty($encoding)) {
            $alter[] = $encoding;
        }
        if (count($columns)) {
            $alter = array_merge($alter, $columns);
        }
        if (count($indexes)) {
            $alter = array_merge($alter, $indexes);
        }
        if (count($alter)) {
            $query[] = sprintf('ALTER TABLE `%s` %s;', $table->name, join(",\n", $alter));
        }
        // 最后执行外键命令
        if (count($foreigners)) {
            $query = array_merge($query, $foreigners);
        }
        return $query;
    }

    /**
     * 建/改表:字段处理
     * @param Table $table
     * @return array
     */
    protected function builderColumn(Table $table)
    {
        $query = [];
        foreach ($table->columns as $column) {
            if ('drop' === $column->command) {
                $query[] = sprintf('DROP `%s`', $column->rename);
            } else {
                $statement = sprintf('`%s` %s', $column->rename, $this->builderColumnType($column));
                if ('add' === $column->command) {
                    if (!$table->create) {
                        if ($column->after !== null) {
                            // 如果为 -1 则认为是置于开头
                            if ($column->after == -1) {
                                $statement .= ' FIRST';
                            } else {
                                $statement .= ' AFTER `'.$column->after.'`';
                            }
                        }
                        $statement = 'ADD ' . $statement;
                    }
                } else {
                    $statement = sprintf('CHANGE `%s` %s', $column->name, $statement);
                }
                $query[] = $statement;
            }
        }
        return $query;
    }

    /**
     * 字段处理: 字段属性
     * @param Column $column
     * @return string
     */
    protected function builderColumnType(Column $column)
    {
        if (self::emptyString($column->realType)) {
            $realType = $this->columnType[$column->type];
            if (false !== strpos($realType, '~length~')) {
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
            if (strpos($realType, '~charset~') !== false) {
                if ($column->collation !== null) {
                    $realType = str_replace('~charset~', static::builderColumnCharset($column->collation), $realType);
                } else {
                    $realType = str_replace('~charset~', '', $realType);
                }
            }
        } else {
            $realType = $column->realType;
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
            $realType .= ' AUTO_INCREMENT';
        } else {
            $realType .= $default;
        }
        if (!empty($column->comment)) {
            $realType .= " COMMENT '". static::escape($column->comment)."'";
        }
        return $realType;
    }

    /**
     * 字段处理: 字段编码
     * @param string $collation
     * @return string
     */
    protected static function builderColumnCharset(string $collation)
    {
        if (($collation = trim($collation)) == '') {
            return '';
        }
        $collations = explode('_', $collation, 2);
        $charset = $collations[0];
        return sprintf(' CHARACTER SET %s COLLATE %s', $charset, $collation);
    }

    /**
     * 建/改表:索引处理
     * @param Table $table
     * @return array
     */
    protected function builderIndex(Table $table)
    {
        $query = [];
        if ($this->autoIncrement) {
            if ($table->create && !$this->primaryKey) {
                $query[] = 'PRIMARY KEY (`'.$this->autoIncrement.'`)';
            } else {
                if (!count($this->primaryKey->column) || $this->autoIncrement !== $this->primaryKey->column[0]) {
                    throw new DatabaseException('there can be only one auto column and it must be defined as a key.');
                }
            }
        }
        foreach ($table->indexes as $index) {
            if ('drop' === $index->command) {
                $query[] = 'DROP INDEX `'.$index->name.'`';
            } else {
                $statement = '';
                if ($index->primary) {
                    $statement .= 'PRIMARY KEY ';
                } elseif ($index->unique) {
                    $statement .= 'UNIQUE KEY `'.$index->rename.'` ';
                } elseif (empty($index->type) || strtoupper($index->type) === 'INDEX') {
                    $statement .= 'KEY `'.$index->rename.'`';
                } else {
                    $statement .= $index->type.' KEY `'.$index->rename.'` ';
                }
                $statement .= '(`'.join('`,`', $index->column).'`)';
                if ('add' === $index->command) {
                    $query[] = ($table->create ? '' : 'ADD ') . $statement;
                } else {
                    $query[] = 'DROP INDEX `'.$index->name.'`, ADD ' . $statement;
                }
            }
        }
        return $query;
    }

    /**
     * 建/改表:外键处理
     * @param Table $table
     * @param ?array $specialForeigners
     * @return array
     */
    protected static function builderForeign(Table $table, array &$specialForeigners = null)
    {
        $query = [];
        $specialForeigners = [];
        foreach ($table->foreigners as $foreign) {
            $drop = 'ALTER TABLE `'.$table->name.'` DROP FOREIGN KEY `'.$foreign->name.'`;';
            if ('drop' === $foreign->command) {
                $specialForeigners[] = $drop;
                continue;
            }
            $statement = sprintf(
                'CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)',
                $foreign->rename, join('`,`', $foreign->column),
                $foreign->table, join('`,`', $foreign->reference)
            );
            if (!empty($foreign->onDelete)) {
                $statement .= ' ON DELETE '.$foreign->onDelete;
            }
            if (!empty($foreign->onUpdate)) {
                $statement .= ' ON UPDATE '.$foreign->onUpdate;
            }
            $add = 'ALTER TABLE `'.$table->name.'` ADD '.$statement.';';
            if ('add' === $foreign->command) {
                if ($table->create) {
                    $foreign->table === $table->name ?  ($specialForeigners[] = $add) :  ($query[] = $statement);
                } else {
                    $query[] = $add;
                }
            } else {
                $specialForeigners[] = $drop;
                $query[] = $add;
            }
        }
        return $query;
    }

    /**
     * 处理数据表引擎编码
     * @param Table $table
     * @return string
     */
    protected static function builderTableEncoding(Table $table)
    {
        $tableEncoding = '';
        if ($table->engine !== null) {
            $tableEncoding .= ' ENGINE='.$table->engine;
        } elseif ($table->create) {
            $tableEncoding .= ' ENGINE=InnoDB';  //默认设置为 InnoDB 引擎
        }
        if ($table->charset !== null) {
            $tableEncoding .= ' DEFAULT CHARACTER SET '.$table->charset;
        }
        if ($table->collation !== null) {
            $tableEncoding .= ' COLLATE '.$table->collation;
        }
        return $tableEncoding;
    }

    /**
     * @inheritDoc
     */
    protected function checkConstraintKey(Foreign $foreign, array $indexes, array $column)
    {
        if (in_array($foreign->command, ['add', 'alter']) && !array_key_exists($foreign->name, $indexes)) {
            return true;
        }
        return $this->checkConstraintReference($indexes, $column);
    }

    /**
     * @inheritDoc
     */
    protected function checkConstraintReference(array $indexes, array $reference)
    {
        $hasIndex = false;
        foreach($indexes as $index) {
            if (count($index->column) >= count($reference) && $reference == array_slice($index->column, 0, count($reference))) {
                $hasIndex = true;
                break;
            }
        }
        return $hasIndex;
    }

    /**
     * @inheritDoc
     */
    protected function checkConstraintType(Column $key, Column $reference)
    {
        if (in_array($key->type, [
            Schema::TYPE_INT,        Schema::TYPE_TINYINT,  Schema::TYPE_SMALLINT,
            Schema::TYPE_MEDIUMINT,  Schema::TYPE_BIGINT,   Schema::TYPE_BOOL,
            Schema::TYPE_FLOAT,      Schema::TYPE_DOUBLE,   Schema::TYPE_DECIMAL,
        ])) {
            if ($reference->type !== $key->type || $reference->unsigned !== $key->unsigned) {
                return false;
            }
        } elseif (in_array($key->type, [
            Schema::TYPE_DATE, Schema::TYPE_TIME, Schema::TYPE_DATETIME, Schema::TYPE_TIMESTAMP,
        ])) {
            if ($reference->type !== $key->type) {
                return false;
            }
        } elseif (in_array($key->type, [
            Schema::TYPE_CHAR, Schema::TYPE_VARCHAR
        ])) {
            if (!in_array($reference->type, [Schema::TYPE_CHAR, Schema::TYPE_VARCHAR]) ||
                $reference->collation !== $key->collation) {
                return false;
            }
        } elseif (Schema::TYPE_ENUM === $key->type) {
            if ($reference->type !== $key->type  || $reference->collation !== $key->collation) {
                return false;
            }
        } elseif (in_array($key->type, [
            Schema::TYPE_TEXT, Schema::TYPE_MEDIUMTEXT, Schema::TYPE_LONGTEXT, Schema::TYPE_JSON, Schema::TYPE_BLOB,
        ])) {
            return false;
        }
        return true;
    }
}
