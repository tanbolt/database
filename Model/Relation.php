<?php
namespace Tanbolt\Database\Model;

use PDO;
use Generator;
use Exception;
use LogicException;
use OverflowException;
use Tanbolt\Database\Model;
use Tanbolt\Database\Query\Builder;
use Tanbolt\Database\Query\Expression;
use Tanbolt\Database\Query\WhereClause;
use Tanbolt\Database\Query\BuilderInterface;
use Tanbolt\Database\Exception\ModelNotFoundException;

/**
 * Class Relation: 在 ActiveRecord 基础上类实现针对 Relation 的查询构造器
 * - 调用查询构造器时, 默认优先添加了 [关联模型默认的 where 约束] 和 [与主模型对应的关联字段的约束]
 * - 同时针对使用中间表的情况, 添加了 withPivot() 辅助方法
 * @package Tanbolt\Database\Model
 * @mixin ActiveRecord
 */
class Relation implements BuilderInterface
{
    /**
     * 关联模型挂载到主模型上使用名称
     * @var string
     */
    protected $relation;

    /**
     * 关联模型对象 NewRecord
     * @var Model
     */
    protected $model;

    /**
     * 关联模型 class name
     * @var string
     */
    protected $relationModelClass;

    /**
     * 主模型对象
     * @var Model
     */
    protected $parent;

    /**
     * 主模型 class name
     * @var string
     */
    protected $relationParentClass;

    /**
     * 以关联模型作为 Model 的 ActiveRecord 对象
     * @var ActiveRecord
     */
    protected $activeRecord;

    /**
     * ActiveRecord 对象的 Builder 对象
     * @var Builder
     */
    protected $activeBuilder;

    /**
     * @var bool
     */
    protected $activeRecordCalled;

    /**
     * 关联模型的约束字段
     * @var string
     */
    protected $foreignKey;

    /**
     * 主模型约束字段
     * @var string
     */
    protected $localKey;

    /**
     * @var bool
     */
    protected $selectMany;

    /**
     * @var ?callable
     */
    protected $relationWhere;

    /**
     * 当前中间表， 表名 或 model class name
     * @var string
     */
    protected $pivot;

    /**
     * 是否为通过表名设置的中间表
     * @var bool
     */
    protected $pivotIsTable;

    /**
     * 中间表的表名称
     * $pivotIsTable=true -> $pivotTableName === $pivot
     * $pivotIsTable=false -> $pivotTableName === $pivot()->getTable()
     * @var string
     */
    protected $pivotTableName;

    /**
     * 中间表主键字段,
     * 当通过 Model 指定, 该值可忽略, 自动获取
     * 若通过 Table 指定, 可不指定，但建议指定
     * @var string|array
     */
    protected $pivotPrimaryKey;

    /**
     * 中间表中 与 关联模型 相对应的字段名
     * @var string
     */
    protected $pivotRelationKey;

    /**
     * 中间表中 与 主模型 相对应的字段名
     * @var string
     */
    protected $pivotParentKey;

    /**
     * 需要捎带着查询的中间表字段
     * @var array|string
     */
    protected $pivotColumns;

    /**
     * 定义关联模型时， 设置的 中间表限定条件
     * @var ?callable
     */
    protected $pivotConstraint;

    /**
     * 查询时，通过 wherePivot 设置的 中间表限定条件
     * @var ?callable
     */
    protected $pivotWhere;

    /**
     * @var int|string
     */
    protected $freeValue = 0;

    /**
     * @var array
     */
    private static $queryDirectFunction = [
        'connection', 'insert', 'insertfrom', 'upsert', 'lastid',
        'getincrementcolumn'
    ];

    /**
     * @var array
     */
    private static $querySelectFunction = [
        'exists',
        'aggregate', 'records', 'sum', 'max', 'min', 'avg',
        'update', 'delete',
    ];

