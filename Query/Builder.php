<?php
namespace Tanbolt\Database\Query;

use PDO;
use StdClass;
use Throwable;
use Exception;
use Generator;
use BadMethodCallException;
use InvalidArgumentException;
use Tanbolt\Database\Connection;
use Tanbolt\Database\Driver\Grammar;

/**
 * Class Builder: sql 语句构造器
 * @package Tanbolt\Database\Query
 * 该类发生变化, 影响 Database\Model\ActiveRecord
 *
 * @mixin WhereClause 魔术方法调用 WhereClause 函数
 *
 * @method $this having($left, $operator = null, $right = null) 字段值与指定值比较
 * @method $this orHaving($left, $operator = null, $right = null)
 *
 * @method $this havingOn($left, $operator, $right = null) 两个字段值比较
 * @method $this orHavingOn($left, $operator, $right = null)
 *
 * @method $this havingTime($left, $operator, $right = null) 字段值与指定时间比较
 * @method $this orHavingTime($left, $operator, $right = null)
 * @method $this havingUnix($left, $operator, $right = null)
 * @method $this orHavingUnix($left, $operator, $right = null)
 *
 * @method $this havingNull($column) 字段值是否为 NULL
 * @method $this orHavingNull($column)
 * @method $this havingNotNull($column)
 * @method $this orHavingNotNull($column)
 *
 * @method $this havingExists($builder) Exists子查询条件
 * @method $this orHavingExists($builder)
 * @method $this havingNotExists($builder)
 * @method $this orHavingNotExists($builder)
 */
class Builder extends Expression implements BuilderInterface
{
    /**
     * 连接器
     * @var Connection
     */
    protected $connection = null;

    /**
     * 语法构造器
     * @var Grammar
     */
    protected $grammar = null;

    /**
     * 查询字段
     * @var Builder[]|Expression[]|array
     */
    protected $selects = [];

    /**
     * 是否查询去重结果
     * @var bool
     */
    protected $distinct = false;

    /**
     * 是否查询合集
     * @var array
     */
    protected $aggregate = null;

    /**
     * 查询表
     * @var Builder|Expression|string
     */
    protected $from = null;

    /**
     * 联表条件
     * @var JoinClause[]
     */
    protected $joins = [];

    /**
     * 查询条件
     * @var WhereClause
     */
    protected $wheres = null;

    /**
     * @var array
     */
    protected $groups = [];

    /**
     * 分组 查询条件
     * @var WhereClause
     */
    protected $having = null;

    /**
     * 排序
     * @var array
     */
    protected $orders = [];

    /**
     * limit
     * @var int
     */
    protected $limit = null;

    /**
     * offset
     * @var int
     */
    protected $offset = null;

    /**
     * @var array
     */
    protected $unions = [];

    /**
     * 加上 unions 后的 排序
     * @var array
     */
    protected $unionOrders = [];

    /**
     * 加上 unions 后的 limit
     * @var int
     */
    protected $unionLimit = null;

    /**
     * 加上 unions 后的 offset
     * @var int
     */
    protected $unionOffset = null;

    /**
     * where 条件语句默认 table
     * @var string
     */
    protected $prefixTable = null;

    /**
     * 加锁
     * @var bool
     */
    protected $lock = null;

    /**
     * 可读变量
     * @var array
     */
    private static $publicProperty = [
        'selects', 'distinct', 'aggregate', 'from', 'joins',
        'wheres', 'groups', 'having', 'orders', 'limit', 'offset',
        'unions', 'unionOrders', 'unionLimit', 'unionOffset',
        'prefixTable', 'lock',
    ];

    /**
     * 查询语句绑定参数
     * @var array
     */
    private $bindings = null;

    /**
     * 自增字段名称
     * @var string
     */
    private $incrementColumn;

    /**
     * 这里使用 static 方式，主要为了 ActiveRecord
     * @var int|string
     */
    private static $lastId = 0;

