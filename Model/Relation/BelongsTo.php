<?php
namespace Tanbolt\Database\Model\Relation;

use PDO;
use Exception;
use LogicException;
use Tanbolt\Database\Model;
use Tanbolt\Database\Model\Relation;
use Tanbolt\Database\Exception\ModelNotFoundException;

class BelongsTo extends Relation
{
    /**
     * 将当前模型作为关联模型 挂载到 指定的主模型上
     * - 当前模型只能 belongTo 一个主模型, 自动与之前 belongTo 的模型解绑
     * - 指定的主模型 $model 必须实际存在, 这里为减少 select 查询, 不进行判断, 而是直接修改当前模型的关联值
     * - 挂载之后, 主模型会自动添加到当前模型对象中
     * - 关联后并不会自动提交, 需要手动保存, 可根据需要在保存前修改其他字段值, 避免多次 update ,
     *   直接关联的使用 save(), 通过中间表关联的, 可通过 saveWithRelation() 或 mode->relation->save()
     * - 返回主模型
     *
     * 使用示例：
     * - 获取 Address Model
     * ```
     * $address = Address::find(1);
     * $user1 = $address->user;  #当前 address belongTo 的 User 模型
     * ```
     *
     * - 绑定到已获取的 User Model
     * ```
     * $user2 = User::find(2);
     * $address = $address->user()->associate($user2); #返回的仍然是 $address
     * $user = $address->user;  #会获取到 $user2
     * ```
     *
     * - 直接用主键绑定
     * ```
     * $address->user()->associate(2); #$address->user 会获取到一个只有主键值的 User 模型
     * ```
     *
     * - 保存修改
     * ```
     * $address->save(); #若 Address 与 User 是直接关联，保存生效
     * $address->saveWithRelation(); #若 Address 与 User 通过中间表关联, 保存关联模型生效
     * $address->user->save(); #也可通过 User 模型保存
     * ```
     *
     * @param Model|array|int $model
     * @param array|null $pivotAttributes 如果使用中间表 设置中间表字段值,
     *                                     若 $model 为关联模型对象且包含 pivot 对象
     *                                     这里设置的字段值会覆盖 pivot 中已设置的字段值
     * @return Model|object
     * @throws Exception
     */
    public function associate($model, array $pivotAttributes = null)
    {
        if ($model instanceof Model) {
            if ($model instanceof $this->relationParentClass) {
                throw new LogicException('Related model must be instanceof ['.$this->relationParentClass.'].');
            } elseif ($model->isNewRecord()) {
                throw new LogicException('Related model ['.$this->relationModelClass.'] must be exist record.');
            }
            $belong = $model;
        } else {
            $belong = $this->model->newModel([], false);
            $belong->setPrimaryValue($model)->syncOriginal();
        }

        // 使用中间表
        if ($this->pivot) {
            return $this->associateThroughPivot($belong, $pivotAttributes);
        }

        // 未使用中间表
        $foreignValue = $belong->hasAttribute($this->foreignKey) ?
            $belong->getAttribute($this->foreignKey) :
            $this->getRelateForeignValue($belong);
        return $this->parent->setAttribute($this->localKey, $foreignValue)
            ->setRelation($this->relation, $belong);
    }

