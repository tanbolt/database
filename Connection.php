<?php
namespace Tanbolt\Database;

use PDO;
use Generator;
use Exception;
use Throwable;
use PDOException;
use PDOStatement;
use InvalidArgumentException;
use Tanbolt\Database\Schema\Schema;
use Tanbolt\Database\Query\Builder;
use Tanbolt\Database\Exception\QueryException;
use Tanbolt\Database\Exception\ConnectException;
use Tanbolt\Database\Exception\DatabaseException;

/**
 * Class Connection
 * @package Tanbolt\Database
 * @property-read string $name 节点名称
 * @property-read array  $config 连接配置
 * @property-read string $prefix 数据表通用前缀
 * @property-read string $lastPrefix 上次使用的数据表通用前缀
 * @property-read bool   $disableForeign 是否禁用外键约束
 * @property-read Driver $driver 连接驱动
 * @property-read string $master 主节点连接地址
 * @property-read string $slave 从节点连接地址
 * @property-read PDO    $pdo 主节点连接的 PDO 对象
 * @property-read PDO    $slavePdo 从节点连接的 PDO 对象
 */
class Connection
{
    /**
     * 连接名称
     * @var string
     */
    protected $conn_name;

    /**
     * 连接原始配置参数
     * 包含 driverName, master, slave, prefix
     * @var array
     */
    protected $conn_config;

    /**
     * 数据表前缀
     * @var string
     */
    protected $conn_prefix = null;

    /**
     * 上次使用的数据表前缀
     * @var string
     */
    protected $conn_lastPrefix = null;

    /**
     * 是否禁用外键约束
     * @var bool
     */
    protected $conn_disableForeign = false;

    /**
     * 连接失败 最多尝试次数
     * @var int
     */
    protected $conn_lostTry = 10;

    /**
     * 当前 PDO 驱动
     * @var Driver
     */
    protected $conn_driver;

    /**
     * 监听函数
     * @var callable[]
     */
    protected $listeners;

    /**
     * slave 分配策略
     * @var callable
     */
    protected $slaveDispatch = null;

    /**
     * PDO 连接配置
     * @var array
     */
    protected $options = [];

    /**
     * master 服务器连接参数
     * @var array
     */
    protected $masterServer = null;

    /**
     * slave 服务器连接参数
     * @var array
     */
    protected $slaveServer = null;

    /**
     * slaves 节点容器
     * @var array
     */
    protected $slaveNodes = null;

    /**
     * 读写服务器 PDO 对象
     * @var PDO
     */
    protected $pdoObj = null;

    /**
     * 从服务器 PDO 对象
     * @var PDO
     */
    protected $slavePdoObj = null;

    /**
     * 是否禁用 slavePdo
     * @var bool
     */
    protected $slaveDisabled = false;

    /**
     * 事务计数器
     * @var int
     */
    protected $transactionLevel = 0;

    /**
     * 当前连接失败 尝试次数
     * @var bool
     */
    private $lostConnectTry = 0;

    /**
     * 当前语句是否检查外键约束
     * @var bool
     */
    private $checkForeign = true;

    /**
     * 当前连接数据库版本
     * @var string
     */
    private $databaseVersion = null;

    /**
     * 当前查询语句是否使用 master 服务器
     * @var bool
     */
    private $useMasterSelect = false;

    /**
     * SQL 收集设置
     * @var bool|int
     */
    private $pretend = 0;

    /**
     * SQL 收集设置记录
     * @var array
     */
    private $pretendCache = [];

    /**
     * @var array
     */
    private $pretendQueries = [];

    /**
     * PDO 测试类名
     * @var string
     */
    private $pdoClassName;

    /**
     * 创建 Connection 对象
     * @param ?string $name
     * @param ?array $config
     */
    public function __construct(string $name = null, array $config = null)
    {
        if ($name) {
            $this->setName($name);
        }
        if ($config) {
            $this->setConfig($config);
        }
    }

    /**
     * 设置节点名称
     * @param string $name
     * @return $this
     */
    public function setName(string $name)
    {
        $this->conn_name = $name;
        return $this;
    }

