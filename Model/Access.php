<?php
namespace Tanbolt\Database\Model;

use Countable;
use Exception;
use ArrayAccess;
use ArrayIterator;
use ReflectionClass;
use JsonSerializable;
use IteratorAggregate;
use InvalidArgumentException;
use Tanbolt\Database\Model;
use Tanbolt\Database\Model\Cast\TimeAble;
use Tanbolt\Database\Model\Cast\ArrayAble;
use Tanbolt\Database\Exception\BadPropertyException;

/**
 * Class Access
 * @package Tanbolt\Database\Model
 */
class Access implements IteratorAggregate, ArrayAccess, Countable, JsonSerializable
{
    const INT = 'int';
    const FLOAT = 'float';
    const MONEY = 'money';
    const BOOL = 'bool';
    const STRING = 'string';
    const JSON = 'json';
    const TIME = 'time';
    const TIMESTAMP = 'timestamp';
    const SERIALIZE = 'serialize';

    /**
     * 定义字段类型，如
     * ```
     * [
     *  'create' => static::TIME,
     *  'update' => static::TIMESTAMP,
     *  'foo' => 'CastAbleNamespace', //自定义类型
     *  'bar' => ['CastAbleNamespace', arg1, arg2], //自定义类型 + 设置 Class 配置
     * ]
     * ```
     * @var array
     */
    protected $casts = [];

    /**
     * toArray 函数返回值不显示字段，如 `['foo', 'bar']`
     * @var array
     */
    protected $hidden = [];

    /**
     * toArray 函数返回值显示字段，如 `['foo', 'bar']`
     * @var array
     */
    protected $visible = [];

    /**
     * 属性值, 根据字段设置, 可能是标量, 也可能是对象
     * @var array
     */
    private $attributes = [];

    /**
     * 原始值, 由 attributes 转换过来对应的标量值
     * @var array
     */
    private $originals = [];

    /**
     * toArray cache
     * @var array
     */
    private $arrayCache = null;

    /**
     * 关联值 值为关联Model
     * @var Model[]|Object[]
     */
    private $relations = [];

    /**
     * 对象自定义方法 缓存
     * @var array
     */
    private static $modifyMethodCache = [];

    /**
     * 自定义 CastAble Class 是否合法的判断结果 缓存
     * @var array
     */
    private static $castAbleCache = [];

    /**
     * 创建 time 类型的值
     * @param mixed $time
     * @return TimeAble
     * @throws Exception
     */
    public static function castTime($time = 'now')
    {
        return new TimeAble($time);
    }

    /**
     * 创建 array 类型的值
     * @param array $items
     * @return ArrayAble
     */
    public static function castArray(array $items = [])
    {
        return new ArrayAble($items);
    }

