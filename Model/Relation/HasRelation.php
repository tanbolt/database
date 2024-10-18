<?php
namespace Tanbolt\Database\Model\Relation;

use PDO;
use Throwable;
use Exception;
use LogicException;
use Tanbolt\Database\Model;
use Tanbolt\Database\Model\Pivot;
use Tanbolt\Database\Model\Helper;
use Tanbolt\Database\Model\Relation;
use Tanbolt\Database\Model\Collection;

abstract class HasRelation extends Relation
{
    /**
     * @var string
     */
    private $pivotClassName;

    /**
     * @var mixed
     */
    private $pivotRelationFill;

    /**
     * 给主模型 增加 关联模型, 会插入数据到数据库
     * > 可用在 hasOne hasMany 如
     * ```
     * hasOne: user->phone   user->phone()->addModel()
     * hasMany: post->comments  user->comment()->addCollection()
     * ```
     *
     * @param Collection|Model|Model[]|array $models
     * @param ?array $pivotAttributes
     * @return Collection
     * @throws Throwable
     */
    protected function addRelationModels($models, array $pivotAttributes = null)
    {
        $this->pivotClassName = null;
        $this->pivotRelationFill = false; //是否已经指定 中间表 与 关联模型的 关联值
        $collection = $this->makeRelatedCollection($models, $pivotAttributes, 'add');
        $flattenModel = Helper::flattenModels($collection);

        // 保存关联模型数据
        if (isset($flattenModel[$this->relationModelClass])) {
            $flattenModel[$this->relationModelClass]->save();
        }
        // 保存中间表数据
        if (!empty($this->pivot) && $this->pivotClassName && isset($flattenModel[$this->pivotClassName])) {
            if ($this->pivotRelationFill) {
                foreach ($collection->all() as $model) {
                    $model->pivot->setAttribute($this->pivotRelationKey, $model->getAttribute($this->foreignKey));
                }
            }
            $flattenModel[$this->pivotClassName]->save();
        }
        return $collection;
    }

    /**
     * 主模型绑定指定的关联模型
     * @param Collection|Model|Model[]|array $models
     * @param ?array $modelAttributes
     * @param ?array $pivotAttributes
     * @param bool $shared
     * @return Collection
     * @throws Throwable
     */
    protected function holdRelationModels($models, array $modelAttributes = null, array $pivotAttributes = null, bool $shared = false)
    {
        $collection = $this->makeCollectionWithPivot(
            $models, $modelAttributes, $pivotAttributes, 'hold', $shared
        );
        $flattenModel = Helper::flattenModels($collection);

        // 保存关联模型
        if (isset($flattenModel[$this->relationModelClass])) {
            $flattenModel[$this->relationModelClass]->save();
        }
        // 保存中间表数据
        if (!empty($this->pivot) && $this->pivotClassName && isset($flattenModel[$this->pivotClassName])) {
            $flattenModel[$this->pivotClassName]->save();
        }
        return $collection;
    }

    /**
     * 解绑释放关联模型, 关联模型对应的字段将被修改为 getFreeValue()
     * @param Collection|Model|Model[]|array|int|null $models
     * @param null $modelAttributes
     * @return Collection|int
     * @throws Throwable
     */
    protected function freedRelationModels($models = null, $modelAttributes = null)
    {
        if (null === $models) {
            return $this->unbindAllRelations(false, $modelAttributes);
        }
        $collection = $this->makeCollectionWithPivot(
            $models, $modelAttributes, null, 'free', null
        );
        $flattenModel = Helper::flattenModels($collection);

        // 保存关联模型
        if (isset($flattenModel[$this->relationModelClass])) {
            $flattenModel[$this->relationModelClass]->save();
        }
        // 移除中间表
        if (!empty($this->pivot) && $this->pivotClassName && isset($flattenModel[$this->pivotClassName])) {
            $flattenModel[$this->pivotClassName]->drop();
        }
        return $collection;
    }

    /**
     * 移除关联模型，移除的关联模型数据会被删除
     * @param Collection|Model[]|array|Model|int|null $models
     * @return Collection|int
     * @throws Exception
     */
    protected function removeRelationModels($models = null)
    {
        if (null === $models) {
            return $this->unbindAllRelations(true);
        }
        $collection = $this->makeCollectionWithPivot(
            $models, null, null, 'remove', null
        );
        $flattenModel = Helper::flattenModels($collection);

        // 移除关联模型
        if (isset($flattenModel[$this->relationModelClass])) {
            $flattenModel[$this->relationModelClass]->drop();
        }
        // 移除中间表
        if (!empty($this->pivot) && $this->pivotClassName && isset($flattenModel[$this->pivotClassName])) {
            $flattenModel[$this->pivotClassName]->drop();
        }
        return $collection;
    }

