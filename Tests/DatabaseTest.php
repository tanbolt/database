<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Database;
use Tanbolt\Database\Connection;
use Tanbolt\Database\Exception\DatabaseException;

class DatabaseTest extends TestCase
{
    protected static function serverNode0()
    {
        return  [
            'driver' => 'mysql',
        ];
    }
    protected static function serverNode1()
    {
        return  [
            'driver' => 'sqlite',
            'dbname' => __DIR__ .'/Fixtures/master.1',
        ];
    }
    protected static function serverNode2()
    {
        return  [
            'driver' => 'sqlite',
            'dbname' => __DIR__ .'/Fixtures/master.2',
        ];
    }
    protected static function serverNode3()
    {
        return [
            'driver' => 'sqlite',
            'dbname' => __DIR__ .'/Fixtures/master.1',
            'slave' => [
                'dbname' => __DIR__ .'/Fixtures/slave.2',
            ],
        ];
    }
    protected static function serverNode4()
    {
        return [
            'driver' => 'sqlite',
            'dbname' => __DIR__ .'/Fixtures/master.1',
            'slave' => [
                [
                    'dbname' => __DIR__ .'/Fixtures/slave.2',
                    'weight' => 1,
                ],
                [
                    'dbname' => __DIR__ .'/Fixtures/slave.3',
                    'weight' => 2,
                ],
            ],
        ];
    }
    protected static function serverNode5()
    {
        return [
            'driver' => 'sqlite',
            'dbname' => __DIR__ .'/Fixtures/master.1',
            'slave' => [
                [
                    'dbname' => __DIR__ .'/Fixtures/slave.4',
                    'weight' => 3,
                ],
                [
                    'dbname' => __DIR__ .'/Fixtures/slave.5',
                    'weight' => 4,
                ],
                [
                    'dbname' => __DIR__ .'/Fixtures/slave.6',
                    'weight' => 4,
                ],
                [
                    'dbname' => __DIR__ .'/Fixtures/slave.7',
                    'weight' => 4,
                ],
            ],
        ];
    }

    public function setUp():void
    {
        $drivers = PDO::getAvailableDrivers();
        if (!in_array('sqlite', $drivers)) {
            self::fail('Unit test need sqlite pdo driver support');
        }
    }

    public function tearDown():void
    {
        @unlink(__DIR__ .'/Fixtures/master.1');
        @unlink(__DIR__ .'/Fixtures/master.2');
        parent::tearDown();
    }

    public function testDefaultNode()
    {
        try {
            Database::getNode();
            self::fail('It should throw exception if database config not set');
        } catch (DatabaseException $e) {
            self::assertTrue(true);
        }
        try {
            Database::getNode(self::serverNode0());
            self::fail('It should throw exception if database config error');
        } catch (DatabaseException $e) {
            self::assertTrue(true);
        }

        // default node connect 不会多次实例化
        Database::putNode(['default' => self::serverNode1()], true);
        $node = Database::getNode();
        $pdo = $node->pdo;
        self::assertInstanceOf(Connection::class, $node);

        Database::putNode(['default' => self::serverNode1()], true);
        $newNode = Database::getNode();
        self::assertSame($node, $newNode);
        self::assertSame($pdo, $newNode->pdo);

        // 不会创建新实例, 而是重置旧实例的配置
        Database::putNode(['default' => self::serverNode2()], true);
        $newNode = Database::getNode();
        self::assertSame($node, $newNode);
        self::assertNotSame($pdo, $newNode->pdo);

        // default node 测试
        self::assertEquals('default', Database::getDefaultNode());

        self::assertEquals('str', Database::setDefaultNode('str'));
        self::assertEquals('str', Database::getDefaultNode());

        self::assertTrue(is_string($str = Database::setDefaultNode(self::serverNode2())));
        self::assertEquals($str, Database::getDefaultNode());

        $connect = new Connection();
        $connect->setName('coon');
        self::assertEquals('coon', Database::setDefaultNode($connect));
        self::assertEquals('coon', Database::getDefaultNode());

        Database::clearNode();
    }

