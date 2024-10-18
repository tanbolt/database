<?php
namespace Tanbolt\Database\Schema;

use ArrayAccess;
use JsonSerializable;
use InvalidArgumentException;

abstract class Collection implements ArrayAccess, JsonSerializable
{
    /**
     * 只读键的键名
     * @var string
     */
    protected $readName = 'command';

    /**
     * 只读键的键值
     * @var string
     */
    private $readValue = null;

    /**
     * 支持的键名
     * @var array
     */
    protected $accepts = [];

    /**
     * 键值容器
     * @var array
     */
    protected $values = [];

    /**
     * Column constructor.
     * @param array $attributes 初始化数组
     * @param string|null $readValue 只读键的值
     */
    public function __construct(array $attributes = [], string $readValue = null)
    {
        if (null !== $readValue) {
            $this->readValue = $readValue;
        }
        $this->clear()->reset($attributes);
    }

    /**
     * @return $this
     */
    public function clear()
    {
        foreach ($this->accepts as $key => $val) {
            $this->values[$key] = $val;
        }
        return $this;
    }

    /**
     * @param array $attributes
     * @return $this
     */
    public function reset(array $attributes = [])
    {
        foreach ($attributes as $key => $val) {
            $this->setAttribute($key, $val);
        }
        return $this;
    }

    /**
     * 设置指定 $key 的 attr 值
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    protected function setAttribute(string $key, $value)
    {
        if (array_key_exists($key, $this->accepts)) {
            $this->values[$key] = $value;
        }
        return $this;
    }

    /**
     * 获取指定 $key 的 attr 值
     * @param string $key
     * @return mixed
     */
    protected function getAttribute(string $key)
    {
        if ($key === $this->readName) {
            return $this->readValue;
        }
        if (array_key_exists($key, $this->accepts)) {
            return $this->values[$key];
        }
        throw new InvalidArgumentException('Undefined property: '.__CLASS__.'::$'.$key);
    }

    /**
     * 转为数组
     * @return array
     */
    public function toArray()
    {
        $attributes = [];
        foreach ($this->accepts as $key => $val) {
            $attributes[$key] = $this->getAttribute($key);
        }
        return $attributes;
    }

    /**
     * @param $key
     * @return bool
     */
    public function __isset($key)
    {
        return array_key_exists($key, $this->accepts);
    }

    /**
     * @param $key
     * @return null
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * @param $key
     * @param $value
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * @param $key
     */
    public function __unset($key)
    {
        if (array_key_exists($key, $this->accepts)) {
            $this->values[$key] = $this->accepts[$key];
        }
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @inheritDoc
     */
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->accepts);
    }

    /**
     * @inheritDoc
     */
    public function offsetGet($offset)
    {
        return $this->getAttribute($offset);
    }

    /**
     * @inheritDoc
     */
    public function offsetSet($offset, $value)
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset($offset)
    {
        if (array_key_exists($offset, $this->accepts)) {
            $this->values[$offset] = $this->accepts[$offset];
        }
    }

    /**
     * @param $method
     * @param $parameters
     * @return $this
     */
    public function __call($method, $parameters)
    {
        return $this->setAttribute($method, count($parameters) ? $parameters[0] : true);
    }
}
