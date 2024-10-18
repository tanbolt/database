<?php
namespace Tanbolt\Database\Schema;

use Tanbolt\Database\Exception\DatabaseException;

/**
 * Class Table: 数据表抽象类
 * @package Tanbolt\Database\Schema
 *
 * @property bool $create 是否为创建新表(否则为修改旧表)
 * @property ?string $name 表名
 * @property ?string $engine 数据表引擎
 * @property ?string $collation 数据表语言
 * @property ?string $charset 数据表编码
 * @property Column[] $columns 数据表字段合集
 * @property Index[] $indexes 数据表索引合集
 * @property Foreign[] $foreigners 数据表外键约束合集
 *
 * @method $this name(?string $name) 设置表名
 * @method $this engine(?string $engine) 设置引擎
 * @method $this collation(?string $collation) 设置语言
 * @method $this charset(?string $charset) 设置编码
 */
class Table extends Collection
{
    // timestamp 类型默认值为当前时间
    const CURRENT_TIMESTAMP = 'CURRENT_TIMESTAMP';

    // FOREIGN_UPDATE, FOREIGN_DELETE: 外键约束处理方法
    const FOREIGN_ACTION_RESTRICT = 'RESTRICT';
    const FOREIGN_ACTION_CASCADE = 'CASCADE';
    const FOREIGN_ACTION_SETDEFAULT = 'SET DEFAULT';
    const FOREIGN_ACTION_SETNULL = 'SET NULL';
    const FOREIGN_ACTION_NOACTION = 'NO ACTION';

    /**
     * @var string
     */
    protected $readName = 'create';

    /**
     * @var array
     */
    protected $accepts = [
        'name' => null,
        'engine' => null,
        'collation' => null,
        'charset' => null,
    ];

    /**
     * @var array
     */
    public $columns = [];

    /**
     * @var array
     */
    public $indexes = [];

    /**
     * @var array
     */
    public $foreigners = [];

    /**
     * 通过字段名生成 索引/外键约束 的名称
     * @param string $tableName
     * @param array|string $columns
     * @param string $type
     * @return string|null
     */
    public static function preparedIndexName(string $tableName, $columns, string $type = 'index')
    {
        $key = null;
        if (is_array($columns)) {
            $key = implode('_', $columns);
        } elseif (is_string($columns)) {
            $key = trim($columns);
        }
        if ($key) {
            return str_replace(['-', '.'], '_', strtolower($tableName.'_'.$key.'_'.$type));
        }
        return null;
    }

    /**
     * @param string $type
     * @param string $command
     * @param array $parameters
     * @return Column|Foreign|Index
     */
    protected function setTable(string $type, string $command, array $parameters = [])
    {
        $name = $parameters['name'] ?? null;
        if (!is_string($name) || empty($name)) {
            throw new DatabaseException($type . ' name must defined.');
        }
        if ($this->create && 'add' !== $command) {
            throw new DatabaseException('Could not '.$command.' '.$type.' when create table.');
        }
        if ('Column' === $type) {
            $items = &$this->columns;
        } elseif ('Index' === $type) {
            $items = &$this->indexes;
        } elseif ('Foreign' === $type) {
            $items = &$this->foreigners;
        } else {
            throw new DatabaseException('setTable '.$type.' not support.');
        }
        if (!array_key_exists($name, $items)) {
            $type = __NAMESPACE__ .'\\'. $type;
            $parameters = array_merge(['name' => $name], $parameters);
            $items[$name] = new $type($parameters, $command);
        } else {
            throw new DatabaseException($type.' ['.$name.'] have added with command ['.$items[$name]->command.'].');
        }
        return $items[$name];
    }

    /**
     * 新增字段
     * @param string $name 字段名
     * @param array $parameters 字段属性
     * @return Column
     * @see Column::$accepts 支持的字段属性
     */
    public function addColumn(string $name, array $parameters = [])
    {
        return $this->setTable('Column', 'add', array_merge(['name' => $name], $parameters));
    }