    /**
     * 通过语句构造器移除或释放所有关联模型
     * @param bool $remove
     * @param ?array $modelAttributes
     * @return int
     * @throws Exception
     */
    protected function unbindAllRelations(bool $remove = false, array $modelAttributes = null)
    {
        $parentValue = $this->parent->getAttribute($this->localKey);
        // 无中间表，直接操作关联模型数据表
        if (empty($this->pivot)) {
            $builder = $this->model->where($this->foreignKey, $parentValue);
            if ($remove) {
                return $builder->delete();
            }
            $modelAttributes = empty($modelAttributes) ? [] : $modelAttributes;
            $modelAttributes[$this->foreignKey] = $this->freeValue;
            return $builder->update($modelAttributes);
        }
        // 从 中间表关联行 查询 对应的关联模型 数据
        $pivot = $this->createPivot();
        $builder = $this->model->where(
            $this->foreignKey,
            'IN',
            $pivot->select($this->pivotRelationKey)->where($this->pivotParentKey, $parentValue)
        );
        // 删除或更新 关联模型数据
        $effect = 0;
        if ($remove) {
            $effect += $builder->delete();
        } elseif (is_array($modelAttributes) && !empty($modelAttributes)) {
            $effect += $builder->update($modelAttributes);
        }
        // 删除中间表数据
        $effect += $pivot->where($this->pivotParentKey, $parentValue)->delete();
        return $effect;
    }

    /**
     * 整理所有 Models 并修正中间表数据, 为 hold free remove 提供整理后的数据
     * @param Collection|Model|Model[]|array $models
     * @param ?array $modelAttributes
     * @param ?array $pivotAttributes
     * @param string $action
     * @param bool $shared
     * @return Collection
     * @throws Exception
     */
    protected function makeCollectionWithPivot($models, ?array $modelAttributes, ?array $pivotAttributes, string $action, ?bool $shared)
    {
        // 中间表 className
        $this->pivotClassName = null;

        // 关联模型可能未指定 其与中间表的关联值
        // 缓存需要查询与中间表关联值的 关联模型主键 ids
        $this->pivotRelationFill = [];

        // 校验参数并整理为一个Collection, 过程中会设置 pivotClassName / pivotRelationFill
        $collection = $this->makeRelatedCollection($models, $pivotAttributes, $action);
        if (!$collection->count()) {
            return $collection;
        }

        // 设置关联模型指定数据
        if (!empty($modelAttributes)) {
            if (empty($this->pivot) && array_key_exists($this->foreignKey, $modelAttributes)) {
                unset($modelAttributes[$this->foreignKey]);
            }
            $collection->setAttribute($modelAttributes);
        }

        // 设置未提供数据的中间表
        if (count($this->pivotRelationFill)) {
            $collection = $this->fillRelationPivotData($collection, $this->pivotRelationFill, $shared);
        }
        return $collection;
    }

