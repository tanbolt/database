<?php
namespace Tanbolt\Database\Model;

use PDO;
use Exception;
use Generator;
use Tanbolt\Database\Model;
use Tanbolt\Database\Query\Builder;
use Tanbolt\Database\Query\Expression;
use Tanbolt\Database\Query\BuilderInterface;
use Tanbolt\Database\Exception\ModelNotFoundException;
use Throwable;

/**
 * Class ActiveRecord: 在 Builder 基础上，实现一个与 Model 集成的查询构造器
 * - 该类在 Query\Builder 基础上，通过设定 Model 源，对 Builder 功能进行了优化扩展
 * - 使用 Model 设定的 Connection 连接器, 且默认对 Builder 添加了 ->from(Model:table), 可使用 Builder 所有公开方法
 * - 在 Builder 基础上增加了针对 Model 的 wherePrimary / has / scope 筛选语句
 * - 新增 with, find 函数, 用于扩展获取记录数的手段
 *
 * 该类发生变化, 影响
 * - Database\Model
 * - Database\Model\Relation
 * @package Tanbolt\Database\Model
 * @mixin Builder 魔术方法调用 Builder 函数
 */
class ActiveRecord implements BuilderInterface
{
    /**
     * @var Model
     */
    protected $model = null;

    /**
     * @var Builder
     */
    protected $builder = null;

    /**
     * @var null
     */
    private $primaryValue = null;

    /**
     * @var array
     */
    protected $withLoad = [];

    /**
     * @var bool
     */
    protected $unUseGlobalScope;

    /**
     * @var array
     */
    protected $scopes = [];

    /**
     * @var array
     */
    private $scopesCalled = [];

    /**
     * @var bool
     */
    private $primaryCalled = false;

    /**
     * 由 builder 返回直接结果
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
        'exists', 'getone', 'getmany', 'getcursor',
        'aggregate', 'records', 'sum', 'max', 'min', 'avg',
        'update', 'delete',
    ];

    /**
     * 创建 ActiveRecord 对象
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->setModel($model);
    }

    /**
     * 设置绑定的 Model 对象
     * @param Model $model
     * @return $this
     */
    public function setModel(Model $model)
    {
        $this->model = $model;
        $this->builder = $model->getConnection()->table($model->getTable())->select($model->getSelectColumns());
        return $this;
    }

    /**
     * 获取绑定的 Model 对象
     * @return Model|object
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * 使用当前 Model 的配置创建一个新的 Model 对象
     * @param array $attributes
     * @param bool $newRecord
     * @return Model|object
     */
    public function newModel(array $attributes = [], bool $newRecord = true)
    {
        $class = get_class($model = $this->model);
        $newModel = new $class($attributes, $newRecord);
        return $newModel->setCasts($model->getCasts())
            ->setHidden($model->getHidden())
            ->setVisible($model->getVisible())
            ->setConnectConfig($model->getConnectConfig())
            ->setConnection($model->getConnection(true))
            ->setTable($model->getTable())
            ->setPrimaryColumn($model->getPrimaryColumn())
            ->setSelectColumns($model->getSelectColumns())
            ->setCreateTimeColumn($model->getCreateTimeColumn())
            ->setUpdateTimeColumn($model->getUpdateTimeColumn());
    }

    /**
     * 使用当前 Model 的配置创建一个新的 Model 对象, 并入库保存
     * @param array $attributes
     * @return Model|object
     * @throws Throwable
     */
    public function createModel(array $attributes = [])
    {
        return $this->newModel($attributes)->save();
    }

    /**
     * 创建一个 Collection (Model lists) 对象
     * @param array|Model[] $attributes
     * @param bool $newRecord
     * @return Collection
     */
    public function newCollection(array $attributes = [], bool $newRecord = true)
    {
        $models = [];
        foreach ($attributes as $attribute) {
            if ($attribute instanceof Model) {
                $models[] = $attribute;
            } else {
                $models[] = $this->newModel($attribute, $newRecord);
            }
        }
        return new Collection($models);
    }

    /**
     * 创建一个 collection (Model lists) 对象， 并入库保存
     * @param array $attributes
     * @param bool $newRecord
     * @return Collection
     * @throws Throwable
     */
    public function createCollection(array $attributes = [], bool $newRecord = true)
    {
        $collection = $this->newCollection($attributes, $newRecord);
        $collection->save();
        return $collection;
    }

    /**
     * 设置SQL构造器中的主键值
     * @param string|array $id
     * @return $this
     */
    public function wherePrimary($id)
    {
        $this->primaryValue = $id;
        return $this;
    }