    public function testExtraNode()
    {
        self::assertCount(0, Database::nodeKeys());
        Database::putNode(['node0' => self::serverNode0()]);
        Database::putNode(['node1' => self::serverNode1()]);
        self::assertCount(2, Database::nodeKeys());

        Database::putNode(['node2'=> self::serverNode2()]);
        Database::putNode(['node3'=> self::serverNode3()]);
        self::assertCount(4, Database::nodeKeys());

        self::assertEquals(['node0', 'node1', 'node2', 'node3'], Database::nodeKeys());
        try {
            Database::getNode('node0');
            self::fail('It should throw exception if database config error');
        } catch (DatabaseException $e) {
            self::assertTrue(true);
        }

        // node connect 不会多次实例化
        $node1 = Database::getNode('node1');
        $pdo = $node1->pdo;
        self::assertInstanceOf(Connection::class, $node1);
        Database::putNode(['node1' => self::serverNode1()]);
        self::assertSame($node1, Database::getNode('node1'));
        self::assertSame($pdo, Database::getNode('node1')->pdo);

        // 不会创建新实例, 而是重置旧实例的配置
        Database::putNode(['node1' => self::serverNode2()]);
        self::assertSame($node1, Database::getNode('node1'));
        self::assertNotSame($pdo, Database::getNode('node1')->pdo);

        // remove node
        self::assertFalse(Database::removeNode('none'));
        self::assertEquals(['node0', 'node1', 'node2', 'node3'], Database::nodeKeys());
        self::assertTrue(Database::removeNode('node0'));
        self::assertEquals(['node1', 'node2', 'node3'], Database::nodeKeys());

        Database::clearNode();
        self::assertCount(0, Database::nodeKeys());
    }

    public function testNodeByConfig()
    {
        $config = self::serverNode1();

        $node = Database::getNode($config);
        self::assertInstanceOf(Connection::class, $node);
        self::assertCount(1, Database::nodeKeys());

        $node2 =  Database::getNode($config);
        self::assertSame($node, $node2);
        self::assertCount(1, Database::nodeKeys());

        Database::clearNode();
    }

    public function testSetMigrations()
    {
        self::assertNull(Database::getMigrations());
        Database::setMigrations('mig');
        self::assertEquals('mig', Database::getMigrations());
    }

    public function testSetSlaveDispatch()
    {
        $servers = [];
        $weights = [];

        self::assertNull(Database::getSlaveDispatch());
        Database::setSlaveDispatch($call = function (){} );
        self::assertEquals($call, Database::getSlaveDispatch());
        Database::setSlaveDispatch(null);
        self::assertNull(Database::getSlaveDispatch());

        Database::putNode(['default' => self::serverNode4()], true);
        Database::setSlaveDispatch($dispatch = function($nodes) use (&$servers, &$weights){
            foreach ($nodes as $dsn => $weight) {
                $servers[] = $dsn;
                $weights[] = $weight;
            }
            return key($nodes);
        });
        self::assertEquals($dispatch, Database::getSlaveDispatch());

        $database = new Database();
        self::assertTrue(false !== strpos($database->slave, 'slave.2'));
        self::assertCount(2, $servers);
        self::assertTrue(false !== strpos($servers[0], 'slave.2'));
        self::assertTrue(false !== strpos($servers[1], 'slave.3'));
        self::assertEquals([1, 2], $weights);

        $node = Database::getNode(self::serverNode5());
        self::assertTrue(false !== strpos($node->slave, 'slave.4'));

        Database::clearNode();
    }

    public function testNodeWithSlaveServer()
    {
        $dispatch = function() {
            return false;
        };
        Database::setSlaveDispatch($dispatch);
        Database::putNode(['default' => self::serverNode3()], true);
        Database::putNode(['foo' => self::serverNode3()], true);

        $node = Database::getNode();
        self::assertNotNull($node->slave);
        self::assertTrue(false !== strpos($node->slave, 'slave.2'));

        $node = Database::getNode('foo');
        self::assertNotNull($node->slave);
        self::assertTrue(false !== strpos($node->slave, 'slave.2'));

        Database::clearNode();
    }