    /**
     * Schema constructor.
     * @param Connection $connection
     * @param ExpressionInterface|callable|string|null $table
     * @param ?string $as
     */
    public function __construct(Connection $connection, $table = null, $as = null)
    {
        $this->connection = $connection;
        $this->grammar = $this->connection->driver->grammar->prefix(function() {
            return $this->connection->prefix;
        });
        if ($table) {
            $this->from($table, $as);
        }
        parent::__construct();
    }

    /**
     * 使用当前对象的 Connection 创建一个新 builder 对象
     * @param ExpressionInterface|callable|string|null $table
     * @param ?string $as
     * @return $this
     */
    public function createBuilder($table = null, string $as = null)
    {
        return new static($this->connection, $table, $as);
    }

    /**
     * 设置 where table
     * @param ?string $table
     * @return $this
     */
    public function setPrefixTable(?string $table)
    {
        $this->prefixTable = $table;
        return $this;
    }

    /**
     * 获取当前 builder 中的 connect 对象
     * @return Connection
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * 设置自增字段的字段名
     * @param ?string $column
     * @return $this
     */
    public function setIncrementColumn(?string $column)
    {
        $this->incrementColumn = $column;
        return $this;
    }

    /**
     * 获取自增字段的字段名
     * @return ?string
     */
    public function getIncrementColumn()
    {
        return $this->incrementColumn;
    }

    /**
     * 处理一个 query 值
     * @param ExpressionInterface|callable|string|null $expression
     * @return ExpressionInterface|string
     */
    protected function preparedQuery($expression)
    {
        if (is_callable($expression)) {
            $callback = $expression;
            call_user_func($callback, $expression = $this->createBuilder());
        }
        if ($expression instanceof ExpressionInterface) {
            return $expression;
        }
        if (is_scalar($expression)) {
            return preg_replace('/\s+/', ' ',trim($expression));
        }
        throw new InvalidArgumentException;
    }

    /**
     * 是否使用 master 服务器进行查询操作
     * @param bool $master
     * @return $this
     */
    public function userMaster(bool $master = true)
    {
        $this->connection->useMaster($master);
        return $this;
    }

    /**
     * 查询数据并加锁(会强制使用 master 服务器) lock for update
     * @return $this
     */
    public function lockForeUpdate()
    {
        $this->lock = true;
        return $this->userMaster();
    }