    /**
     * 给当前SQL语句构造器对象 添加 globalScope() 设置的限制条件
     * @return $this
     */
    public function withGlobalScope()
    {
        $this->unUseGlobalScope = false;
        return $this;
    }

    /**
     * 从当前SQL语句构造器对象 去除 globalScope() 设置的限制条件
     * @return $this
     */
    public function withoutGlobalScope()
    {
        $this->unUseGlobalScope = true;
        return $this;
    }

    /**
     * 设置关联模型记录数的限制条件
     * - ex: has('foo', '=', 5)
     * @param string $relation
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function has(string $relation, string $operator = '>=', int $count = 1)
    {
        return $this->whereRelation($relation, null, false, $operator, $count);
    }

    /**
     * 设置关联模型记录数的限制条件
     * - ex: orHas('foo', '>', 5)
     * @param string $relation
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function orHas(string $relation, string $operator = '>=', int $count = 1)
    {
        return $this->whereRelation($relation, null, false, $operator, $count, false);
    }

    /**
     * 设置关联模型无记录数的限制条件
     * @param string $relation
     * @param callable|null $callback
     * @return $this
     */
    public function notHas(string $relation, callable $callback = null)
    {
        return $this->whereRelation($relation, $callback, true, '=', 0);
    }

    /**
     * 设置关联模型无记录数的限制条件
     * @param string $relation
     * @param callable|null $callback
     * @return $this
     */
    public function orNotHas(string $relation, callable $callback = null)
    {
        return $this->whereRelation($relation, $callback, true, '=', 0, false);
    }

    /**
     * 设置关联模型记录数的限制条件 (可通过回调函数设置关联模型的筛选条件)
     * - ex: whereHas('foo', function(Relation){}, '>', 5)
     * @param string $relation
     * @param callable|null $callback
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function whereHas(string $relation, callable $callback = null, string $operator = '>=', int $count = 1)
    {
        return $this->whereRelation($relation, $callback, false, $operator, $count);
    }

    /**
     * 设置关联模型记录数的限制条件 (可通过回调函数设置关联模型的筛选条件)
     * - ex: orWhereHas('foo', function(Relation){}, '>', 5)
     * @param string $relation
     * @param callable|null $callback
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function orWhereHas(string $relation, callable $callback = null, string $operator = '>=', int $count = 1)
    {
        return $this->whereRelation($relation, $callback, false, $operator, $count, false);
    }

    /**
     * @param string $relation
     * @param ?callable $callback
     * @param bool $notHas
     * @param string $operator
     * @param int $count
     * @param bool $and
     * @return $this
     */
    protected function whereRelation(
        string $relation,
        callable $callback = null,
        bool $notHas = false,
        string $operator = '>=',
        int $count = 1,
        bool $and = true
    ) {
        /** @var Relation $relation */
        $relation = $this->model->{$relation}();
        if ($callback) {
            call_user_func($callback, $relation);
        }
        $relation = $relation->activeRecord(true)->select('*');
        if (!$notHas && ( ('<=' === $operator && 0 === $count) || ('<' === $operator && 1 === $count) ) ){
            $notHas = true;
        }
        if ($notHas) {
            $and ? $this->builder->whereNotExists($relation) : $this->builder->orWhereNotExists($relation);
        } else {
            if (('>=' === $operator && 1 === $count) || ('>' === $operator && 0 === $count)) {
                $and ? $this->builder->whereExists($relation) : $this->builder->orWhereExists($relation);
            } else {
                if ($and) {
                    $this->builder->where($relation->select('COUNT(*)'), $operator, new Expression($count));
                } else {
                    $this->builder->orWhere($relation->select('COUNT(*)'), $operator, new Expression($count));
                }
            }
        }
        return $this;
    }

    /**
     * 设置需要预加载的关联模型
     * - with('foo', 'bar', 'foo.biz'...)
     * - with(['foo', 'bar', 'foo.biz'...], ['biz'...])
     * @param mixed $relation
     * @return $this
     */
    public function with(...$relation)
    {
        $this->withLoad = array_merge($this->withLoad, Helper::flattenRelations($relation));
        return $this;
    }