    /**
     * Relation constructor.
     * @param Model $model
     * @param Model $parent
     * @param string $foreignKey
     * @param string $localKey
     * @param bool $many
     */
    public function __construct(Model $model, Model $parent, string $foreignKey, string $localKey, bool $many = false)
    {
        $this->model = $model;
        $this->relationModelClass = get_class($model);
        $this->parent = $parent;
        $this->relationParentClass = get_class($parent);
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
        $this->selectMany = $many;
    }

    /**
     * @param ?callable $where
     * @param bool $many 是否获取多条关联模型
     * @param ?string $name
     * @return $this
     */
    public function resetRelationBuilder(callable $where = null, bool $many = false, string $name = null)
    {
        $this->relation = $name;
        $this->relationWhere = $where;
        $this->pivot = null;
        $this->pivotColumns = null;
        $this->pivotWhere = null;
        $this->selectMany = $many;

        // 关联模型 relation 的 连接器 connection 应该与 主模型 parent 相同
        // 设置 activeRecord builder 的 prefixTable, 在使用 where group order 等方法时可自动添加表前缀
        $this->model->setConnection($this->parent->getConnection());
        $this->activeRecord = new ActiveRecord($this->model);
        $this->activeBuilder = $this->activeRecord->modelBuilder(false)->setPrefixTable($this->model->getTable());
        $this->activeRecordCalled = null;
        return $this;
    }

    /**
     * 获取关联模型的对象
     * - 比如 (Model) user -> (Relation) address, 得到 address 对象
     * @return Model|object
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * 获取主模型对象
     * - 比如 (Model) user -> (Relation) address, 得到 user 对象
     * @return Model|object
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * 是否为获取多条数据的关联模型
     * - 比如 user->address, isMany()=true 查询会获取到 (Collection)[address, address], 否则得到 address
     * @return bool
     */
    public function isMany()
    {
        return $this->selectMany;
    }

    /**
     * 获取关联模型的约束字段
     * - 比如 user->address, 获取到 address 中 user_id 字段
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignKey;
    }

    /**
     * 获取主模型的主键字段
     * - 比如 user->address, 获取到 user 中的 id 字段
     * @return string
     */
    public function getLocalKey()
    {
        return $this->localKey;
    }

    /**
     * 获取中间表名称
     * @return ?string
     */
    public function getPivot()
    {
        return $this->pivot;
    }

    /**
     * 是否为通过表名设置的中间表
     * @return bool
     */
    public function isTablePivot()
    {
        return $this->pivot && $this->pivotIsTable;
    }

    /**
     * 获取中间表中与关联模型对应的字段
     * - 比如 user->address, 获取到中间表中 等于 address.user_id 的字段
     * @return ?string
     */
    public function getPivotRelationKey()
    {
        return $this->pivot ? $this->pivotRelationKey : null;
    }

    /**
     * 获取中间表中与主模型对应的字段
     * - 比如 user->address, 获取到中间表中 等于 user.id 的字段
     * @return ?string
     */
    public function getPivotParentKey()
    {
        return $this->pivot ? $this->pivotParentKey : null;
    }

    /**
     * 获取中间表自己的主键字段
     * @return array|string|null
     */
    public function getPivotPrimaryKey()
    {
        return $this->pivot ? $this->pivotPrimaryKey : null;
    }

    /**
     * 设置关联模型与主模型解除关联后，对应字段被重置的值，比如 0, null
     * @param int|string|null $value
     * @return $this
     */
    public function setFreeValue($value)
    {
        $this->freeValue = $value;
        return $this;
    }

    /**
     * 获取关联模型与主模型解除关系之后，对应字段被重置的值
     * @return int|string|null
     */
    public function getFreeValue()
    {
        return $this->freeValue;
    }

    /**
     * 通过 Model 设置关联模型的中间表
     * @param string $model
     * @param string $relationKey
     * @param string $parentKey
     * @param ?callable $where
     * @return $this
     */
    public function throughModel(string $model, string $relationKey, string $parentKey, callable $where = null)
    {
        $this->pivot = $model;
        $this->pivotIsTable = false;
        $this->pivotRelationKey = $relationKey;
        $this->pivotParentKey = $parentKey;
        $this->pivotConstraint = $where;

        /** @var Model $pivotModel */
        $pivotModel = new $model([], false);
        $this->pivotTableName = $pivotModel->getTable();
        $this->pivotPrimaryKey = $pivotModel->getPrimaryColumn();
        unset($pivotModel);
        return $this;
    }

