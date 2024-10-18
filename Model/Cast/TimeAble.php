<?php
namespace Tanbolt\Database\Model\Cast;

use DateTime;
use Tanbolt\Database\Model\CastAble;

class TimeAble extends DateTime implements CastAble
{
    const TIMESTAMP = 'timestamp';

    /**
     * @var string
     */
    private $format = 'Y-m-d H:i:s';

    /**
     * 设置转为时间字符串时的格式, 若转为时间戳, 设置为 self::TIMESTAMP
     * @param mixed $config
     * @return $this
     */
    public function __config(...$config)
    {
        $this->format = $config[0] ?? 'Y-m-d H:i:s';
        return $this;
    }

    /**
     * @param string|int $value
     * @return $this
     */
    public function __setter($value)
    {
        if (!(is_int($value) || (self::TIMESTAMP === $this->format && ctype_digit($value)))) {
            if ($time = strtotime($value)) {
                $value = '@'.$time;
            } else {
                $value = '@0';
            }
        } else {
            $value = '@'.$value;
        }
        $this->modify($value);
        return $this;
    }

    /**
     * @return string|int
     */
    public function __toScalar()
    {
        if (self::TIMESTAMP === $this->format) {
            return $this->getTimestamp();
        }
        return $this->format($this->format);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string) $this->__toScalar();
    }
}