    /**
     * 从已设置的预加载模型中排除不需要预加载的
     * - withOut('bar', 'foo.biz'...)
     * - withOut(['bar', 'foo.biz'...], ['biz'...])
     * @param mixed $relation
     * @return $this
     */
    public function withOut(...$relation)
    {
        $withLoad = [];
        $withOut = Helper::flattenRelations($relation, true);
        array_walk($this->withLoad, function($item) use($withOut, &$withLoad) {
            $with = is_array($item) ? key($item) : $item;
            // 相同
            if (in_array($with, $withOut)) {
                return false;
            }
            if (!strpos($with, '.')) {
                $withLoad[] = $item;
                return true;
            }
            // 父级指定 如 with = 'foo.bar.biz', withOut 中出现 'foo', 'foo.bar' 都会清除 with
            $withs = explode('.', $with);
            array_pop($withs);
            $parents = [];
            $lastParent = '';
            foreach ($withs as $w) {
                $lastParent .= (empty($lastParent) ? '' : '.').$w;
                $parents[] = $lastParent;
            }
            if (count(array_intersect($parents, $withOut))) {
                return false;
            }
            $withLoad[] = $item;
            return true;
        });
        $this->withLoad = $withLoad;
        return $this;
    }

    /**
     * 清除所有已经设置的 with 关联模型
     * @return $this
     */
    public function withNone()
    {
        $this->withLoad = [];
        return $this;
    }

    /**
     * 获取当期已设置的，需要预加载的关联模型
     * @return array
     */
    public function withWhich()
    {
        return $this->withLoad;
    }

    /**
     * 根据限制条件查询并返回 Model,若不存在,返回false
     * @param string|array $id
     * @return Model|object|false
     * @throws Exception
     */
    public function find($id = null)
    {
        $model = $this->modelBuilder($id ?: true)->getOne(PDO::FETCH_ASSOC);
        if (is_array($model)) {
            $model = $this->newModel($model, false);
            if ($model && count($this->withLoad)) {
                Helper::loadModelRelations([$model], $this->withLoad);
            }
            return $model;
        }
        return false;
    }

    /**
     * 根据限制条件查询并返回 Model,若不存在,抛出异常
     * @param string|array $id
     * @return Model|object
     * @throws Exception
     */
    public function findOrThrow($id = null)
    {
        if (!($model = $this->find($id))) {
            throw (new ModelNotFoundException)->setModel(get_class($this->model));
        }
        return $model;
    }

    /**
     * 据限制条件查询并返回 Collection (包含多个 Model 的合集)
     * @param array|null $ids
     * @return Collection
     * @throws Exception
     */
    public function findMany(array $ids = null)
    {
        $collection = $this->modelBuilder($ids ?: true)->getMany(PDO::FETCH_ASSOC);
        $collection = $this->newCollection($collection, false);
        if (count($collection) && count($this->withLoad)) {
            Helper::loadModelRelations($collection->all(), $this->withLoad);
        }
        return $collection;
    }

    /**
     * 通过 PDO 内部指针循环获取数据，相比直接 findMany 更加节省内存
     * 需要注意的是：with() 设置的关联模型在使用该方法时不生效，仅返回单纯的 Model
     * 因为这个函数本来就是设计节省内存，利用 POD 指针特性，
     * 若附带查询 with() 的关联模型，就无法利用 PDO 的该特性，必须先查询所有 Model 继而获取关联模型数据
     * @param int $chunk
     * @return Generator|Model[]|Collection[]|object[]
     * @throws Exception
     */
    public function findCursor(int $chunk = 1)
    {
        $many = $chunk > 1;
        foreach ($this->modelBuilder()->getCursor($chunk, PDO::FETCH_ASSOC) as $row) {
            yield ($many ? $this->newCollection(
                $row,
                false
            ) : $this->newModel(
                $row,
                false
            ));
        }
    }

    /**
     * 获取已设附加了 Model 限制条件后的 SQL 语句构造器
     * > 如： `ActiveRecord->where()->has()->...()->modelBuilder() : Builder`
     * - 返回的 Builder 自动设置了限制条件 (也包括 wherePrimary() 设置，但可通过 $primaryValue 重置该限制值)
     * - 执行该函数后, 就不能再次设置 主键限制条件，若只是想通过该函数获取到 Builder 对象, 请设置参数 $primaryValue=false
     *
     * @param array|string|bool $primaryValue true: 则使用 wherePrimary() 设置值；
     *                                        false: 不使用 primaryValue 限制；
     *                                        string|array: 使用指定的 primaryValue
     * @return Builder
     */
    public function modelBuilder($primaryValue = true)
    {
        if (true === $primaryValue) {
            $this->setWherePrimary($this->primaryValue);
        } elseif ($primaryValue) {
            $this->primaryValue = $primaryValue;
            $this->setWherePrimary($primaryValue);
        }
        if (!$this->unUseGlobalScope) {
            $this->addScope('globalScope');
        }
        return $this->callScope()->builder;
    }

