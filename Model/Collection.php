<?php
namespace Tanbolt\Database\Model;

use Throwable;
use Exception;
use Countable;
use ArrayAccess;
use ArrayIterator;
use LogicException;
use JsonSerializable;
use IteratorAggregate;
use Tanbolt\Database\Model;

/**
 * Class Collection: Model 集合
 * - 一个 Collection 集合中允许包括不同接口的 Model，但不建议这么做，实际使用时也不常用
 * - Collection 集合中的 Model 必须使用相同的 Connection，即不能跨数据库，否则 save() 等方法无法正确处理
 * @package Tanbolt\Database\Model
 */
class Collection implements IteratorAggregate, ArrayAccess, Countable, JsonSerializable
{
    /**
     * @var Model[]|object[]
     */
    protected $items;

    /**
     * Collection constructor.
     * @param array $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * 获取所有 Model
     * @return Model[]|object[]
     */
    public function all()
    {
        return $this->items;
    }

    /**
     * 获取 Collection 中第 $offset 个 Model
     * - 若 $offset >= 0, 从头部算起
     * - 若 $offset < 0, 从尾部算起, 如 get(-1) 将返回最后一个 Model
     * - 若不存在返回 false
     * @param int $offset
     * @return Model|object|false
     */
    public function get(int $offset = 0)
    {
        if ($offset < 0) {
            $offset = count($this->items) + $offset;
        }
        return $offset < 0 || !isset($this->items[$offset]) ? false : $this->items[$offset];
    }

    /**
     * 弹出数组最后一个单元（出栈）
     * @return Model|object|null
     */
    public function pop()
    {
        return array_pop($this->items);
    }

    /**
     * 将数组开头的单元移出数组
     * @return Model|object|null
     */
    public function shift()
    {
        return array_shift($this->items);
    }

    /**
     * 将一个或多个 Model 追加到 Collection 尾部
     * @param Model ...$model
     * @return $this
     */
    public function push(Model ...$model)
    {
        array_push($this->items, ...$model);
        return $this;
    }

    /**
     * 将一个或多个 Model 插入到 Collection 开头
     * @param Model ...$model
     * @return $this
     */
    public function unshift(Model ...$model)
    {
        array_unshift($this->items, ...$model);
        return $this;
    }

    /**
     * 返回 Collection 中指定的一列的值
     * @param string|int|null $column
     * @param string|int|null $index
     * @return array
     * @see https://www.php.net/manual/zh/function.array-column.php
     */
    public function column($column, $index = null)
    {
        return array_column($this->toArray(), $column, $index);
    }

    /**
     * 移除重复的 Model (主键相同的保留第一个，其他移除)，返回一个新的 Collection 对象
     * @return $this
     */
    public function unique()
    {
        $models = [];
        foreach ($this->items as $item) {
            $key = $item->uniqueSymbol();
            if (!isset($models[$key])) {
                $models[$key] = $item;
            }
        }
        return new static(array_values($models));
    }

    /**
     * 返回一个新的单元顺序相反的 Collection 对象
     * @param bool $preserveKeys 是否保留数字键
     * @return $this
     */
    public function reverse(bool $preserveKeys = false)
    {
        return new static(array_reverse($this->items, $preserveKeys));
    }

    /**
     * 打乱当前 Collection 的单元顺序
     * @return $this
     */
    public function shuffle()
    {
        shuffle($this->items);
        return $this;
    }

    /**
     * 根据字段值对当前 Collection 进行排序，使用方式与 array_multisort 相同, 比如：
     * - sort('foo'): 根据 foo 字段按照上升顺序排序
     * - sort('foo', SORT_DESC): 根据 foo 字段按照下降顺序排序
     * - sort('foo', SORT_DESC, SORT_STRING): 将 foo 字段按照 string 排序
     * - sort('foo', 'bar'): 先根据 foo 字段排序, foo 相同, 在根据 bar 排序，二者都是升序
     * - sort('foo', SORT_DESC, 'bar'): 先根据 foo 降序排序, foo 相同, 在根据 bar 升序排序
     * @param ...$column
     * @return $this
     * @see https://www.php.net/manual/zh/function.array-multisort.php
     */
    public function sort(...$column)
    {
        $keys = [];
        $sorts = [];
        foreach ($column as $key) {
            if (is_string($key)) {
                $keys[] = $key;
                $sorts[$key] = [];
            } else {
                $sorts[] = $key;
            }
        }
        if (!$keys) {
            return $this;
        }
        foreach ($this->items as $k => $item) {
            foreach ($keys as $name) {
                $sorts[$name][$k] = $item[$name] ?? null;
            }
        }
        $sorts = array_values($sorts);
        $sorts[] = &$this->items;
        array_multisort(...$sorts);
        return $this;
    }

