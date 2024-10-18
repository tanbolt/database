<?php
namespace Tanbolt\Database\Model\Relation;

use Throwable;
use Exception;
use Tanbolt\Database\Model;
use Tanbolt\Database\Model\Collection;

class HasMany extends HasRelation
{
    /**
     * 增加关联模型, 新增的关联模型会插入数据到数据库, 返回添加成功关联模型 Collection
     * - 返回的 Collection 不包含完整的数据，仅包含指定字段(关联字段, $model, $modelAttributes, $pivotAttributes 设定值)
     * - 不会直接挂载到主模型对象上，因为数据库可能还存在之前添加的，所以在主模型上获取关联模型，应重新查询
     *
     * ```
     * $post = Post::with('comments')->find(1);
     * $newComments = $post->comments()->addCollection([....]);
     * var_dump(count($newComments)); #新增数据
     * var_dump(count($post->comments)); #此时 comments 为脏数据, 不包含新增数据
     * $post->loadRelation('comments');  #重新查询
     * var_dump(count($post->comments)); #关联模型包含新增数据
     * ```
     *
     * @param Collection|Model|Model[]|array $collection 关联模型 (newRecord) 或 关联模型字段数组
     * @param ?array $pivotAttributes 如果有中间表，设置中间表字段值，
     *                                若 $collection 数组为关联模型对象 $model[] 且包含 pivot 对象，
     *                                这里设置的字段值会覆盖 pivot 中已设置的字段值
     * @return Collection
     * @throws Throwable
     */
    public function addCollection($collection, array $pivotAttributes = null)
    {
        return $this->addRelationModels($collection, $pivotAttributes);
    }

    /**
     * 在主模型上绑定指定关联模型，关联模型的约束字段将被修改，并修改数据库中对应字段值, 返回绑定的关联模型 Collection
     * - 返回的 Collection 并非包含完整的数据的对象，仅包含指定字段(关联字段, $model, $modelAttributes, $pivotAttributes 设定值)
     * - 返回的 Collection 为参数 Collection，并不意味着一定更新成功，比如指定的 Collection 中包含了不存在数据
     * - 不会直接挂载到主模型对象上，因为数据库可能还存在之前添加的，所以在主模型上获取关联模型，应重新查询
     * - 绑定的关联模型若之前有主模型，将会与原主模型解绑，但无法从已创建主模型对象中清除
     *
     * ```
     * $post = Post::with('comments')->find(1);
     * $post2 = Post::with('comments')->find(2);
     *
     * $newComments = $post2->comments()->hold($post->comments);
     * var_dump(count($newComments)); #绑定成功的数据
     *
     * var_dump(count($post2->comments)); #此时 comments 为脏数据，不包含新关联数据
     * $post2->loadRelation('comments');
     * var_dump(count($post2->comments)); #重新查询后, 关联模型才会包含新增数据
     *
     * var_dump(count($post->comments)); #此时 comments 为脏数据, 未移除更换主模型的数据
     * $post->loadRelation('comments');
     * var_dump(count($post->comments)); #重新查询后, 关联模型才会修正为正确数据
     * ```
     *
     * @param Collection|Model|Model[]|array $collection 关联模型 (!newRecord) 或 关联模型主键数组
     * @param ?array $modelAttributes 设置关联模型字段值，
     *                                若 $model 为关联模型对象, 这里设置的字段值会覆盖 $model 中已设置的字段值
     * @param ?array $pivotAttributes 如果有中间表，设置中间表字段值，
     *                                若 $model 为关联模型对象且包含 pivot 对象，这里设置的字段值会覆盖 pivot 中已设置的字段值
     * @return Collection
     * @throws Throwable
     */
    public function hold($collection, array $modelAttributes = null, array $pivotAttributes = null)
    {
        return $this->holdRelationModels($collection, $modelAttributes, $pivotAttributes);
    }

    /**
     * 与 hold() 方法作用相同，该函数针对有中间表的关联模型
     * - 会在中间表中创建数据，将【关联模型】和【主模型】关联
     * - 不会删除 关联模型 和 其他主模型的关联
     * - 即一个 关联模型 可能属于不同的主模型
     *
     * ```
     * #如文章有多个 TAG, 每个 TAG 又属于不同文章
     * $tag = TAG::findMany([1, 2]);
     *
     * #$tag 会和两篇文章都产生关联
     * Article::find(1)->tag()->holdShared($tag);
     * Article::find(2)->tag()->holdShared($tag);
     * ```
     *
     * @param Collection|Model|Model[]|array $collection
     * @param null $modelAttributes
     * @param null $pivotAttributes
     * @return Collection
     * @throws Throwable
     * @see hold
     */
    public function holdShared($collection, $modelAttributes = null, $pivotAttributes = null)
    {
        return $this->holdRelationModels($collection, $modelAttributes, $pivotAttributes, true);
    }

    /**
     * 解绑关联模型，返回解绑的关联模型 Collection
     * - 关联模型对应的字段将被修改为 getFreeValue()
     * - 若是通过中间表进行关联的，会直接删除中间表中对应的数据
     * - 若未指定要解绑的 $collection，即解绑全部关联模型，仅返回影响数据库的条数，而不是返回 Collection (节省内存)。
     *   且不会触发关联模型的 UPDATING 监听事件, 这种批量操作, 本来也不应该对所有数据循环使用回调函数
     *
     * @param Collection|Model|Model[]|array|null $collection 关联模型 (!newRecord) 或 关联模型主键数组 或 null(解除所有)
     * @param ?array $modelAttributes 设置关联模型字段值，若 $model 为关联模型对象, 这里设置的字段值会覆盖 $model 中已设置的字段值
     * @return Collection|int
     * @throws Throwable
     */
    public function freed($collection = null, array $modelAttributes = null)
    {
        return $this->freedRelationModels($collection, $modelAttributes);
    }

    /**
     * 移除关联模型，返回删除的关联模型 Collection
     * - 关联模型数据会被移除
     * - 若是通过中间表进行关联的，中间表数据也会被移除
     * - 若未指定要移除的 $collection，即移除全部关联模型，仅返回影响数据库的条数，而不是返回 Collection (节省内存)。
     *   且不会触发关联模型的 DROP 监听事件, 这种批量操作, 本来也不应该对所有数据循环使用回调函数
     *
     * @param Collection|Model|Model[]|array|null $collection 关联模型 (!newRecord) 或 关联模型主键数组 或 null(删除所有)
     * @return Collection|int
     * @throws Exception
     */
    public function remove($collection = null)
    {
        return $this->removeRelationModels($collection);
    }
}