    /**
     * 修改字段
     * @param string $name 字段名
     * @param array|string $parameters 新字段名或字段属性
     * @return Column
     * @see Column::$accepts 支持的字段属性
     */
    public function alterColumn(string $name, $parameters = [])
    {
        if (is_string($parameters)) {
            $parameters = ['rename' => $parameters];
        }
        return $this->setTable('Column', 'alter', array_merge(['name' => $name], (array) $parameters));
    }

    /**
     * 删除字段
     * @param string $name
     * @return Column
     */
    public function dropColumn(string $name)
    {
        return $this->setTable('Column', 'drop', ['name' => $name]);
    }

    /**
     * 索引/外键 兼容处理
     * @param string $type
     * @param array|string $name
     * @param array $args
     * @return array
     */
    protected function preparedIndex(string $type, $name, array $args  = [])
    {
        if (is_array($name)) {
            $args = array_merge(['column' => $name], $args);
        } elseif (is_string($name)) {
            $args = array_merge(['name' => $name], $args);
        }
        if (empty($args['name']) && isset($args['column']) && !empty($args['column'])) {
            if ($name = static::preparedIndexName($this->name, $args['column'], $type)) {
                $args['name'] = $name;
            }
        }
        return $args;
    }

    /**
     * 新增索引
     * @param array|string $name 索引名，如果索引名为数组，则认为是指定了索引字段，会自动生成索引名
     * @param array $parameters 索引属性
     * @return Index
     * @see Index::$accepts 支持的索引属性
     */
    public function addIndex($name, array $parameters = [])
    {
        return $this->setTable('Index', 'add', $this->preparedIndex('index', $name, $parameters));
    }

    /**
     * 修改索引
     * @param array|string $name 索引名或通过数组指定索引字段, 或 "PRIMARY"
     * @param array|string $parameters 新索引名|索引属性
     * @return Index
     * @see Index::$accepts 支持的索引属性
     */
    public function alterIndex($name, $parameters = [])
    {
        if (!is_array($name) && strtoupper($name) === 'PRIMARY') {
            $name = 'PRIMARY';
        }
        if (is_string($parameters)) {
            $parameters = ['rename' => $parameters];
        }
        return $this->setTable('Index', 'alter', $this->preparedIndex('index', $name, $parameters));
    }

    /**
     * 删除索引
     * @param array|string $name 索引名或通过数组指定索引字段, 或 "PRIMARY"
     * @return Index
     */
    public function dropIndex($name)
    {
        if (!is_array($name) && strtoupper($name) === 'PRIMARY') {
            $name = 'PRIMARY';
        }
        return $this->setTable('Index', 'drop', $this->preparedIndex('index', $name, []));
    }

    /**
     * 新增外键
     * @param array|string $name 外键名，如果外键名为数组，则认为指定了外键字段，外键名自动生成
     * @param array $parameters 外键属性
     * @return Foreign
     * @see Foreign::$accepts 支持的外键属性
     */
    public function addForeign($name, array $parameters = [])
    {
        return $this->setTable('Foreign', 'add', $this->preparedIndex('foreign', $name, $parameters));
    }

    /**
     * 修改外键
     * @param array|string $name 外键名
     * @param array|string $parameters 外键属性 或 新外键名
     * @return Foreign
     * @see Foreign::$accepts 支持的外键属性
     */
    public function alterForeign($name, $parameters = [])
    {
        if (is_string($parameters)) {
            $parameters = ['rename' => $parameters];
        }
        return $this->setTable('Foreign', 'alter', $this->preparedIndex('foreign', $name, $parameters));
    }

    /**
     * 删除外键
     * @param array|string $name
     * @return Foreign
     */
    public function dropForeign($name)
    {
        return $this->setTable('Foreign', 'drop', $this->preparedIndex('foreign', $name, []));
    }

    /**
     * @inheritDoc
     */
    public function toArray()
    {
        $attributes = parent::toArray();
        $attributes['columns'] = [];
        foreach ($this->columns as $name => $column) {
            $attributes['columns'][$name] = $column->toArray();
        }
        $attributes['indexes'] = [];
        foreach ($this->indexes as $name => $column) {
            $attributes['indexes'][$name] = $column->toArray();
        }
        $attributes['foreigners'] = [];
        foreach ($this->foreigners as $name => $column) {
            $attributes['foreigners'][$name] = $column->toArray();
        }
        return $attributes;
    }
}
