<?php
namespace Tanbolt\Database\Model;

use PDO;
use Closure;
use Exception;
use PDOException;
use Serializable;
use ReflectionClass;
use Tanbolt\Database\Model;
use Tanbolt\Database\Query\Expression;

/**
 * Class Helper: 提供针对 Model 和其 relations 整合的一些方法
 * @package Tanbolt\Database\Model
 */
class Helper
{
    /**
     * 分拣 Model 或者 Collection, 返回包含所有 model 的数组
     * ```
     * [
     *    model => {
     *       relations => [
     *           model1 => {}
     *           model2 => {}
     *       ]
     *    }
     *    model3 => {
     *      relations => [
     *           model2 => {}
     *      ]
     *   }
     * ]  =>
     * [
     *    model => Collection([object])
     *    model1 => Collection([object])
     *    model2 => Collection([object, object])
     *    model3 => Collection([object])
     * ]
     *```
     * @param Model|Collection $model
     * @return Collection[]
     */
    public static function flattenModels($model)
    {
        $collections = [];
        foreach (static::groupedModels($model) as $class => $models) {
            $collections[$class] = new Collection($models);
        }
        return $collections;
    }

    /**
     * @param Model|Collection $model
     * @return array
     */
    protected static function groupedModels($model)
    {
        $models = null;
        if ($model instanceof Model) {
            $models = [$model];
        } elseif ($model instanceof Collection) {
            $models = $model->all();
        }
        $collections = [];
        if ($models) {
            foreach ($models as $model) {
                if (!$model instanceof Model) {
                    continue;
                }
                $class = get_class($model);
                if (!isset($collections[$class])) {
                    $collections[$class] = [];
                }
                $collections[$class][] = $model;
                foreach ($model->getRelation() as $relation) {
                    $collections = array_merge_recursive($collections, static::groupedModels($relation));
                }
            }
        }
        return $collections;
    }

    /**
     * 将 relations 数组 转为一维数组
     * `[x, [y,z, [w,q], m] => [x,y,z,w,q,m]`
     * @param array $relations
     * @param bool $onlyKey
     * @return array
     */
    public static function flattenRelations(array $relations, bool $onlyKey = false)
    {
        $return = [];
        array_walk_recursive($relations, function ($item, $key) use ($onlyKey, &$return) {
            if (is_numeric($key)) {
                $return[] = $item;
            } else {
                $return[] = $onlyKey ? $key : [$key => $item];
            }
        });
        return $return;
    }

    /**
     * 加载指定的 $relations(string[]) 关联模型到 $models(Model[]) 中。
     * $models 数组元素必须同属于相同的 Model 接口, $relations 为需要加载的关联模型 (关联模型必须是 Model 中定义过的)
     * @param Model[] $models 需要加载的 models
     * @param array $relations 需要加载的相关模型
     * @return Model[]
     * @throws Exception
     */
    public static function loadModelRelations(array $models, array $relations)
    {
        if (!count($models) || !count($relations)) {
            return $models;
        }
        /*
         * flattenRelations 将 $relation 格式由
         * [
         *    'foo', 'bar.biz'
         * ]
         * 被整理为 $with
         * [
         *     'foo' => callback
         *     'bar.biz' => callback
         * ]
         */
        $withs = [];
        foreach (static::flattenRelations($relations) as $related) {
            if (is_array($related)) {
                $withs[key($related)] = current($related);
            } else {
                $withs[(string) $related] = null;
            }
        }

        // 处理嵌套关联
        $withs = static::nestedRelationWiths($withs);

        // 加载关联模型
        foreach ($withs as $name => $constraint) {
            static::initRelationModels($models, $name, $constraint);
        }
        return $models;
    }

    /**
     * 对包含 `.` 的 relation 进行递归处理, 解析为 loadRelationsModel 可处理的形式
     * @param array $relations
     * @return array
     */
    protected static function nestedRelationWiths(array $relations)
    {
        /*
         * $relations
         * [
         *     foo => callback
         *     biz.que => callback
         * ]
         *
         * 由 $relations 处理得到 $nested 结果类似于
         * [
         *      foo => [
         *          '$' => callback
         *      ],
         *      biz => [
         *          '$' => callback(){
         *              'que' => [
         *                 '$' => callback
         *              ]
         *          }
         *      ],
         * ]
         *
         */
        $nested = [];
        ksort($relations);
        foreach ($relations as $key => $val) {
            static::setDeepArray($nested, $key.'.$', $val);
        }

        // 将 $relations 处理成 loadRelationsModel 可使用的数组形式
        $relations = [];
        foreach ($nested as $key => $val) {
            $relations[$key] = static::setRelationConstraint($val);
        }
        return $relations;
    }