    /**
     * 解除查询数据锁 lock shared
     * @return $this
     */
    public function lockShared()
    {
        $this->lock = false;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function select(...$columns)
    {
        $count = count($columns);
        if (!$count) {
            $this->selects = ['*'];
        } elseif (1 === $count && !$columns[0]) {
            $this->selects = null;
            return $this;
        }
        $this->selects = [];
        return $this->addSelect($columns);
    }

    /**
     * 添加查询列
     * - ex: addSelect('column', 'table.column', ['foo', 'bar as biz'])
     * @param mixed $columns
     * @return $this
     */
    public function addSelect(...$columns)
    {
        $selects = self::flattenArray($columns, [$this, 'preparedQuery']);
        $this->selects = array_merge($this->selects, $selects);
        $this->bindings = null;
        return $this;
    }

    /**
     * 设置 是否查询去重结果
     * @param bool $distinct
     * @return $this
     */
    public function distinct(bool $distinct = true)
    {
        $this->distinct = $distinct;
        return $this;
    }

    /**
     * 设置查询表名称
     * @param ExpressionInterface|callable|string|null $table
     * @param ?string $as
     * @return $this
     */
    public function from($table, string $as = null)
    {
        if (null !== $table) {
            $table = $this->preparedQuery($table);
            if ($as) {
                if (is_string($table)) {
                    $table = trim($table, '`');
                    if ($this->connection->prefix && strpos($table, $this->connection->prefix) !== 0) {
                        $table = $this->connection->prefix . $table;
                    }
                    $table = new Expression('`'.$table.'`');
                }
                $table->alias($as);
            }
        }
        $this->from = $table;
        $this->bindings  = null;
        return $this;
    }

    /**
     * 通过 using 条件添加一个联合查询表
     * @param ExpressionInterface|callable|string|null $table
     * @param array|string $columns
     * @param ?string $joinType
     * @return $this
     */
    public function joinUsing($table, $columns, string $joinType = null)
    {
        return $this->addJoin($table, $columns, null, null, $joinType);
    }

    /**
     * 通过 on 条件添加一个联合查询表，可省略 $operator，会自动设置为 "="，既该方法有两个原型
     * - `joinOn($table, $left, $operator, $right = null, string $joinType = null)`
     * - `joinOn($table, $left, $right = null, string $joinType = null)`
     * @param ExpressionInterface|callable|string|null $table
     * @param ExpressionInterface|string $left
     * @param ExpressionInterface|string $operator
     * @param ExpressionInterface|string|null $right
     * @param string|null $joinType
     * @return $this
     */
    public function joinOn($table, $left, $operator, $right = null, string $joinType = null)
    {
        if (func_num_args() < 4) {
           $right = $operator;
           $operator = '=';
        }
        return $this->addJoin(
            $table, null, compact('left', 'operator', 'right'), null, $joinType
        );
    }

    /**
     * joinOn 函数的一个实现（$joinType = 'LEFT'），可省略 $operator，会自动设置为 "="，既该方法有两个原型
     * - `leftJoinOn($table, $left, $operator, $right = null)`
     * - `leftJoinOn($table, $operator, $right = null)`
     * @param ExpressionInterface|callable|string|null $table
     * @param ExpressionInterface|string $left
     * @param ExpressionInterface|string $operator
     * @param ExpressionInterface|string|null $right
     * @return $this
     * @see joinOn
     */
    public function leftJoinOn($table, $left, $operator, $right = null)
    {
        if (func_num_args() < 4) {
            $right = $operator;
            $operator = '=';
        }
        return $this->addJoin(
            $table, null, compact('left', 'operator', 'right'), null, 'LEFT'
        );
    }

    /**
     * joinOn 函数的一个实现（$joinType = 'RIGHT'），可省略 $operator，会自动设置为 "="，既该方法有两个原型
     * - `rightJoinOn($table, $left, $operator, $right = null)`
     * - `rightJoinOn($table, $operator, $right = null)`
     * @param ExpressionInterface|callable|string|null $table
     * @param ExpressionInterface|string $left
     * @param ExpressionInterface|string $operator
     * @param ExpressionInterface|string|null $right
     * @return $this
     * @see joinOn
     */
    public function rightJoinOn($table, $left, $operator, $right = null)
    {
        if (func_num_args() < 4) {
            $right = $operator;
            $operator = '=';
        }
        return $this->addJoin(
            $table, null, compact('left', 'operator', 'right'), null, 'RIGHT'
        );
    }

    /**
     * 通过 where 条件添加一个联合查询表，可省略 $operator，会自动设置为 "="，既该方法有两个原型
     * - `joinWhere($table, $left, $operator = null, $right = null, string $joinType = null)`
     * - `joinWhere($table, $left, $right = null, string $joinType = null)`
     * @param ExpressionInterface|callable|string|null $table
     * @param WhereClause|callable|string $left
     * @param ExpressionInterface|callable|string $operator
     * @param ExpressionInterface|callable|string $right
     * @param ?string $joinType
     * @return $this
     */
    public function joinWhere($table, $left, $operator = null, $right = null, string $joinType = null)
    {
        if (is_callable($left)) {
            call_user_func($left, $where = new WhereClause($this));
            $joinType = $operator;
        } elseif ($left instanceof WhereClause) {
            $where = $left;
            $joinType = $operator;
        } else {
            $where = new WhereClause($this);
            if (func_num_args() < 4) {
                $right = $operator;
                $operator = '=';
            }
            $where->where($left, $operator, $right);
        }
        return $this->addJoin($table, null, null, $where, $joinType);
    }

    /**
     * add join query
     * @param ExpressionInterface|callable|string|null $table
     * @param array|string|null $using
     * @param ?array $on
     * @param WhereClause|null $where
     * @param ?string $joinType
     * @return $this
     */
    protected function addJoin($table, $using = null, array $on = null, WhereClause $where = null, string $joinType = null)
    {
        $join = new JoinClause($this->preparedQuery($table));
        if ($using) {
            $join->using($using);
        } elseif ($on) {
            $join->on($on);
        } else {
            $join->where($where);
        }
        $this->joins[] = $join->joinType($joinType);
        $this->bindings  = null;
        return $this;
    }

    /**
     * 清除所有已设置 join 数据表
     * @return $this
     */
    public function clearJoin()
    {
        $this->joins = [];
        $this->bindings = null;
        return $this;
    }

    /**
     * 设置 group by 字段
     * @param array|string $columns
     * @return $this
     */
    public function group($columns)
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }
        $this->groups = [];
        foreach ($columns as $column) {
            $this->groups = array_merge($this->groups, is_array($column) ? $column : [$column]);
        }
        return $this;
    }

    /**
     * 设置 order by 字段、排序方式
     * @param array|string $column
     * @param bool $asc
     * @return $this
     */
    public function order($column, bool $asc = false)
    {
        $this->{$this->unions ? 'unionOrders' : 'orders'}[] = [
            'column' => !is_array($column) ? [$column] : $column,
            'asc' => $asc,
        ];
        return $this;
    }

    /**
     * 设置查询结果数 limit 值
     * @param int $limit
     * @param ?int $offset
     * @return $this
     */
    public function limit(int $limit, int $offset = null)
    {
        $this->{$this->unions ? 'unionLimit' : 'limit'} = $limit;
        if (null !== $offset) {
            return $this->offset($offset);
        }
        return $this;
    }

    /**
     * 设置查询起点 offset 值
     * @param int $value
     * @return $this
     */
    public function offset(int $value)
    {
        $this->{$this->unions ? 'unionOffset' : 'offset'} = $value;
        return $this;
    }

    /**
     * 设置 union 子查询
     * @param Builder|callable $builder
     * @param bool $all
     * @return $this
     */
    public function union($builder, bool $all = false)
    {
        if (is_callable($builder)) {
            $call = $builder;
            call_user_func($call, $builder = $this->createBuilder());
        }
        $this->unions[] = compact('builder', 'all');
        $this->bindings  = null;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function query()
    {
        return $this->grammar->builderSelect($this);
    }

    /**
     * @inheritDoc
     */
    public function getBindings()
    {
        if ($this->bindings) {
            return $this->bindings;
        }
        $queries = [
            $this->selects, $this->from, $this->joins, $this->wheres, $this->having
        ];
        foreach ($this->unions as $union) {
            $queries[] = $union['builder'];
        }
        return $this->bindings = $this->mergeBindings($queries);
    }

    /**
     * 判断记录是否存在
     * @return bool
     * @throws Exception
     */
    public function exists()
    {
        if ($query = $this->grammar->builderExists($this)) {
            $get = $this->connection->fetchOne($query, $this->getBindings(), PDO::FETCH_OBJ);
            if ($get && isset($get->exists)) {
                return (bool) $get->exists;
            }
        }
        return false;
    }

    /**
     * 查询一条数据
     * @param mixed $style
     * @param null $argument
     * @param null $ctor_args
     * @return StdClass|array|false
     * @throws Exception
     * @see http://php.net/manual/zh/pdostatement.fetchall.php 后面三个参数作用
     */
    public function getOne($style = null, $argument = null, $ctor_args = null)
    {
        if ('' === $query = trim($this->query())) {
            return false;
        }
        return $this->connection->fetchOne($query, $this->getBindings(), $style, $argument, $ctor_args);
    }

    /**
     * 查询一列数据
     * @param mixed $style
     * @param null $argument
     * @param null $ctor_args
     * @return array
     * @throws Exception
     * @see http://php.net/manual/zh/pdostatement.fetchall.php 后面三个参数作用
     */
    public function getMany($style = null, $argument = null, $ctor_args = null)
    {
        if ('' === $query = trim($this->query())) {
            return [];
        }
        return (array) $this->connection->fetchAll($query, $this->getBindings(), $style, $argument, $ctor_args);
    }

    /**
     * 通过 PDO 内部指针循环获取数据，相比直接 getMany 更加节省内存
     * @param int $chunk
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return Generator
     * @throws Exception
     * @see http://php.net/manual/zh/pdostatement.fetchall.php 后面三个参数作用
     */
    public function getCursor(int $chunk = 1, $style = null, $argument = null, $ctor_args = null)
    {
        return $this->connection->cursor(trim($this->query()), $this->getBindings(), $chunk, $style, $argument, $ctor_args);
    }

    /**
     * 内建函数 查询 ($execute 是否直接返回结果, 不直接返回结果)
     * @param ?string $function
     * @param array|string|null $columns
     * @param bool $execute
     * @return $this|int
     * @throws Exception
     */
    public function aggregate(?string $function, $columns = '*', bool $execute = true)
    {
        if (null === $function) {
            $this->aggregate = null;
            return $this;
        }
        $this->aggregate = compact('function', 'columns');
        if ($execute) {
            $get = $this->getOne(PDO::FETCH_OBJ);
            return $get ? $get->aggregate : false;
        }
        return $this;
    }

    /**
     * 查询总记录数
     * @return int
     * @throws Exception
     */
    public function records()
    {
        return (int) $this->aggregate('count');
    }

    /**
     * 求和查询
     * @param array|string|null $column
     * @return int
     * @throws Exception
     */
    public function sum($column)
    {
        return (int) $this->aggregate(__FUNCTION__, $column);
    }

    /**
     * 查询最大值
     * @param array|string|null $column
     * @return int
     * @throws Exception
     */
    public function max($column)
    {
        return (int) $this->aggregate(__FUNCTION__, $column);
    }

    /**
     * 查询最小值
     * @param array|string|null $column
     * @return int
     * @throws Exception
     */
    public function min($column)
    {
        return (int) $this->aggregate(__FUNCTION__, $column);
    }

    /**
     * 查询平均值
     * @param array|string|null $column
     * @return int
     * @throws Exception
     */
    public function avg($column)
    {
        return (int) $this->aggregate(__FUNCTION__, $column);
    }

    /**
     * 更新数据
     * @param array $dates
     * @return bool|int
     * @throws Exception
     */
    public function update(array $dates)
    {
        if (!($query = $this->grammar->builderUpdate($this, $dates, $bindings))) {
            return false;
        }
        return $this->connection->execute($query, $bindings);
    }

    /**
     * 新增数据
     * @param array $dates
     * @param bool $replace
     * @return bool|int
     * @throws Throwable
     */
    public function insert(array $dates, bool $replace = false)
    {
        if (!($inserts = $this->preparedInsertDates($dates, true))) {
            return false;
        }
        // 防止 dates 中既有指明主键的数据, 也有未指明主键的数据, 插入过程主键冲突, 插入失败
        return $this->connection->transaction(function() use ($inserts, $replace) {
            return $this->insertDates($inserts, $replace, true);
        });
    }

    /**
     * 新增数据 (通过子查询)
     * @param BuilderInterface|callable $select
     * @param array|string $columns
     * @return bool|int
     * @throws Exception
     */
    public function insertFrom($select, $columns = null)
    {
        if (is_callable($select)) {
            $callback = $select;
            call_user_func($callback, $select = $this->createBuilder());
        }
        if (!$select instanceof BuilderInterface) {
            return false;
        }
        if (($query = $this->grammar->builderInsertFrom($this, $select, $columns))) {
            $row = $this->connection->execute($query, $select->getBindings());
            // 该方式返回 last id 总为数组
            if ($column = $this->getIncrementColumn()) {
                $lastId = $this->connection->lastId($column);
                if ($this->connection->driver->connector->isAbsoluteLastId()) {
                    static::$lastId = range($lastId - $row + 1, $lastId);
                } else {
                    static::$lastId = range($lastId, $lastId + $row - 1);
                }
            }
            return $row;
        }
        return false;
    }

    /**
     * 获取 insert 最后一条数据 id
     * @return int|string
     */
    public function lastId()
    {
        return static::$lastId;
    }

    /**
     * (新增 or else 修改) 数据
     * mysql sqlite 支持 replace into, 但前提是更新字段中必须包含主键字段
     * 并且 php5.5以下(sqlite < 3.8), sqlite pdo 驱动有bug(至少 windows 下实测出现问题)
     * 鉴于以上情况, upsert 不使用 replace into 方式, 而是先查询然后使用 insert 或 update
     * @param array $dates
     * @param array|string $search
     * @return bool|int
     * @throws Throwable
     */
    public function upsert(array $dates, $search)
    {
        if (!is_array($search)) {
            $search = [$search];
        }
        if (!is_array(reset($dates))) {
            $dates = [$dates];
        }
        // 由 search 指定的主键整理查询数组
        $upsert = [];
        $inClause = [];
        $onePrimary = count($search) < 2;
        foreach ($dates as $data) {
            if (count(array_diff($search, array_keys($data)))) {
                continue;
            }
            if ($onePrimary) {
                $primaryValue = $data[$search[0]];
                $primaryKey = $primaryValue instanceof ExpressionInterface ? $primaryValue->query() : $primaryValue;
            } else {
                $primaryKey = [];
                $primaryValue = array_map(function($key) use ($data, &$primaryKey) {
                    $item = $data[$key];
                    $primaryKey[] = $item instanceof ExpressionInterface ? $item->query() : (string) $item;
                    return $item;
                }, $search);
                $primaryKey = md5(serialize($primaryKey));
            }
            $inClause[] = $primaryValue;
            $upsert[(string) $primaryKey] = $data;
        }
        // 查询已存在的数据
        $existLists = $this->select($search)
            ->where($onePrimary ? $search[0] : $search, 'IN', $inClause)
            ->getMany(PDO::FETCH_ASSOC);
        $this->select(false)->clearWhere();
        $exists = [];
        foreach ($existLists as $list) {
            if ($onePrimary) {
                $exists[] = (string) $list[$search[0]];
            } else {
                $exists[] = md5(serialize(array_map(function($key) use ($list) {
                    return (string) $list[$key];
                }, $search)));
            }
        }
        // 分拣为 update 和 insert 两个数组
        $inserts = $updates = [];
        foreach($upsert as $key => $date) {
            if (in_array($key, $exists)) {
                $updates[] = $date;
            } else {
                $inserts[] = $date;
            }
        }
        // 根据分拣结果 分别 更新 或 插入
        $inserts = $this->preparedInsertDates($inserts);
        $updates = $this->preparedUpdateDates($updates, $search);
        return $this->connection->transaction(function() use ($inserts, $updates, $search) {
            $row = 0;
            if (!empty($inserts)) {
                $row += $this->insertDates($inserts);
            }
            if (!empty($updates)) {
                $row += $this->updateDates($updates, $search);
            }
            return $row;
        });
    }

    /**
     * 删除数据
     * @return bool|int
     * @throws Exception
     */
    public function delete()
    {
        if (!($query = $this->grammar->builderDelete($this))) {
            return false;
        }
        return $this->connection->execute($query, $this->mergeBindings([$this->from, $this->wheres]));
    }

    /**
     * prepared insert dates
     * @param array $dates
     * @param bool $preparedLastId
     * @return array|bool
     */
    protected function preparedInsertDates(array $dates, bool $preparedLastId = false)
    {
        if (empty($dates)) {
            return false;
        }
        $inserts = [];
        if (!is_array(reset($dates))) {
            static::$lastId = 0;
            $inserts[] = [$dates];
        } else {
            $sort = 0;
            static::$lastId = [];
            foreach ($dates as $date) {
                if (empty($date)) {
                    continue;
                }
                ksort($date);
                $key = array_keys($date);
                $md5 = md5(serialize($key));
                if (!isset($inserts[$md5])) {
                    $inserts[$md5] = [];
                }
                if ($preparedLastId && $this->getIncrementColumn()) {
                    $date['_tanbolt_sort_'] = $sort++;
                }
                $inserts[$md5][] = $date;
            }
        }
        return $inserts;
    }

    /**
     * prepared insert bindings
     * @param array $inserts
     * @return array
     */
    protected function preparedInsertBindings(array $inserts)
    {
        $bindings = [];
        foreach ($inserts as $insert) {
            foreach ($insert as $val) {
                if ($val instanceof ExpressionInterface) {
                    $bindings = array_merge($bindings, $val->getBindings());
                } else {
                    $bindings[] = $val;
                }
            }
        }
        return $bindings;
    }

    /**
     * insert prepared dates
     * @param array $inserts
     * @param bool $replace
     * @param bool $withLastId
     * @return false|int
     * @throws Exception
     */
    protected function insertDates(array $inserts, bool $replace = false, bool $withLastId = false)
    {
        $rows = 0;
        $column = $withLastId ? $this->getIncrementColumn() : null;
        $multiple = $column && is_array(static::$lastId);
        $lastIds = [];
        foreach ($inserts as $insert) {
            if (empty($insert)) {
                continue;
            }
            // 手工指定主键的值 和 未指定主键值的 需区分对待
            $customGroup = $autoGroup = null;
            if ($multiple) {
                foreach ($insert as $key => $item) {
                    if (isset($item[$column])) {
                        $customGroup[$item['_tanbolt_sort_']] = $item[$column];
                        unset($insert[$key]['_tanbolt_sort_']);
                    }
                }
                if (!$customGroup) {
                    foreach ($insert as $key => $item) {
                        $autoGroup[] = $item['_tanbolt_sort_'];
                        unset($insert[$key]['_tanbolt_sort_']);
                    }
                }
            }
            $query = $this->grammar->builderInsert($this, $insert, $replace);
            if ($query && $row = $this->connection->execute($query, $this->preparedInsertBindings($insert))) {
                $rows += $row;
                // 同时插入多条数据的情况, 根据是否手动指定主键值处理 last id
                if ($multiple) {
                    if ($customGroup) {
                        $lastIds = $lastIds + $customGroup;
                    } elseif ($autoGroup) {
                        $currentLastId = $this->connection->lastId($column);
                        if ($this->connection->driver->connector->isAbsoluteLastId()) {
                            $currentLastId = range($currentLastId - $row + 1, $currentLastId);
                        } else {
                            $currentLastId = range($currentLastId, $currentLastId + $row - 1);
                        }
                        $lastIds = $lastIds + array_combine($autoGroup, $currentLastId);
                    }
                }
            }
        }
        if ($column) {
            if ($multiple) {
                ksort($lastIds);
                static::$lastId = $lastIds;
            } else {
                $insert = reset($inserts);
                static::$lastId = $insert[$column] ?? $this->connection->lastId($column);
            }
        }
        return $rows;
    }

    /**
     * @param array $dates
     * @param array $search
     * @return array
     */
    protected function preparedUpdateDates(array $dates, array $search)
    {
        $updates = [];
        $onePrimary = count($search) < 2;
        foreach ($dates as $date) {
            if ($onePrimary) {
                $primary = $date[$search[0]];
                unset($date[$search[0]]);
            } else {
                $primary = [];
                foreach ($search as $key) {
                    $primary[$key] = $date[$key];
                    unset($date[$key]);
                }
            }
            if (empty($date)) {
                continue;
            }
            ksort($date);
            $hash = md5(serialize($date));
            if (!isset($updates[$hash])) {
                $updates[$hash] = [
                    'dates' => $date,
                    'primary' => [],
                ];
            }
            $updates[$hash]['primary'][] = $primary;
        }
        return $updates;
    }

    /**
     * @param array $updates
     * @param array $search
     * @return bool|int
     * @throws Exception
     */
    protected function updateDates(array $updates, array $search)
    {
        $row = 0;
        $primary = count($search) > 1 ? $search : $search[0];
        foreach ($updates as $update) {
            if (empty($update)) {
                continue;
            }
            $row += $this->clearWhere()->where($primary, 'IN', $update['primary'])->update($update['dates']);
        }
        $this->clearWhere();
        return $row;
    }

    /**
     * 清空所有已设置的 where 条件
     * @return $this
     */
    public function clearWhere()
    {
        if ($this->wheres) {
            $this->bindings = null;
            $this->wheres = null;
        }
        return $this;
    }

    /**
     * 清空所有已设置的 having 条件
     * @return $this
     */
    public function clearHaving()
    {
        if ($this->having) {
            $this->bindings = null;
            $this->having = null;
        }
        return $this;
    }

    /**
     * add where clause
     * @param string $method
     * @param array $arguments
     * @return $this
     */
    protected function addWhere(string $method, array $arguments)
    {
        if (!$this->wheres) {
            $this->wheres = new WhereClause($this, $this->prefixTable);
        }
        $this->bindings = null;
        call_user_func_array([$this->wheres, $method], $arguments);
        return $this;
    }

    /**
     * add where clause
     * @param string $method
     * @param array $arguments
     * @return $this
     */
    protected function addHaving(string $method, array $arguments)
    {
        if (!$this->having) {
            $this->having = new WhereClause($this, $this->prefixTable);
        }
        $this->bindings = null;
        call_user_func_array([$this->having, $method], $arguments);
        return $this;
    }

    /**
     * 获取 Builder 当前设置 (selects,join,where,having....), 主要用于数据库驱动扩展中
     * @param string $key
     * @return mixed|null
     */
    public function parameter(string $key)
    {
        return in_array($key, self::$publicProperty) ? $this->{$key} : null;
    }

    /**
     * 工具函数, 作用是把 层层嵌套的 array/ExpressionInterface 转为一维数组, 主要用于数据库驱动扩展中
     * @param array $queries
     * @return array
     */
    public function mergeBindings(array $queries)
    {
        $bindings = [];
        foreach ($queries as $query) {
            if (is_array($query)) {
                foreach ($query as $subQuery) {
                    if ($subQuery instanceof ExpressionInterface) {
                        $bindings = array_merge($bindings, $subQuery->getBindings());
                    }
                }
            } elseif ($query instanceof ExpressionInterface) {
                $bindings = array_merge($bindings, $query->getBindings());
            }
        }
        return $bindings;
    }

    /**
     * @param $method
     * @param $arguments
     * @return Builder
     */
    public function __call($method, $arguments)
    {
        $method = strtolower($method);
        if (substr($method, 0, 5) === 'where' || substr($method, 0, 7) === 'orwhere') {
            return $this->addWhere($method, $arguments);
        } elseif (substr($method, 0, 6) === 'having' || substr($method, 0, 8) === 'orhaving') {
            return $this->addHaving($method, $arguments);
        }
        throw new BadMethodCallException("Method $method does not exist.");
    }
}
