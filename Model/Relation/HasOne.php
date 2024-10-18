<?php
namespace Tanbolt\Database\Model\Relation;

use Throwable;
use Exception;
use Tanbolt\Database\Model;

class HasOne extends HasRelation
{
    /**
     * 增加关联模型, 新增的关联模型会插入数据到数据库, 返回添加成功的关联模型
     * - 若主模型已有对应的关联模型，也会添加, 这里并不做判断, 因为要多一步查询, 如有强制需求, 执行前请自行判断
     * - 返回的关联模型仅包含新增时指定字段 (关联字段, $model, $pivotAttributes 设定值)
     * - 新增模型不会主动挂载到主模型上, 如有需要, 手动请求主模型的关联模型
     * - 会触发事件监听函数
     *
     * ```
     * $user = User::find(1);
     * $phone = $user->phone()->addModel([]); # $phone 仅包含部分字段的关联模型
     * $user->loadRelation('phone');
     * $phone = $user->phone; # $phone 包含完整字段的关联模型
     * ```
     *
     * @param Model|array $model 关联模型 (newRecord Model) 或 关联模型字段为键名的数组
     * @param ?array $pivotAttributes 如果有中间表，可同时设置中间表字段值；
     *                                若 $model 为 Model 对象且包含 pivot 对象，这里设置的字段值会覆盖 pivot 中已设置的字段值
     * @return Model|object
     * @throws Throwable
     */
    public function addModel($model, array $pivotAttributes = null)
    {
        return $this->addRelationModels($model, $pivotAttributes)->get();
    }

    /**
     * 在主模型绑定已有关联模型, 关联模型的约束字段将被修改，并修改数据库中对应字段值, 返回绑定成功的关联模型
     * - 若主模型已有对应的关联模型，也会添加, 这里并不做判断, 因为要多一步查询, 如有强制需求, 添加前请自行判断
     * - 绑定的关联模型仅包含指定字段(关联字段, $model, $modelAttributes, $pivotAttributes 设定值)
     * - 绑定不一定会成功: hold 会直接执行 update related_column where primary=model, 若 model 就不存在, 当然是不会成功
     * - 关联模型也不会主动挂载到主模型上, 如需要确认, 手动请求主模型的关联模型
     * - 若关联模型已挂载到其他主模型，也不会自动卸载
     * - 会触发事件监听函数
     *
     * ```
     * $user1 = User::find(1);
     * $user2 = User::find(2);
     * $phone = $user2->phone()->hold($user1->phone); #仅返回挂载模型
     * $user2->loadRelation('phone');
     * $phone = $user->phone; #包含完整字段的关联模型
     * if ($phone) {
     *   # $user1->phone 重新挂载到 $user2 上,
     *   # 但 $user1 对象已经包含 phone 属性, 无法自动清除，为脏数据
     *   unset($user1->phone);
     * }
     * ```
     *
     * @param Model|int $model 关联模型 (!newRecord Model) 或 关联模型主键值
     * @param ?array $modelAttributes 设置关联模型字段值，
     *                                若 $model 为关联模型对象，这里设置的字段值会覆盖 $model 中已设置的字段值
     * @param ?array $pivotAttributes 如果有中间表，设置中间表字段值，
     *                                若 $model 为关联模型对象且包含 pivot 对象，这里设置的字段值会覆盖 pivot 中已设置的字段值
     * @return Model|object
     * @throws Throwable
     */
    public function hold($model, array $modelAttributes = null, array $pivotAttributes = null)
    {
        return $this->holdRelationModels([$model], $modelAttributes, $pivotAttributes)->get();
    }

    /**
     * 解绑释放关联模型, 关联模型表 或 中间表 对应的字段将被修改为 getFreeValue()，返回解绑的关联模型
     * - 被 free 的数据将成为无主数据，若二者有强烈依赖关系，不建议这么做
     * - 若主模型在 freed 之前加载过关联模型，freed 会触发关联模型的 UPDATE 监听事件，否则不会
     *
     * > 因为触发监听事件需要先实例化关联模型，这就需要先从数据库查询关联模型数据，之后再设置其关联值。
     * 若使用场景本来就没设置触发事件，这就白白多了一次查询。
     * 若对触发事件有刚性要求，可以在 freed 之前先获取，再 freed
     *
     * ```
     * $user = User::find(1);
     * $user->phone()->freed();
     * ```
     *
     * @param ?array $modelAttributes 如果有中间表，设置中间表字段值
     * @return bool
     * @throws Throwable
     */
    public function freed(array $modelAttributes = null)
    {
        return (bool) $this->freedRelationModels(
            $this->parent->hasRelation($this->relation) ? $this->parent->getRelation($this->relation) : null,
            $modelAttributes
        );
    }

    /**
     * 移除关联模型，移除的关联模型数据会被删除，如果有中间表，中间表数据也会被删除
     * - 触发机制与 freed 相同
     *
     * ```
     * $user = User::find(1);
     * $user->phone()->remove();
     * ```
     * @return bool
     * @throws Exception
     * @see freed
     */
    public function remove()
    {
        return (bool) $this->removeRelationModels(
            $this->parent->hasRelation($this->relation) ? $this->parent->getRelation($this->relation) : null
        );
    }
}