    /**
     * ```
     * setDeepArray($array, 'foo.bar.biz', 'que']
     * $array = [
     *      'foo' => [
     *             'bar' => 'que'
     *       ]
     * ]
     * ```
     * @param $array
     * @param $keys
     * @param $value
     */
    protected static function setDeepArray(&$array, $keys, $value)
    {
        $keys = explode(".", $keys);
        $current = &$array;
        foreach ($keys as $key) {
            $current = &$current[$key];
        }
        $current = $value;
    }

    /**
     * 处理使用 setDeepArray 整理过的 with 数组
     * @param array $constraints
     * @return Closure|null
     */
    protected static function setRelationConstraint(array $constraints)
    {
        $callback = null;
        if (array_key_exists('$', $constraints)) {
            $callback = is_callable($constraints['$']) ? $constraints['$'] : null;
            unset($constraints['$']);
        }
        $withs = null;
        if (count($constraints)) {
            $withs = [];
            foreach ($constraints as $key => $val) {
                $withs[$key] = static::setRelationConstraint($val);
            }
        }
        if ($callback || $withs) {
            return function(Relation $relation) use ($callback, $withs) {
                if ($callback) {
                    call_user_func($callback, $relation);
                }
                if ($withs) {
                    foreach ($withs as $with => $call) {
                        call_user_func([$relation, 'with'], [$with => $call]);
                    }
                }
            };
        }
        return null;
    }

    /**
     * 讲 关联模型 插入到 $models 中
     * @param Model[] $models
     * @param string $related
     * @param callable|null $constraint
     * @return Model[]|object[]
     * @throws Exception
     */
    protected static function initRelationModels(array $models, string $related, callable $constraint = null)
    {
        if (strpos($related, '_')) {
            $method = implode('', array_map(function($w) {
                return ucfirst($w);
            }, explode('_', $related)));
        } else {
            $method = $related;
        }

        /** @var Relation $relation */
        $relation = current($models)->{$method}();
        if ($constraint) {
            call_user_func($constraint, $relation);
        }

        // 获取所有主模型中 与 关联模型 对应字段的值
        $foreignValue = [];
        $localKey = $relation->getLocalKey();
        foreach ($models as $model) {
            if (isset($model[$localKey]) && !in_array($model[$localKey], $foreignValue)) {
                $foreignValue[] = $model[$localKey];
            }
        }

        // 获取包含所有 models 的 关联模型数据 Collection
        $relations = static::findRelatedModelByAR(
            $relation->activeRecord(false, $foreignValue),
            true
        )->walk(function($item) use ($relation) {
            static::formatRelatedModel($relation, $item);
        });

        // 将关联模型 Collection 转为数组
        $pivot = (bool) $relation->getPivot();
        $relations = static::preparedRelationModels(
            $relations, $pivot,
            ($pivot ? $relation->getPivotParentKey() : $relation->getForeignKey())
        );

        // 将关联模型填充到主模型中
        return static::matchWithModels($relation, $models, $relations, $related);
    }

    /**
     * 将关联模型的 Collection 转为数组，使用 关联模型 与 主模型 的关联字段作为键值
     * @param Collection $collection
     * @param bool $pivot
     * @param string $foreignKey
     * @return Model[]|object[]
     */
    protected static function preparedRelationModels(Collection $collection, bool $pivot, string $foreignKey)
    {
        $models = [];
        /** @var Model $model */
        foreach ($collection as $model) {
            $key = $pivot ? $model->pivot->{$foreignKey} : $model->{$foreignKey};
            $models[$key][] = $model;
        }
        return $models;
    }

    /**
     * @param Relation $relation
     * @param Model[] $models
     * @param Model[] $relations
     * @param $name
     * @return Model[]|object[]
     */
    protected static function matchWithModels(Relation $relation, array $models, array $relations, $name)
    {
        $many = $relation->isMany();
        $localKey = $relation->getLocalKey();
        $relatedModel = $relation->getModel();
        foreach ($models as $model) {
            $localValue = $model->{$localKey};
            if ($many) {
                $relation = $relatedModel->newCollection($relations[$localValue] ?? [], false);
            } else {
                $relation = isset($relations[$localValue]) ? reset($relations[$localValue]) : null;
            }
            $model->setRelation($name, $relation);
        }
        return $models;
    }

