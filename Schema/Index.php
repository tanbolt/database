<?php
namespace Tanbolt\Database\Schema;

/**
 * Class Index: 数据表索引对象
 * @package Tanbolt\Database\Schema
 *
 * @property ?string $name 索引名
 * @property ?string $type 索引类型
 * @property bool $primary 是否主键
 * @property bool $unique 是否唯一
 * @property ?int $length 长度
 * @property ?array $column 索引字段
 *
 * 供驱动接口使用的属性
 * 隐式索引：创建数据表时，由数据库服务器自动创建的索引，即不是手动设置的索引
 * @property string $command 操作类型 add|alter|drop
 * @property ?string $rename 新名称
 * @property ?bool $implicit 是否隐式索引
 *
 * 可用方法, 也可以直接设置属性值, 但用方法设置可以用链式代码
 * @method $this type(?string $type)
 * @method $this primary(bool $primary = true)
 * @method $this unique(bool $unique = true)
 * @method $this length(?int $length)
 * @method $this column(array|string|null $columns)
 * @method $this rename(?string $name)
 */
class Index extends Collection
{
    /**
     * 索引属性
     * @var array
     */
    protected $accepts = [
        'name'=> null,
        'type' => null,
        'unique' => false,
        'primary' => false,
        'implicit' => false,
        'column' => null,
        'rename' => null,
    ];

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    protected function setAttribute(string $key, $value)
    {
        $key = strtolower($key);
        if ('type' === $key) {
            $valueUpper = is_string($value) ? strtoupper(trim($value)) : null;
            if ('PRIMARY' === $valueUpper) {
                $this->values['unique'] = false;
                $this->values['primary'] = true;
            } elseif ('UNIQUE' === $valueUpper) {
                $this->values['unique'] = true;
                $this->values['primary'] = false;
            } else {
                $this->values['unique'] = false;
                $this->values['primary'] = false;
            }
        } elseif ('primary' === $key) {
            if ($value) {
                $this->values['unique'] = false;
                $this->values['type'] = 'PRIMARY';
            } else {
                if ($this->unique) {
                    $this->values['type'] = 'UNIQUE';
                } elseif (null === $this->type || strtoupper($this->type) === 'PRIMARY') {
                    $this->values['type'] = '';
                }
            }
        } elseif ('unique' === $key) {
            if ($value) {
                $this->values['primary'] = false;
                $this->values['type'] = 'UNIQUE';
            } else {
                if ($this->primary) {
                    $this->values['type'] = 'PRIMARY';
                } elseif (null === $this->type || strtoupper($this->type) === 'UNIQUE') {
                    $this->values['type'] = '';
                }
            }
        } elseif ('column' === $key && $value && !is_array($value)) {
            $value = [$value];
        }
        return parent::setAttribute($key, $value);
    }
}
