<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Connection;

abstract class DatabaseConnectionBasic extends TestCase
{
    /**
     * @var array
     */
    protected static $connectionConfig = null;

    /**
     * @var Connection
     */
    protected static $connection = null;

    /**
     * @var bool
     */
    protected static $createTable = false;
    protected static $createTableName = 'PHPUNIT_44A7267E4044EDB9FE1B65BEBBFC4604';

    /**
     * 创建测试 Table  结构如下
     * @param $tableName
     * @return string
     */
    protected function createTableSql($tableName)
    {
        return $this->createTempTableSql($tableName);
    }

    protected function createTempTableSql($tableName)
    {
        return
            "CREATE TABLE `{$tableName}` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `rid` mediumint UNIQUE,
            `cid` mediumint,
            FOREIGN KEY (cid) REFERENCES {$tableName} (rid)
        )";
    }

    /**
     * 删除测试表
     * @return bool
     */
    protected static function dropTable()
    {
        try {
            self::$connection->statement('DROP TABLE `'.self::$createTableName.'`');
        } catch (\Exception $e) {

        }
        return true;
    }

    /**
     * 创建测试表
     * @return bool
     */
    protected function createTable()
    {
        if (self::$createTable) {
            return true;
        }
        try {
            self::dropTable();
            $statement = self::$connection->statement($this->createTableSql(self::$createTableName));
            static::assertInstanceOf('\PDOStatement', $statement);
        } catch (\ErrorException $e) {
            $this->fail('create test table failed');
        }
        self::$createTable = true;
        return true;
    }

    public static function setUpBeforeClass():void
    {
        $database = substr(get_called_class(), 18);
        $config = include (__DIR__.'/../Config/'.$database.'.php');
        if (array_key_exists('slave', $config['config'])) {
            unset($config['config']['slave']);
        }
        if (array_key_exists('dis_foreign', $config['config'])) {
            unset($config['config']['dis_foreign']);
        }
        if (array_key_exists('prefix', $config['config'])) {
            unset($config['config']['prefix']);
        }
        self::$connectionConfig = $config['config'];
        self::$connection = new Connection('PHPUNIT_CONNECTION', self::$connectionConfig);
        self::$createTable = false;
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass():void
    {
        self::dropTable();
        self::$connection->disconnect();
        self::$connectionConfig = null;
        self::$connection = null;
        self::$createTable = false;
        parent::tearDownAfterClass();
    }


    // 判断执行一些方法时, 数据库是否真实连接
    protected function isConnect($connect)
    {
        if ($connect) {
            static::assertTrue(self::$connection->isConnect());
        } else {
            static::assertFalse(self::$connection->isConnect());
        }
    }

    public function testSetName()
    {
        static::assertEquals('PHPUNIT_CONNECTION', self::$connection->name);
        static::assertSame(self::$connection, self::$connection->setName('foo'));
        static::assertEquals('foo', self::$connection->name);
        $this->isConnect(false);
    }

    public function testSetConfig()
    {
        static::assertEquals(self::$connectionConfig, self::$connection->config);
        $config = self::$connectionConfig;
        $config['foo'] = 'bar';
        static::assertSame(self::$connection, self::$connection->setConfig($config));
        static::assertEquals($config, self::$connection->config);
        self::$connection->setConfig(self::$connectionConfig);
        $this->isConnect(false);
    }

    public function testSetPrefix()
    {
        $config = self::$connectionConfig;
        $config['prefix'] = 'foo';
        self::$connection->setConfig($config);
        static::assertNull(self::$connection->lastPrefix);
        static::assertEquals('foo', self::$connection->prefix);

        $config['prefix'] = null;
        self::$connection->setConfig($config);
        static::assertEquals('foo', self::$connection->lastPrefix);
        static::assertNull(self::$connection->prefix);

        $config['prefix'] = 'bar';
        self::$connection->setConfig($config);
        static::assertNull(self::$connection->lastPrefix);
        static::assertEquals('bar', self::$connection->prefix);

        unset($config['prefix']);
        self::$connection->setConfig($config);
        static::assertEquals('bar', self::$connection->lastPrefix);
        static::assertNull(self::$connection->prefix);

        static::assertSame(self::$connection, self::$connection->setPrefix('foo'));
        static::assertNull( self::$connection->lastPrefix);
        static::assertEquals('foo', self::$connection->prefix);

        self::$connection->setConfig(self::$connectionConfig);
        $this->isConnect(false);
    }

    public function testPropertyDisableForeign()
    {
        $config = self::$connectionConfig;
        $config['dis_foreign'] = true;
        self::$connection->setConfig($config);
        static::assertTrue(self::$connection->disableForeign);

        $config['dis_foreign'] = false;
        self::$connection->setConfig($config);
        static::assertFalse(self::$connection->disableForeign);

        unset($config['dis_foreign']);
        self::$connection->setConfig($config);
        static::assertFalse(self::$connection->disableForeign);

        self::$connection->setConfig(self::$connectionConfig);
        $this->isConnect(false);
    }

    public function testPropertyServer()
    {
        static::assertNotEmpty(self::$connection->master);
        $config = self::$connectionConfig;
        unset($config['dbname']);

        try {
            self::$connection->setConfig($config);
            static::fail('It should throw exception if not defined dbname');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // do nothing
        }
        self::$connection->setConfig(self::$connectionConfig);
        $this->isConnect(false);
    }

    public function testPropertySlave()
    {
        static::assertNull(self::$connection->slave);
        $config = self::$connectionConfig;
        $config['slave'] = self::$connectionConfig;

        self::$connection->setConfig($config);
        static::assertNull(self::$connection->slave);

        $config = self::$connectionConfig;
        $configSlave = self::$connectionConfig;
        $configSlave['dbname'] =  $configSlave['dbname'].'_copy';
        $config['slave'] = $configSlave;
        self::$connection->setConfig($config);
        static::assertNotEmpty(self::$connection->slave);

        self::$connection->setConfig(self::$connectionConfig);
        static::assertNull(self::$connection->slave);
        $this->isConnect(false);
    }

    public function testConnectMethod()
    {
        static::assertFalse(self::$connection->isConnect());

        static::assertSame(self::$connection, self::$connection->connect());
        static::assertTrue(self::$connection->isConnect());

        static::assertSame(self::$connection, self::$connection->disconnect());
        static::assertFalse(self::$connection->isConnect());

        static::assertInstanceOf('\PDO', self::$connection->pdo);
        static::assertInstanceOf('\PDO', self::$connection->slavePdo);
        static::assertSame(self::$connection->pdo, self::$connection->slavePdo);

        static::assertTrue(self::$connection->isConnect());
        self::$connection->disconnect();
    }

    public function testAttributeMethod()
    {
        $case = self::$connection->getAttribute(PDO::ATTR_CASE);

        static::assertSame(self::$connection, self::$connection->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER));
        static::assertEquals(PDO::CASE_LOWER, self::$connection->getAttribute(PDO::ATTR_CASE));

        static::assertSame(self::$connection, self::$connection->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER));
        static::assertEquals(PDO::CASE_UPPER, self::$connection->getAttribute(PDO::ATTR_CASE));

        static::assertSame(self::$connection, self::$connection->setAttribute([PDO::ATTR_CASE => PDO::CASE_LOWER]));
        static::assertEquals(PDO::CASE_LOWER, self::$connection->getAttribute(PDO::ATTR_CASE));

        self::$connection->setAttribute(PDO::ATTR_CASE, $case);
        self::$connection->disconnect();
    }

    public function testVersionMethod()
    {
        $version = self::$connection->version();
        $versionFull = self::$connection->version(true);

        static::assertTrue( is_string($versionFull) || is_numeric($versionFull));
        static::assertTrue(is_float($version));

        $this->isConnect(true);
        self::$connection->disconnect();
        $this->isConnect(false);
    }

    public function testSlaveStatusMethod()
    {
        static::assertNull(self::$connection->slaveStatus());
        static::assertSame(self::$connection, self::$connection->disableSlave(false));
        static::assertNull(self::$connection->slaveStatus());
        $this->isConnect(false);
    }


    protected function insert($data)
    {
        return self::$connection->execute(
            'INSERT INTO `'.self::$createTableName.'` (`id`, `rid`, `cid`) VALUES (?,?,?)', $data
        );
    }

    protected function update($id, $data)
    {
        return self::$connection->execute(
            'UPDATE `'.self::$createTableName.'` SET `rid` = ?, `cid` = ? WHERE `id` = '.$id, $data
        );
    }

    protected function delete($id)
    {
        return self::$connection->execute(
            'DELETE FROM `'.self::$createTableName.'`  WHERE `id` = ?', [$id]
        );
    }


    // disable foreign 时, check foreign 总为 false
    public function testDisableForeign()
    {
        static::assertTrue(self::$connection->isCheckForeign());
        static::assertSame(self::$connection, self::$connection->checkForeign(false));
        static::assertFalse(self::$connection->isCheckForeign());
        self::$connection->checkForeign(true);

        $config = self::$connectionConfig;
        $config['dis_foreign'] = true;
        self::$connection->setConfig($config);
        static::assertFalse(self::$connection->isCheckForeign());
        static::assertSame(self::$connection, self::$connection->checkForeign(true));
        static::assertFalse(self::$connection->isCheckForeign());

        self::$connection->setConfig(self::$connectionConfig);
    }

    public function testExecuteMethod()
    {
        $this->createTable();
        static::assertEquals(1, $this->insert([1, 1, null]));
        static::assertEquals(1, $this->update(1, [2, null]));
        static::assertEquals(1, $this->delete(1));
    }

    // 测试是否真实的 disable check foreign
    public function testCheckForeignMethod()
    {
        $this->createTable();
        static::assertEquals(1, $this->insert([1, 1, null]));
        static::assertEquals(1, $this->insert([2, 2, 1]));
        static::assertEquals(1, $this->insert([3, null, 2]));
        try {
            $this->insert([4, 3, 4]);
            static::fail('It should be failed when insert foreign key not exist.');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // do nothing
        }
        try {
            $this->delete(2);
            static::fail('It should be failed when delete foreign key parent row.');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // do nothing
        }

        static::assertSame(self::$connection, self::$connection->checkForeign(false));
        static::assertEquals(1, $this->insert([4, 3, 4]));
        static::assertEquals(1, $this->delete(2));

        static::assertEquals(1, $this->delete(4));
        static::assertEquals(1, $this->insert([2, 2, 1]));
        static::assertSame(self::$connection, self::$connection->checkForeign(true));
        try {
            $this->insert([4, 3, 4]);
            static::fail('It should be failed when insert foreign key not exist.');
        } catch (PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // do nothing
        }
        try {
            $this->delete(2);
            static::fail('It should be failed when delete foreign key parent row.');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // do nothing
        }

        static::assertEquals(1, $this->delete(3));
        static::assertEquals(1, $this->delete(2));
        static::assertEquals(1, $this->delete(1));
    }

    public function testLastIdMethod()
    {
        $this->createTable();
        static::assertEquals(1, $this->insert([2,1,null]));
        static::assertEquals(2, self::$connection->lastId('id'));
        static::assertEquals(1, $this->delete(2));
    }

    public function testFetchMethod()
    {
        $this->createTable();
        static::assertEquals(1, $this->insert([1, 1, null]));
        static::assertEquals(1, $this->insert([2, 2, 1]));
        static::assertEquals(1, $this->insert([3, null, 2]));

        $sql = 'SELECT * FROM `'.self::$createTableName.'`';
        $selectSql = $sql . 'ORDER BY `id` ASC';
        $getoneSql = $sql . 'WHERE `id` = ?';

        // fetchOne (缺省 使用 stdClass)
        static::assertEquals(
            (object) ['id' => 1, 'rid' => 1, 'cid' => null],
            self::$connection->fetchOne($getoneSql, [1])
        );

        static::assertEquals(
            ['id' => 1, 'rid' => 1, 'cid' => null],
            self::$connection->fetchOne($getoneSql, [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            ['id' => 2, 'rid' => 2, 'cid' => 1],
            self::$connection->fetchOne($getoneSql, [2], PDO::FETCH_ASSOC)
        );
        $stdClass =  self::$connection->fetchOne($getoneSql, [2], PDO::FETCH_CLASS);
        static::assertInstanceOf('\stdClass', $stdClass);
        static::assertEquals(2, $stdClass->id);

        $stdClass =  self::$connection->fetchOne($getoneSql, [2], PDO::FETCH_CLASS, 'MyStdClass');
        static::assertInstanceOf('MyStdClass', $stdClass);
        static::assertEquals(2, $stdClass->id);
        static::assertEquals('foo', $stdClass->foo);
        static::assertEquals('bar', $stdClass->bar);

        $stdClass =  self::$connection->fetchOne($getoneSql, [2], PDO::FETCH_CLASS, 'MyStdClass', ['f','b']);
        static::assertInstanceOf('MyStdClass', $stdClass);
        static::assertEquals(2, $stdClass->id);
        static::assertEquals('f', $stdClass->foo);
        static::assertEquals('b', $stdClass->bar);

        // fetchAll
        $data = [
            ['id' => 1, 'rid' => 1, 'cid' => null],
            ['id' => 2, 'rid' => 2, 'cid' => 1],
            ['id' => 3, 'rid' => null, 'cid' => 2],
        ];
        static::assertEquals($data, self::$connection->fetchAll($selectSql, [], PDO::FETCH_ASSOC));

        $lists = self::$connection->fetchAll($selectSql, [], PDO::FETCH_CLASS);
        foreach ($lists as $k => $list) {
            static::assertInstanceOf('\stdClass', $list);
            static::assertEquals($data[$k]['id'], $list->id);
        }

        $lists = self::$connection->fetchAll($selectSql, [], PDO::FETCH_CLASS, 'MyStdClass');
        foreach ($lists as $k => $list) {
            static::assertInstanceOf('\MyStdClass', $list);
            static::assertEquals($data[$k]['id'], $list->id);
            static::assertEquals('foo', $list->foo);
            static::assertEquals('bar', $list->bar);
        }

        $lists = self::$connection->fetchAll($selectSql, [], PDO::FETCH_CLASS, 'MyStdClass', ['f']);
        foreach ($lists as $k => $list) {
            static::assertInstanceOf('\MyStdClass', $list);
            static::assertEquals($data[$k]['id'], $list->id);
            static::assertEquals('f', $list->foo);
            static::assertEquals('bar', $list->bar);
        }

        // cursor
        $lists = self::$connection->cursor($selectSql, [], 1, PDO::FETCH_ASSOC);
        foreach ($lists as $k => $list) {
            static::assertEquals($data[$k], $list);
        }

        $lists = self::$connection->cursor($selectSql, [], 1, PDO::FETCH_CLASS);
        foreach ($lists as $k => $list) {
            static::assertInstanceOf('\stdClass', $list);
            static::assertEquals($data[$k]['id'], $list->id);
        }

        $lists = self::$connection->cursor($selectSql, [], 1, PDO::FETCH_CLASS, 'MyStdClass');
        foreach ($lists as $k => $list) {
            static::assertInstanceOf('\MyStdClass', $list);
            static::assertEquals($data[$k]['id'], $list->id);
            static::assertEquals('foo', $list->foo);
            static::assertEquals('bar', $list->bar);
        }

        $lists = self::$connection->cursor($selectSql, [], 1, PDO::FETCH_CLASS, 'MyStdClass', ['f']);
        foreach ($lists as $k => $list) {
            static::assertInstanceOf('\MyStdClass', $list);
            static::assertEquals($data[$k]['id'], $list->id);
            static::assertEquals('f', $list->foo);
            static::assertEquals('bar', $list->bar);
        }

        static::assertEquals(1, $this->delete(3));
        static::assertEquals(1, $this->delete(2));
        static::assertEquals(1, $this->delete(1));
    }

    public function testTransactionMethod()
    {
        $this->createTable();
        self::$connection->beginTransaction();
        $this->insert([1, 1, null]);
        self::$connection->commit();
        static::assertEquals(
            ['id' => 1, 'rid' => 1, 'cid' => null],
            self::$connection->fetchOne("SELECT * FROM `".self::$createTableName."` WHERE id = ?", [1], PDO::FETCH_ASSOC),
            'Database not support transaction'
        );
        static::assertEquals(1, $this->delete(1));

        self::$connection->beginTransaction();
        $this->insert([1, 1, null]);
        self::$connection->rollBack();
        static::assertFalse(
            self::$connection->fetchOne("SELECT * FROM `".self::$createTableName."` WHERE id = ?", [1]),
            'Database not support transaction'
        );
    }

    public function testTransactionCallback()
    {
        $this->createTable();
        $level = null;
        $result = self::$connection->transaction(function(Connection $connection) use (&$level) {
            $level = $connection->transactionLevel();
            return $connection->execute(
                'INSERT INTO `'.self::$createTableName.'` (`id`, `rid`, `cid`) VALUES (?,?,?)',
                [1, 1, null]
            );
        });
        static::assertEquals(1, $level);
        static::assertEquals(1, $result);
        static::assertEquals(
            ['id' => 1, 'rid' => 1, 'cid' => null],
            self::$connection->fetchOne("SELECT * FROM `".self::$createTableName."` WHERE id = ?", [1], PDO::FETCH_ASSOC),
            'Database not support transaction'
        );
        static::assertEquals(1, $this->delete(1));

        $result = self::$connection->transaction(function(Connection $connection) {
            $connection->execute(
                'INSERT INTO `'.self::$createTableName.'` (`id`, `rid`, `cid`) VALUES (?,?,?)',
                [1, 1, null]
            );
            return 'foo';
        });
        static::assertEquals('foo', $result);
        static::assertEquals(1, $this->delete(1));

        try {
            self::$connection->transaction(function(Connection $connection) {
                $connection->execute(
                    'INSERT INTO `'.self::$createTableName.'` (`id`, `rid`, `cid`) VALUES (?,?,?)',
                    [1, 1, null]
                );
                $connection->execute(
                    'INSERT INTO `'.self::$createTableName.'` (`id`, `rid`, `cid`) VALUES (?,?,?)',
                    [2, 2, 5]
                );
                return true;
            });
            static::fail('It should be throw exception when transaction is failed');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
        }
        static::assertFalse(
            self::$connection->fetchOne("SELECT * FROM `".self::$createTableName."` WHERE id = ?", [1]),
            'Database not support transaction'
        );
    }

    public function testListenMethod()
    {
        $this->createTable();
        $query = null;
        $binds = null;
        $duration = null;

        $query2 = null;
        $connection = null;
        $query_sql = 'INSERT INTO `'.self::$createTableName.'` (`id`, `rid`, `cid`) VALUES (?,?,?)';
        $binds_sql = [1, 1, null];

        self::$connection->setListener(function($execute) use (&$query, &$binds, &$duration) {
            $query = $execute->query;
            $binds = $execute->bindings;
            $duration = $execute->duration;
        });

        self::$connection->addListener(function($execute) use (&$query2, &$connection) {
            $query2 = $execute->query;
            $connection = $execute->connection;
        });

        self::$connection->execute($query_sql, $binds_sql);
        static::assertEquals($query, $query_sql);
        static::assertEquals($binds, $binds_sql);
        static::assertGreaterThanOrEqual(0, $duration);

        static::assertEquals($query2, $query_sql);
        static::assertSame($connection, self::$connection);

        self::$connection->setListener(null);
        static::assertEquals(1, $this->delete(1));
    }

    public function testAllTablesMethod()
    {
        $this->createTable();
        $tables = self::$connection->allTable();
        static::assertTrue(is_array($tables));
        foreach ($tables as $table) {
            static::assertTrue(is_string($table));
        }
        $tables = self::$connection->allTable('PHPUNIT_');
        foreach ($tables as $table) {
            static::assertTrue(strtoupper(substr($table, 0, 8)) === 'PHPUNIT_');
        }
    }

    public function testHasTableMethod()
    {
        $this->createTable();
        static::assertTrue(self::$connection->hasTable(self::$createTableName));
        static::assertFalse(self::$connection->hasTable(self::$createTableName.'_NONE'));
    }

    public function testSchemaMethod()
    {
        static::assertInstanceOf('Tanbolt\Database\Schema\Schema', self::$connection->schema('foo'));
    }

    public function testTableMethod()
    {
        static::assertInstanceOf('Tanbolt\Database\Query\Builder', self::$connection->table('foo'));
    }

    public function testPretend()
    {
        $this->createTable();
        $query_sql = 'INSERT INTO `'.self::$createTableName.'` (`id`, `rid`, `cid`) VALUES (?,?,?)';
        $binds_sql = [1, 1, null];
        $fetch_sql = "SELECT * FROM `".self::$createTableName."` WHERE id = ?";

        $rs = self::$connection->pretend(function () use ($query_sql, $binds_sql){
            self::$connection->execute($query_sql, $binds_sql);
        });
        static::assertCount(1, $rs);
        $sql = $rs[0];
        static::assertInstanceOf(\Tanbolt\Database\Sql::class, $sql);
        static::assertEquals($query_sql, $sql->query);
        static::assertEquals($binds_sql, $sql->bindings);
        static::assertFalse(self::$connection->fetchOne($fetch_sql, [1]));
    }

    // 使用 sqlite 真实测试 主从机制, 只测试一次
    public function testSlaveStatusActual()
    {
        $key = 'PHPUNIT_TEST_F10633019292884AB8F7E53FA62955C5';
        if (array_key_exists($key, $GLOBALS) && $GLOBALS[$key]) {
            static::assertTrue(true);
            return ;
        }

        $drivers = PDO::getAvailableDrivers();
        if (!in_array('sqlite', $drivers)) {
            static::fail('Unit test need sqlite pdo driver support');
        }
        $master = __DIR__ .'/../Fixtures/MASTER_F10633019292884AB8F7E53FA62955C5';
        $slave =  __DIR__ .'/../Fixtures/SLAVE_F10633019292884AB8F7E53FA62955C5';
        @unlink($master);
        @unlink($slave);

        $connect = new Connection('PHPUNIT_SlaveStatusActual_Ready1', [
            'driver' => 'sqlite',
            'dbname' => $master,
        ]);
        $connect->statement('
            CREATE TABLE `foo` (
                `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
                `foo` mediumint UNIQUE
            )
        ');

        $connect = new Connection('PHPUNIT_SlaveStatusActual_Ready2', [
            'driver' => 'sqlite',
            'dbname' => $slave,
        ]);
        $connect->statement('
            CREATE TABLE `foo` (
                `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
                `foo` mediumint UNIQUE
            )
        ');

        $config = [
            'driver' => 'sqlite',
            'dbname' => $master,
            'slave' => [
                'dbname' => $slave,
            ],
            'options' => [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ],
        ];

        $connect = new Connection('PHPUNIT_SlaveStatusActual', $config);
        static::assertTrue(false !== strpos($connect->master, 'MASTER_F10633019292884AB8F7E53FA62955C5'));
        static::assertTrue(false !== strpos($connect->slave, 'SLAVE_F10633019292884AB8F7E53FA62955C5'));

        static::assertTrue($connect->slaveStatus());
        static::assertNotSame($connect->pdo, $connect->slavePdo);

        static::assertSame($connect, $connect->disableSlave(true));
        static::assertFalse($connect->slaveStatus());
        static::assertSame($connect->pdo, $connect->slavePdo);

        static::assertSame($connect, $connect->disableSlave(false));
        static::assertTrue($connect->slaveStatus());
        static::assertNotSame($connect->pdo, $connect->slavePdo);

        $connect->execute("INSERT INTO foo (foo) VALUES (?)", [11]);
        $sql = 'SELECT * FROM `foo`';

        // statement master or slave
        $statement = $connect->statement($sql, []);
        static::assertEquals(11, $statement->fetch()->foo);
        $statement = $connect->statement($sql, [], true);
        static::assertFalse($statement->fetch());

        // default: select from slave server
        static::assertFalse($connect->fetchOne($sql));

        // disabled salve
        $connect->disableSlave(true);
        static::assertEquals(11, $connect->fetchOne($sql)->foo);
        static::assertEquals(11, $connect->fetchOne($sql)->foo);

        // enable salve
        $connect->disableSlave(false);
        static::assertFalse($connect->fetchOne($sql));
        static::assertFalse($connect->fetchOne($sql));

        // use master
        static::assertEquals(11, $connect->useMaster()->fetchOne("SELECT * FROM `foo`")->foo);
        static::assertFalse($connect->fetchOne("SELECT * FROM `foo`"));
        static::assertEquals(11, $connect->useMaster()->fetchOne("SELECT * FROM `foo`")->foo);
        static::assertFalse($connect->fetchOne("SELECT * FROM `foo`"));

        $connect->disableSlave(false);
        $connect->disconnect();

        @unlink($master);
        @unlink($slave);
        $GLOBALS[$key] = true;
    }
}

class MyStdClass
{
    public $foo;
    public $bar;

    public function __construct($foo = 'foo', $bar = 'bar')
    {
        $this->foo = $foo;
        $this->bar = $bar;
    }

}