    /**
     * 查找获取 Relation 对象的结果集
     * @param Relation $relation
     * @param ?bool $many 值为 null 则从 $relation 设置自动获取
     * @return Collection|Model|object|false
     * @throws Exception
     */
    public static function findRelatedModel(Relation $relation, bool $many = null)
    {
        $many = null === $many ? $relation->isMany() : $many;
        $models = static::findRelatedModelByAR($relation->activeRecord(), $many);
        if (!$many) {
            return $models ? static::formatRelatedModel($relation, $models) : false;
        }
        return $models->walk(function($item) use ($relation){
            static::formatRelatedModel($relation, $item);
        });
    }

    /**
     * 由当前的设置查询匹配的 model,
     * 在需要查询所有中间表字段时,情况比较特殊,不能直接使用 ActiveRecord 的查询函数
     * @param ActiveRecord $activeRecord
     * @param bool $many
     * @return Collection|Model|object|false
     * @throws Exception
     */
    protected static function findRelatedModelByAR(ActiveRecord $activeRecord, bool $many = false)
    {
        if (!in_array('pivot.*', $activeRecord->parameter('selects'))) {
            return $many ? $activeRecord->findMany() : $activeRecord->find();
        }
        // 多个
        if ($many) {
            $collection = array_map(
                [static::class, 'preparedRelationValueWithPivot'],
                $activeRecord->getMany(PDO::FETCH_NAMED)
            );
            $collection = $activeRecord->newCollection($collection, false);
            if (count($collection) && count($with = $activeRecord->withWhich())) {
                static::loadModelRelations($collection->all(), $with);
            }
            return $collection;
        }
        // 单个
        $model = static::preparedRelationValueWithPivot($activeRecord->getOne(PDO::FETCH_NAMED));
        if (is_array($model)) {
            $model = $activeRecord->newModel($model, false);
            if ($model && count($with = $activeRecord->withWhich())) {
                static::loadModelRelations([$model], $with);
            }
            return $model;
        }
        return false;
    }

    /**
     * 若查询中间表所有字段，通过 findModel() 先获取分组的查询数据，
     * 以固定字符串 "*" "**" 作为分隔符，分隔符之间的字段为中间表字段。
     * 如果中间表和关联模型有相同字段，相对应的中间表字段值将附加到 关联模型字段上，以数组形式展示。
     * 对于重名字段，中间表字段单独归属，若有连表，则后续连表字段值覆盖关联模型字段值。如
     * - 关联模型字段为 (id, name, title)
     * - 中间表字段为 (a, b, name)
     * - 连表字段为 (name, other)
     *
     * SQL查询结果类似：
     * ```
     * [
     *    'id' => 1,
     *    'name' => ['foo', 'bar', 'biz']
     *    'title' => 't',
     *    '*' => '*',
     *    'a' => 'a'
     *    'b' => 'b',
     *    '**' => '**',
     *    'other' => 'other'
     * ]
     * ```
     * 这个函数最终的目的就是要整理为 formatFoundModel() 函数可以处理的数据，类似:
     * ```
     * [
     *    'id' => 1,
     *    'name' => 'biz',
     *    'title' => 't',
     *    'pivot_name' => 'bar',
     *    'pivot_a' => 'a'
     *    'pivot_b' => 'b',
     *    'other' => 'other'
     * ]
     * ```
     * @param array|false $row
     * @return array
     */
    protected static function preparedRelationValueWithPivot($row)
    {
        if (!is_array($row)) {
            return $row;
        }
        $pivots = [];
        $relation = [];
        $step = 0;
        foreach ($row as $column => $value) {
            // (不同数据库，固定值字段返回可能不一样， 比如 mysql 返回 * sqlite 返回 '*')
            if (0 === $step && ('*' === $column || "'*'" === $column)) {
                // pivot 字段开始
                $step = 1;
            } elseif ($step < 2 && ('**' === $column || "'**'" === $column)) {
                // pivot 字段结束
                $step = 2;
            } elseif ($step > 1) {
                // 其他连表字段
                $relation[$column] = is_array($value) ? end($value) : $value;
            } elseif ($step > 0) {
                // 中间表字段, 理论上不可能出现数组, 同一个数据表不能有两个同名字段
                $relation['pivot_'.$column] = is_array($value) ? end($value) : $value;
            } elseif (is_array($value)) {
                // 第一个值肯定是关联模型的
                $relation[$column] = array_shift($value);
                if (count($value)) {
                    // 还有值, 认为属于中间表 (这里有隐患, 因为第二个值可能不属于中间表, 而是属于连表)
                    // 考虑到写SQL时, 若连表有同名字段, 我们都会通过 AS 特别指定, 第二个大概率会是中间表的
                    $pivots['pivot_'.$column] = array_shift($value);
                    if (count($value)) {
                        // 竟然还有值, 估计是连表字段, 覆盖关联模型值
                        $relation[$column] = array_shift($value);
                    }
                }
            } else {
                $relation[$column] = $value;
            }
        }
        return array_merge($relation, $pivots);
    }