    /**
     * 通过中间表挂载 关联模型
     * @param Model $belong
     * @param ?array $pivotAttributes
     * @return Model|object
     * @throws Exception
     */
    protected function associateThroughPivot(Model $belong, array $pivotAttributes = null)
    {
        // $belong 中是否含有与 中间表对应的字段值
        $foreignValue = $belong->hasAttribute($this->foreignKey) ? $belong->getAttribute($this->foreignKey) : null;
        $pivot = $this->getCurrentPivot();
        if ($pivot) {
            // 若当前模型已加载过关联模型, 直接修改 Pivot 与关联模型的 关联字段值即可
            if (!$foreignValue) {
                $foreignValue = $this->getRelateForeignValue($belong);
            }
        } else {
            $localValue = $this->parent->getAttribute($this->localKey);
            $pivot = $this->createPivot();

            // 中间表主键字段
            $pivotColumn = $pivot->getPrimaryColumn();
            if (is_array($pivotColumn)) {
                $pivotColumnSelect = $pivotColumn;
            } else {
                $pivotColumnSelect = [$pivotColumn];
            }
            // 中间表关联字段
            if (!in_array($this->pivotRelationKey, $pivotColumnSelect)) {
                $pivotColumnSelect[] = $this->pivotRelationKey;
            }
            if (!in_array($this->pivotParentKey, $pivotColumnSelect)) {
                $pivotColumnSelect[] = $this->pivotParentKey;
            }
            //设置别名
            $pivotColumnSelect = array_map(function($column) {
                return $this->pivotTableName.'.'.$column.' AS pivot_'.$column;
            }, $pivotColumn);

            if ($foreignValue) {
                // 若关联模型与中间表 关联字段值存在, 仅查询中间表即可
                $pivotBuilder = $pivot->select($pivotColumnSelect)->where(
                    $this->pivotTableName.'.'.$this->pivotParentKey,
                    $localValue
                );
            } else {
                // 若关联模型与中间表 关联字段值不存在, 查询关联字段并查询中间表
                $pivotColumnSelect[] = $belong->getTable().'.'.$this->foreignKey.' AS pivot_*';
                $pivotBuilder = $belong->select($pivotColumnSelect)->joinWhere(
                    $pivot->getTable(),
                    $this->pivotTableName.'.'.$this->pivotParentKey,
                    '=',
                    $localValue,
                    'LEFT'
                )->where(
                    $belong->getTablePrimary(),
                    $belong->primaryValue()
                );
            }
            $row = $pivotBuilder->getOne(PDO::FETCH_ASSOC);
            if (!$foreignValue && !is_array($row)) {
                throw new ModelNotFoundException('Associate model not exist');
            }

            // 整理查询到的中间表数据
            $attributes = [];
            if (is_array($row)) {
                foreach ($row as $k => $v) {
                    if ('pivot_*' === $k) {
                        $foreignValue = $v;
                    } else {
                        $attributes[substr($k, 6)] = $v;
                    }
                }
            }

            // 赋值到关联模型对象上，设置关联模型与中间表对应字段值
            $belong->setAttribute($this->foreignKey, $foreignValue)->syncOriginal();

            // 赋值到 Pivot 模型对象上
            if (isset($attributes[$this->pivotParentKey]) && $attributes[$this->pivotParentKey]) {
                $pivot->setAttribute($attributes)->syncOriginal()->setNewRecord(false);
            } else {
                $pivot->setAttribute($this->pivotParentKey, $localValue);
            }
        }
        return $this->parent->setRelation($this->relation, $belong->setRelation(
            'pivot',
            $pivot->setAttribute($this->pivotRelationKey, $foreignValue)
                ->setAttribute($this->checkPivotAttribute($pivotAttributes))
        ));
    }

    /**
     * 由关联模型主键字段 查询 其与中间表关联的字段值
     * @param Model $belong
     * @return mixed
     * @throws Exception
     */
    protected function getRelateForeignValue(Model $belong)
    {
        $row = $belong::select($this->foreignKey)->wherePrimary(
            $belong->primaryValue()
        )->getOne(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ModelNotFoundException('Associate model not exist');
        }
        $foreignValue = $row[$this->foreignKey];
        $belong->setAttribute($this->foreignKey, $foreignValue)->syncOriginal();
        return $foreignValue;
    }

    /**
     * 去除自定义中间表字段中的 关联字段
     * @param ?array $attributes
     * @return array|null
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

    /**
     * 获取当前模型 与 关联模型的 中间表模型
     * @return null|object|Model
     */
    protected function getCurrentPivot()
    {
        /** @var Model $relation */
        $relation = $this->parent->hasRelation($this->relation) ? $this->parent->getRelation($this->relation) : null;
        return $relation && $relation->hasRelation('pivot') ? $relation->pivot : null;
    }

    /**
     * 释放解绑当前的模型
     * - 直接关联的, 仅把关联字段设置为 freeValue, 需要 save() 之后生效
     * - 通过中间表关联的, 会直接删除中间表关联行, 立即生效，
     *   若 dissociate() 之前加载过关联模型, dissociate() 会触发中间表模型的 DROP 事件, 否则不会
     *
     * > 关于第二点：因为触发监听事件需要先实例化关联模型, 这就需要先从数据库查询关联模型数据, 之后在删除。
     *   若使用场景本来就没设置 DROP 事件监听, 这就白白多了一次查询。
     *   若对触发事件有刚性要求，可以在 dissociate 之前先获取，再 dissociate
     * @return Model|object
     * @throws Exception
     */
    public function dissociate()
    {
        if ($this->pivot) {
            if ($pivot = $this->getCurrentPivot()) {
                $pivot->drop();
            } else {
                $this->createPivot()->where(
                    $this->pivotParentKey, $this->parent->getAttribute($this->localKey)
                )->delete();
            }
        } else {
            $this->parent->setAttribute($this->localKey, $this->freeValue);
        }
        return $this->parent->setRelation($this->relation);
    }
}
