<?php
namespace Tanbolt\Database;

use PDO;
use Tanbolt\Database\Exception\DatabaseException;

/**
 * Class Database
 * > 该类主要用来进行数据库配置, 比如设置数据库节点, 监听函数。
 * 同时通过魔术方法提供了 Database\Connection 的属性和方法，
 * 对于默认节点而言, 等同于 extend 了 Connection 类
 * @package Tanbolt\Database
 *
 * @mixin Connection
 */
class Database
{
    /**
     * @var array
     */
    private static $nodes = [];

    /**
     * @var string
     */
    private static $default = null;

    /**
     * @var string
     */
    private static $migrations = null;

    /**
     * @var callable
     */
    private static $slaveDispatch = null;

    /**
     * @var callable|null
     */
    private static $queryListener = null;

    /**
     * @var callable|null
     */
    private static $modelListener = null;

    /**
     * @param mixed $node
     * @return array|bool
     */
    private static function parseNode($node)
    {
        if (!is_array($node)) {
            return false;
        }
        if (count($node) === 1 && is_string(key($node)) && is_array(current($node))) {
            return $node;
        }
        $name = $node;
        ksort($name);
        $name = md5(serialize($name));
        return [$name => $node];
    }

    /**
     * 配置一个节点，$node 格式为
     * ```
     * [
     *   nodeName => [
     *      'driver' => 'mysql,sqlite', 数据库类型
     *      'dis_foreign' => false,   是否禁用外键约束
     *      'lost_try' => 10,  连接断开尝试重连次数
     *      ...config, 其他 driver 所需参数
     *
     *      // 若数据库从主同步, 可设置只读节点配置, 会自动根据 SQL 语句从主分离
     *      'slave' => [
     *         ...config  // driver 所需参数, 与主节点一致的可省略, 会自动继承
     *      ],
     *
     *      // 若有多个只读节点, 可使用数组配置, 额外支持 weight 参数设置权重, 该值默认为 1
     *      'slave' => [
     *           ['weight' => 1, ...config],
     *           ['weight' => 1, ...config],
     *      ]
     *   ]
     * ]
     * ```
     * @param Connection|array $node
     * @param bool $default 是否作为默认节点
     */
    public static function putNode($node, bool $default = false)
    {
        $name = null;
        if ($arr = static::parseNode($node)) {
            list($name, $node) = [key($arr), current($arr)];
        } elseif ($node instanceof Connection) {
            $name = $node->name;
        }
        if (!$name) {
            throw new DatabaseException('Connection node name error');
        }
        $existNode = static::$nodes[$name] ?? null;
        if ($existNode instanceof Connection) {
            if (is_array($node)) {
                $existNode->setConfig($node);
            } else {
                $existNode->disconnect();
                static::$nodes[$name] = $node;
            }
        } else {
            static::$nodes[$name] = $node;
        }
        if ($default) {
            static::setDefaultNode($name);
        }
    }

    /**
     * 设置默认连接节点
     * @param Connection|array|string $name
     * @return string|null
     */
    public static function setDefaultNode($name)
    {
        if ($name instanceof Connection) {
            $name = $name->name;
        } elseif ($arr = static::parseNode($name)) {
            $name = key($arr);
        } else {
            $name = (string) $name;
        }
        return static::$default = $name;
    }

    /**
     * 获取默认连接节点名称
     * @return string
     */
    public static function getDefaultNode()
    {
        return static::$default;
    }

    /**
     * 获取所有节点名称
     * @return array
     */
    public static function nodeKeys()
    {
        return array_keys(static::$nodes);
    }