    /**
     * 修正有中间表字段的关联模型数据
     * @param Relation $relation
     * @param Model $model
     * @return Model
     */
    protected static function formatRelatedModel(Relation $relation, Model $model)
    {
        if (!$relation->getPivot()) {
            return $model;
        }
        $pivotColumn = [];
        foreach ($model as $key => $val) {
            if (substr($key, 0, 6) === 'pivot_') {
                $pivotColumn[substr($key, 6)] = $val;
                unset($model[$key]);
            }
        }
        $pivot = $relation->createPivot($pivotColumn, false)->syncOriginal();
        $model->setRelation('pivot', $pivot)->syncOriginal();
        return $model;
    }

    /**
     * 将 preparedRelationValueWithPivot 整理后的 pivot_* 合并到一个数组中
     * @param array $row
     * @return array
     */
    public static function splitRelationValues(array $row)
    {
        $row = static::preparedRelationValueWithPivot($row);
        $pivot = [];
        foreach ($row as $key => $val) {
            if (substr($key, 0, 6) === 'pivot_') {
                $pivot[substr($key, 6)] = $val;
                unset($row[$key]);
            }
        }
        $row['pivot'] = $pivot;
        return $row;
    }

    /**
     * 校验 fetch style 的合法性，为了 pivot 字段，无论指定什么样的 fetch style 都会以 PDO::FETCH_NAMED 查询。
     * 但可能查询查询数据后无法根据指定的 fetch style 返回正确的值，要在查询前校验 fetch style 是否合法。
     * @param ActiveRecord $activeRecord
     * @param null $fetch
     * @param null $argument
     * @param null $ctor_args
     * @param int $all
     * @return object
     * @throws Exception
     */
    public static function verifyPDOFetchStyle(
        ActiveRecord $activeRecord,
        $fetch = null,
        $argument = null,
        $ctor_args = null,
        int $all = 0
    ) {
        $obj = static::verifyPdoMode($fetch, $argument, $ctor_args, $all);
        if (PDO::FETCH_KEY_PAIR === $obj->mode) {
            $selects = 0;
            foreach ($activeRecord->parameter('selects') as $column) {
                if ('*' === $column ||
                    (is_string($column) && (
                            substr($column, -2) === '.*' || substr($column, 0, 6) === 'pivot.'
                        )) ||
                    ($column instanceof Expression && $column->query() === "'*'")
                ) {
                    break;
                }
                $selects++;
                if ($selects > 2) {
                    break;
                }
            }
            if ($selects !== 2) {
                throw new PDOException(
                    'SQLSTATE[HY000]: General error: '.
                    'FETCH_KEY_PAIR fetch mode requires the result set to contain extactly 2 columns'
                );
            }
        }
        return $obj;
    }