    /**
     * 设置连接配置信息
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config)
    {
        if ($this->conn_config == $config) {
            return $this;
        }
        $this->conn_config = $config;
        return $this->restConfig($config);
    }

    /**
     * 设置数据表通用前缀
     * @param ?string $prefix
     * @return $this
     */
    public function setPrefix(?string $prefix)
    {
        $this->conn_lastPrefix = $this->conn_prefix;
        $this->conn_prefix = $prefix ?: null;
        return $this;
    }

    /**
     * 设置 query 监听函数, 独占方式
     * @param ?callable $callback
     * @return $this
     */
    public function setListener(callable $callback = null)
    {
        if (null === $callback) {
            $this->listeners = null;
        } else {
            $this->listeners = [$callback];
        }
        return $this;
    }

    /**
     * 添加 query 监听函数, 叠加方式
     * @param callable $callback
     * @return $this
     */
    public function addListener(callable $callback)
    {
        if (!is_array($this->listeners)) {
            $this->listeners = [];
        }
        $this->listeners[] = $callback;
        return $this;
    }

    /**
     * 设置 slave 分配策略
     * @param ?callable $callback
     * @return $this
     */
    public function setDispatch(callable $callback = null)
    {
        $this->slaveDispatch = $callback;
        $this->dispatchSlave();
        return $this;
    }

    /**
     * 重置连接配置
     * @param array $config
     * @return $this
     */
    protected function restConfig(array $config)
    {
        if ($this->isConnect()) {
            $this->disconnect();
        }
        $driver = $config['driver'] ?? null;
        if (!$driver) {
            throw new DatabaseException('Connection driver not set');
        }
        if (!$this->conn_driver || $this->conn_driver->type !== strtolower($driver)) {
            $this->conn_driver = new Driver($driver);
        }
        $this->setMaster($config);
        $this->setOptions($config['options'] ?? []);
        $this->setSlave((array_key_exists('slave', $config) ? $config['slave'] : null), $config);
        $this->setPrefix(array_key_exists('prefix', $config) ? $config['prefix'] : null);
        $this->setDisableForeign($config['dis_foreign'] ?? false);
        if (isset($config['lost_try'])) {
            $this->setLostTry($config['lost_try']);
        }
        return $this;
    }

    /**
     * 重置主节点
     * @param array $master
     * @return array
     */
    protected function setMaster(array $master)
    {
        $master = $this->conn_driver->connector->preparedServer($master);
        if ($this->masterServer !== $master) {
            $this->masterServer = $master;
            $this->pdoObj = null;
        }
        return $master;
    }

    /**
     * 重置 PDO 连接选项
     * @param array $options
     * @return array
     */
    protected function setOptions(array $options)
    {
        $options = $this->conn_driver->connector->preparedOption($options);
        if ($this->options !== $options) {
            $this->options = $options;
            $this->pdoObj = $this->slavePdoObj = null;
        }
        return $options;
    }

    /**
     * set disableForeign
     * @param bool $disableForeign
     * @return bool
     */
    protected function setDisableForeign(bool $disableForeign)
    {
        return $this->conn_disableForeign = $disableForeign;
    }

    /**
     * set connect lost try
     * @param int $lostTry
     * @return int
     */
    protected function setLostTry(int $lostTry)
    {
        return $this->conn_lostTry = $lostTry;
    }