    /**
     * Access constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->setAttribute($attributes);
    }

    /**
     * 设置字段的转换类型，即添加到 $casts 变量中
     * @param array|string $key
     * @param mixed $type
     * @return $this
     */
    public function setCasts($key, $type = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $p) {
                $this->casts[$k] = $p;
            }
        } else {
            $this->casts[$key] = $type;
        }
        return $this;
    }

    /**
     * 获取字段的转换类型
     * @param ?string $key null 则返回全部
     * @return array|string|null
     */
    public function getCasts(string $key = null)
    {
        if ($key) {
            return $this->casts[$key] ?? null;
        }
        return $this->casts;
    }

    /**
     * 设置 toArray 函数需要隐藏的字段，如
     * - `setHidden('foo', ['bar', 'biz'], 'que')`
     * @param mixed $columns
     * @return $this
     */
    public function setHidden(...$columns)
    {
        $this->arrayCache = null;
        $this->hidden = self::flatten($columns);
        return $this;
    }

    /**
     * 增加 toArray 函数需要隐藏的字段，如
     * - `addHidden('foo', ['bar', 'biz'], 'que')`
     * @param mixed $columns
     * @return $this
     */
    public function addHidden(...$columns)
    {
        $this->arrayCache = null;
        $this->hidden = array_unique(array_merge($this->hidden, self::flatten($columns)));
        return $this;
    }

    /**
     * 获取 toArray 函数会隐藏的字段
     * @return array
     */
    public function getHidden()
    {
        return $this->hidden;
    }

    /**
     * 设置 toArray 函数仅显示的字段，如
     * - `setVisible('foo', ['bar', 'biz'], 'que')`
     * @param mixed $columns
     * @return $this
     */
    public function setVisible(...$columns)
    {
        $this->arrayCache = null;
        $this->visible = self::flatten($columns);
        return $this;
    }

    /**
     * 增加 toArray 函数仅显示的字段，如
     * - `addVisible('foo', ['bar', 'biz'], 'que')`
     * @param mixed $columns
     * @return $this
     */
    public function addVisible(...$columns)
    {
        $this->arrayCache = null;
        $this->visible = array_unique(array_merge($this->visible, self::flatten($columns)));
        return $this;
    }

    /**
     * 获取 toArray 函数仅显示的字段
     * @return array
     */
    public function getVisible()
    {
        return $this->visible;
    }

    /**
     * 查询 获取修改器 的方法名
     * @param ?string $method
     * @return array|string
     */
    public function modifyGetMethod(string $method = null)
    {
        return $this->modifyMethod(false, $method);
    }

    /**
     * 查询 设置修改器 的方法名
     * @param ?string $method
     * @return array|string
     */
    public function modifySetMethod(string $method = null)
    {
        return $this->modifyMethod(true, $method);
    }

    /**
     * 一次性获取当前类所有的修改器 并缓存
     * @param bool $set
     * @param ?string $find
     * @return array|string
     */
    private function modifyMethod(bool $set = false, string $find = null)
    {
        $modifyMethod = [];
        $class = get_class($this);
        if (isset(self::$modifyMethodCache[$class])) {
            $modifyMethod = self::$modifyMethodCache[$class];
        } else {
            foreach (get_class_methods($this) as $method) {
                if (strlen($method) < 13 || substr($method, -9) !== 'Attribute') {
                    continue;
                }
                $do = substr($method, 0 ,3);
                if ($do !== 'get' && $do !== 'set') {
                    continue;
                }
                $property = implode('_', array_map(function($w) {
                    return strtolower($w);
                }, preg_split('/(?=[A-Z])/', substr($method, 3, -9), -1, PREG_SPLIT_NO_EMPTY)));
                if (!isset($modifyMethod[$do])) {
                    $modifyMethod[$do] = [];
                }
                $modifyMethod[$do][$property] = $method;
            }
            self::$modifyMethodCache[$class] = $modifyMethod;
        }
        $key = $set ? 'set' : 'get';
        $modifyMethod = $modifyMethod[$key] ?? [];
        if ($find) {
            $find = strtolower($find);
            return $modifyMethod[$find] ?? null;
        }
        return $modifyMethod;
    }

    /**
     * 是否包含属性值
     * @param string $key
     * @return bool
     */
    public function hasAttribute(string $key)
    {
        return $this->hasSomething('attributes', $key);
    }

    /**
     * 设置属性值
     * ```
     * 会根据设置, 将字段值缓存为对应的类型(变量或对象) =>
     * 如果还自定义了 modifySetMethod 方法, 会再经过该方法处理后再缓存 =>
     * 最终的缓存值可能为标量或对象, 但总可以转换为标量, 且即为数据库存储值
     * ```
     * @param array|string $key 使用数组可批量设置
     * @param mixed $val
     * @return $this
     */
    public function setAttribute($key, $val = null)
    {
        $this->arrayCache = null;
        return $this->setSomething('attributes', $key, $val);
    }

    /**
     * 获取属性值, 返回 attributes 中真正包含的值, 不会判断是否有虚拟字段, 也不会使用 modifyGetMethod 处理
     * @param ?string $key 参数为 null 则返回全部
     * @return mixed
     */
    public function getAttribute(string $key = null)
    {
        return $this->getSomething('attributes', $key);
    }

    /**
     * 清空或移除属性值
     * @param ?string $key 参数为 null 则移除全部
     * @return $this
     */
    public function removeAttribute(string $key = null)
    {
        $this->arrayCache = null;
        return $this->removeSomething('attributes', $key);
    }

    /**
     * 获取当前所有 attributes 所有变量的标量值, 可用于数据库插入
     * @return array
     */
    public function attributes()
    {
        $attributes = [];
        foreach ($this->attributes as $key => $value) {
            $attributes[$key] = $this->getPropertyScalar($value, true);
        }
        return $attributes;
    }

    /**
     * 是否包含原始值
     * @param string $key
     * @return bool
     */
    public function hasOriginal(string $key)
    {
        return $this->hasSomething('originals', $key);
    }

    /**
     * 设置原始值 Original 值应该总是为标量
     * @param string|array $key 使用数组可批量设置
     * @param mixed $val
     * @return $this
     */
    public function setOriginal($key, $val = null)
    {
        return $this->setSomething('originals', $key, $val);
    }

    /**
     * 获取原始值, 总是标量
     * @param ?string $key 参数为 null 则返回全部
     * @return mixed
     */
    public function getOriginal(string $key = null)
    {
        return $this->getSomething('originals', $key);
    }

    /**
     * 清空或移除原始值
     * @param ?string $key 参数为 null 则移除全部
     * @return $this
     */
    public function removeOriginal(string $key = null)
    {
        return $this->removeSomething('originals', $key);
    }

    /**
     * 同步属性值 到 原始值, 属性值若为对象, 将被转换为标量后同步
     * @param mixed $columns 设置要同步的字段, 若为空则同步所有字段
     * @return $this
     */
    public function syncOriginal(...$columns)
    {
        if (count($columns)) {
            foreach (self::flatten($columns) as $key) {
                $this->setOriginal($key, $this->getPropertyScalar($this->getAttribute($key), true));
            }
        } else {
            $this->originals = array_map(function($val) {
                return $this->getPropertyScalar($val, true);
            }, $this->attributes);
        }
        return $this;
    }

    /**
     * 获取所有 修改过的 属性 (attributes), 返回标量值数组, 可用于更新数据库
     * @return array
     */
    public function changed()
    {
        return $this->getChanged();
    }

    /**
     * 判断 属性值是否 修改过
     * - 若不指定参数, 则有任何字段发送变动都会返回 true
     * - 若指定指定, 所指定字段任意一个发送变动都会返回 true
     * @param mixed $columns
     * @return bool
     */
    public function isChanged(...$columns)
    {
        return $this->getChanged(self::flatten($columns));
    }

    /**
     * @param array|null $keys
     * @return array|bool
     */
    protected function getChanged(array $keys = null)
    {
        $changed = [];
        $onlyCheck = null !== $keys;
        $checkKeys = $onlyCheck && count($keys) ? $keys : null;
        foreach ($this->attributes as $key => $value) {
            if ($checkKeys && !in_array($key, $checkKeys)) {
                continue;
            }
            // 如果 有对应的 Original 值且相等, 也跳过 (对于 1 / "1" 值也认为相等)
            $value = $this->getPropertyScalar($value, true);
            if ($this->hasOriginal($key)) {
                $original = $this->getOriginal($key);
                if ((is_numeric($value) && is_numeric($original) && strcmp((string) $value, (string) $original) === 0)
                    || $value === $original
                ) {
                    continue;
                }
            }
            if ($onlyCheck) {
                return true;
            }
            $changed[$key] = $value;
        }
        return $onlyCheck ? false : $changed;
    }

    /**
     * 是否含有关联值
     * @param string $key
     * @return bool
     */
    public function hasRelation(string $key)
    {
        return $this->hasSomething('relations', $key);
    }

    /**
     * 设置关联值
     * @param string|array $key 使用数组可批量设置
     * @param mixed $val
     * @return $this
     */
    public function setRelation($key, $val = null)
    {
        $this->arrayCache = null;
        return $this->setSomething('relations', $key, $val);
    }

    /**
     * 获取关联值
     * @param ?string $key 参数为 null 则返回全部
     * @return array|null
     */
    public function getRelation(string $key = null)
    {
        return $this->getSomething('relations', $key);
    }

    /**
     * 清空或移除关联值
     * @param ?string $key 参数为 null 则移除全部
     * @return $this
     */
    public function removeRelation(string $key = null)
    {
        $this->arrayCache = null;
        return $this->removeSomething('relations', $key);
    }

    /**
     * (array) $property 是否含有 $key
     * @param string $property
     * @param string $key
     * @return bool
     */
    private function hasSomething(string $property, string $key)
    {
        return array_key_exists($key, $this->{$property});
    }

    /**
     * 设置 (array) $property 中 $key 的值
     * @param string $property
     * @param array|string $key
     * @param mixed $val
     * @param bool $deep
     * @return $this
     */
    private function setSomething(string $property, $key, $val = null, bool $deep = false)
    {
        if (!$deep && is_array($key)) {
            foreach ($key as $k => $v) {
                $this->setSomething($property, $k, $v, true);
            }
        } else {
            if ('attributes' === $property) {
                $val = $this->convertAttributeValueForSet($key, $val);
            } elseif ('originals' === $property && !is_scalar($val)) {
                throw new BadPropertyException('Original index: ['.$key.'] value must be scalar ');
            }
            $this->{$property}[$key] = $val;
        }
        return $this;
    }

    /**
     * @param string $key
     * @param mixed $val
     * @return mixed
     */
    private function convertAttributeValueForSet(string $key, $val)
    {
        $val = $this->covertAttributeToCastForSet($key, $val);
        if (($method = $this->modifySetMethod($key))) {
            $this->{$method}($val);
        }
        return $val;
    }

    /**
     * 根据 casts 设置 获取 attribute 对应值
     * @param string $key
     * @param mixed $val
     * @return mixed
     */
    private function covertAttributeToCastForSet(string $key, $val)
    {
        if (null === $val || !($type = $this->getCasts($key))) {
            return $val;
        }
        if (self::INT === $type) {
            return (int) $val;
        }
        if (self::MONEY === $type) {
            return (float) number_format($val, 2, '.', '');
        }
        if (self::FLOAT === $type) {
            return (float) $val;
        }
        if (self::BOOL === $type) {
            return (int) (bool) $val;
        }
        if (self::STRING === $type) {
            return (string) $val;
        }
        // 若 cast 设置类型不是 castAble , 不再处理,  直接返回
        if (!$class = self::findCastAbleClass($type, $config)) {
            return $val;
        }
        // 设置值本身就是对应的 castAble 类型
        if ($val instanceof $class) {
            $value = $val;
            $val = $value->__toScalar();
        } else {
            try {
                $value = $this->getAttribute($key);
            } catch (BadPropertyException $e) {
                $value = new $class;
            }
        }
        if ($config) {
            call_user_func_array([$value, '__config'], $config);
        }
        $value->__setter($val);
        return $value;
    }

    /**
     * 校验 casts 设置是否为 castAble 类型
     * @param array|string $type
     * @param null $config
     * @return string|null
     */
    private static function findCastAbleClass($type, &$config = null)
    {
        if (is_string($type)) {
            // 数组
            if (self::JSON === $type || self::SERIALIZE === $type) {
                $config = [self::JSON === $type];
                return __NAMESPACE__.'\\Cast\\ArrayAble';
            }
            // 时间
            if (self::TIMESTAMP === $type || self::TIME === $type ||
                (strlen($type) > 6 && substr($type, 0, 5) === 'time(' && substr($type, -1) === ')')
            ) {
                if (self::TIMESTAMP === $type) {
                    $config = [$type];
                } elseif (self::TIME === $type) {
                    $config = ['Y-m-d H:i:s'];
                } else {
                    $config = [substr($type, 5, -1)];
                }
                return __NAMESPACE__.'\\Cast\\TimeAble';
            }
            // 自定义
            $className = $type;
        } elseif (is_array($type) && count($type)) {
            // 带参数自定义
            $className = array_shift($type);
            $config = $type;
        } else {
            return null;
        }
        $className = ltrim($className, '\\');
        $castAble = self::$castAbleCache['castAble'] ?? [];
        if (array_key_exists($className, $castAble)) {
            return $castAble[$className] ? $className : null;
        }
        if (!class_exists($className)) {
            $castAble[$className] = false;
        } else {
            $castAble[$className] = (new ReflectionClass($className))->implementsInterface(__NAMESPACE__ . '\\CastAble');
        }
        self::$castAbleCache['castAble'] = $castAble;
        return $castAble[$className] ? $className : null;
    }

    /**
     * 获取 (array) $property 中 $key 的值
     * @param string $property
     * @param ?string $key
     * @return mixed
     */
    private function getSomething(string $property, string $key = null)
    {
        if (null === $key) {
            return $this->{$property};
        }
        if ($this->hasSomething($property, $key)) {
            return $this->{$property}[$key];
        }
        throw new BadPropertyException('Undefined index: ['.$key.'] in '.$property);
    }

    /**
     * 清空 (array) $property 或 移除 (array) $property 的 $key
     * @param string $property
     * @param ?string $key
     * @return $this
     */
    private function removeSomething(string $property, string $key = null)
    {
        if (null === $key) {
            $this->{$property} = [];
        } elseif ($this->hasSomething($property, $key)) {
            unset($this->{$property}[$key]);
        }
        return $this;
    }

    /**
     * 获取指定属性的值 (包括 attributes 和 relations)
     * - 从 attribute 中获取 (若存在 modifyGetMethod, 返回该方法处理后返回值)
     * - 从 modifyGetMethod 设置的虚拟字段获取
     * - 从 relations 中获取, 若 relations 当前为加载, 则自动加载
     *
     * 备注：这里的 $scalar 与 attributes Original changed 获取标量有所不同
     * 前者是真正的标量,即总为数字或字符串, 此处 还可能为数组
     *
     * @param string $key
     * @param bool $scalar
     * @return mixed
     */
    protected function getProperty(string $key, bool $scalar = false)
    {
        $temp = [];
        $method = $this->modifyGetMethod($key);
        if ($this->hasAttribute($key)) {
            $temp['v'] = $method ? $this->{$method}($this->getAttribute($key)) : $this->getAttribute($key);
        } elseif ($method) {
            $temp['v'] = $this->{$method}(null);
        } else {
            $relation = $this->hasRelation($key) ? $this->getRelation($key) : $this->getPropertyByRelation($key);
            if ($relation !== false) {
                if ($scalar) {
                    if ($relation instanceof Access || $relation instanceof Collection) {
                        // 既然获取 relation 的标量值了, 那么 relation 子级别的 relation 也应返回
                        $relation = $relation->toArray(true);
                    } elseif ($relation instanceof JsonSerializable) {
                        $relation = $relation->jsonSerialize();
                    }
                }
                $temp['v'] = $relation;
            }
        }
        if (array_key_exists('v', $temp)) {
            return $scalar ? $this->getPropertyScalar($temp['v']) : $temp['v'];
        }
        throw new InvalidArgumentException('Undefined property: '.__CLASS__.'::$'.$key);
    }

    /**
     * 获取指定属性的标量值
     * @param mixed $value
     * @param bool $string
     * @return array|bool|float|int|string
     */
    protected function getPropertyScalar($value, bool $string = false)
    {
        if ($value instanceof CastAble) {
            return $string ? $value->__toString() : $value->__toScalar();
        }
        $array = false;
        if ($value instanceof self) {
            $array = true;
            $value = $value->toArray();
        } elseif (is_array($value)) {
            $array = true;
        } elseif (!is_scalar($value)) {
            $value = null;
        }
        return $string && $array ? json_encode($value) : $value;
    }

    /**
     * get property by relation method
     * @param string $relation
     * @return mixed
     */
    protected function getPropertyByRelation(string $relation)
    {
        if (strpos($relation, '_')) {
            $method = implode('', array_map(function($w) {
                return ucfirst($w);
            }, explode('_', $relation)));
        } else {
            $method = $relation;
        }
        if (!method_exists($this, $method)) {
            return false;
        }
        $model = $this->{$method}();
        if ($model instanceof Relation) {
            $property = $model->findResults();
            $property = $property ?: null;
            $this->setRelation($relation, $property);
            return $property;
        }
        return false;
    }

    /**
     * 获取所有属性 包括 attributes 和 relations
     * @return array
     */
    public function allColumn()
    {
        return array_unique(array_merge(array_keys($this->attributes), array_keys($this->relations)));
    }

    /**
     * 获取所有在 toArray() 中可用的属性 包括 attributes 和 relations
     * @return array
     */
    public function ableColumn()
    {
        return $this->ableItems($this->allColumn());
    }

    /**
     * 获取所有在 toArray() 中可用的 attributes 属性
     * @return array
     */
    public function ableAttribute()
    {
        return $this->ableItems(array_keys($this->attributes));
    }

    /**
     * 获取所有在 toArray() 中可用的 relations 属性
     * @return array
     */
    public function ableRelation()
    {
        return $this->ableItems(array_keys($this->relations));
    }

    /**
     * get able keys
     * @param array $keys
     * @return array
     */
    protected function ableItems(array $keys)
    {
        if (count($this->visible) > 0) {
            $keys = array_intersect($keys, $this->visible);
        }
        if (count($this->hidden) > 0) {
            $keys = array_diff($keys, $this->hidden);
        }
        return array_values($keys);
    }

    /**
     * 根据 $hidden $visible 的设定，将可用属性转为数组
     * @param bool $includeRelation 是否将 $relation 一并返回
     * @return array
     */
    public function toArray(bool $includeRelation = false)
    {
        $keys = $includeRelation ? $this->ableColumn() : $this->ableAttribute();
        if (!count($keys)) {
            return [];
        }
        $properties = $this->getAbleArray();
        $keys = array_combine($keys, array_pad([], count($keys), 0));
        return array_intersect_key($properties, $keys);
    }

    /**
     * 获取一个包含指定属性值的数组 (可指定 attribute relation 属性), 不受 根据 $hidden $visible 的限制
     * @param mixed $columns
     * @return array
     */
    public function getArray(...$columns)
    {
        $items = [];
        foreach (self::flatten($columns) as $key) {
            $items[$key] = $this->getProperty($key, true);
        }
        return $items;
    }

    /**
     * 从所有 attribute relation 中排除指定的属性，将剩余的所有属性转为数组, 不受 根据 $hidden $visible 的限制
     * @param mixed $columns
     * @return array
     */
    public function getArrayExcept(...$columns)
    {
        return $this->getExcept($this->allColumn(), $columns);
    }

    /**
     * 从所有 attribute 中排除指定的属性，将剩余的所有属性转为数组, 不受 根据 $hidden $visible 的限制
     * @param mixed $columns
     * @return array
     */
    public function getAttributeExcept(...$columns)
    {
        return $this->getExcept(array_keys($this->attributes), $columns);
    }

    /**
     * 获取从 $able 排除 $keys 的属性数组
     * @param array $able
     * @param array $keys
     * @return array
     */
    private function getExcept(array $able, array $keys)
    {
        $keys = array_diff($able, $keys);
        if (!count($keys)) {
            return [];
        }
        $properties = $this->getAbleArray();
        $keys = array_combine($keys, array_pad([], count($keys), 0));
        return array_intersect_key($properties, $keys);
    }

    /**
     * 以数组形式获取当前所有可用字段值 (包括 attributes 和 relations)
     * @return array
     */
    private function getAbleArray()
    {
        if (!$this->arrayCache) {
            $this->arrayCache = [];
            foreach ($this->allColumn() as $key) {
                $this->arrayCache[$key] = $this->getProperty($key, true);
            }
        }
        return $this->arrayCache;
    }

    /**
     * flatten array
     * @param $array
     * @return array
     */
    protected static function flatten($array)
    {
        $return = [];
        array_walk_recursive($array, function ($x) use (&$return) {
            if ($x) {
                $return[] = $x;
            }
        });
        return array_unique($return);
    }

    /**
     * @inheritDoc
     */
    public function offsetExists($offset)
    {
        return isset($this->{$offset});
    }

    /**
     * @inheritDoc
     */
    public function offsetGet($offset)
    {
        return $this->getProperty($offset, true);
    }

    /**
     * @inheritDoc
     */
    public function offsetSet($offset, $value)
    {
        $this->{$offset} = $value;
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset($offset)
    {
        unset($this->{$offset});
    }

    /**
     * 返回 allColumn 字段数目
     * @return int
     */
    public function count()
    {
        return count($this->allColumn());
    }

    /**
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * 优先级：是否存在于attribute > 是否设置了虚拟字段函数 > 是否存在于relations
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        return $this->hasAttribute($name) || $this->modifyGetMethod($name) || $this->hasRelation($name);
    }

    /**
     * 仅可设置 attribute, 如需设置 relation, 需使用 setRelation
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        $this->setAttribute($name, $value);
    }

    /**
     * 返回 标量 或 CastAble 对象
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->getProperty($name);
    }

    /**
     * @param $name
     */
    public function __unset($name)
    {
        $this->removeAttribute($name)->removeRelation($name);
    }

    /**
     * @return string
     */
    public function __toString()
    {
       return json_encode($this->toArray());
    }
}