    /**
     * 使用回调函数过滤数组的元素, 返回一个新的 Collection 对象
     * @param callable $callable
     * @param int $mode 0(default) | ARRAY_FILTER_USE_KEY | ARRAY_FILTER_USE_BOTH
     * @return $this
     * @see https://www.php.net/manual/zh/function.array-filter.php
     */
    public function filter(callable $callable, int $mode = 0)
    {
        return new static(array_filter($this->items, $callable, $mode));
    }

    /**
     * 使用用户自定义函数对每一个 Model 做回调处理
     * @param callable $callback
     * @param mixed $userData
     * @return $this
     */
    public function walk(callable $callback, $userData = null)
    {
        array_walk($this->items, $callback, $userData);
        return $this;
    }

    /**
     * 当前 Collection 是否包含有新纪录
     * @return bool
     */
    public function hasNewRecord()
    {
        foreach ($this->items as $item) {
            if ($item->isNewRecord()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 将所有新记录出栈, 并返回新纪录的 Collection
     * @return static
     */
    public function popNewRecord()
    {
        $items = [];
        $newItems = [];
        foreach ($this->items as $item) {
            if ($item->isNewRecord()) {
                $newItems[] = $item;
            } else {
                $items[] = $item;
            }
        }
        $this->items = $items;
        return new static($newItems);
    }

    /**
     * 设置 Collection 所有 Model 的属性
     * @param string|array $key
     * @param mixed $val
     * @return $this
     */
    public function setAttribute($key, $val = null)
    {
        foreach ($this->items as $item) {
            $item->setAttribute($key, $val);
        }
        return $this;
    }

    /**
     * 是否全部含有 key
     * @param string $key
     * @return bool
     */
    public function hasAttribute(string $key)
    {
        foreach ($this->items as $item) {
            if (!$item->hasAttribute($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 同步 Collection 的所有 Model
     * @param mixed $columns 设置要同步的字段, 若为空则同步所有字段
     * @return $this
     */
    public function syncOriginal(...$columns)
    {
        foreach ($this->items as $item) {
            $item->syncOriginal(...$columns);
        }
        return $this;
    }

    /**
     * 保存 Collection 中 Model 的修改, 返回实际影响数据库的条数
     * @return int
     * @throws Throwable
     */
    public function save()
    {
        $success = 0;
        $dates = $this->preparedSaveModels($this->items);
        foreach ($dates['insert'] as $models) {
            $success += $this->saveInsertDates($models);
        }
        foreach ($dates['update'] as $update) {
            $success += $this->saveUpdateDates($update['model'], $update['dates']);
        }
        return $success;
    }

    /**
     * 保存 Collection 中 Model 的修改以及关联 Model 的修改, 返回实际影响数据库的条数
     * @return int
     * @throws Throwable
     */
    public function saveWithRelation()
    {
        $success = 0;
        foreach (Helper::flattenModels($this) as $collection) {
            $success += $collection->save();
        }
        return $success;
    }

    /**
     * 为 save 方法准备新增修改的数据，最终整理格式如下
     * ```
     * [
     *      insert => [
     *          class_1 => [model, model]
     *          class_2 => [model, model]
     *      ],
     *      update => [
     *          hash_1 => [
     *              dates => [update dates array]
     *              model => [model, model]
     *          ]
     *          hash_2 => [
     *              dates => [update dates array]
     *              model => [model, model]
     *          ]
     *      ]
     * ]
     * ```
     * @param Model[] $models
     * @return array
     */
    protected function preparedSaveModels(array $models)
    {
        $saveDates = [
            'insert' => [],
            'update' => [],
        ];
        foreach ($models as $model) {
            if (!$model instanceof Model) {
                throw new LogicException('Collections items must be all model type.');
            }
            $class = get_class($model);
            if ($model->isNewRecord()) {
                if (!count($model)) {
                    continue;
                }
                if (!isset($saveDates['insert'][$class])) {
                    $saveDates['insert'][$class] = [];
                }
                $saveDates['insert'][$class][] = $model;
            } else {
                if (!$model->isChanged()) {
                    continue;
                }
                $updates = $model->changed();
                ksort($updates);
                $hash = md5($class.serialize($updates));
                if (!isset($saveDates['update'][$hash])) {
                    $saveDates['update'][$hash] = [
                        'dates' => $updates,
                        'model' => [],
                    ];
                }
                $saveDates['update'][$hash]['model'][] = $model;
            }
        }
        return $saveDates;
    }

    /**
     * save 方法插入新数据
     * @param Model[] $models
     * @return int
     * @throws Throwable
     */
    protected function saveInsertDates(array $models)
    {
        $dates = [];
        $now = '@'.time();
        $insertModels = [];
        foreach ($models as $model) {
            if ($model->fireListener(Model::EVENT_SAVING) === false ||
                $model->fireListener(Model::EVENT_CREATING) === false
            ) {
                continue;
            }
            // 自动维护 create time 和 update time
            if (($column = $model->getCreateTimeColumn()) && !$model->isChanged($column)) {
                $model->setCreateTime($now);
            }
            if (($column = $model->getUpdateTimeColumn()) && !$model->isChanged($column)) {
                $model->setUpdateTime($now);
            }
            $insertModels[] = $model;
            $dates[] = $model->attributes();
        }
        /** @var Model[] $insertModels */
        if (!count($insertModels)) {
            return 0;
        }
        $modelInstance = $insertModels[0];
        $primaryColumn = $modelInstance->getPrimaryColumn();
        if ($primaryColumn && !is_array($primaryColumn)) {
            if ($success = $modelInstance->setIncrementColumn($primaryColumn)->insert($dates)) {
                $lastIds = $modelInstance->lastId();
                foreach ($insertModels as $key => $model) {
                    $model->setAttribute($primaryColumn, $lastIds[$key]);
                    $model->fireListener(Model::EVENT_CREATED);
                    $model->afterSaved();
                }
            }
        } else {
            if ($success = $modelInstance->insert($dates)) {
                foreach ($insertModels as $model) {
                    $model->fireListener(Model::EVENT_CREATED);
                    $model->afterSaved();
                }
            }
        }
        return $success;
    }

    /**
     * save 方法修改旧数据
     * @param Model[] $models
     * @param array $dates
     * @return int
     * @throws Exception
     */
    protected function saveUpdateDates(array $models, array $dates)
    {
        if (!count($models) || !count($dates)) {
            return 0;
        }
        $now = '@'.time();
        $primaries = [];
        $updateModels = [];
        $syncTimeColumn = null;
        foreach ($models as $model) {
            $primary = $model->primaryValue();
            if (empty($primary)) {
                throw new LogicException('No primary key defined on model.');
            }
            if ($model->fireListener(Model::EVENT_SAVING) === false ||
                $model->fireListener(Model::EVENT_UPDATING) === false
            ) {
                continue;
            }
            $updateModels[] = $model;
            // 自动维护 update time
            if (null === $syncTimeColumn) {
                if (($column = $model->getUpdateTimeColumn()) && !isset($dates[$column])) {
                    $syncTimeColumn = $column;
                    $model->setUpdateTime($now);
                    $dates[$column] = $model->{$column};
                } else {
                    $syncTimeColumn = false;
                }
            }
            $primaries[] = $primary;
        }
        /** @var Model[] $updateModels */
        if (!count($updateModels)) {
            return 0;
        }
        if ($success = (new ActiveRecord($updateModels[0]))->wherePrimary($primaries)->update($dates)) {
            // 这里需要注意, 若 Collection 中的 !newRecord 数据实际并不存在
            // 那么也就不可能更新成功, 但还是触发了 EVENT_UPDATED 回调
            // 这里没有判断实际更新了哪些数据
            foreach ($updateModels as $model) {
                if ($syncTimeColumn) {
                    $model->{$syncTimeColumn} = $now;
                }
                $model->fireListener(Model::EVENT_UPDATED);
                $model->afterSaved();
            }
        }
        return $success;
    }

    /**
     * 删除当前 Collection 中所有 Model, 返回实际影响数据库的条数
     * @return int
     * @throws Exception
     */
    public function drop()
    {
        $success = 0;
        $existRecords = [];
        foreach ($this->items as $item) {
            if ($item instanceof Model && !$item->isNewRecord()) {
                $class = get_class($item);
                if (!isset($existRecords[$class])) {
                    $existRecords[$class] = [];
                }
                $existRecords[$class][] = $item;
            }
        }
        foreach ($existRecords as $item) {
            $primaries = [];
            $droppedModel = [];
            foreach ($item as $model) {
                /** @var Model $model */
                $primary = $model->getPrimaryColumn();
                $primary = empty($primary) ? null : $model->primaryValue();
                if (empty($primary)) {
                    throw new LogicException('No primary key defined on model.');
                }
                if ($model->fireListener(Model::EVENT_DROPPING) === false) {
                    continue;
                }
                if (!in_array($primary, $primaries)) {
                    $primaries[] = $primary;
                }
                $droppedModel[] = $model;
            }
            /** @var Model[] $droppedModel */
            if (count($droppedModel) && (
                $success = (new ActiveRecord($droppedModel[0]))->wherePrimary($primaries)->delete()
            )) {
                // 这里需要注意, 若 Collection 中的 !newRecord 数据实际并不存在
                // 那么也就不可能删除成功, 但还是触发了 EVENT_DROPPED 回调
                // 这里没有判断实际删除了哪些数据
                foreach ($droppedModel as $model) {
                    $model->setNewRecord()->fireListener(Model::EVENT_DROPPED);
                }
            }
        }
        return $success;
    }

    /**
     * 删除当前 Collection 中所有 Model 及其关联 Model, 返回实际影响数据库的条数
     * @return int
     * @throws Exception
     */
    public function dropWithRelation()
    {
        $success = 0;
        foreach (Helper::flattenModels($this) as $collection) {
            $success += $collection->drop();
        }
        return $success;
    }

    /**
     * 手动加载指定的关联模型,默认情况下,直接以属性方式访问关联模型,会自动加载并获取结果。
     * 手动加载可以将 Collection 中所有 Model 的关联 Model 用一次查询全部获取
     * ```
     * loadRelation('foo', 'bar')
     * loadRelation([
     *    'foo' => function() {
     *       //Scope
     *    },
     *   'bar' => function() {
     *       //Scope
     *    },
     * ])
     * ```
     * @param mixed $relation
     * @return $this
     * @throws Exception
     */
    public function loadRelation(...$relation)
    {
        Helper::loadModelRelations($this->items, $relation);
        return $this;
    }

    /**
     * 从当前对象重载所有数据, 返回一个新对象
     * @param mixed $relation 可同时设置要重新加载的关联模型
     * @return Collection
     */
    public function fresh(...$relation)
    {
        if (!($count = $this->count())) {
            return new static();
        }
        // 对已存在和未存在的 model 进行分拣
        $newRecords = [];
        $existRecords = [];
        foreach ($this->items as $key => $item) {
            if ($item instanceof Model && !$item->isNewRecord()) {
                $class = get_class($item);
                if (!isset($existRecords[$class])) {
                    $existRecords[$class] = [];
                }
                $existRecords[$class][$key] = $item;
            } else {
                $newRecords[$key] = $item;
            }
        }
        // 获取已存在 model 的当前数据
        $freshRecords = [];
        foreach ($existRecords as $item) {
            $modelKeys = [];
            $primaries = [];
            $modelInstance = null;
            // 整理当前 model 主键值
            foreach ($item as $key => $model) {
                /** @var Model $model */
                if (!$modelInstance) {
                    $modelInstance = $model;
                }
                $primary = $model->primaryValue();
                if (empty($primary)) {
                    throw new LogicException('No primary key defined on model.');
                }
                if (!in_array($primary, $primaries)) {
                    $primaries[] = $primary;
                }
                if (is_array($primary)) {
                    $primary = join('_', $primary);
                }
                // 防止 collection 多次添加同一条数据的 model
                if (isset($modelKeys[$primary])) {
                    $modelKeys[$primary][] = $key;
                } else {
                    $modelKeys[$primary] = [$key];
                }
            }
            // 查询当前 model 最新数据
            $collection = [];
            if ($modelInstance) {
                $tmp = call_user_func_array([$modelInstance, 'with'], $relation)
                    ->wherePrimary($primaries)->findMany()->all();
                foreach ($tmp as $model) {
                    /** @var Model $model */
                    $primary = $model->primaryValue();
                    if (is_array($primary)) {
                        $primary = join('_', $primary);
                    }
                    $collection[$primary] = $model;
                }
                unset($tmp);
            }
            // 将当前 model 存放到 $freshRecords
            foreach ($modelKeys as $primary => $modelKey) {
                foreach ($modelKey as $key) {
                    if (isset($collection[$primary])) {
                        $freshRecords[$key] = $collection[$primary];
                    } else {
                        $freshRecords[$key] = $item[$key];
                    }
                }
            }
        }
        // 合并不存在的 model 和 更新后的已存在 model
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            if (isset($newRecords[$i])) {
                $items[$i] = $newRecords[$i];
            } else {
                $items[$i] = $freshRecords[$i];
            }
        }
        unset($newRecords, $freshRecords);
        return new static($items);
    }

    /**
     * 重载当前对象的所有数据
     * @return $this
     */
    public function refresh()
    {
        $this->items = $this->fresh()->all();
        return $this;
    }

    /**
     * 转为数组
     * @param bool $includeRelation 是否将 $relation 一并返回
     * @return array
     */
    public function toArray(bool $includeRelation = false)
    {
        return array_map(function ($value) use ($includeRelation) {
            if ($value instanceof Access || $value instanceof Collection) {
                return $value->toArray($includeRelation);
            } elseif ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            } else {
                return $value;
            }
        }, $this->items);
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @inheritDoc
     */
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->items);
    }

    /**
     * @param mixed $offset
     * @return Model|object
     */
    public function offsetGet($offset)
    {
        return $this->items[$offset];
    }

    /**
     * @inheritDoc
     */
    public function offsetSet($offset, $value)
    {
        $this->items[$offset] = $value;
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }
}
