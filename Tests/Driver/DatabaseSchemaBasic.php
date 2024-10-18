<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Connection;
use Tanbolt\Database\Schema\Table;
use Tanbolt\Database\Schema\Schema;
use Tanbolt\Database\Exception\DatabaseException;

abstract class DatabaseSchemaBasic extends TestCase
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
    protected static $verifyTablePrepared = false;

    /**
     * @var bool
     */
    protected static $alterTablePrepared = false;

    /**
     * @var bool
     */
    protected static $foreignTablePrepared = false;

    /**
     * @var array
     */
    protected static $tableNames = [

        // 用于测试基本表操作
        'basic'  => 'PHPUNIT_F3545FB6BA449B086F571B48EC547128',
        'rename' => 'PHPUNIT_BBCCCBDEFA4098A67ABC3E936B272AD5',
        'bind'   => 'PHPUNIT_362A0DEE314F0C1F0317FF84276005F7',

         // 用于测试 表创建/修改
        'create' => 'PHPUNIT_5A944BA44B92FE0AD8391AC42C54335C',
        'alter' =>  'PHPUNIT_620BF3ADB5E39253B3D4FD81D9264B3B',

        // 外键约束表  用于配合 表创建/修改
        'foreign' => 'PHPUNIT_76D79DEF72AF085817AE529DB7840265',

        // drop 测试表
        'drop'   =>  'PHPUNIT_6E37E3441CC500797B4E278473635D5D',

        // drop 测试表
        'dropif'   =>  'PHPUNIT_25C6A8A7EFA953E7613E954EC1CACC32',

        // create column 测试表
        'column'   =>  'PHPUNIT_1AFD32818D1C9525F82AFF4C09EFD254',

        // disableForeign 测试表
        'disableForeign'   =>  'PHPUNIT_9561B6049AF57BCA1C112CCABDEE96B4',
    ];


    public static function setUpBeforeClass():void
    {
        $database = substr(get_called_class(), 14);
        $config = include (__DIR__.'/../Config/'.$database.'.php');
        self::$connectionConfig = $config['config'];
        self::$connection = new Connection('PHPUNIT'.$database, self::$connectionConfig);
        self::$connection->setPrefix(null);
        self::$verifyTablePrepared = false;
        self::$alterTablePrepared = false;
        self::$foreignTablePrepared = false;
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass():void
    {
        foreach (self::$tableNames as $table) {
            self::tryToDropTable($table);
        }
        self::$connection->disconnect();
        self::$connectionConfig = null;
        self::$connection = null;
        self::$verifyTablePrepared = false;
        self::$alterTablePrepared = false;
        self::$foreignTablePrepared = false;
        parent::tearDownAfterClass();
    }

    /*
     * 通用函数
     *---------------------------------------------------------*/

    /**
     * 尝试删除一个表
     * @param $table
     */
    protected static function tryToDropTable($table)
    {
        $checkForeign = self::$connection->isCheckForeign();
        if ($checkForeign) {
            self::$connection->checkForeign(false);
        }
        try {
            self::$connection->statement("DROP TABLE `{$table}`");
        } catch (Throwable $e) {

        }
        if ($checkForeign) {
            self::$connection->checkForeign(true);
        }
    }

    /**
     * 获取一个表的 schema 对象
     * @param int $tableNumber
     * @return Schema
     */
    protected static function getSchema($tableNumber)
    {
        return new Schema(self::$connection, self::$tableNames[$tableNumber]);
    }

    /**
     * 尝试参数表
     * @param Schema $schema
     * @return Schema
     */
    protected static function dropIfTableBySchema(Schema $schema)
    {
        self::tryToDropTable($schema->realTable());
        return $schema;
    }

    //  创建表 为 基本测试做准备
    protected static function preparedVerifyTable()
    {
        if (!self::$verifyTablePrepared) {
            static::dropIfTableBySchema(static::getSchema('rename'));

            if (self::$connection->isCheckForeign()) {
                static::dropIfTableBySchema(static::getSchema('bind'))->create(function(Table $table) {
                    $table->addColumn('id')->int(10)->unsigned(false)->auto();
                    $table->addColumn('fid')->mediumint(8)->unsigned();
                    $table->addIndex(['fid'])->unique();
                });
            }

            static::dropIfTableBySchema(static::getSchema('basic'))->create(function(Table $table) {
                $table->addColumn('id')->int(10)->unsigned(false)->auto();
                $table->addColumn('fid')->mediumint(8)->unsigned();
                $table->addColumn('tid')->tinyint(3)->unsigned();
                $table->addIndex(self::$tableNames['basic'] . '_tid')->column(['tid']);
                if (self::$connection->isCheckForeign()) {
                    $table->addForeign(self::$tableNames['basic'] . '_foreign')->column(['fid'])
                        ->table(self::$tableNames['bind'])->reference(['fid']);
                }
            });

            self::$verifyTablePrepared = true;
        }
    }



    /*
     * 通用测试
     *---------------------------------------------------------*/


    // 基本测试: 表前缀的设置与获取
    public function testSchemaResetTable()
    {
        $schema = new Schema(self::$connection, 'foo');
        static::assertEquals('foo', $schema->getTable());
        static::assertEquals('foo', $schema->realTable());
        static::assertSame($schema, $schema->setTable('bar'));
        static::assertEquals('bar', $schema->getTable());
        static::assertEquals('bar', $schema->realTable());

        self::$connection->setPrefix('phpunit_');
        static::assertEquals('bar', $schema->getTable());
        static::assertEquals('phpunit_bar', $schema->realTable());
        static::assertSame($schema, $schema->setTable('foo'));
        static::assertEquals('foo', $schema->getTable());
        static::assertEquals('phpunit_foo', $schema->realTable());

        self::$connection->setPrefix(null);
        static::assertEquals('foo', $schema->getTable());
        static::assertEquals('foo', $schema->realTable());
    }

    // 基本测试: 创建表的 SQL
    public function testCreateSqlMethod()
    {
        static::preparedVerifyTable();
        $sql0 = static::getSchema('basic')->createSql();
        static::assertNotEmpty($sql0);
        static::assertTrue(false !== strpos($sql0, 'id'));
        static::assertTrue(false !== strpos($sql0, 'fid'));
        static::assertTrue(false !== strpos($sql0, 'tid'));
        if (self::$connection->isCheckForeign()) {
            $sql1 = static::getSchema('bind')->createSql();
            static::assertNotEmpty($sql1);
            static::assertTrue(false !== strpos($sql1, 'id'));
            static::assertTrue(false !== strpos($sql1, 'fid'));
        }
    }

    // 基本测试: 表字段
    public function testGetColumnsMethod()
    {
        static::preparedVerifyTable();
        $columns = static::getSchema('basic')->columns();
        $columnsKey = array_keys($columns);
        static::assertCount(0, array_diff(['id', 'fid', 'tid'], $columnsKey));
        static::assertCount(0, array_diff($columnsKey, ['id', 'fid', 'tid']));

        /** @var \Tanbolt\Database\Schema\Column $id */
        $id = $columns['id'];
        static::assertInstanceOf('Tanbolt\Database\Schema\Column', $id);
        static::assertTrue($id->auto);
        static::assertEquals(10, $id->length);
        static::assertFalse($id->unsigned);
        static::assertEquals(Schema::TYPE_INT, $id->type);
    }

    // 基本测试: 是否包含字段
    public function testHasColumnMethod()
    {
        static::preparedVerifyTable();
        static::assertTrue(static::getSchema('basic')->hasColumn('id'));
        static::assertTrue(static::getSchema('basic')->hasColumn('tid'));
        static::assertFalse(static::getSchema('basic')->hasColumn('mid'));
        if (self::$connection->isCheckForeign()) {
            static::assertTrue(static::getSchema('bind')->hasColumn('fid'));
            static::assertFalse(static::getSchema('bind')->hasColumn('tid'));
        }
    }

    // 基本测试: 索引字段
    public function testGetIndexesMethod()
    {
        static::preparedVerifyTable();
        $indexes = static::getSchema('basic')->indexes();
        static::assertArrayHasKey('PRIMARY', $indexes);
        static::assertArrayHasKey(self::$tableNames['basic'] . '_tid', $indexes);

        /** @var \Tanbolt\Database\Schema\Index $index */
        $index = $indexes['PRIMARY'];
        static::assertInstanceOf('Tanbolt\Database\Schema\Index', $index);
        static::assertTrue($index->primary);
        static::assertFalse($index->unique);
        static::assertEquals(['id'], $index->column);

        $index = $indexes[self::$tableNames['basic'] . '_tid'];
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);
        static::assertEquals(['tid'], $index->column);
    }

    // 基本测试: 外键约束
    public function testGetForeignersMethod()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        static::preparedVerifyTable();
        $foreigners = static::getSchema('basic')->foreigners();
        static::assertArrayHasKey(self::$tableNames['basic'] . '_foreign', $foreigners);

        /** @var \Tanbolt\Database\Schema\Foreign $index */
        $foreign = $foreigners[self::$tableNames['basic'] . '_foreign'];
        static::assertInstanceOf('Tanbolt\Database\Schema\Foreign', $foreign);

        static::assertEquals(self::$tableNames['basic'] . '_foreign', $foreign->name);
        static::assertEquals(['fid'], $foreign->column);
        static::assertEquals(['fid'], $foreign->reference);
        static::assertEquals(strtolower(self::$tableNames['bind']), strtolower($foreign->table));
    }

    // 基本测试: 外键约束
    public function testGetConstraintsMethod()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        static::preparedVerifyTable();
        $foreigners = static::getSchema('bind')->constraints();
        static::assertCount(1, $foreigners);

        $foreign = $foreigners[0];
        static::assertInstanceOf('Tanbolt\Database\Schema\Foreign', $foreign);
        static::assertEquals(self::$tableNames['basic'] . '_foreign', $foreign->name);
        static::assertEquals(['fid'], $foreign->column);
        static::assertEquals(['fid'], $foreign->reference);
        static::assertEquals(strtolower(self::$tableNames['basic']), strtolower($foreign->table));
    }

    // 基本测试: 修改表名
    public function testRenameTableNameMethod()
    {
        static::preparedVerifyTable();
        if (self::$connection->isCheckForeign()) {
            static::assertTrue(self::$connection->hasTable(self::$tableNames['bind']));
            $schema = static::getSchema('bind');
            static::assertEquals(self::$tableNames['bind'], $schema->realTable());

            static::assertSame($schema, $schema->rename(self::$tableNames['rename']));

            static::assertEquals(self::$tableNames['rename'], $schema->realTable());
            static::assertFalse(self::$connection->hasTable(self::$tableNames['bind']));
            static::assertTrue(self::$connection->hasTable(self::$tableNames['rename']));

            $foreigners = static::getSchema('basic')->foreigners();
            $foreign = $foreigners[self::$tableNames['basic'] . '_foreign'];
            static::assertEquals(strtolower(self::$tableNames['rename']), strtolower($foreign->table));

            $schema->rename(self::$tableNames['bind']);
        } else {

            static::assertTrue(self::$connection->hasTable(self::$tableNames['basic']));
            $schema = static::getSchema('basic');
            static::assertEquals(self::$tableNames['basic'], $schema->realTable());

            static::assertSame($schema, $schema->rename(self::$tableNames['rename']));

            static::assertEquals(self::$tableNames['rename'], $schema->realTable());
            static::assertFalse(self::$connection->hasTable(self::$tableNames['basic']));
            static::assertTrue(self::$connection->hasTable(self::$tableNames['rename']));

            $schema->rename(self::$tableNames['basic']);
        }
    }

    // 清空表
    public function testClearTableMethod()
    {
        static::preparedVerifyTable();
        if (self::$connection->isCheckForeign()) {
            self::$connection->execute('INSERT INTO `' . self::$tableNames['bind'] . '` (`fid`) VALUES (?)', [1]);
        }
        self::$connection->execute('INSERT INTO `' . self::$tableNames['basic'] . '` (`tid`,`fid`) VALUES (?,?)', [1, 1]);
        self::$connection->execute('INSERT INTO `' . self::$tableNames['basic'] . '` (`tid`,`fid`) VALUES (?,?)', [2, 1]);
        $count = self::$connection->fetchOne('SELECT COUNT(*) AS d FROM `'. self::$tableNames['basic'] .'`');
        static::assertEquals(2, $count->d);

        if (self::$connection->isCheckForeign()) {
            try {
                static::getSchema('bind')->clear();
                static::fail('It should throw exception if clear constraint table');
            } catch (\PHPUnit_Exception $e) {
                throw $e;
            } catch (\Exception $e) {
                static::assertTrue(true);
            }
        }
        static::assertEquals(2, static::getSchema('basic')->clear());
        $count = self::$connection->fetchOne('SELECT COUNT(*) AS d FROM `'. self::$tableNames['basic'] .'`');
        static::assertEquals(0, $count->d);
    }

    // 删除表 (drop)
    public function testDropTableMethod()
    {
        $tableName = self::$tableNames['drop'];
        $schema = new Schema(self::$connection, $tableName);
        static::assertFalse(self::$connection->hasTable($tableName));
        try {
            $schema->drop();
            static::fail('It should throw exception if drop a not exist table');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // do nothing
        }
        $schema->create(function(Table $table){
            $table->addColumn('id')->mediumint(8);
        });
        static::assertTrue(self::$connection->hasTable($tableName));
        static::assertSame($schema, $schema->drop());
        static::assertFalse(self::$connection->hasTable($tableName));

        if (self::$connection->isCheckForeign()) {
            try {
                static::getSchema('bind')->drop();
                static::fail('It should throw exception if drop constraint table');
            } catch (\PHPUnit_Exception $e) {
                throw $e;
            } catch (\Exception $e) {
                // do nothing
            }
        }
    }

    // 删除表 (dropif)
    public function testDropIfTableMethod()
    {
        static::preparedVerifyTable();
        $tableName = self::$tableNames['dropif'];
        $schema = new Schema(self::$connection, $tableName);

        static::assertFalse(self::$connection->hasTable($tableName));
        static::assertSame($schema, $schema->dropIf());

        $schema->create(function(Table $table){
            $table->addColumn('id')->mediumint(8);
        });
        static::assertTrue(self::$connection->hasTable($tableName));
        static::assertSame($schema, $schema->dropIf());
        static::assertFalse(self::$connection->hasTable($tableName));

        if (self::$connection->isCheckForeign()) {
            try {
                static::getSchema('bind')->dropIf();
                static::fail('It should throw exception if drop constraint table');
            } catch (\PHPUnit_Exception $e) {
                throw $e;
            } catch (\Exception $e) {
                // do nothing
            }
        }
    }

    // 创建表, 验证字段是否符合预期, 由于字段映射问题, 不同数据库可能不同
    // 此处仅验证字段 个数/名称 是否完全相符
    public function testCreateTableMethod()
    {
        $tableName = self::$tableNames['column'];
        $schema = new Schema(self::$connection, $tableName);
        $schema->create(function(Table $table){
            $table->addColumn('id')->int()->auto();
            $table->addColumn('aid')->int();
            $table->addColumn('bid')->int();
            $table->addColumn('cid')->int();
            $table->addColumn('did')->int();
            $table->addColumn('eid')->int();
        });
        $columns = $schema->columns();
        static::assertEquals(['id', 'aid', 'bid', 'cid', 'did', 'eid'], array_keys($columns));
        static::assertTrue($columns['id']->auto);
        $schema->drop();
    }

    // 测试 datetime timestamp 字段
    public function testCreateTableContainDateColumn()
    {
        $now = time() - 2;
        $nowTime = date('Y-m-d H:i:s', $now);
        $tableName = self::$tableNames['column'];
        $schema = new Schema(self::$connection, $tableName);
        $schema->create(function(Table $table){
            $table->addColumn('id')->int()->auto();
            $table->addColumn('fid')->int()->default(0);
            $table->addColumn('foo')->datetime();
            $table->addColumn('bar')->timestamp();
        });
        self::$connection->execute("INSERT INTO $tableName (fid,foo) VALUES (1, '$nowTime')");
        $row = self::$connection->fetchOne("SELECT * FROM $tableName");
        $foo = (new \DateTime($row->foo))->getTimestamp();
        $bar = (new \DateTime($row->bar))->getTimestamp();

        static::assertEquals($foo, $now);
        static::assertNotEquals($bar, $now);
        static::assertTrue($bar - $now < 5);
        $schema->drop();

        // default value  = null
        $schema->create(function(Table $table){
            $table->addColumn('id')->int()->auto();
            $table->addColumn('fid')->int()->default(0);
            $table->addColumn('foo')->datetime()->default(null);
            $table->addColumn('bar')->timestamp()->default(null);
        });
        self::$connection->execute("INSERT INTO $tableName (fid,foo) VALUES (1, '$nowTime')");
        $row = self::$connection->fetchOne("SELECT * FROM $tableName");
        $foo = (new \DateTime($row->foo))->getTimestamp();
        static::assertEquals($foo, $now);
        static::assertNull($row->bar);
        $schema->drop();
    }

    // 创建表, 测试禁用外键约束的情况
    public function testCreateTableMethodWithDisableForeign()
    {
        $tableName = self::$tableNames['disableForeign'];
        $config = self::$connectionConfig;
        $config['dis_foreign'] = true;
        $connection = new Connection('PHPUNIT_DISABLEFOREIGN', $config);
        $schema =  new Schema($connection, $tableName);

        try {
            $schema->create(function(Table $table) use ($tableName) {
                $table->addColumn('id')->int()->auto();
                $table->addColumn('aid')->int();
                $table->addColumn('bid')->int();
                $table->addIndex('aid')->unique();

                $table->addForeign($tableName.'bid_foreign')->column(['bid'])->reference('aid');
            });
            static::fail('It should throw exception when create foreign key constraint');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // do nothing
        }
        static::assertTrue(true);
    }

    /**
     * 准备一个 外键约束表  用于测试 表创建/修改
     * @return Schema
     * @throws Exception
     * @throws Throwable
     */
    protected function preparedForeignTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return true;
        }
        if (!self::$foreignTablePrepared) {
            static::dropIfTableBySchema(static::getSchema('foreign'))->create(function(Table $table) {
                $table->addColumn('id')->int(10)->unsigned(false)->auto();

                $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
                $table->addColumn('uid')->mediumint(8)->unsigned()->default(0);
                $table->addColumn('mid')->mediumint(8)->unsigned()->default(0);

                $table->addColumn('pid')->tinyint(3)->unsigned()->default(0);
                $table->addColumn('sid')->tinyint(3)->unsigned()->default(0);
                $table->addColumn('fid')->tinyint(3)->unsigned()->default(0);
                $table->addColumn('nid')->tinyint(3)->unsigned()->default(0);

                $table->addIndex(self::$tableNames['foreign'].'_sid')->column(['sid'])->unique();
                $table->addIndex(self::$tableNames['foreign'].'_tid')->column(['tid'])->unique();
                $table->addIndex(self::$tableNames['foreign'].'_mid')->column(['mid','fid'])->unique();
                $table->addIndex(self::$tableNames['foreign'].'_pid')->column(['pid'])->unique();
                $table->addIndex(self::$tableNames['foreign'].'_nid')->column(['nid','mid'])->unique();
            });
            self::$foreignTablePrepared = true;
        }
        return static::getSchema('foreign');
    }

    /**
     * 测试创建表 成功的语句
     * @param $call
     * @return Schema
     * @throws Exception
     * @throws Throwable
     */
    protected function createSuccess($call)
    {
        $this->preparedForeignTable();
        $schema = static::dropIfTableBySchema(static::getSchema('create'));
        static::assertSame($schema, $schema->create($call));
        static::assertTrue(self::$connection->hasTable(self::$tableNames['create']));
        return $schema;
    }

    /**
     * 测试创建表 失败的语句
     * @param $call
     * @throws Exception
     * @throws Throwable
     */
    protected function createFailed($call)
    {
        $this->preparedForeignTable();
        $schema = static::dropIfTableBySchema(static::getSchema('create'));
        try {
            $schema->create($call);
            static::fail('It should throw exception if table config error');
        } catch (DatabaseException $e) {
            //var_dump($e->getMessage());
            static::assertTrue(true);
        }
    }



    // 失败: 创建无字段表
    public function testCreateTableWithoutColumn()
    {
        $this->createFailed(function(Table $table) {
        });
    }

    // 失败: 创建无字段表
    public function testCreateTableWithSameNameColumn()
    {
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10);
            $table->addColumn('id')->int(10);
        });
    }

    // 失败 : 创建表 - 两个 primary 索引
    public function testCreateTableWithPrimaryMultiple()
    {
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false);
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addIndex(['id'])->primary();
            $table->addIndex(['tid'])->primary();
        });
    }

    // 失败 : 创建表 - 指定 auto / primary,   但 primary 未匹配
    public function testCreateTablePrimaryNotMatchAutoColumn()
    {
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addIndex(['tid'])->primary();
        });
    }

    // 成功  : 创建表 - 指定 auto / primary 且匹配
    public function testCreateTablePrimaryMatchAutoColumn()
    {
        $this->createSuccess(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addIndex(['id'])->primary();
        });
    }

    // 成功 : 创建表 - 仅指定 auto
    public function testCreateTableWithAutoColumn()
    {
        $this->createSuccess(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
        });
    }

    /**
     * 创建表 - 指定 auto / primary,   但 primary 与 auto 不完全相等, 不同数据库可能表现不一致
     * @param $success
     */
    protected function createTablePrimaryNotEqualsAutoColumn($success)
    {
        $method = $success ? 'createSuccess' : 'createFailed';
        $this->$method(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addIndex(['id', 'tid'])->primary();
        });
    }




    // 失败: 创建外键 - 同表 - 外键 不存在
    public function testCreateTableForeignColumnNotExistInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->tinyint(3)->unsigned()->default(0);
            $table->addIndex(['fid']);
            $table->addForeign(['tid'])->reference(['rid']);
        });
    }

    // 失败 : 创建外键 - 同表 - 个数不匹配
    public function testCreateTableWithForeignNumberNotMatchInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->tinyint(3)->unsigned()->default(0);
            $table->addIndex(['fid']);
            $table->addForeign(['tid'])->reference(['fid','id']);
        });
    }

    // 失败 : 创建外键 - 同表 - 类型不完全相同,  索引不完全相同
    public function testCreateTableWithForeignTypeNotMatchInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->tinyint(3)->unsigned()->default(0);
            $table->addIndex(['fid']);
            $table->addForeign(['tid'])->reference(['fid'])->onDelete($table::FOREIGN_ACTION_CASCADE);
        });
    }

    // 失败 : 创建外键 - 同表 - 类型匹配 但外键无索引
    public function testCreateTableWithOutForeignKeyIndexInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['fid'])->onDelete($table::FOREIGN_ACTION_CASCADE);
        });
    }

    // 成功: 创建外键 - 同表 - 类型完全相同, 索引不完全相同  不同数据库可能表现不一致
    public function testCreateTableWithForeignMatchInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createSuccess(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addIndex(['tid'])->unique();
            $table->addForeign(['fid'])->reference(['tid']);
        });
    }

    /**
     * 创建外键 - 同表 - 类型不匹配, 索引相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function createTableForeignNotEqualsTypeInSameTable($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'createSuccess' : 'createFailed';
        $this->$method(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->tinyint(3)->unsigned()->default(0);
            $table->addIndex(['fid'])->unique();
            $table->addForeign(['tid'])->reference(['fid'])->onDelete($table::FOREIGN_ACTION_CASCADE);
        });
    }

    /**
     * 创建外键 - 同表 - 类型匹配, 索引不相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function createTableForeignNotEqualsIndexInSameTable($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'createSuccess' : 'createFailed';
        $this->$method(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addIndex(['fid', 'tid'])->unique();
            $table->addForeign(['tid'])->reference(['fid']);
        });
    }





    // 失败: 创建外键 - 异表 - 外键 不存在
    public function testCreateTableForeignColumnNotExist()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['rid'])->table(self::$tableNames['foreign']);
        });
    }

    // 失败 : 创建外键 - 异表 - 个数不匹配
    public function testCreateTableWithForeignNumberNotMatch()
    {
        if (!self::$connection->isCheckForeign()) {
            static::assertTrue(true);
            return ;
        }
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['fid','mid'])->table(self::$tableNames['foreign']);
        });
    }

    // 失败 : 创建外键 - 异表 - 类型不匹配,  索引不相同
    public function testCreateTableWithForeignTypeNotMatch()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['fid'])->table(self::$tableNames['foreign']);
        });
    }

    // 失败 : 创建外键 - 异表 - 类型匹配, 无索引
    public function testCreateTableWithOutForeignKeyIndex()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createFailed(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['uid'])->table(self::$tableNames['foreign']);
        });
    }

    // 成功: 创建外键 - 异表 - 类型匹配, 索引相同
    public function testCreateTableWithForeignMatch()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->createSuccess(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['tid'])->table(self::$tableNames['foreign']);
        });
    }

    /**
     * 创建外键 - 异表 - 类型不匹配, 索引相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function createTableForeignNotEqualsType($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'createSuccess' : 'createFailed';
        $this->$method(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['pid'])->table(self::$tableNames['foreign']);
        });
    }

    /**
     * 创建外键 - 异表 - 类型匹配, 索引不相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function createTableForeignNotEqualsIndex($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'createSuccess' : 'createFailed';
        $this->$method(function(Table $table) {
            $table->addColumn('id')->int(10)->unsigned(false)->auto();
            $table->addColumn('tid')->mediumint(8)->unsigned()->default(0);
            $table->addColumn('fid')->mediumint(8)->unsigned()->default(0);
            $table->addForeign(['tid'])->reference(['mid'])->table(self::$tableNames['foreign']);
        });
    }


    protected function preparedAlterTable($isForeign = false)
    {
        $schema = $this->preparedForeignTable();
        if (!self::$alterTablePrepared) {
            static::dropIfTableBySchema(static::getSchema('alter'))->create(function(Table $table) {
                $table->addColumn('id')->int(10)->unsigned(false)->auto();

                $table->addColumn('tid')->mediumint(8)->unsigned();
                $table->addColumn('fid')->mediumint(8)->unsigned();
                $table->addColumn('mid')->mediumint(8)->unsigned();
                $table->addColumn('xid')->mediumint(8)->unsigned();
                $table->addColumn('sid')->tinyint(3)->unsigned();
                $table->addColumn('kid')->mediumint(8)->unsigned();
                $table->addColumn('pid')->tinyint(3)->unsigned();

                $table->addIndex(self::$tableNames['alter'].'_tid')->column(['tid'])->unique();
                $table->addIndex(self::$tableNames['alter'].'_pid')->column(['pid'])->unique();
                $table->addIndex(self::$tableNames['alter'].'_fid')->column(['fid'])->unique();
                $table->addIndex(self::$tableNames['alter'].'_xid')->column(['xid','mid'])->unique();
                $table->addIndex(self::$tableNames['alter'].'_kid')->column(['kid'])->unique();
                $table->addIndex(self::$tableNames['alter'].'_sid')->column(['sid'])->unique();

                if (self::$connection->isCheckForeign()) {
                    $table->addForeign(self::$tableNames['alter'].'_foreign_tid')->column(['tid'])->reference(['fid']);
                    $table->addForeign(self::$tableNames['alter'].'_foreign_pid')->column(['pid'])
                        ->reference(['pid'])->table(self::$tableNames['foreign']);
                }
            });
            self::$alterTablePrepared = true;
        }
        return $isForeign ? $schema : static::getSchema('alter');
    }

    /**
     * 测试修改表 成功的语句
     * @param $call
     * @throws Exception
     * @throws Throwable
     */
    protected function alterSuccess($call)
    {
        $schema = $this->preparedAlterTable();
        static::assertSame($schema, $schema->alter($call));
    }

    /**
     * 测试修改表 失败的语句
     * @param $call
     * @throws Exception
     * @throws Throwable
     */
    protected function alterFailed($call)
    {
        try {
            $this->preparedAlterTable()->alter($call);
            static::fail('It should throw exception if table config error');
        } catch (DatabaseException $e) {
            //var_dump($e->getMessage());
            static::assertTrue(true);
        }
    }

    /**
     * 测试修改表 成功的语句
     * @param $call
     * @throws Exception
     * @throws Throwable
     */
    protected function alterForeignSuccess($call)
    {
        $schema = $this->preparedAlterTable(true);
        static::assertSame($schema, $schema->alter($call));
    }

    /**
     * 测试修改表 失败的语句
     * @param $call
     * @throws Exception
     * @throws Throwable
     */
    protected function alterForeignFailed($call)
    {
        try {
            $this->preparedAlterTable(true)->alter($call);
            static::fail('It should throw exception if table config error');
        } catch (DatabaseException $e) {
            //var_dump($e->getMessage());
            static::assertTrue(true);
        }
    }


    // 失败: 修改字段名 相同
    public function testAlterTableUseSameColumnName()
    {
        $this->alterFailed(function(Table $table) {
            $table->alterColumn('tid')->rename('foo');
            $table->alterColumn('mid')->rename('foo');
        });
    }

    // 失败: 修改表 - 制造第二个  auto
    public function testAlterTableWithEditMultipleAuto()
    {
        $this->alterFailed(function(Table $table) {
            $table->alterColumn('tid')->mediumint(8)->auto();
        });
    }

    // 失败: 修改表 - 尝试添加第二个  auto
    public function testAlterTableWithAddMultipleAuto()
    {
        $this->alterFailed(function(Table $table) {
            $table->addColumn('mid')->mediumint(8)->auto();
        });
    }

    // 失败: 修改表 - 取消原 auto 新修改为 auto 的字段没有索引
    public function testAlterTableWithEditAutoWithoutIndex()
    {
        $this->alterFailed(function(Table $table) {
            $table->alterColumn('id')->auto(false);
            $table->alterColumn('fid')->mediumint(8)->auto();
        });
    }

    // 失败: 修改表 - 删除原 auto 新修改为 auto 的字段没有索引
    public function testAlterTableWithAddAutoWithoutIndex()
    {
        $this->alterFailed(function(Table $table) {
            $table->dropColumn('id');
            $table->alterColumn('fid')->mediumint(8)->auto();
        });
    }

    // 成功: 修改表 - 指定  auto 字段 且 primary 匹配成功
    public function testAlterTableWithAutoMatchIndex()
    {
        $this->alterSuccess(function(Table $table) {
            $table->alterColumn('id')->auto(false);
            $table->alterColumn('mid')->auto()->rename('foo');
            $table->alterIndex('primary')->column(['mid']);
        });
        // 复原
        $this->alterSuccess(function(Table $table) {
            $table->alterColumn('id')->auto();
            $table->alterColumn('foo')->auto(false)->rename('mid');
            $table->alterIndex('primary')->column(['id']);
        });
    }

    // 成功  : 修改表 - 修改删除字段, 对应索引字段同步更新
    public function testAlterTableIndexChangeAfterChangeColumn()
    {
        $this->alterSuccess(function(Table $table) {
            $table->alterColumn('xid', 'nid');
            $table->dropColumn('sid');
            $table->dropColumn('mid');
        });

        $indexes = static::getSchema('alter')->indexes();
        static::assertArrayHasKey(self::$tableNames['alter'].'_xid', $indexes);
        static::assertEquals(['nid'], $indexes[self::$tableNames['alter'].'_xid']->column);
        static::assertArrayNotHasKey(self::$tableNames['alter'].'_sid', $indexes);

        // 复原
        $this->alterSuccess(function(Table $table) {
            $table->alterColumn('nid', 'xid');
            $table->addColumn('sid')->tinyint(3)->unsigned()->after('xid');
            $table->addColumn('mid')->mediumint(8)->unsigned()->after('fid');

            $table->alterIndex(self::$tableNames['alter'].'_xid')->column(['xid','mid']);
            $table->addIndex(self::$tableNames['alter'].'_sid')->column(['sid'])->unique();
        });

        $indexes = static::getSchema('alter')->indexes();
        static::assertArrayHasKey(self::$tableNames['alter'].'_xid', $indexes);
        static::assertEquals(['xid','mid'], $indexes[self::$tableNames['alter'].'_xid']->column);
        static::assertArrayHasKey(self::$tableNames['alter'].'_sid', $indexes);
        static::assertEquals(['sid'], $indexes[self::$tableNames['alter'].'_sid']->column);
    }

    /**
     * 修改表 - 指定 auto / primary,   但 primary 与 auto 不完全相等, 不同数据库可能表现不一致
     * @param $success
     */
    protected function alterTablePrimaryNotEqualsAutoColumn($success)
    {
        $method = $success ? 'alterSuccess' : 'alterFailed';
        $this->$method(function(Table $table) {
            $table->alterColumn('id')->auto(false);
            $table->alterColumn('mid')->auto();
            $table->alterIndex('primary')->column(['mid','tid']);
        });
        if ($success) {
            $this->alterSuccess(function(Table $table) {
                $table->alterColumn('id')->auto();
                $table->alterColumn('mid')->auto(false);
                $table->alterIndex('primary')->column(['id']);
            });
        }
    }



    // 失败: 修改外键 - 同表 - 外键 不存在
    public function testAlterTableForeignColumnNotExistInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['tid'])->reference(['rid']);
        });
    }

    // 失败:  修改外键 - 同表 - 个数不匹配
    public function testAlterTableForeignNumberNotMatchInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['tid'])->reference(['pid','mid']);
        });
    }

    //  失败:  修改外键 - 同表 - 类型不匹配  无索引
    public function testAlterTableWithOutForeignKeyIndexInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['tid'])->reference(['pid']);
        });
    }

    //  失败:  修改外键 - 同表 - 类型匹配  索引不匹配
    public function testAlterTableForeignMatchWithOutKeyIndexInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['tid'])->reference(['mid']);
        });
    }

    //  成功:  修改外键 - 同表 - 类型匹配  索引相同
    public function testAlterTableForeignMatchInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterSuccess(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_tid')->reference(['kid']);
        });
        // 复原
        $this->alterSuccess(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_tid')->reference(['fid']);
        });
    }

    /**
     * 修改外键 - 同表 - 类型不匹配, 索引相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function alterTableForeignNotEqualsTypeInSameTable($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'alterSuccess' : 'alterFailed';
        $this->$method(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_tid')->reference(['sid']);
        });
        // 复原
        if ($success) {
            $this->alterSuccess(function(Table $table) {
                $table->alterForeign(self::$tableNames['alter'].'_foreign_tid')->reference(['fid']);
            });
        }
    }

    /**
     * 修改外键 - 同表 - 类型匹配, 索引不相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function alterTableForeignNotEqualsIndexInSameTable($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'alterSuccess' : 'alterFailed';
        $this->$method(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_tid')->reference(['xid']);
        });
        // 复原
        if ($success) {
            $this->alterSuccess(function(Table $table) {
                $table->alterForeign(self::$tableNames['alter'].'_foreign_tid')->reference(['fid']);
            });
        }
    }




    // 失败: 修改外键 - 异表 - 外键 不存在
    public function testAlterTableForeignColumnNotExist()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['pid'])->reference(['xid']);
        });
    }

    // 失败:  修改外键 - 异表 - 个数不匹配
    public function testAlterTableForeignNumberNotMatch()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['pid'])->reference(['tid','sid']);
        });
    }

    //  失败:  修改外键 - 异表 - 类型不匹配  无索引
    public function testAlterTableWithOutForeignKeyIndex()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['pid'])->reference(['uid']);
        });


    }

    //  失败:  修改外键 - 异表 - 类型匹配  索引不匹配
    public function testAlterTableForeignMatchWithOutKeyIndex()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->alterForeign(['pid'])->reference(['fid']);
        });
    }

    //  成功:  修改外键 - 异表 - 类型匹配  索引相同
    public function testAlterTableForeignMatch()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterSuccess(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_pid')->reference(['sid']);
        });
        // 复原
        $this->alterSuccess(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_pid')->reference(['pid']);
        });
    }

    /**
     * 修改外键 - 异表 - 类型不匹配, 索引相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function alterTableForeignNotEqualsType($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'alterSuccess' : 'alterFailed';
        $this->$method(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_pid')->reference(['tid']);
        });
        // 复原
        if ($success) {
            $this->alterSuccess(function(Table $table) {
                $table->alterForeign(self::$tableNames['alter'].'_foreign_pid')->reference(['pid']);
            });
        }
    }

    /**
     * 修改外键 - 异表 - 类型匹配, 索引不相同  不同数据库可能表现不一致
     * @param $success
     * @return bool
     */
    protected function alterTableForeignNotEqualsIndex($success)
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $method = $success ? 'alterSuccess' : 'alterFailed';
        $this->$method(function(Table $table) {
            $table->alterForeign(self::$tableNames['alter'].'_foreign_pid')->reference(['nid']);
        });
        // 复原
        if ($success) {
            $this->alterSuccess(function(Table $table) {
                $table->alterForeign(self::$tableNames['alter'].'_foreign_pid')->reference(['pid']);
            });
        }
    }





    //  失败: 修改外键约束 - 同表 - 删除约束字段
    public function testDropForeignTableColumnInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->dropColumn('tid');
        });
    }

    //  失败: 修改外键约束 - 同表 - 删除外键字段
    public function testDropForeignTableReferenceInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->dropColumn('fid');
        });
    }

    //  失败: 修改外键约束 - 同表 - 删除外键字段索引
    public function testDropForeignTableIndexInSameTable()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->dropIndex(self::$tableNames['alter'].'_fid');
        });
    }



    //  失败: 修改外键约束 - 异表 - 删除约束字段
    public function testDropForeignTableColumn()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterFailed(function(Table $table) {
            $table->dropColumn('pid');
        });
    }

    //  失败: 修改外键约束 - 异表 - 删除外键字段
    public function testDropForeignTableReference()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterForeignFailed(function(Table $table) {
            $table->dropColumn('pid');
        });
    }

    //  失败: 修改外键约束 - 同表 - 删除外键字段索引
    public function testDropForeignTableIndex()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterForeignFailed(function(Table $table) {
            $table->dropIndex(self::$tableNames['foreign'].'_pid');
        });
    }

    //  失败: 修改字段名称后, 将字段设置为约束外键
    public function testDropForeignColumnAfterRename()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterForeignFailed(function(Table $table) {
            $table->dropColumn('sid');
            $table->alterForeign(self::$tableNames['alter'].'_foreign_pid')->reference(['sid']);
        });
    }

    //  成功: 先删除外键约束, 再删除外键约束字段
    public function testDropForeignColumnAfterDropForeign()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterSuccess(function(Table $table) {
            $table->dropColumn('tid');
            $table->dropColumn('pid');
            $table->dropForeign(self::$tableNames['alter'].'_foreign_tid');
            $table->dropForeign(self::$tableNames['alter'].'_foreign_pid');
        });

        $indexes = static::getSchema('alter')->indexes();
        static::assertArrayNotHasKey(self::$tableNames['alter'].'_tid', $indexes);
        static::assertArrayNotHasKey(self::$tableNames['alter'].'_pid', $indexes);
        static::assertCount(0, static::getSchema('alter')->foreigners());

        // 复原
        $this->alterSuccess(function(Table $table) {
            $table->addColumn('tid')->mediumint(8)->unsigned()->after('id');
            $table->addColumn('pid')->tinyint(3)->unsigned()->after('kid');

            $table->addIndex(self::$tableNames['alter'].'_tid')->column(['tid'])->unique();
            $table->addIndex(self::$tableNames['alter'].'_pid')->column(['pid'])->unique();

            $table->addForeign(self::$tableNames['alter'].'_foreign_tid')->column(['tid'])->reference(['fid']);
            $table->addForeign(self::$tableNames['alter'].'_foreign_pid')->column(['pid'])
                ->reference(['pid'])->table(self::$tableNames['foreign']);
        });
    }


    // 修改字段名称,  对应外键约束 的 约束字段 也要修改
    public function testAlterForeignColumnName()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }

        $this->alterSuccess(function(Table $table){
            $table->alterColumn('tid')->rename('vid');
            $table->alterColumn('pid', 'nid');
        });

        $foreigners = static::getSchema('alter')->foreigners();
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_tid', $foreigners);
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_pid', $foreigners);
        static::assertEquals(['vid'], $foreigners[self::$tableNames['alter'].'_foreign_tid']->column);
        static::assertEquals(['nid'], $foreigners[self::$tableNames['alter'].'_foreign_pid']->column);

        // 复原
        $this->alterSuccess(function(Table $table){
            $table->alterColumn('vid')->rename('tid');
            $table->alterColumn('nid', 'pid');
        });

        $foreigners = static::getSchema('alter')->foreigners();
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_tid', $foreigners);
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_pid', $foreigners);
        static::assertEquals(['tid'], $foreigners[self::$tableNames['alter'].'_foreign_tid']->column);
        static::assertEquals(['pid'], $foreigners[self::$tableNames['alter'].'_foreign_pid']->column);
    }

    // 修改字段名称,  对应外键约束 的 外键字段 也要修改
    public function testAlterForeignReferenceColumnName()
    {
        if (!self::$connection->isCheckForeign()) {
            return ;
        }
        $this->alterSuccess(function(Table $table){
            $table->alterColumn('fid')->rename('vid');
        });
        $this->alterForeignSuccess(function(Table $table){
            $table->alterColumn('pid')->rename('vid');
        });

        $foreigners = static::getSchema('alter')->foreigners();
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_tid', $foreigners);
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_pid', $foreigners);
        static::assertEquals(['vid'], $foreigners[self::$tableNames['alter'].'_foreign_tid']->reference);
        static::assertEquals(['vid'], $foreigners[self::$tableNames['alter'].'_foreign_pid']->reference);

        // 复原
        $this->alterSuccess(function(Table $table){
            $table->alterColumn('vid')->rename('fid');
        });
        $this->alterForeignSuccess(function(Table $table) {
            $table->alterColumn('vid')->rename('pid');
        });

        $foreigners = static::getSchema('alter')->foreigners();
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_tid', $foreigners);
        static::assertArrayHasKey(self::$tableNames['alter'].'_foreign_pid', $foreigners);
        static::assertEquals(['fid'], $foreigners[self::$tableNames['alter'].'_foreign_tid']->reference);
        static::assertEquals(['pid'], $foreigners[self::$tableNames['alter'].'_foreign_pid']->reference);
    }
}