    /**
     * add Primary constraint to instance
     * @param array|string|int $id
     * @return $this|false
     */
    protected function setWherePrimary($id)
    {
        if ($this->primaryCalled || empty($id)) {
            return $this;
        }
        $this->primaryCalled = true;
        $columns = $this->model->getPrimaryColumn();
        if (is_array($columns)) {
            if (!is_array($id)) {
                return false;
            }
            $columnCount = count($columns);
            $first = reset($id);
            if (is_array($first)) {
                $ids = [];
                foreach ($id as $val) {
                    if (!($item = static::getPrimaryArrayValue($columnCount, $columns, $val))) {
                        return false;
                    }
                    $ids[] = $item;
                }
                $this->builder->where($columns, 'IN', $ids);
            } else {
                $item = static::getPrimaryArrayValue($columnCount, $columns, $id);
                if (!$item) {
                    return false;
                }
                $wheres = [];
                foreach ($columns as $key => $column) {
                    $wheres[] = [$column, $item[$key]];
                }
                $this->builder->where($wheres);
            }
        } else {
            if (is_array($id)) {
                $this->builder->where($columns, 'IN', $id);
            } else {
                $this->builder->where($columns, $id);
            }
        }
        return $this;
    }

    /**
     * @param int $columnCount
     * @param array $columns
     * @param array $id
     * @return array|bool
     */
    protected static function getPrimaryArrayValue(int $columnCount, array $columns, array $id)
    {
        if (count($id) < $columnCount) {
            return false;
        }
        $wheres = [];
        foreach ($columns as $column) {
            if (isset($id[$column])) {
                $wheres[] = $id[$column];
            }
        }
        if (count($wheres) !== $columnCount) {
            $wheres = [];
            $id = array_values($id);
            foreach ($columns as $key => $column) {
                $wheres[] = $id[$key];
            }
        }
        return $wheres;
    }

    /**
     * add scope to the current builder instance.
     * @param string $scope
     * @param array $arguments
     * @return $this
     */
    protected function addScope(string $scope, array $arguments = [])
    {
        $this->scopes[$scope] = $arguments;
        return $this;
    }

    /**
     * call scopes
     * @return $this
     */
    protected function callScope()
    {
        // 调用 scope 方法前, 设置 Mode 的 ScopeActiveRecord
        $this->model->__setScopeActiveRecord($this);
        // 调用 scope
        $scopes = array_diff(array_keys($this->scopes), $this->scopesCalled);
        foreach ($scopes as $scope) {
            $arguments = $this->scopes[$scope];
            array_unshift($arguments, $this);
            call_user_func_array([$this->model, $scope], $arguments);
            $this->scopesCalled[] = $scope;
        }
        // 恢复 Model 的 ScopeActiveRecord
        $this->model->__setScopeActiveRecord(null);
        return $this;
    }

    /**
     * 以下几个函数本来已经可以在魔术函数中调用 Builder 对象的，
     * 这里重新啰嗦一遍，是为了实现 builderInterface, 这样 Relation 也可以用于查询构造器中
     * @inheritDoc
     */
    public function createBuilder($table = null)
    {
        return $this->builder->createBuilder($table);
    }

    /**
     * @inheritDoc
     */
    public function select(...$columns)
    {
        $this->builder->select(...$columns);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function query()
    {
        return $this->modelBuilder()->query();
    }

    /**
     * @inheritDoc
     */
    public function parameter(string $key)
    {
        return $this->modelBuilder()->parameter($key);
    }

    /**
     * @inheritDoc
     */
    public function getBindings()
    {
        return $this->modelBuilder()->getBindings();
    }

    /**
     * @param array $queries
     * @return array
     */
    public function mergeBindings(array $queries)
    {
        return $this->builder->mergeBindings($queries);
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
        if (method_exists($this->model, $name)) {
            return $this->addScope($name, $arguments);
        } elseif (in_array($name, self::$queryDirectFunction)) {
            return call_user_func_array([$this->builder, $name], $arguments);
        } elseif (in_array($name, self::$querySelectFunction)) {
            return call_user_func_array([$this->modelBuilder(), $name], $arguments);
        }
        call_user_func_array([$this->builder, $name], $arguments);
        return $this;
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     * @return void
     */
    public function __clone()
    {
        $this->builder = clone $this->builder;
    }
}