    /**
     * set slave
     * @param ?array $slaves
     * @param array $master
     * @return array|null
     */
    protected function setSlave(?array $slaves, array $master = [])
    {
        if (empty($slaves)) {
            $this->slaveNodes = $this->slaveServer = $this->slavePdoObj = null;
            return null;
        }
        // 整理所有 可用的 slave 节点
        $slaveNodes = [];
        try {
            $node = $this->conn_driver->connector->preparedServer(array_merge($master, $slaves));
            if ($node !== $this->masterServer) {
                $dsn = isset($node['dsn']) ? (string) $node['dsn'] : null;
                if (!empty($dsn) && !isset($slaveList[$dsn])) {
                    $slaveNodes[$dsn] = [
                        'server' => $node,
                        'weight' => ($config['slave']['weight'] ?? 1),
                    ];
                }
            }
        } catch (DatabaseException $e) {
            // do nothing
        }
        foreach ($slaves as $slave) {
            $node = is_array($slave) ? $this->conn_driver->connector->preparedServer(array_merge($master, $slave)) : null;
            if ($node && $node !== $this->masterServer) {
                $dsn = isset($node['dsn']) ? (string) $node['dsn'] : null;
                if (!empty($dsn) && !isset($slaveList[$dsn])) {
                    $slaveNodes[$dsn] = [
                        'server' => $node,
                        'weight' => ($slave['weight'] ?? 1),
                    ];
                }
            }
        }
        // 分配 slave 节点
        if ( empty($slaveNodes) ) {
            $this->slaveNodes = $this->slaveServer = $this->slavePdoObj = null;
            return null;
        }
        $this->slaveNodes = $slaveNodes;
        return $this->dispatchSlave();
    }

    /**
     * dispatch slave
     * @param string|null $removeServer
     * @return array|null
     */
    protected function dispatchSlave(string $removeServer = null)
    {
        if ($removeServer && is_array($this->slaveNodes) && array_key_exists($removeServer, $this->slaveNodes)) {
            unset($this->slaveNodes[$removeServer]);
        }
        if (!is_array($this->slaveNodes) || empty($this->slaveNodes)) {
            $this->slaveServer = $this->slavePdoObj = null;
            return null;
        }
        if (count($this->slaveNodes) < 2) {
            $server = reset($this->slaveNodes)['server'];
            if ($this->slaveServer !== $server) {
                $this->slavePdoObj = null;
            }
            return $this->slaveServer = $server;
        }
        $servers = [];
        foreach ($this->slaveNodes as $dsn => $node) {
            if ($this->slaveDispatch) {
                $servers[$dsn] = $node['weight'];
            } else {
                $weight = max(1, $node['weight']);
                for ($i = 0; $i < $weight; $i++) {
                    $servers[] = $dsn;
                }
            }
        }
        $dsn = $this->slaveDispatch ? call_user_func($this->slaveDispatch, $servers) : $servers[array_rand($servers)];
        $server = isset($this->slaveNodes[$dsn]) ? $this->slaveNodes[$dsn]['server'] : null;
        if ($this->slaveServer !== $server) {
            $this->slavePdoObj = null;
        }
        return $this->slaveServer = $server;
    }

    /**
     * create pdo connect
     * @param bool $slave
     * @return PDO
     */
    protected function createPdo(bool $slave = false)
    {
        $config = $slave ? $this->slaveServer : $this->masterServer;
        try {
            $class = $this->pdoClassName ?: 'PDO';
            $pdo = new $class(
                $config['dsn'],
                $config['username'] ?? null,
                $config['password'] ?? null,
                $this->options
            );
            $this->conn_driver->connector->afterConnect($pdo, $config, $this->options);
        } catch (PDOException $e) {
            // slave connect failed , switch slave auto, all failed, return master connect
            if ($slave) {
                if ($this->dispatchSlave($config['dsn'])) {
                    return $this->createPdo(true);
                } else {
                    return $this->getPdo();
                }
            }
            throw new ConnectException($e->getMessage(), $e->getCode(), $e);
        }
        return $pdo;
    }

    /**
     * 设置 PHP pdo 连接属性
     * @param array|int|mixed $key
     * @param mixed $val
     * @return $this
     * @see http://php.net/manual/zh/pdo.setattribute.php
     */
    public function setAttribute($key, $val = null)
    {
        if (is_array($key)) {
            foreach ($key as $k=>$v) {
                $this->setAttribute($k, $v);
            }
            return $this;
        }
        if (!$this->pdo->setAttribute($key, $val)) {
            throw new PDOException(sprintf('set driver attribute failed: %s => %s', $key, $val));
        }
        return $this;
    }