    /**
     * 整理需要绑定到主模型上的 关联模型中间表
     * @param Collection $collection collection 对象
     * @param array $relatePrimary 关联模型主键值
     * @param bool|null $shared true:允许多条中间表数据, false:只能一条数据, null:只能一条数据且必须存在
     * @return Collection
     * @throws Exception
     */
    protected function fillRelationPivotData(Collection $collection, array $relatePrimary, bool $shared = null)
    {
        // 中间表主键字段
        $pivotColumn = $collection[0]->pivot->getPrimaryColumn();
        $pivotColumnSelect = is_array($pivotColumn) ? $pivotColumn : [$pivotColumn];
        // 中间表关联字段
        if (!in_array($this->pivotRelationKey, $pivotColumnSelect)) {
            $pivotColumnSelect[] = $this->pivotRelationKey;
        }
        if (!in_array($this->pivotParentKey, $pivotColumnSelect)) {
            $pivotColumnSelect[] = $this->pivotParentKey;
        }
        $pivotColumnSelect = array_map(function($column) {
            return $this->pivotTableName.'.'.$column.' AS pivot_'.$column;
        }, $pivotColumnSelect);

        // 查询关联模型 与 主模型 对应的中间表数据
        // 关联模型主键值、和中间表对应字段值; 中间表主键值、中间表的两个关联字段值;
        $lists = $this->model->select(
            $this->model->getTablePrimary(),
            $this->model->getTable().'.'.$this->foreignKey.' AS pivot_*',
            $pivotColumnSelect
        )->leftJoinOn(
            $this->pivotTableName,
            $this->model->getTable().'.'.$this->foreignKey,
            $this->pivotTableName.'.'.$this->pivotRelationKey
        )->where(
            $this->model->getTablePrimary(), 'IN', $relatePrimary
        )->getMany(PDO::FETCH_ASSOC);

        /*
         * 查询数据整理为 关联模型主键字段值 作为 键名的数组
         * 整理后数据为
         * [
         *     关联模型主键值 => [
         *         * => 值(关联模型与中间表对应字段值)
         *         中间表主键 => 值
         *         中间表与关联模型字段 => 值
         *         中间表与主模型字段 => 值
         *     ],
         *     .....
         * ]
         */
        $listsWithPrimaryKey = [];
        $primary = $this->model->getPrimaryColumn();
        $primaries = is_array($primary) ? $primary : null;
        $localVal = $this->parent->getAttribute($this->localKey);
        foreach ($lists as $list) {
            //key
            if ($primaries) {
                $key = [];
                foreach ($primaries as $p) {
                    $key[] = $list[$p];
                    unset($list[$p]);
                }
                $key = join('_', $key);
            } else {
                $key = $list[$primary];
                unset($list[$primary]);
            }
            //val
            $val = [];
            foreach ($list as $k => $v) {
                $val[substr($k, 6)] = $v;
            }
            // 如果有多条中间表数据, 且都不是与当前主模型关联的, 只取第一条, 其他忽略掉
            if (isset($listsWithPrimaryKey[$key]) && $val[$this->pivotParentKey] != $localVal) {
                continue;
            }
            $listsWithPrimaryKey[$key] = $val;
        }

        // 将查询数据匹配到 Collection 上
        $newCollection = [];
        foreach ($collection as $model) {
            if ($primaries) {
                $key = [];
                foreach ($primaries as $primary) {
                    $key[] = $model[$primary];
                }
                $key = join('_', $key);
            } else {
                $key = $model[$primary];
            }

            // 指定的关联模型数据不存在, 忽略之
            if (!isset($listsWithPrimaryKey[$key])) {
                if (!$model->pivot->isNewRecord() && $model->pivot->hasAttribute($this->pivotRelationKey)) {
                    $newCollection[] = $model;
                }
                continue;
            }
            $data = $listsWithPrimaryKey[$key];
            $newRecord = is_null($data[$this->pivotRelationKey]);

            // 针对多对多的, 即使已存在数据, 但存在的中间表数据 并非 关联指定的 关联模型和主模型, 仍插入新数据
            if (!$newRecord && $shared !== false && $data[$this->pivotParentKey] != $localVal) {
                $newRecord = true;
            }

            if ($newRecord) {
                if (null === $shared) {
                    // free|remove 去掉 pivot
                    $model->removeRelation('pivot');
                } else {
                    // 插入一条新的中间表数据
                    $model->pivot->setAttribute($this->pivotRelationKey, $data['*'])->setNewRecord(true);
                }
            } else {
                // 修改原有中间表有数据 (把原始数据套上去, 并同步)
                $newParent = $model->pivot->getAttribute($this->pivotParentKey);
                $newRelated = $data['*'];
                unset($data['*']);
                $model->pivot->setAttribute($data)->syncOriginal(array_keys($data))->setNewRecord(false);
                // hold, 设置新值, 更新时会自动判断是否进行sql
                if ($shared !== null) {
                    $model->pivot->setAttribute($this->pivotRelationKey, $newRelated)
                        ->setAttribute($this->pivotParentKey, $newParent);
                }
            }
            $newCollection[] = $model;
        }
        return new Collection($newCollection);
    }

    /**
     * 整理待操作的关联模型为 Collection 对象
     * @param Collection|Model|Model[]|array $models
     * @param ?array $pivotAttributes
     * @param string $action
     * @return Collection
     */
    protected function makeRelatedCollection($models, ?array $pivotAttributes, string $action)
    {
        $collection = [];
        foreach ($this->preparedRelateModels($models, $action) as $model) {
            $collection[] = $this->formatRelateModel($model, $pivotAttributes, $action);
        }
        return new Collection($collection);
    }

    /**
     * 将关联模型 Collection|Model|Model[]|array 转换为 Model[]
     * @param Collection|Model|Model[]|array $collection
     * @param string $action
     * @return Model[]|object[]
     */
    protected function preparedRelateModels($collection, string $action)
    {
        $models = [];
        if ($collection instanceof Collection) {
            $models = $collection->all();
        } elseif ($collection instanceof Model) {
            $models = [$collection];
        } elseif ('add' === $action) {
            if (is_array($collection)) {
                $first = reset($collection);
                if (!is_array($first) && !$first instanceof Model) {
                    $collection = [$collection];
                }
                foreach ($collection as $model) {
                    $models[] = $model instanceof Model ? $model : new $this->relationModelClass($model);
                }
            }
        } else {
            if (!is_array($collection)) {
                $collection = [$collection];
            }
            foreach ($collection as $model) {
                if (!($model instanceof Model)) {
                    $model = $this->setRelateModelPrimaryValue(new $this->relationModelClass([], false), $model);
                }
                if ($model) {
                    $models[] = $model;
                }
            }
        }
        if (!count($models)) {
            throw new LogicException('Related model must be model instance or array.');
        }
        return $models;
    }

