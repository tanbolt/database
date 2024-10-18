<?php
namespace Tanbolt\Database\Model\Cast;

use ArrayObject;
use Tanbolt\Database\Model\CastAble;

class ArrayAble extends ArrayObject implements CastAble
{
    /**
     * @var bool
     */
    private $json = false;

    /**
     * 转 string 是否转为 json 格式 (否则为 serialize 格式)
     * @param mixed $config
     * @return $this
     */
    public function __config(...$config)
    {
        $this->json = isset($config[0]) && $config[0];
        return $this;
    }

    /**
     * @param array|string $value
     * @return $this
     */
    public function __setter($value)
    {
        if (!is_array($value)) {
            $value = (array) ($this->json ? @json_decode($value, true) : @unserialize($value));
        }
        $this->exchangeArray($value);
        return $this;
    }

    /**
     * @return array
     */
    public function __toScalar()
    {
        return $this->getArrayCopy();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return !$this->json ? serialize($this->getArrayCopy()) : json_encode($this->getArrayCopy());
    }
}
