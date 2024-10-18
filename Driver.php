<?php
namespace Tanbolt\Database;

use Tanbolt\Database\Exception\DatabaseException;

/**
 * @property-read string $type 数据库类型
 * @property-read Driver\Connector $connector 数据库连接驱动
 * @property-read Driver\Structure $structure 数据库结构构造器
 * @property-read Driver\Grammar $grammar 数据库语句构造器
 */
class Driver
{
    /**
     * @var string
     */
    private $driverType;

    /**
     * @var array
     */
    private $drivers = [];

    /**
     * 创建 Driver 对象
     * @param string $type 数据库类型
     */
    public function __construct(string $type)
    {
        $this->driverType = strtolower($type);
    }

    /**
     * @param string $abstract
     * @return string
     */
    protected function getClass(string $abstract)
    {
        $constructor = __NAMESPACE__.'\\Driver\\'.ucfirst($this->driverType).'\\'.$abstract;
        if (!class_exists($constructor)) {
            throw new DatabaseException(sprintf('%s driver "%s" not found', $this->driverType, $constructor));
        }
        $abstract = __NAMESPACE__.'\\Driver\\'.$abstract;
        $constructor = new $constructor;
        if (!$constructor instanceof $abstract) {
            throw new DatabaseException(sprintf(
                '%s driver "%s" should be instanceof "%s"',
                $this->driverType,
                get_class($constructor),
                $abstract
            ));
        }
        return $constructor;
    }

    /**
     * 获取数据库驱动的不同子驱动 (Connector/Structure/Grammar)
     * @param mixed $name
     * @return string
     */
    public function __get($name)
    {
        $name = strtolower($name);
        if ('type' === $name) {
            return $this->driverType;
        }
        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->getClass(ucfirst($name));
        }
        return $this->drivers[$name];
    }
}