    /**
     * 获取 PHP pdo 连接属性
     * @param mixed $key
     * @return mixed
     * @see http://php.net/manual/zh/pdo.getattribute.php
     */
    public function getAttribute($key)
    {
        return $this->pdo->getAttribute($key);
    }

    /**
     * 禁用/启用 slave 服务器，对数据延迟有较高要求的场景可禁用 slave server 避免数据同步延迟造成的问题
     * @param bool $disabled
     * @return $this
     */
    public function disableSlave(bool $disabled = true)
    {
        $this->slaveDisabled = $disabled;
        return $this;
    }

    /**
     * 获取 slave 当前状态：
     * 返回 true/false 表明是否禁用, 返回 null 意味着无 slave 节点
     * @return bool|null
     */
    public function slaveStatus()
    {
        return $this->slaveServer ? !$this->slaveDisabled : null;
    }

    /**
     * 设置是否检查外键约束
     * @param bool $check
     * @return $this
     */
    public function checkForeign(bool $check)
    {
        if ($this->conn_disableForeign) {
            return $this;
        }
        $this->checkForeign = !(false === $check);
        if ($this->pdoObj) {
            $this->conn_driver->connector->checkForeign($this->pdoObj, $this->checkForeign);
        }
        return $this;
    }

    /**
     * 判断当前是否了外检约束，若配置选项禁用或设置为不检查，返回 false
     * @return bool
     */
    public function isCheckForeign()
    {
        return !$this->conn_disableForeign && $this->checkForeign;
    }

    /**
     * 根据配置连接到数据库，一般无需手动连接，执行 sql 时会自动连接
     * - slave = true : connect slave
     * - slave = false: connect master
     * - slave = null : connect master and slave
     * @param ?bool $slave
     * @return $this
     */
    public function connect(bool $slave = null)
    {
        if (null === $this->pdoObj && !$slave) {
            $this->pdoObj = $this->createPdo();
            $this->conn_driver->connector->checkForeign($this->pdoObj, $this->isCheckForeign());
        }
        if (!$this->slaveDisabled && $this->slaveServer && null === $this->slavePdoObj && (null === $slave || $slave)) {
            $this->slavePdoObj = $this->createPdo(true);
        }
        return $this;
    }

    /**
     * 断开数据库连接
     * - slave = true : disconnect slave
     * - slave = false: disconnect master
     * - slave = null : disconnect master and slave
     * @param ?bool $slave
     * @return $this
     */
    public function disconnect(bool $slave = null)
    {
        if (!$slave) {
            $this->pdoObj = null;
        }
        if (null === $slave || $slave) {
            $this->slavePdoObj = null;
        }
        return $this;
    }

    /**
     * 判断当前数据库是否已连接 (master && slave 都连接)
     * @return bool
     */
    public function isConnect()
    {
        if (!$this->slaveDisabled && $this->slaveServer && null === $this->slavePdoObj) {
            return false;
        }
        return null !== $this->pdoObj;
    }

    /**
     * 获取当前 master 数据库连接的 PDO 对象
     * @return PDO
     */
    protected function getPdo()
    {
        if (!$this->pdoObj) {
            $this->connect(false);
        }
        return $this->pdoObj;
    }

    /**
     * 获取当前 slave 数据库连接的 PDO 对象
     * @return PDO
     */
    protected function getSlavePdo()
    {
        // 禁用/未设置 slave server 或者 在事务中  返回 master
        if ($this->slaveDisabled || !$this->slaveServer || $this->transactionLevel >= 1) {
            return $this->getPdo();
        }
        if (!$this->slavePdoObj) {
            $this->connect(true);
        }
        return $this->slavePdoObj;
    }

    /**
     * 预处理 sql 语句 和 绑定值, 并返回 PDOStatement 对象
     * @param string $query SQL语句
     * @param array $bindings 绑定值
     * @param bool $salve 是否使用slave服务器
     * @param array $options 驱动配置,一般用来设置游标
     * @return PDOStatement|null
     * @throws Exception
     * @see http://php.net/manual/zh/pdo.prepare.php
     * @see http://php.net/manual/zh/class.pdostatement.php
     */
    public function statement(string $query, array $bindings = [], bool $salve = false, array $options = [])
    {
        $this->lostConnectTry = 0;
        return $this->runQuery($query, $bindings, $salve, $options);
    }