    /**
     * 通过数据表名称设置关联模型的中间表
     * - throughTable($table, $relationKey, $parentKey, $where = null, $primaryKey = null)
     * - throughTable($table, $relationKey, $parentKey, $primaryKey = null)
     * @param string $table
     * @param string $relationKey
     * @param string $parentKey
     * @param callable|array|string|null $where
     * @param array|string|null $primaryKey
     * @return $this
     */
    public function throughTable(string $table, string $relationKey, string $parentKey, $where = null, $primaryKey = null)
    {
        $this->pivot = $table;
        $this->pivotIsTable = true;
        $this->pivotTableName = $this->pivot;
        $this->pivotRelationKey = $relationKey;
        $this->pivotParentKey = $parentKey;
        if (is_callable($where)) {
            $this->pivotConstraint = $where;
            $this->pivotPrimaryKey = $primaryKey;
        } else {
            $this->pivotConstraint = null;
            $this->pivotPrimaryKey = $where;
        }
        return $this;
    }

    /**
     * 获取关联模型时, 顺带查询返回中间表的指定字段
     * - 如 user->address，$relations = user->address()->withPivot('foo', 'bar')->find()
     * 结果为 Address 模型
     * @param mixed $pivot 要查询的中间表
     * @return $this
     */
    public function withPivot(...$pivot)
    {
        if (!$this->pivot) {
            return $this;
        }
        if (!func_num_args()) {
            $this->pivotColumns = '*';
            return $this;
        }
        try {
            $columns = [];
            $parameters = $pivot;
            array_walk_recursive($parameters, function ($x) use (&$columns) {
                if ('*' === $x) {
                    throw new OverflowException();
                }
                if (!empty($x)) {
                    $columns[] = $x;
                }
            });
            $this->pivotColumns = $columns;
        } catch (OverflowException $e) {
            // 要捎带着查询所有中间表字段
            $this->pivotColumns = '*';
        }
        return $this;
    }

    /**
     * 获取关联模型时，设置中间表的限制条件
     * @param ?callable $where
     * @return $this
     */
    public function wherePivot(callable $where = null)
    {
        if ($this->pivot) {
            $this->pivotWhere = $where;
        }
        return $this;
    }

    /**
     * 获取以当前关联模型作为 Model 源的 ActiveRecord 对象
     * - ActiveRecord 自动设置了关联模型默认的 where 约束
     * - ActiveRecord 自动设置了与主模型对应的关联字段的约束
     * - 由于当前 Model 使用了模式函数映射了 ActiveRecord 的所有方法, 可直接调用 ActiveRecord 方法，所以一般情况该函数用不到
     * @param bool $isMatch (true:设置关联字段=主模型字段) (false:设置关联字段=主模型字段值/或手工指定的值)
     * @param mixed $foreignValue 若 $isMatch=false, 可手工指定一个值, 否则使用当前主模型对应的字段值
     * @return ActiveRecord
     */
    public function activeRecord(bool $isMatch = false, $foreignValue = null)
    {
        if ($this->activeRecordCalled) {
            return $this->activeRecord;
        }
        $this->activeRecordCalled = true;

        // 定义关联模型时 设置的 where 条件
        if ($this->relationWhere) {
            $relatedWhere = new WhereClause($this->activeBuilder, $this->model->getTable());
            call_user_func($this->relationWhere, $relatedWhere);
            $this->activeRecord->where($relatedWhere);
        }

        // 关联字段的限制条件
        if ($this->pivot) {
            $this->setPivotConstraint($isMatch, $foreignValue);
        } else {
            if ($isMatch) {
                $this->activeRecord->whereOn(
                    $this->model->getTablePrimary($this->foreignKey),
                    $this->parent->getTablePrimary($this->localKey)
                );
            } else {
                $foreignValue = empty($foreignValue) ? $this->parent->getAttribute($this->localKey) : $foreignValue;
                if (is_array($foreignValue)) {
                    $this->activeRecord->where($this->model->getTablePrimary($this->foreignKey), 'IN', $foreignValue);
                } else {
                    $this->activeRecord->where($this->model->getTablePrimary($this->foreignKey), $foreignValue);
                }
            }
        }
        return $this->activeRecord;
    }