    /**
     * 获取指定的 SQL 连接
     * - null: 获取默认连接
     * - string: 获取通过 setNode 预设的连接
     * - array: 根据 config 配置直接返回对应连接
     * @param Connection|array|string|null $name
     * @return Connection
     */
    public static function getNode($name = null)
    {
        if ($name instanceof Connection) {
            return $name;
        }
        if (!$name) {
            $name = static::$default;
        } elseif (!is_string($name)) {
            if (!($arr = static::parseNode($name))) {
                $name = null;
            } else {
                list($name, $node) = [key($arr), current($arr)];
                if (!isset(static::$nodes[$name])) {
                    static::$nodes[$name] = $node;
                }
            }
        }
        if (!($node = $name && isset(static::$nodes[$name]) ? static::$nodes[$name] : null)) {
            throw new DatabaseException('Connection node not exist');
        }
        if ($node instanceof Connection) {
            return $node;
        }
        return static::$nodes[$name] = (new Connection($name, $node))
            ->setListener(static::$queryListener)
            ->setDispatch(static::$slaveDispatch);
    }

    /**
     * 断开全部 或 指定的 节点连接
     * @param Connection|array|string|null $node
     * @return bool|string
     */
    public static function disconnectNode($node = null)
    {
        if ($node) {
            $name = null;
            if (is_string($node)) {
                $name = $node;
            } elseif ($arr = static::parseNode($node)) {
                $name = key($arr);
            } elseif ($node instanceof Connection) {
                $name = $node->name;
            }
            $existNode = $name && isset(static::$nodes[$name]) ? static::$nodes[$name] : null;
            if (!$existNode) {
                return false;
            }
            if ($existNode instanceof Connection) {
                $existNode->disconnect();
            }
            return $name;
        }
        foreach (static::$nodes as $node) {
            if ($node instanceof Connection) {
                $node->disconnect();
            }
        }
        return true;
    }

    /**
     * 断开所有短连接节点
     */
    public static function disconnectShort()
    {
        foreach (static::$nodes as $node) {
            if ($node instanceof Connection && !$node->getAttribute(PDO::ATTR_PERSISTENT)) {
                $node->disconnect();
            }
        }
    }

    /**
     * 断开指定节点连接并移除
     * @param Connection|array|string $node
     * @return bool
     */
    public static function removeNode($node)
    {
        $name = $node ? static::disconnectNode($node) : false;
        if ($name) {
            unset(static::$nodes[$name]);
            return true;
        }
        return false;
    }

    /**
     * 断开所有节点连接并清除
     */
    public static function clearNode()
    {
        static::disconnectNode();
        static::$nodes = [];
    }

    /**
     * 设置 migration 表名称
     * @param string $migrations
     */
    public static function setMigrations(string $migrations)
    {
        static::$migrations = $migrations;
    }

    /**
     * 获取 migration 表名称
     * @return string
     */
    public static function getMigrations()
    {
        return static::$migrations;
    }

    /**
     * 设置 slave 节点命中算法, 在配置了多个 slave 节点时生效, 默认根据权重随机分配
     * @param ?callable $dispatch
     */
    public static function setSlaveDispatch(callable $dispatch = null)
    {
        static::$slaveDispatch = $dispatch;
    }

    /**
     * 获取 slave 节点命中算法
     * @return callable
     */
    public static function getSlaveDispatch()
    {
        return static::$slaveDispatch;
    }

    /**
     * 设置执行 query 语句时的监听函数
     * @param ?callable $listener
     */
    public static function setQueryListener(callable $listener = null)
    {
        static::$queryListener = $listener;
    }

    /**
     * 获取执行 query 语句时的监听函数
     * @return ?callable
     */
    public static function getQueryListener()
    {
        return static::$queryListener;
    }

    /**
     * 设置 model 操作时的监听函数
     * @param ?callable $listener
     */
    public static function setModelListener(callable $listener = null)
    {
        static::$modelListener = $listener;
    }

    /**
     * 获取 model 操作时的监听函数
     * @return ?callable
     */
    public static function getModelListener()
    {
        return static::$modelListener;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        return isset(static::getNode()->{$name});
    }

    /**
     * 默认 SQL 连接的魔术变量
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        return static::getNode()->{$name};
    }

    /**
     * 默认 SQL 连接的魔术方法
     * @param mixed $method
     * @param mixed $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return call_user_func_array([static::getNode(), $method], $parameters);
    }
}