    /**
     * 设置指定 Model 的主键值
     * @param Model $model
     * @param $primary
     * @return Model|object
     */
    protected function setRelateModelPrimaryValue(Model $model, $primary)
    {
        return $model->setPrimaryValue($primary)->syncOriginal();
    }

    /**
     * 格式化 关联模型
     * @param Model $model
     * @param ?array $pivotAttributes
     * @param string $action
     * @return Model
     */
    protected function formatRelateModel(Model $model, array $pivotAttributes = null, string $action = '')
    {
        // 校验关联模型
        if (!$model instanceof $this->relationModelClass) {
            throw new LogicException('Related model must be instanceof ['.$this->relationModelClass.'].');
        }
        if ('add' === $action) {
            if (!$model->isNewRecord()) {
                throw new LogicException('Related model ['.$this->relationModelClass.'] must be new record.');
            }
        } elseif ($model->isNewRecord()) {
            throw new LogicException('Related model ['.$this->relationModelClass.'] must be exist record.');
        }

        // 无中间表, 设置关联模型的 关联字段 即可
        if (empty($this->pivot)) {
            $foreignKeyValue = $this->freeValue;
            if ('add' === $action || 'hold' === $action) {
                $foreignKeyValue = $this->parent->getAttribute($this->localKey);
            }
            return $model->setAttribute($this->foreignKey, $foreignKeyValue);
        }

        // 关联模型有中间表子模型, 先校验中间表子模型
        if ($model->hasRelation('pivot')) {
            if (!$this->ifPivotModelMatch($model->pivot)) {
                throw new LogicException('Related model pivot not match.');
            }
            if ('add' === $action) {
                if (!$model->pivot->isNewRecord()) {
                    throw new LogicException('Related model pivot ['.get_class($model->pivot).'] must be new record.');
                }
            } elseif ($model->pivot->isNewRecord()) {
                throw new LogicException('Related model pivot ['.get_class($model->pivot).'] must be exist record.');
            }
            $model->pivot->setAttribute($this->checkPivotAttribute($pivotAttributes));
        } else {
            $model->setRelation('pivot', $this->createPivot((array) $pivotAttributes));
        }
        if (!$this->pivotClassName) {
            $this->pivotClassName = get_class($model->pivot);
        }

        // 设置中间表 对应 关联模型 的 字段值
        if ('add' === $action) {
            if ($model->hasAttribute($this->foreignKey)) {
                $model->pivot->setAttribute($this->pivotRelationKey, $model->getAttribute($this->foreignKey));
            } else {
                $primary = $this->model->getPrimaryColumn();
                if (!is_string($primary) || $primary !== $this->foreignKey) {
                    throw new LogicException('Related model pivot related key not set.');
                }
                if (!$this->pivotRelationFill) {
                    $this->pivotRelationFill = true;
                }
            }
        } else {
            // 待补充的关联模型中间表: 中间表为新插入 或 未中间表与关联模型对应字段值 或 未指明中间表主键值
            if ($model->pivot->isNewRecord() || !$model->pivot->hasAttribute($this->pivotRelationKey) ||
                empty($model->pivot->primaryValue())
            ) {
                $this->pivotRelationFill[] = $model->primaryValue();
            }
        }

        // 设置中间表 对应 主模型 的 字段值
        $model->pivot->setAttribute($this->pivotParentKey, $this->parent->getAttribute($this->localKey));
        return $model;
    }

    /**
     * 验证中介模型是否匹配
     * @param Model $pivot
     * @return bool
     */
    protected function ifPivotModelMatch(Model $pivot)
    {
        if (!$this->pivotIsTable) {
            return $pivot instanceof $this->pivot;
        }
        return $pivot instanceof Pivot
            && $pivot->getTable() === $this->pivot
            && $pivot->relationKey() === $this->pivotRelationKey
            && $pivot->parentKey() === $this->pivotParentKey
            && (!$this->pivotPrimaryKey || $pivot->getPrimaryColumn() === $this->pivotPrimaryKey);
    }

    /**
     * 去除自定义中间表字段中的 关联字段
     * @param ?array $attributes
     * @return array
     */
    protected function checkPivotAttribute(array $attributes = null)
    {
        if (empty($attributes)) {
            return [];
        }
        if (isset($attributes[$this->pivotRelationKey])) {
            unset($attributes[$this->pivotRelationKey]);
        }
        if (isset($attributes[$this->pivotParentKey])) {
            unset($attributes[$this->pivotParentKey]);
        }
        return $attributes;
    }
}