    /**
     * 设置中间表查询字段 约束条件
     * @param bool $isMatch
     * @param mixed $parentValue
     * @return $this
     */
    protected function setPivotConstraint(bool $isMatch = false, $parentValue = null)
    {
        // 需要查询的中间表字段
        if ('*' === $this->pivotColumns) {
            // 查询所有字段
            $this->activeRecord->addSelect(Expression::raw("'*'"), 'pivot.*', Expression::raw("'**'"));
        } else {
            // 查询 指定的字段 和 关联字段
            $columns = $this->pivotColumns ?: [];
            if (!empty($this->pivotPrimaryKey)) {
                $primaryKey = is_array($this->pivotPrimaryKey) ? $this->pivotPrimaryKey : [$this->pivotPrimaryKey];
                foreach ($primaryKey as $key) {
                    if (!in_array($key, $columns)) {
                        $columns[] = $key;
                    }
                }
            }
            if (!in_array($this->pivotRelationKey, $columns)) {
                $columns[] = $this->pivotRelationKey;
            }
            if (!in_array($this->pivotParentKey, $columns)) {
                $columns[] = $this->pivotParentKey;
            }
            $this->pivotColumns = [];
            foreach ($columns as $key) {
                $this->pivotColumns[] = 'pivot.'.$key.' AS pivot_'.$key;
            }
            $this->activeRecord->addSelect($this->pivotColumns);
        }

        // pivot join
        $table = $this->pivotTableName.' AS pivot';
        $tableKey = $this->model->getTable().'.'.$this->foreignKey;
        $relationKey = 'pivot.'.$this->pivotRelationKey;
        $parentKey = 'pivot.'.$this->pivotParentKey;
        $this->activeRecord->joinOn($table, $tableKey, '=', $relationKey);

        // foreign constraint
        if ($isMatch) {
            $this->activeRecord->whereOn($parentKey, $this->parent->getTablePrimary($this->localKey));
        } else {
            $parentValue = empty($parentValue) ? $this->parent->getAttribute($this->localKey) : $parentValue;
            if (is_array($parentValue)) {
                $this->activeRecord->where($parentKey, 'IN', $parentValue);
            } else {
                $this->activeRecord->where($parentKey, $parentValue);
            }
        }

        // pivot constraint
        if ($this->pivotConstraint) {
            $pivotConstraint = new WhereClause($this->activeBuilder, 'pivot');
            call_user_func($this->pivotConstraint, $pivotConstraint);
            $this->activeRecord->where($pivotConstraint);
        }

        // pivot where
        if ($this->pivotWhere) {
            $pivotWhere = new WhereClause($this->activeBuilder, 'pivot');
            call_user_func($this->pivotWhere, $pivotWhere);
            $this->activeRecord->where($pivotWhere);
        }
        return $this;
    }

    /**
     * 重新实现 getOne 方法：若 $fetch = PDO::FETCH_NAMED, 不再处理中间表数据, 需自行处理
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return array|object|false
     * @throws Exception
     */
    public function getOne($style = null, $argument = null, $ctor_args = null)
    {
        if (!$this->pivot) {
            return $this->activeRecord()->getOne($style, $argument, $ctor_args);
        }
        $verify = (object) [];
        $row = $this->verifyFetchMode($verify, $style, $argument, $ctor_args)->getOne(PDO::FETCH_NAMED);
        if ($row && $verify->mode !== PDO::FETCH_NAMED) {
            $key = Helper::formatFetchResult($row, false, $verify, $argument, $ctor_args);
            if ($key !== false) {
                $row = [$key => $row];
            }
        }
        return $row;
    }

