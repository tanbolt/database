<?php
namespace Tanbolt\Database\Schema;

/**
 * Class Foreign: 数据表外键对象
 * @package Tanbolt\Database\Schema
 *
 * @property string $name 外键名
 * @property array  $column 外键字段
 * @property string $table 约束表名(Scheme->foreigners()) 或 外键表名(Scheme->constraints())
 * @property array  $reference 约束表字段
 * @property ?string $onUpdate 更新逻辑
 * @property ?string $onDelete 删除逻辑
 *
 * // 供驱动接口使用的属性
 * @property string $command 操作类型 add|alter|drop
 * @property ?string $rename 新名称
 *
 * // 可用方法, 也可以直接设置属性值, 但用方法设置可以用链式代码
 * @method $this column(array|string $column)
 * @method $this table(string $type)
 * @method $this reference(array|string $length)
 * @method $this onUpdate(?string $update)
 * @method $this onDelete(?string $delete)
 * @method $this rename(?string $rename)
 */
class Foreign extends Collection
{
    /**
     * @var array
     */
    protected $accepts = [
        'name'=> null,
        'column' => null,
        'table' => null,
        'reference' => null,
        'onUpdate' => null,
        'onDelete' => null,
        'rename' => null,
    ];

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    protected function setAttribute(string $key, $value)
    {
        if (('column' === $key || 'reference' === $key) && $value && !is_array($value)) {
            $value = [$value];
        }
        return parent::setAttribute($key, $value);
    }
}