    /**
     * fetch style 防御性验证
     * @param $how
     * @param null $argument
     * @param null $args
     * @param int $all
     * @return object
     * @throws Exception
     * @see https://github.com/php/php-src/blob/master/ext/pdo/pdo_stmt.c
     */
    protected static function verifyPdoMode($how, $argument = null, $args = null, int $all = 0)
    {
        $e = 'SQLSTATE[HY000]: General error: ';
        if (!is_int($how)) {
            throw new PDOException($e.'mode must be an integer');
        }
        $fetch_flags = 0xFFFF0000;
        $flags = $how & $fetch_flags;
        $mode = $how & ~$fetch_flags;
        $classType = ($flags & PDO::FETCH_CLASSTYPE) === PDO::FETCH_CLASSTYPE;
        $propsLate = ($flags & PDO::FETCH_PROPS_LATE) === PDO::FETCH_PROPS_LATE;
        $serialize = ($flags & PDO::FETCH_SERIALIZE) === PDO::FETCH_SERIALIZE;
        if ($mode !== PDO::FETCH_CLASS) {
            if ($classType) {
                throw new PDOException($e.'PDO::FETCH_CLASSTYPE can only be used together with PDO::FETCH_CLASS');
            } elseif ($propsLate) {
                throw new PDOException($e.'PDO::FETCH_PROPS_LATE can only be used together with PDO::FETCH_CLASS');
            } elseif ($serialize) {
                throw new PDOException($e.'PDO::FETCH_SERIALIZE can only be used together with PDO::FETCH_CLASS');
            }
        }
        if ($mode < 0 || $mode > 12) {
            throw new PDOException($e.'invalid fetch mode');
        }
        $object = (object) compact('mode', 'flags', 'classType', 'propsLate', 'serialize');
        $object->reflection = null;
        switch($mode) {
            case PDO::FETCH_LAZY:
                // 不支持 FETCH_LAZY
                throw new PDOException($e.'PDO::FETCH_LAZY mode not support yet');
            case PDO::FETCH_BOUND:
                // 不支持 FETCH_BOUND
                throw new PDOException($e.'PDO::FETCH_BOUND mode not support');
            case PDO::FETCH_ASSOC:
            case PDO::FETCH_NUM:
            case PDO::FETCH_BOTH:
            case PDO::FETCH_OBJ:
            case PDO::FETCH_NAMED:
            case PDO::FETCH_KEY_PAIR:
                if ($argument !== null || $args !== null) {
                    throw new PDOException($e.'fetch mode doesn\'t allow any extra arguments');
                }
                // FETCH_KEY_PAIR fetch mode requires the result set to contain extactly 2 columns 放到语句构造器中检测
                break;
            case PDO::FETCH_COLUMN:
                if ($argument !== null && !is_int($argument)) {
                    throw new PDOException($e.'colno must be an integer');
                }
                // Invalid column index 放到查询结果验证中
                break;
            case PDO::FETCH_FUNC:
                if (!$all) {
                    throw new PDOException($e.'PDO::FETCH_FUNC is only allowed in PDOStatement::fetchAll()');
                } elseif (null === $argument) {
                    throw new PDOException($e.'No fetch function specified');
                } elseif (!is_callable($argument)) {
                    throw new PDOException($e.'user-supplied function must be a valid callback');
                }
                break;
            case PDO::FETCH_INTO:
                if (null === $argument) {
                    throw new PDOException($e.'No fetch-into object specified.');
                } elseif (!is_object($argument)) {
                    throw new PDOException($e.'object must be an object');
                } elseif ($all) {
                    throw new PDOException(
                        $e.'PDO::FETCH_INTO can\'t be used with Relation::'.
                        ($all > 1 ? 'getMany()' : 'getCursor()')
                    );
                }
                break;
            case PDO::FETCH_CLASS:
                if ($classType) {
                    if ($argument !== null || $args !== null) {
                        throw new PDOException($e.'fetch mode doesn\'t allow any extra arguments');
                    }
                    return $object;
                }
                if ($args !== null && !is_array($args)) {
                    throw new PDOException($e.'ctor_args must be either NULL or an array');
                }
                if (null === $argument) {
                    // use StdClass
                    return $object;
                }
                if (!is_string($argument)) {
                    throw new PDOException($e.'Invalid class name (should be a string)');
                }
                if (!class_exists($argument)) {
                    throw new PDOException($e.'could not find user-specified class');
                }
                $reflection = new ReflectionClass($argument);
                if (!$reflection->IsInstantiable()) {
                    throw new PDOException($e.' class can not instance');
                }
                if ($args !== null && !$reflection->getConstructor()) {
                    throw new PDOException(
                        $e.'user-supplied class does not have a constructor, '.
                        'use NULL for the ctor_params parameter, or simply omit it'
                    );
                }
                if ($serialize && !$reflection->implementsInterface(Serializable::class)) {
                    throw new PDOException($e.'PDO::FETCH_SERIALIZE must be instanceof \Serializable');
                }
                $object->reflection = $reflection;
                break;
        }
        return $object;
    }