    /**
     * 重新实现 getMany 方法：若 $fetch = PDO::FETCH_NAMED, 不再提取中间表数据, 需自行处理
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return array
     * @throws Exception
     */
    public function getMany($style = null, $argument = null, $ctor_args = null)
    {
        if (!$this->pivot) {
            return $this->activeRecord()->getMany($style, $argument, $ctor_args);
        }
        $verify = (object) [];
        $activeRecord = $this->verifyFetchMode($verify, $style, $argument, $ctor_args, 1);
        $style = PDO::FETCH_NAMED;
        if ($verify->unique) {
            $style = $style|PDO::FETCH_UNIQUE;
        } elseif ($verify->group) {
            $style = $style|PDO::FETCH_GROUP;
        }
        $lists = $activeRecord->getMany($style);
        if (PDO::FETCH_NAMED === $verify->mode) {
            return $lists;
        }
        $rs = [];
        if ($verify->group) {
            foreach ($lists as $gk => $group) {
                if (!isset($rs[$gk])) {
                    $rs[$gk] = [];
                }
                foreach ($group as $key => $list) {
                    $key = Helper::formatFetchResult($list, $key, $verify, $argument, $ctor_args);
                    $rs[$gk][$key] = $list;
                }
            }
        } else {
            foreach ($lists as $key => $list) {
                $key = Helper::formatFetchResult($list, $key, $verify, $argument, $ctor_args);
                $rs[$key] = $list;
            }
        }
        unset($lists);
        return $rs;
    }

    /**
     * 重新实现 getCursor 方法：若 $fetch = PDO::FETCH_NAMED, 不再处理中间表数据, 需自行处理
     * @param int $chunk
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return Generator
     * @throws Exception
     */
    public function getCursor(int $chunk = 1, $style = null, $argument = null, $ctor_args = null)
    {
        if (!$this->pivot) {
            return $this->activeRecord()->getCursor($chunk, $style, $argument, $ctor_args);
        }
        $verify = (object) [];
        $activeRecord = $this->verifyFetchMode($verify, $style, $argument, $ctor_args, 2);
        if (PDO::FETCH_NAMED === $verify->mode) {
            return $activeRecord->getCursor($chunk, PDO::FETCH_NAMED);
        }
        return $this->generateCursor($activeRecord, $chunk, $verify, $argument, $ctor_args);
    }

    /**
     * 不知道是不是 bug, 该函数代码放到 getCursor() 中, 可能是出现 yield 关键字, 影响 $activeRecord->getCursor 结果
     * 单独创建出来, getCursor() 只负责调度就没问题
     * @param ActiveRecord $activeRecord
     * @param $chunk
     * @param $verify
     * @param null $argument
     * @param null $ctor_args
     * @return Generator
     * @throws Exception
     */
    protected function generateCursor(ActiveRecord $activeRecord, $chunk, $verify, $argument = null, $ctor_args = null)
    {
        foreach ($activeRecord->getCursor($chunk, PDO::FETCH_NAMED) as $key => $lists) {
            if ($chunk > 1) {
                $rs = [];
                foreach ($lists as $k => $list) {
                    $k = Helper::formatFetchResult($list, $k, $verify, $argument, $ctor_args);
                    $rs[$k] = $list;
                }
                unset($lists);
                yield $key => $rs;
            } else {
                yield Helper::formatFetchResult($lists, $key, $verify, $argument, $ctor_args) => $lists;
            }
        }
    }