    public function testPartSlaveConnectFailed()
    {
        // part failed
        Database::putNode(['default' => self::serverNode5()], true);

        $dispatch = [];
        Database::setSlaveDispatch(function($nodes) use (&$dispatch) {
            $dispatch[] = array_keys($nodes);
            return key($nodes);
        });

        $db = Database::getNode();
        $db->__setPdoClassName('PHPUNIT_PDO_PART_SLAVE_CONNECT_FAILED');

        self::assertNotSame($db->pdo, $db->slavePdo);
        self::assertInstanceOf('\PDO', $db->pdo);
        self::assertTrue(false !== strpos($db->pdo->testDsn, 'master.1'));
        self::assertInstanceOf('\PDO', $db->slavePdo);
        self::assertTrue(false !== strpos($db->slavePdo->testDsn, 'slave.6'));
        self::dispatchChecked($dispatch);

        Database::clearNode();
    }

    public function testAllSlaveConnectFailed()
    {
        // all failed
        Database::putNode(['default' => self::serverNode5()], true);

        $dispatch = [];
        Database::setSlaveDispatch(function($nodes) use (&$dispatch) {
            $dispatch[] = array_keys($nodes);
            return key($nodes);
        });

        $db = Database::getNode();
        $db->__setPdoClassName('PHPUNIT_PDO_ALL_SLAVE_CONNECT_FAILED');

        self::assertInstanceOf('\PDO', $db->slavePdo);
        self::assertSame($db->pdo, $db->slavePdo);
        self::dispatchChecked($dispatch);

        Database::clearNode();
    }

    public function testListenMasterConnectFailed()
    {
        // all failed
        Database::putNode(['default' => self::serverNode5()], true);

        /** @var \Tanbolt\Database\Sql $sql */
        $sql = null;
        Database::setQueryListener($listener = function($event) use (&$sql) {
            $sql = $event;
        });
        self::assertSame($listener, Database::getQueryListener());

        $db = Database::getNode();
        $db->__setPdoClassName('PHPUNIT_PDO_MASTER_CONNECT_FAILED');

        $query = 'UPDATE `table` SET age=1 WHERE id = ?';
        $bindings = [1];
        try {
            $db->execute($query, $bindings);
            self::fail('It should throw exception if connect failed');
        } catch (\Tanbolt\Database\Exception\ConnectException $e) {

        }
        self::assertInstanceOf(Tanbolt\Database\Sql::class, $sql);
        self::assertEquals($query, $sql->query);
        self::assertEquals($bindings, $sql->bindings);
        self::assertInstanceOf(\Tanbolt\Database\Exception\ConnectException::class, $sql->exception);

        Database::clearNode();
    }

    public function testListenMasterLostConnect()
    {
        // all failed
        Database::putNode(['default' => self::serverNode5()], true);

        /** @var \Tanbolt\Database\Sql $sql */
        $sql = null;
        Database::setQueryListener(function($event) use (&$sql) {
            $sql = $event;
        });

        $db = Database::getNode();
        $db->__setPdoClassName('PHPUNIT_PDO_MASTER_FAILED');

        $query = 'UPDATE `table` SET age=1 WHERE id = ?';
        $bindings = [1];

        try {
            $db->execute($query, $bindings);
            self::fail('It should throw exception if connect failed');
        } catch (\Tanbolt\Database\Exception\QueryException $e) {

        }
        self::assertEquals(1, $db->pdo->lostTry);
        self::assertInstanceOf('Tanbolt\Database\Sql', $sql);
        self::assertEquals($query, $sql->query);
        self::assertEquals($bindings, $sql->bindings);
        self::assertInstanceOf('Tanbolt\Database\Exception\QueryException', $sql->exception);
        self::assertTrue(false !== strpos($sql->exception->getMessage(), 'connect failed'));

        $db->pdo->maxLostTry = 5;
        try {
            $db->execute($query, $bindings);
            self::fail('It should throw exception if connect failed');
        } catch (\Tanbolt\Database\Exception\QueryException $e) {

        }
        self::assertEquals(6, $db->pdo->lostTry);
        self::assertInstanceOf('Tanbolt\Database\Sql', $sql);
        self::assertEquals($query, $sql->query);
        self::assertEquals($bindings, $sql->bindings);
        self::assertInstanceOf('Tanbolt\Database\Exception\QueryException', $sql->exception);
        self::assertTrue(false !== strpos($sql->exception->getMessage(), 'connect failed'));

        Database::clearNode();
    }