    /**
     * 为了 pivot 字段，无论指定什么样的 fetch style 都会以 PDO::FETCH_NAMED 查询，
     * 这里将查询结果反向整理为 fetch style 指定的格式。
     * @param array $row
     * @param $key
     * @param $verify
     * @param null $argument
     * @param null $ctor_args
     * @return string|false
     * @throws Exception
     */
    public static function formatFetchResult(array &$row, $key, $verify, $argument = null, $ctor_args = null)
    {
        $row = static::splitRelationValues($row);
        switch ($verify->mode) {
            case PDO::FETCH_NUM:
                $row = array_values($row);
                break;
            case PDO::FETCH_COLUMN:
                $argument = max(0, (int) $argument);
                if ($argument >= count($row)) {
                    throw new PDOException('SQLSTATE[HY000]: General error: Invalid column index');
                }
                $row = current(array_slice($row, $argument, 1));
                break;
            case PDO::FETCH_BOTH:
                $i = 0;
                $both = [];
                foreach ($row as $k => $v) {
                    $both[$k] = $v;
                    $both[$i] = $v;
                    $i++;
                }
                $row = $both;
                break;
            case PDO::FETCH_OBJ:
                $row = (object) $row;
                break;
            case PDO::FETCH_KEY_PAIR:
                $key = array_shift($row);
                $row = array_shift($row);
                break;
            case PDO::FETCH_FUNC:
                $row = call_user_func_array($argument, array_values($row));
                break;
            case PDO::FETCH_INTO:
                foreach ($row as $k => $v) {
                    $argument->{$k} = $v;
                }
                $row = $argument;
                break;
            case PDO::FETCH_CLASS:
                $row = static::formatFetchClass($row, $verify, $ctor_args);
                break;
        }
        return $key;
    }

    /**
     * 处理 PDO::FETCH_CLASS 类型的值
     * @param array $row
     * @param $verify
     * @param null $ctor_args
     * @return object
     * @throws Exception
     */
    protected static function formatFetchClass(array $row, $verify, $ctor_args = null)
    {
        $reflection = null;
        if ($verify->classType) {
            $reflection = array_shift($row);
            $reflection = class_exists($reflection) ?  new ReflectionClass($reflection) : null;
            if ($verify->serialize && (!$reflection || !$reflection->implementsInterface(Serializable::class))) {
                throw new PDOException(
                    'SQLSTATE[HY000]: General error: PDO::FETCH_SERIALIZE must be instanceof \Serializable'
                );
            }
        }
        if (null === $reflection) {
            $reflection = $verify->reflection;
        }
        if (null === $reflection) {
            return (object) $row;
        }
        // 创建对象但不调用 __construct()
        $obj = $reflection->newInstanceWithoutConstructor();
        if ($verify->serialize) {
            $obj->unserialize(array_shift($row));
            return static::setFetchClassPropertyValue($reflection, $obj, $row);
        }
        if ($verify->propsLate) {
            call_user_func_array([$obj, '__construct'], $ctor_args ?: []);
            return static::setFetchClassPropertyValue($reflection, $obj, $row);
        }
        $obj = static::setFetchClassPropertyValue($reflection, $obj, $row);
        call_user_func_array([$obj, '__construct'], $ctor_args ?: []);
        return $obj;
    }

    /**
     * 使用 $row 给 $obj 属性赋值
     * @param ReflectionClass $reflection
     * @param $obj
     * @param array $row
     * @return mixed
     */
    protected static function setFetchClassPropertyValue(ReflectionClass $reflection, $obj, array $row)
    {
        if (!isset($reflection->propertiesCache)) {
            $reflection->propertiesCache = [];
        }
        foreach ($row as $key => $value) {
            if (isset($reflection->propertiesCache[$key])) {
                $reflectionProperty = $reflection->propertiesCache[$key];
            } else {
                $reflectionProperty = false;
                if ($reflection->hasProperty($key)) {
                    $reflectionProperty = $reflection->getProperty($key);
                    if ($reflectionProperty->isStatic()) {
                        $reflectionProperty = false;
                    } else {
                        $reflectionProperty->setAccessible(true);
                    }
                }
                $reflection->propertiesCache[$key] = $reflectionProperty;
            }
            if ($reflectionProperty) {
                $reflectionProperty->setValue($obj, $value);
            } else {
                $obj->{$key} = $value;
            }
        }
        return $obj;
    }
}