    /**
     * 先获取默认 style, 再配合自定义的 style 得到最终应用的 style 并加以验证
     * @param $verify
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @param int $all
     * @return ActiveRecord
     * @throws Exception
     */
    protected function verifyFetchMode(&$verify, $style = null, $argument = null, $ctor_args = null, int $all = 0)
    {
        $unique = $group = false;
        if ($style !== null) {
            $flags = $style & 0xFFFF0000;
            $unique = ($flags & PDO::FETCH_UNIQUE) === PDO::FETCH_UNIQUE;
            $group = !$unique && ($flags & PDO::FETCH_GROUP) === PDO::FETCH_GROUP;
        }
        $activeRecord = $this->activeRecord();
        if (null === $style) {
            $style = $activeRecord->connection()->slavePdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
        }
        if ($unique) {
            $style = $style|PDO::FETCH_UNIQUE;
        } elseif ($group) {
            $style = $style|PDO::FETCH_GROUP;
        }
        $verify = Helper::verifyPDOFetchStyle($activeRecord, $style, $argument, $ctor_args, $all);
        $verify->unique = $unique;
        $verify->group = $group;
        return $activeRecord;
    }

    /**
     * 获取 Model, 若不存在返回 false
     * @return Model|object|false
     * @throws Exception
     */
    public function find()
    {
        return Helper::findRelatedModel($this, false) ?: false;
    }

    /**
     * 获取 Model, 若不存在抛出异常
     * @return Model|object
     * @throws Exception
     */
    public function findOrThrow()
    {
        if (!($model = $this->find())) {
            throw (new ModelNotFoundException())->setModel(get_class($this->model));
        }
        return $model;
    }

    /**
     * 获取 Collection
     * @return Collection
     * @throws Exception
     */
    public function findMany()
    {
        return Helper::findRelatedModel($this, true);
    }

    /**
     * 获取结果, 根据 isMany 自动判断返回 Model 或 Collection
     * @return Collection|Model|object
     * @throws Exception
     */
    public function findResults()
    {
        return Helper::findRelatedModel($this);
    }

    /**
     * 创建中间表模型对象
     * @param array $attributes
     * @param bool $newRecord
     * @return Pivot
     */
    public function createPivot(array $attributes = [], bool $newRecord = true)
    {
        if (empty($name = $this->pivot)){
            throw new LogicException('Related model ['.$this->relationModelClass.'] don\'t have pivot.');
        }
        if ($this->pivotIsTable) {
            $pivot = new Pivot($attributes, $newRecord);
            $pivot->setTable($name)->setPivotKeys($this->pivotRelationKey, $this->pivotParentKey);
            if (empty($this->pivotPrimaryKey)) {
                $pivot->setPrimaryColumn([$this->pivotRelationKey, $this->pivotParentKey]);
            } else {
                $pivot->setPrimaryColumn($this->pivotPrimaryKey);
            }
        } else {
            $pivot = new $name($attributes, $newRecord);
        }
        return $pivot->setConnection($this->parent->getConnection());
    }

    /**
     * 以下几个函数本来已经可以在魔术函数中调用 Builder 对象的，
     * 这里重新啰嗦一遍，是为了实现 builderInterface, 这样 Relation 也可以用于查询构造器中
     * @inheritDoc
     */
    public function createBuilder($table = null)
    {
        return $this->activeRecord->createBuilder($table);
    }

    /**
     * @inheritDoc
     */
    public function select(...$columns)
    {
        $this->activeRecord->select(...$columns);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function query()
    {
        return $this->activeRecord()->query();
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function parameter(string $key)
    {
        return $this->activeRecord()->parameter($key);
    }

    /**
     * @inheritDoc
     */
    public function getBindings()
    {
        return $this->activeRecord()->getBindings();
    }

    /**
     * @inheritDoc
     */
    public function mergeBindings(array $queries)
    {
        return $this->activeRecord->mergeBindings($queries);
    }

    /**
     * @inheritDoc
     */
    public function getAlias()
    {
        return null;
    }

    /**
     * @param $name
     * @param $arguments
     * @return $this
     */
    public function __call($name, $arguments)
    {
        $name = strtolower($name);
        if (in_array($name, self::$queryDirectFunction)) {
            return call_user_func_array([$this->activeRecord, $name], $arguments);
        } elseif (in_array($name, self::$querySelectFunction)) {
            return call_user_func_array([$this->activeRecord(), $name], $arguments);
        }
        call_user_func_array([$this->activeRecord, $name], $arguments);
        return $this;
    }
}