    /**
     * 设置本次读取是否使用 master 服务器查询：
     * 在执行 fetchAll fetchOne cursor 之后，useMaster 会自动恢复为 false
     * @param bool $master
     * @return $this
     */
    public function useMaster(bool $master = true)
    {
        $this->useMasterSelect = $master;
        return $this;
    }

    /**
     * 与 fetchAll 相同, 只是返回一条数据
     * @param string $query
     * @param array $bindings
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return bool|mixed|null
     * @throws Exception
     * @see http://php.net/manual/zh/pdostatement.fetchall.php 后面三个参数作用
     */
    public function fetchOne(string $query, array $bindings = [], $style = null, $argument = null, $ctor_args = null)
    {
        $statement = $this->selectStatement($query, $bindings, $style);
        if (!$statement) {
            return false;
        }
        $mode = static::preSetFetchOneMode($statement, $style, $argument, $ctor_args);
        if ($mode > 1) {
            return $this->revertFetchOneMode($statement, $statement->fetch());
        }
        return $mode ? $statement->fetch($style) : $statement->fetch();
    }

    /**
     * @param string $query
     * @param array $bindings
     * @param null $style
     * @return PDOStatement|null
     * @throws Exception
     */
    protected function selectStatement(string $query, array $bindings = [], $style = null)
    {
        if ($style & ~0xFFFF0000 === PDO::FETCH_BOUND) {
            throw new PDOException('SQLSTATE[HY000]: General error:  PDO::FETCH_BOUND not support');
        }
        $statement = $this->statement($query, $bindings, !$this->useMasterSelect);
        $this->useMasterSelect = false;
        return $statement;
    }

    /**
     * 在执行 PDOStatement->fetch() 之前先 设置 fetchMode
     * @param PDOStatement $statement
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return int
     */
    protected static function preSetFetchOneMode(PDOStatement $statement, $style = null, $argument = null, $ctor_args = null)
    {
        if ($style !== null && $argument !== null && $ctor_args !== null) {
            $statement->setFetchMode($style, $argument, $ctor_args);
            return 3;
        }
        if ($style !== null && $argument !== null) {
            $statement->setFetchMode($style, $argument);
            return 2;
        }
        if (PDO::FETCH_CLASS === $style) {
            $statement->setFetchMode(PDO::FETCH_CLASS, 'stdClass');
            return 2;
        }
        return 1;
    }