    public function testListenSlaveLostConnect()
    {
        Database::putNode(['default' => self::serverNode5()], true);

        $dispatch = [];
        Database::setSlaveDispatch(function($nodes) use (&$dispatch) {
            $dispatch[] = array_keys($nodes);
            return key($nodes);
        });

        $db = Database::getNode();
        $db->__setPdoClassName('PHPUNIT_PDO_SLAVE_FAILED');

        $query = 'SELECT * FROM `table` WHERE id = ?';
        $bindings = [1];
        $foo = $db->fetchAll($query, $bindings);
        self::assertEquals(['foo'], $foo);
        self::dispatchChecked($dispatch);

        self::assertEquals(6, $db->slavePdo->lostTry());
        self::assertTrue(false !== strpos($db->slavePdo->testDsn, 'slave.7'));

        Database::clearNode();
    }

    protected function dispatchChecked(array $dispatch)
    {
        self::assertCount(3, $dispatch);

        self::assertCount(4, $dispatch[0]);
        self::assertTrue(false !== strpos($dispatch[0][0], 'slave.4'));
        self::assertTrue(false !== strpos($dispatch[0][1], 'slave.5'));
        self::assertTrue(false !== strpos($dispatch[0][2], 'slave.6'));
        self::assertTrue(false !== strpos($dispatch[0][3], 'slave.7'));

        self::assertCount(3, $dispatch[1]);
        self::assertTrue(false !== strpos($dispatch[1][0], 'slave.5'));
        self::assertTrue(false !== strpos($dispatch[1][1], 'slave.6'));
        self::assertTrue(false !== strpos($dispatch[1][2], 'slave.7'));

        self::assertCount(2, $dispatch[2]);
        self::assertTrue(false !== strpos($dispatch[2][0], 'slave.6'));
        self::assertTrue(false !== strpos($dispatch[2][1], 'slave.7'));
    }
}


class PHPUNIT_PDO_ALL_SLAVE_CONNECT_FAILED extends PDO
{
    public function __construct($dsn, $username, $passwd, $options)
    {
        if (strpos($dsn, 'master.1') === false) {
            throw new PDOException;
        }
    }
    public function exec($statement)
    {

    }
}


class PHPUNIT_PDO_PART_SLAVE_CONNECT_FAILED extends PDO
{
    public $testDsn;

    public function __construct($dsn, $username, $passwd, $options)
    {
        if (strpos($dsn, 'slave.4') || strpos($dsn, 'slave.5')) {
            throw new PDOException;
        }
        $this->testDsn = $dsn;
    }
    public function exec($statement)
    {

    }
}


class PHPUNIT_PDO_MASTER_CONNECT_FAILED extends PDO
{
    public function __construct($dsn, $username, $passwd, $options)
    {
        if (strpos($dsn, 'master.1')) {
            throw new PDOException;
        }
    }
}

class PHPUNIT_PDO_MASTER_FAILED extends PDO
{
    public $maxLostTry = 0;

    public $lostTry = 0;

    public function __construct($dsn, $username, $passwd, $options)
    {

    }

    public function exec($statement)
    {

    }

    public function prepare($statement, $driver_options = null)
    {
        $this->lostTry++;
        if ($this->lostTry > $this->maxLostTry) {
            throw new PDOException('connect failed');
        } else {
            throw new PDOException('server has gone away');
        }
    }
}


class PHPUNIT_PDO_SLAVE_FAILED extends PDO
{
    public $testDsn;
    public static $lostTry = 0;

    public function __construct($dsn, $username, $passwd, $options)
    {
        if (strpos($dsn, 'slave.4') || strpos($dsn, 'slave.5')) {
            throw new PDOException;
        }
        $this->testDsn = $dsn;
    }

    public function lostTry()
    {
        return self::$lostTry;
    }

    public function prepare($statement, $driver_options = null)
    {
        if (strpos($this->testDsn, 'slave.6')) {
            self::$lostTry++;
            if (self::$lostTry > 5) {
                throw new PDOException('connect failed');
            } else {
                throw new PDOException('server has gone away');
            }
        } else {
            return new PHPUNIT_PDO_Statement();
        }
    }

}

class PHPUNIT_PDO_Statement extends PDOStatement
{
    public function execute($input_parameters = null)
    {
    }

    public function fetchAll($mode = PDO::FETCH_BOTH, ...$args)
    {
        return ['foo'];
    }
}



