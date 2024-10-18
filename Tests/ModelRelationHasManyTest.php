<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelRelationHasManyTest extends TestCase
{
    protected static $dbPath = 'ModelRelationHasManyTest';

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
//        Database::setQueryListener(function(\Tanbolt\Database\Sql $sql) {
//            var_dump((string) $sql);
//        });

        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
        ]], true);
        $connection = (new ModelRelationHasManyTest_Foo)->connection();

        $connection->execute("CREATE TABLE `foo` (
            `fid`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` INTEGER
        )");

        $connection->execute("CREATE TABLE `bar` (
            `bid`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `foo_id` INTEGER,
            `x` INTEGER
        )");

        $connection->execute("CREATE TABLE `biz` (
            `bid`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` INTEGER
        )");
        $connection->execute("CREATE TABLE `foo_biz` (
            `foo_id` INTEGER,
            `biz_id` INTEGER,
            `x` INTEGER
        )");

        $connection->execute("CREATE TABLE `que` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `qid` INTEGER,
            `x` INTEGER
        )");
        $connection->execute("CREATE TABLE `foo_que` (
            `foo_id` INTEGER,
            `que_id` INTEGER,
            `x` INTEGER
        )");

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass():void
    {
        Database::getNode()->disconnect();
        Database::clearNode();
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        parent::tearDownAfterClass();
    }
    
    protected function tearDown():void
    {
        // clear foo
        $statement = 'DELETE FROM `foo`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'foo'");
        
        // clear bar
        $statement = 'DELETE FROM `bar`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'bar'");
        
        // clear biz
        $statement = 'DELETE FROM `biz`';
        Database::getNode()->execute($statement);
        $statement = 'DELETE FROM `foo_biz`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'biz'");
        
        // clear que
        $statement = 'DELETE FROM `que`';
        Database::getNode()->execute($statement);
        $statement = 'DELETE FROM `foo_que`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'que'");
        
        parent::tearDown();
    }

    public function testAddCollectionMethod()
    {
        $foo = ModelRelationHasManyTest_Foo::createModel(['x' => 1]);

        // add by array
        $bar = $foo->bar()->addCollection(['x' => 2]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['x' => 2, 'foo_id' => 1, 'bid' => 1]
        ], $bar->toArray());

        // add by model
        $bar = $foo->bar()->addCollection(new ModelRelationHasManyTest_Bar(['x' => 3]));
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['x' => 3, 'foo_id' => 1, 'bid' => 2]
        ], $bar->toArray());

        // add by array
        $bar = $foo->bar()->addCollection([
            ['x' => 4],
            ['x' => 5],
        ]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['x' => 4, 'foo_id' => 1, 'bid' => 3],
            ['x' => 5, 'foo_id' => 1, 'bid' => 4],
        ], $bar->toArray());

        // add by model array
        $bar = $foo->bar()->addCollection([
            new ModelRelationHasManyTest_Bar(['x' => 6]),
            new ModelRelationHasManyTest_Bar(['x' => 7]),
        ]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['x' => 6, 'foo_id' => 1, 'bid' => 5],
            ['x' => 7, 'foo_id' => 1, 'bid' => 6],
        ], $bar->toArray());

        // add by mixing
        $bar = $foo->bar()->addCollection([
            new ModelRelationHasManyTest_Bar(['x' => 8]),
            ['x' => 9],
            new ModelRelationHasManyTest_Bar(['x' => 10]),
        ]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['x' => 8, 'foo_id' => 1, 'bid' => 7],
            ['x' => 9, 'foo_id' => 1, 'bid' => 8],
            ['x' => 10, 'foo_id' => 1, 'bid' => 9],
        ], $bar->toArray());

        // add by collection
        $bar = $foo->bar()->addCollection(ModelRelationHasManyTest_Bar::newCollection([
            ['x' => 11],
            ['x' => 12],
        ]));
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['x' => 11, 'foo_id' => 1, 'bid' => 10],
            ['x' => 12, 'foo_id' => 1, 'bid' => 11],
        ], $bar->toArray());

        static::assertCount(11, $foo->connection()->fetchAll('select * from bar'));
    }

    public function testAddCollectionThroughMethod()
    {
        $foo = ModelRelationHasManyTest_Foo::createModel(['x' => 1]);

        $biz = $foo->biz()->addCollection([
            new ModelRelationHasManyTest_Biz(['x' => 8]),
            ['x' => 9],
            new ModelRelationHasManyTest_Biz(['x' => 10]),
        ], ['x' => 8]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $biz);
        static::assertEquals([
            [
                'x' => 8,
                'bid' => 1,
                'pivot' => [
                    'x' => 8,
                    'foo_id' => 1,
                    'biz_id' => 1
                ]
            ],
            [
                'x' => 9,
                'bid' => 2,
                'pivot' => [
                    'x' => 8,
                    'foo_id' => 1,
                    'biz_id' => 2
                ]
            ],
            [
                'x' => 10,
                'bid' => 3,
                'pivot' => [
                    'x' => 8,
                    'foo_id' => 1,
                    'biz_id' => 3
                ]
            ],
        ], $biz->toArray(true));

        static::assertCount(3, $foo->connection()->fetchAll('select * from biz'));
        static::assertCount(3, $foo->connection()->fetchAll('select * from foo_biz'));
    }

    public function testHoldMethod()
    {
        $foo = ModelRelationHasManyTest_Foo::createModel(['x' => 1]);

        // hold foo->bar
        ModelRelationHasManyTest_Bar::insert([
            ['foo_id' => 0, 'x' => 2],
            ['foo_id' => 0, 'x' => 3],
            ['foo_id' => 0, 'x' => 4],
            ['foo_id' => 0, 'x' => 5],
            ['foo_id' => 0, 'x' => 6],
            ['foo_id' => 0, 'x' => 7],
        ]);
        $bar = $foo->bar()->hold(2);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['foo_id' => 1, 'bid' => 2],
        ], $bar->toArray());
        static::assertCount(1, $foo->connection()->fetchAll('select * from bar where foo_id = ?', [1]));

        $bar = $foo->bar()->hold([1,3], ['x' => 10]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertEquals([
            ['foo_id' => 1, 'bid' => 1, 'x' => 10],
            ['foo_id' => 1, 'bid' => 3, 'x' => 10],
        ], $bar->toArray());
        static::assertCount(3, $foo->connection()->fetchAll('select * from bar where foo_id = ?', [1]));

        static::assertCount(3, $foo->bar()->hold(ModelRelationHasManyTest_Bar::where('bid', '>' ,3)->findMany(), ['x' => 11]));
        static::assertCount(1, $foo->bar()->hold(10));
        static::assertEquals([
            ['x' => 10, 'bid' => 1, 'foo_id' => 1],
            ['x' => 3, 'bid' => 2, 'foo_id' => 1],
            ['x' => 10, 'bid' => 3, 'foo_id' => 1],
            ['x' => 11, 'bid' => 4, 'foo_id' => 1],
            ['x' => 11, 'bid' => 5, 'foo_id' => 1],
            ['x' => 11, 'bid' => 6, 'foo_id' => 1],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        // hold foo->biz
        ModelRelationHasManyTest_Biz::insert([
            ['x' => 2],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
        ]);
        $biz = $foo->biz()->hold(2);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $biz);
        static::assertEquals([[
            'bid' => 2,
            'pivot' => [
                'foo_id' => 1,
                'biz_id' => 2
            ]
        ]], $biz->toArray(true));
        static::assertEquals([
            ['foo_id' => '1', 'biz_id' => '2', 'x' => null]
        ], $foo->connection()->fetchAll('select * from foo_biz', [], PDO::FETCH_ASSOC));

        static::assertCount(2, $foo->biz()->hold([1,3], ['x' => 10], ['x' => 6]));
        static::assertEquals([
            ['x' => 10],
            ['x' => 10],
        ], $foo->connection()->fetchAll('select x from biz where bid in (?,?)', [1,3], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => '1', 'biz_id' => '2', 'x' => null],
            ['foo_id' => '1', 'biz_id' => '1', 'x' => 6],
            ['foo_id' => '1', 'biz_id' => '3', 'x' => 6],
        ], $foo->connection()->fetchAll('select * from foo_biz', [], PDO::FETCH_ASSOC));

        static::assertCount(2, $foo->biz()->hold([3,4], ['x' => 11], ['x' => 7]));
        static::assertEquals([
            ['x' => 10],
            ['x' => 11],
            ['x' => 11],
        ], $foo->connection()->fetchAll('select x from biz where bid in (?,?,?)', [1,3,4], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => '1', 'biz_id' => '2', 'x' => null],
            ['foo_id' => '1', 'biz_id' => '1', 'x' => 6],
            ['foo_id' => '1', 'biz_id' => '3', 'x' => 7],
            ['foo_id' => '1', 'biz_id' => '4', 'x' => 7],
        ], $foo->connection()->fetchAll('select * from foo_biz', [], PDO::FETCH_ASSOC));

        // hold foo->que
        ModelRelationHasManyTest_Que::insert([
            ['qid' => 2, 'x' => 3],
            ['qid' => 3, 'x' => 4],
            ['qid' => 4, 'x' => 5],
            ['qid' => 5, 'x' => 6],
        ]);
        static::assertCount(1, $foo->que()->hold(2));
        static::assertEquals([
            ['foo_id' => '1', 'que_id' => '3', 'x' => null]
        ], $foo->connection()->fetchAll('select * from foo_que', [], PDO::FETCH_ASSOC));

        // 更换主模型
        $foo2 = ModelRelationHasManyTest_Foo::createModel(['x' => 2]);
        static::assertCount(3, $foo2->que()->hold([1,2,3], ['x' => 10], ['x' => 6]));
        static::assertEquals([
            ['x' => 10],
            ['x' => 10],
            ['x' => 10],
        ], $foo->connection()->fetchAll('select x from que where id in (?,?,?)', [1,2,3], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => '2', 'que_id' => '3', 'x' => 6], // 不会添加一条新的 中间表数据
            ['foo_id' => '2', 'que_id' => '2', 'x' => 6],
            ['foo_id' => '2', 'que_id' => '4', 'x' => 6],
        ], $foo->connection()->fetchAll('select * from foo_que', [], PDO::FETCH_ASSOC));

        // 再次换模型
        static::assertCount(2, $foo->que()->hold([3, 4], ['x' => 11], ['x' => 7]));
        static::assertEquals([
            ['x' => 10],
            ['x' => 11],
            ['x' => 11],
        ], $foo->connection()->fetchAll('select x from que where id in (?,?,?)', [1,3,4], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => '2', 'que_id' => '3', 'x' => 6],
            ['foo_id' => '2', 'que_id' => '2', 'x' => 6],
            ['foo_id' => '1', 'que_id' => '4', 'x' => 7],
            ['foo_id' => '1', 'que_id' => '5', 'x' => 7],
        ], $foo->connection()->fetchAll('select * from foo_que', [], PDO::FETCH_ASSOC));
    }

    public function testHoldSharedMethod()
    {
        $foo = ModelRelationHasManyTest_Foo::createModel(['x' => 1]);
        $foo2 = ModelRelationHasManyTest_Foo::createModel(['x' => 2]);
        ModelRelationHasManyTest_Que::insert([
            ['qid' => 2, 'x' => 3],
            ['qid' => 3, 'x' => 4],
            ['qid' => 4, 'x' => 5],
        ]);
        $que = $foo->que()->holdShared([1,2], ['x' => 10], ['x' => 6]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $que);
        static::assertCount(2, $que);
        static::assertEquals([
            [

                'id' => 1,
                'x' => 10,
                'pivot' => [
                    'x' => 6,
                    'foo_id' => 1,
                    'que_id' => 2
                ]
            ],
            [

                'id' => 2,
                'x' => 10,
                'pivot' => [
                    'x' => 6,
                    'foo_id' => 1,
                    'que_id' => 3
                ]
            ]
        ], $que->toArray(true));
        static::assertEquals([
            ['foo_id' => '1', 'que_id' => '2', 'x' => 6],
            ['foo_id' => '1', 'que_id' => '3', 'x' => 6],
        ], $foo->connection()->fetchAll('select * from foo_que', [], PDO::FETCH_ASSOC));

        $foo2->que()->holdShared([2, 3], ['x' => 9], ['x' => 7]);

        static::assertEquals([
            ['id' => 1,'x' => 10],
            ['id' => 2,'x' => 9],
            ['id' => 3,'x' => 9],
        ], $foo->connection()->fetchAll('select id, x from que', [], PDO::FETCH_ASSOC));

        static::assertEquals([
            ['foo_id' => '1', 'que_id' => '2', 'x' => 6],
            ['foo_id' => '1', 'que_id' => '3', 'x' => 6],
            ['foo_id' => '2', 'que_id' => '3', 'x' => 7],
            ['foo_id' => '2', 'que_id' => '4', 'x' => 7],
        ], $foo->connection()->fetchAll('select * from foo_que', [], PDO::FETCH_ASSOC));
    }

    public function testFreedMethod()
    {
        $foo = ModelRelationHasManyTest_Foo::createModel(['x' => 1]);

        // freed foo->bar
        $foo->bar()->addCollection([
            ['x' => 2],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
            ['x' => 6],
        ]);
        $bar = $foo->bar()->freed(2);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertCount(1, $bar);
        static::assertEquals([
            ['x' => 2, 'bid' => 1, 'foo_id' => 1],
            ['x' => 3, 'bid' => 2, 'foo_id' => 0],
            ['x' => 4, 'bid' => 3, 'foo_id' => 1],
            ['x' => 5, 'bid' => 4, 'foo_id' => 1],
            ['x' => 6, 'bid' => 5, 'foo_id' => 1],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        $foo->bar()->freed([1,5], ['x' => 8]);
        static::assertEquals([
            ['x' => 8, 'bid' => 1, 'foo_id' => 0],
            ['x' => 3, 'bid' => 2, 'foo_id' => 0],
            ['x' => 4, 'bid' => 3, 'foo_id' => 1],
            ['x' => 5, 'bid' => 4, 'foo_id' => 1],
            ['x' => 8, 'bid' => 5, 'foo_id' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        static::assertEquals(2, $foo->bar()->freed(null, ['x' => 9]));
        static::assertEquals([
            ['x' => 8, 'bid' => 1, 'foo_id' => 0],
            ['x' => 3, 'bid' => 2, 'foo_id' => 0],
            ['x' => 9, 'bid' => 3, 'foo_id' => 0],
            ['x' => 9, 'bid' => 4, 'foo_id' => 0],
            ['x' => 8, 'bid' => 5, 'foo_id' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        // freed foo->biz
        $foo->biz()->addCollection([
            ['x' => 2],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
            ['x' => 6],
        ], ['x' => 1]);
        $foo->biz()->freed(2);
        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 1],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 3],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 4],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 5],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));

        $biz = $foo->biz()->freed([1,5], ['x' => 8]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $biz);
        static::assertCount(2, $biz);
        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 3],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 4],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 8],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
            ['x' => 8],
        ], $foo->connection()->fetchAll('SELECT x FROM `biz`', [], PDO::FETCH_ASSOC));

        static::assertEquals(4, $foo->biz()->freed(null, ['x' => 9]));
        static::assertEquals([
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 8],
            ['x' => 3],
            ['x' => 9],
            ['x' => 9],
            ['x' => 8],
        ], $foo->connection()->fetchAll('SELECT x FROM `biz`', [], PDO::FETCH_ASSOC));

        // freed foo->que
        $foo->que()->addCollection([
            ['qid' => 2, 'x' => 2],
            ['qid' => 3, 'x' => 3],
            ['qid' => 4, 'x' => 4],
            ['qid' => 5, 'x' => 5],
            ['qid' => 6, 'x' => 6],
        ], ['x' => 1]);

        $que = $foo->que()->freed(2);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $que);
        static::assertCount(1, $que);
        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'que_id' => 2],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 4],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 5],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 6],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));

        $foo->que()->freed([1,5], ['x' => 8]);
        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'que_id' => 4],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 5],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['qid' => 2, 'x' => 8],
            ['qid' => 3, 'x' => 3],
            ['qid' => 4, 'x' => 4],
            ['qid' => 5, 'x' => 5],
            ['qid' => 6, 'x' => 8],
        ], $foo->connection()->fetchAll('SELECT qid,x FROM `que`', [], PDO::FETCH_ASSOC));

        $foo->que()->freed(null, ['x' => 9]);
        static::assertEquals([
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['qid' => 2, 'x' => 8],
            ['qid' => 3, 'x' => 3],
            ['qid' => 4, 'x' => 9],
            ['qid' => 5, 'x' => 9],
            ['qid' => 6, 'x' => 8],
        ], $foo->connection()->fetchAll('SELECT qid,x FROM `que`', [], PDO::FETCH_ASSOC));
    }

    public function testRemoveMethod()
    {
        $foo = ModelRelationHasManyTest_Foo::createModel(['x' => 1]);

        // remove foo->bar
        $foo->bar()->addCollection([
            ['x' => 2],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
            ['x' => 6],
        ]);
        $bar = $foo->bar()->remove(2);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar);
        static::assertCount(1, $bar);

        static::assertEquals([
            ['x' => 2, 'bid' => 1, 'foo_id' => 1],
            ['x' => 4, 'bid' => 3, 'foo_id' => 1],
            ['x' => 5, 'bid' => 4, 'foo_id' => 1],
            ['x' => 6, 'bid' => 5, 'foo_id' => 1],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        $foo->bar()->remove([1,5]);
        static::assertEquals([
            ['x' => 4, 'bid' => 3, 'foo_id' => 1],
            ['x' => 5, 'bid' => 4, 'foo_id' => 1],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        static::assertEquals(2, $foo->bar()->remove() );
        static::assertEquals([
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        // remove foo->biz
        $foo->biz()->addCollection([
            ['x' => 2],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
            ['x' => 6],
        ], ['x' => 1]);
        $foo->biz()->remove(2);
        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 1],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 3],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 4],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 5],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 2],
            ['x' => 4],
            ['x' => 5],
            ['x' => 6],
        ], $foo->connection()->fetchAll('SELECT x FROM `biz`', [], PDO::FETCH_ASSOC));

        $biz = $foo->biz()->remove([1,5]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $biz);
        static::assertCount(2, $biz);

        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 3],
            ['x' => 1, 'foo_id' => 1, 'biz_id' => 4],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 4],
            ['x' => 5],
        ], $foo->connection()->fetchAll('SELECT x FROM `biz`', [], PDO::FETCH_ASSOC));

        static::assertEquals(4, $foo->biz()->remove());
        static::assertEquals([
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
        ], $foo->connection()->fetchAll('SELECT x FROM `biz`', [], PDO::FETCH_ASSOC));

        // freed foo->que
        $foo->que()->addCollection([
            ['qid' => 2, 'x' => 2],
            ['qid' => 3, 'x' => 3],
            ['qid' => 4, 'x' => 4],
            ['qid' => 5, 'x' => 5],
            ['qid' => 6, 'x' => 6],
        ], ['x' => 1]);

        $foo->que()->remove(2);
        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'que_id' => 2],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 4],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 5],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 6],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['qid' => 2, 'x' => 2],
            ['qid' => 4, 'x' => 4],
            ['qid' => 5, 'x' => 5],
            ['qid' => 6, 'x' => 6],
        ], $foo->connection()->fetchAll('SELECT qid,x FROM `que`', [], PDO::FETCH_ASSOC));

        $foo->que()->remove([1,5]);
        static::assertEquals([
            ['x' => 1, 'foo_id' => 1, 'que_id' => 4],
            ['x' => 1, 'foo_id' => 1, 'que_id' => 5],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['qid' => 4, 'x' => 4],
            ['qid' => 5, 'x' => 5],
        ], $foo->connection()->fetchAll('SELECT qid,x FROM `que`', [], PDO::FETCH_ASSOC));

        $foo->que()->remove();
        static::assertEquals([
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
        ], $foo->connection()->fetchAll('SELECT qid,x FROM `que`', [], PDO::FETCH_ASSOC));
    }
}

class ModelRelationHasManyTest_Foo extends Model
{
    protected $tableName = 'foo';

    protected $primaryColumn = 'fid';

    /**
     * @return Model\Relation\HasMany
     */
    public function bar()
    {
        return $this->hasMany('ModelRelationHasManyTest_Bar', 'foo_id', 'fid');
    }

    /**
     * @return Model\Relation\HasMany
     */
    public function biz()
    {
        return $this->hasMany('ModelRelationHasManyTest_Biz', 'bid', 'fid')->throughTable('foo_biz', 'biz_id', 'foo_id');
    }

    /**
     * @return Model\Relation\HasMany
     */
    public function que()
    {
        return $this->hasMany('ModelRelationHasManyTest_Que', 'qid', 'fid')
            ->throughTable('foo_que', 'que_id', 'foo_id', ['que_id', 'foo_id']);
    }
}

class ModelRelationHasManyTest_Bar extends Model
{
    protected $tableName = 'bar';

    protected $primaryColumn = 'bid';
}

class ModelRelationHasManyTest_Biz extends Model
{
    protected $tableName = 'biz';

    protected $primaryColumn = 'bid';
}

class ModelRelationHasManyTest_Que extends Model
{
    protected $tableName = 'que';
}