    /**
     * 在执行 PDOStatement->fetch() 完毕后，恢复默认 fetchMode
     * @param PDOStatement $statement
     * @param null $return
     * @return null
     */
    protected function revertFetchOneMode(PDOStatement $statement, $return = null)
    {
        $statement->setFetchMode($this->getSlavePdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        return $return;
    }

    /**
     * 执行语句并获取一组数据, style 默认为 PDO::FETCH_ASSOC
     * @param string $query
     * @param array $bindings
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return array|bool
     * @throws Exception
     * @see http://php.net/manual/zh/pdostatement.fetchall.php 后面三个参数作用
     */
    public function fetchAll(string $query, array $bindings = [], $style = null, $argument = null, $ctor_args = null)
    {
        $statement = $this->selectStatement($query, $bindings, $style);
        if (!$statement) {
            return false;
        }
        if ($style !== null && $argument !== null && $ctor_args !== null) {
            return $statement->fetchAll($style, $argument, $ctor_args);
        }
        if ($style !== null && $argument !== null) {
            return $statement->fetchAll($style, $argument);
        }
        if ($style !== null) {
            return $statement->fetchAll($style);
        }
        return $statement->fetchAll();
    }

    /**
     * 通过 PDO 内部指针循环获取数据，相比直接 fetchAll 更加节省内存
     * ```
     * $data = $this->cursor('Select...', []);
     * foreach($data as $d) {
     *      //code
     * }
     * ```
     * 若 $chunk > 1, 循环获取的值为数组
     *
     * @param string $query
     * @param array $bindings
     * @param int $chunk
     * @param null $style
     * @param null $argument
     * @param null $ctor_args
     * @return Generator
     * @throws Exception
     * @see http://php.net/manual/zh/pdostatement.fetchall.php 后面三个参数作用
     */
    public function cursor(string $query, array $bindings = [], int $chunk = 1, $style = null, $argument = null, $ctor_args = null)
    {
        if (empty($query)) {
            return;
        }
        $statement = $this->selectStatement($query, $bindings, $style);
        if (!$statement) {
            return;
        }
        $chunk = max(1, $chunk);
        $mode = static::preSetFetchOneMode($statement, $style, $argument, $ctor_args);
        if ($chunk > 1) {
            $fields = [];
            while ($row = ($mode ? $statement->fetch($style) : $statement->fetch())) {
                $fields[] = $row;
                if (count($fields) >= $chunk) {
                    yield $fields;
                    $fields = [];
                }
            }
            if (count($fields)) {
                yield $fields;
            }
            unset($fields);
        } else {
            while ($row = ($mode ? $statement->fetch($style) : $statement->fetch())) {
                yield $row;
            }
        }
        if ($mode > 1) {
            $this->revertFetchOneMode($statement);
        }
    }

    /**
     * 执行一条 SQL 并返回影响条数
     * @param string $query
     * @param array $bindings
     * @return bool|int
     * @throws Exception
     */
    public function execute(string $query, array $bindings = [])
    {
        return ($statement = $this->statement($query, $bindings)) ? $statement->rowCount() : false;
    }

    /**
     * 返回最后插入数据的 主键 ID [部分驱动支持 可能需要指明 column name]
     * @param ?string $name
     * @return int|string
     */
    public function lastId(string $name = null)
    {
        $id = $this->getPdo()->lastInsertId($name);
        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * 执行 SQL 语句
     * @param string $query
     * @param array $bindings
     * @param bool $read
     * @param array $options
     * @return null|PDOStatement
     * @throws Exception
     */
    protected function runQuery(string $query, array $bindings = [], bool $read = false, array $options = [])
    {
        if (empty($query)) {
            throw new DatabaseException('SQL query statement could not be empty.');
        }
        /** @var Exception $exception */
        $exception = null;
        $statement = null;
        $start = microtime(true);
        if (0 === $this->pretend || 1 === $this->pretend) {
            try {
                $pdo = $read ? $this->getSlavePdo() : $this->getPdo();
                $statement = $pdo->prepare($query, $options);
                $statement->execute($bindings);
            } catch (ConnectException $e) {
                $exception = $e;
            } catch (PDOException $e) {
                if ($this->transactionLevel >= 1) {
                    $exception = $e;
                } elseif (static::isLostConnect($e->getMessage()) && $this->lostConnectTry < $this->conn_lostTry) {
                    $this->lostConnectTry++;
                    return $this->runQuery($query, $bindings, $read, $options);
                } elseif ($read && $this->slaveServer && $this->dispatchSlave($this->slaveServer['dsn'])) {
                    return $this->runQuery($query, $bindings, $read, $options);
                } else {
                    $exception = new QueryException($query, ($statement ? $bindings : false), $e);
                }
            }
            $this->callListen(
                $query, $bindings, round((microtime(true) - $start) * 1000, 2), $read, $exception
            );
        }
        if ($this->pretend) {
            $this->pretendQueries[] = new Sql($this, $query, $bindings, 0, $read, $exception);
        }
        if ($exception) {
            throw $exception;
        }
        return $statement;
    }

    /**
     * @param string $message
     * @return bool
     */
    protected static function isLostConnect(string $message)
    {
        $words = [
            'server has gone away', 'no connection to the server', 'Lost connection', 'is dead or not enabled'
        ];
        foreach ($words as $str) {
            if (strpos($message, $str) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 触发回调函数
     * @param ?string $query
     * @param array $bindings
     * @param int $duration
     * @param bool $isSlave
     * @param null $exception
     * @return $this
     */
    protected function callListen(
        string $query = null,
        array $bindings = [],
        int $duration = 0,
        bool $isSlave = false,
        $exception = null
    ) {
        if (empty($this->listeners)) {
            return $this;
        }
        $sql = new Sql($this, $query, $bindings, $duration, $isSlave, $exception);
        foreach ($this->listeners as $listen) {
            call_user_func($listen, $sql);
        }
        return $this;
    }

    /**
     * 获取连接的数据库版本
     * @param bool $full
     * @return float|string|mixed
     * @throws Exception
     */
    public function version(bool $full = false)
    {
        if (!$this->databaseVersion) {
            $statement = $this->conn_driver->connector->versionStatement();
            if (is_array($statement)) {
                $query = array_shift($statement);
                $bindings = array_shift($statement);
            } else {
                $query = (string) $statement;
                $bindings = [];
            }
            $this->databaseVersion = $this->fetchAll($query, $bindings, PDO::FETCH_COLUMN, 0)[0];
        }
        if (!$full) {
            if (preg_match('/[0-9.]+/', $this->databaseVersion, $version)) {
                $version = explode('.', $version[0]);
                return (float) (array_shift($version).'.'.join('', $version));
            }
        }
        return $this->databaseVersion;
    }

    /**
     * 获取当前连接数据库的数据表
     * - tables(true)   ->  find tables start with this->prefix
     * - tables(false)  ->  find all tables
     * - tables('prefix') ->  find tables start with 'prefix'
     * @param string|bool $prefix
     * @return array|bool
     * @throws Exception
     */
    public function allTable($prefix = true)
    {
        if ($prefix) {
            $prefix = true === $prefix ? $this->conn_prefix : (string) $prefix;
        } else {
            $prefix = null;
        }
        $statement = $this->conn_driver->connector->tablesStatement($prefix);
        if (is_array($statement)) {
            $query = array_shift($statement);
            $bindings = array_shift($statement);
        } else {
            $query = (string) $statement;
            $bindings = [];
        }
        return $this->fetchAll($query, $bindings, PDO::FETCH_COLUMN, 0);
    }

    /**
     * 判断数据库是否存在指定的表
     * @param string $table 表名, 若有表前缀, 可使用 "{db}name", 其中 "{db}"代表前缀
     * @return bool
     * @throws Exception
     */
    public function hasTable(string $table)
    {
        if (!($tables = $this->allTable(false))) {
            return false;
        }
        $tables = array_map('strtolower', $tables);
        $table = str_replace('{db}', $this->conn_prefix, strtolower($table));
        return in_array($table, $tables);
    }

    /**
     * 启动一个数据库事务
     * @return int
     * @throws Exception
     */
    public function beginTransaction()
    {
        if ($this->transactionLevel) {
            $pdo = $this->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->exec('SAVEPOINT point'.$this->transactionLevel);
            }
        } else {
            try {
                $this->getPdo()->beginTransaction();
            } catch (Exception $e) {
                throw $e;
            }
        }
        return ++$this->transactionLevel;
    }

    /**
     * 提交一个数据库事务
     * @return bool
     */
    public function commit()
    {
        $pdo = $this->getPdo();
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
        if (!$pdo->inTransaction()) {
            return true;
        }
        if ($this->transactionLevel) {
            return (bool) $pdo->exec('RELEASE SAVEPOINT point'.$this->transactionLevel);
        }
        return $pdo->commit();
    }

    /**
     * 回滚一个数据库事务
     * @return bool
     */
    public function rollBack()
    {
        $pdo = $this->getPdo();
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
        if (!$pdo->inTransaction()) {
            return true;
        }
        if ($this->transactionLevel) {
            return $this->getPdo()->exec('ROLLBACK TO SAVEPOINT point'.$this->transactionLevel);
        }
        return $this->getPdo()->rollBack();
    }

    /**
     * 执行一个数据库事务，未抛出异常自动提交，否则回滚
     * @param callable $transaction
     * @return mixed
     * @throws Throwable
     */
    public function transaction(callable $transaction)
    {
        $this->beginTransaction();
        try {
            $result = call_user_func($transaction, $this);
            $this->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
        return $result;
    }

    /**
     * 获得当前事务 嵌套等级
     * @return int
     */
    public function transactionLevel()
    {
        return $this->transactionLevel;
    }

    /**
     * 获取数据库结构生成器对象
     * @param string $table 表名
     * @param bool $withoutPrefix 不要自动添加表前缀
     * @return Schema
     */
    public function schema(string $table, bool $withoutPrefix = false)
    {
        return new Schema($this, $table, $withoutPrefix);
    }

    /**
     * 获取SQL语句构造器对象
     * @param string|Query\Expression $table
     * @param string|null $as
     * @return Builder
     */
    public function table($table = null, string $as = null)
    {
        return new Builder($this, $table, $as);
    }

    /**
     * 获取数据库 Model 对象
     * @param string|null $table
     * @param array|string|null $primary
     * @return Model|object
     */
    public function model(string $table, $primary = null)
    {
        return Model::instance($table, $primary);
    }

    /**
     * 开启SQL语句收集功能
     * @param callable $call
     * @return Sql[]
     */
    public function pretend(callable $call)
    {
        $this->pretend = true;
        $this->pretendQueries = [];
        call_user_func($call);
        $this->pretend = 0;
        return $this->pretendQueries;
    }

    /**
     * 设置/获取 sql 收集功能选项
     * > 在使用 pretend() 收集 sql 语句时，可以在回调函数中使用该方法，
     * 有些语句的生成依赖其他语句的执行结果，想要获得完整的 sql 语句，在部分情况下，需要实际执行部分语句。
     * 比如通过构造器修改表结构，可能需要先查询原表结构才能继续，此时便可临时允许 sql 运行，获取后再收集修改表的 sql 语句，
     * 为更加灵活，该方法参数支持以下设定值：
     * - true:  不执行, 记录
     * - false: 不执行, 不记录
     * - 1: 执行, 记录
     * - 0: 执行, 不记录
     * - null: 返回当前设置值
     * @param bool|int|null $pretend
     * @return bool
     */
    public function pretending($pretend = null)
    {
        if (null === $pretend) {
            return $this->pretend;
        }
        $oldPretend = $this->pretend;
        $this->pretendCache[] = $oldPretend;
        $this->pretend = 0 === $pretend || 1 === $pretend ? $pretend : (bool) $pretend;
        return $oldPretend;
    }

    /**
     * 在使用 pretending() 修改状态后, 可以通过 revertPretending() 恢复到修改前的状态，
     * 这样可以在复杂的嵌套环境下正确设置 $pretend 状态
     * @return bool|int
     */
    public function pretendingRevert()
    {
        return $this->pretend = array_pop($this->pretendCache);
    }

    /**
     * 设置 PDO 类名，通常用于单元测试
     * @param string $name
     * @return $this'
     */
    public function __setPdoClassName(string $name)
    {
        $this->pdoClassName = $name;
        return $this;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        return in_array($name, [
            'name', 'config', 'prefix', 'lastPrefix', 'disableForeign', 'driver',
            'master', 'slave', 'pdo', 'slavePdo'
        ]);
    }

    /**
     * @param $name
     * @return bool|mixed|null|PDO
     */
    public function __get($name)
    {
        if ('master' === $name) {
            return $this->masterServer['dsn'];
        } elseif('slave' === $name) {
            return $this->slaveServer ? $this->slaveServer['dsn'] : null;
        } elseif ('pdo' === $name) {
            return $this->getPdo();
        } elseif ('slavePdo' === $name) {
            return $this->getSlavePdo();
        } elseif (in_array($name, ['name', 'config', 'prefix', 'lastPrefix', 'disableForeign', 'driver'])) {
            return $this->{'conn_'.$name};
        }
        throw new InvalidArgumentException('Undefined property: '.__CLASS__.'::$'.$name);
    }
}
