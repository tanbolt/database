<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelRelationHasOneTest extends TestCase
{
    protected static $dbPath = 'ModelRelationHasOneTest';

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
        ]], true);
        $connection = (new ModelRelationHasOneTest_Foo)->connection();

        $connection->execute("CREATE TABLE `foo` (
            `fid`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` INTEGER DEFAULT 0,
            `y` INTEGER DEFAULT 0
        )");
        $connection->execute("CREATE TABLE `bar` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `foo_id` INTEGER,
            `x` INTEGER
        )");


        $connection->execute("CREATE TABLE `biz` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` INTEGER
        )");
        $connection->execute("CREATE TABLE `foo_biz` (
            `foo_id` INTEGER,
            `biz_id` INTEGER,
            `x` INTEGER DEFAULT 0
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

    public function testAddModelMethod()
    {
        // add foo->bar
        $foo = ModelRelationHasOneTest_Foo::createModel([
            'x' => 1,
            'y' => 1,
        ]);

        $triggers = [];
        Database::setModelListener(function($type, $model) use (&$triggers) {
            // 可能会临时创建对象 获取其配置, BOOT 或多次启动, 排除他进行测试
            if ($model instanceof ModelRelationHasOneTest_Bar && $type !== Model::EVENT_STARTUP && $type !== Model::EVENT_BOOT) {
                $triggers[] = $type;
            }
        });
        $bar = $foo->bar()->addModel(['x' => 2]);
        Database::setModelListener(null);
        static::assertEquals([
            Model::EVENT_SAVING,
            Model::EVENT_CREATING,
            Model::EVENT_CREATED,
            Model::EVENT_SAVED
        ], $triggers);

        static::assertFalse($bar->isNewRecord());
        static::assertInstanceOf('ModelRelationHasOneTest_Bar', $bar);
        static::assertEquals(1, $bar->id);
        static::assertEquals(1, $bar->foo_id);
        static::assertEquals(2, $bar->x);

        // add foo->bar
        $foo = ModelRelationHasOneTest_Foo::createModel(['x' => 2, 'y' => 2]);
        $bar = new ModelRelationHasOneTest_Bar(['x' => 3]);
        $bar = $foo->bar()->addModel($bar);
        static::assertFalse($bar->isNewRecord());
        static::assertInstanceOf('ModelRelationHasOneTest_Bar', $bar);
        static::assertEquals(2, $bar->id);
        static::assertEquals(2, $bar->foo_id);
        static::assertEquals(3, $bar->x);

        // add foo->biz
        $foo = ModelRelationHasOneTest_Foo::createModel(['x' => 3, 'y' => 3]);
        $biz = $foo->biz()->addModel(['x' => 4]);
        static::assertFalse($biz->isNewRecord());
        static::assertInstanceOf('ModelRelationHasOneTest_Biz', $biz);
        static::assertEquals(1, $biz->id);
        static::assertEquals(4, $biz->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $biz->pivot);
        static::assertEquals(3, $biz->pivot->foo_id);
        static::assertEquals(1, $biz->pivot->biz_id);

        // add foo->que
        $foo = ModelRelationHasOneTest_Foo::createModel(['x' => 4]);
        try {
            $foo->que()->addModel(['x' => 5], ['x' => 6]);
            static::fail('It should throw exception if model not find');
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            static::assertTrue(true);
        }

        // add foo->que
        $que = $foo->que()->addModel(['x' => 5, 'qid' => 6], ['x' => 7]);
        static::assertFalse($que->isNewRecord());
        static::assertInstanceOf('ModelRelationHasOneTest_Que', $que);
        static::assertEquals(1, $que->id);
        static::assertEquals(6, $que->qid);
        static::assertEquals(5, $que->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $que->pivot);
        static::assertEquals(4, $que->pivot->foo_id);
        static::assertEquals(6, $que->pivot->que_id);
        static::assertEquals(7, $que->pivot->x);

        // actual
        static::assertEquals([
            ['fid' => 1, 'x' => 1, 'y' => 1],
            ['fid' => 2, 'x' => 2, 'y' => 2],
            ['fid' => 3, 'x' => 3, 'y' => 3],
            ['fid' => 4, 'x' => 4, 'y' => 0],
        ], $que->connection()->fetchAll('SELECT * FROM `foo`', [], PDO::FETCH_ASSOC));

        static::assertEquals([
            ['x' => 2, 'id' => 1, 'foo_id' => 1],
            ['x' => 3, 'id' => 2, 'foo_id' => 2],
        ], $que->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        static::assertEquals([
            ['x' => 0, 'foo_id' => 3, 'biz_id' => 1],
        ], $que->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));

        static::assertEquals([
            ['x' => 4, 'id' => 1],
        ], $que->connection()->fetchAll('SELECT * FROM `biz`', [], PDO::FETCH_ASSOC));

        static::assertEquals([
            ['x' => 7, 'foo_id' => 4, 'que_id' => 6],
        ], $que->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));

        static::assertEquals([
            ['x' => 5, 'id' => 1, 'qid' => 6],
        ], $que->connection()->fetchAll('SELECT * FROM `que`', [], PDO::FETCH_ASSOC));

        //
        $foo = ModelRelationHasOneTest_Foo::find(3);
        $biz = $foo->biz()->withPivot()->find();
        static::assertEquals([
            'fid' => 3,
            'x' => 3,
            'y' => 3
        ], $foo->getAttribute());
        static::assertEquals([
            'x' => 4,
            'id' => 1,
        ], $biz->getAttribute());
        static::assertEquals([
            'x' => 0,
            'foo_id' => 3,
            'biz_id' => 1
        ], $biz->pivot->getAttribute());
    }

    public function testHoldMethod()
    {
        $foo = ModelRelationHasOneTest_Foo::createModel(['x' => 1]);

        // hold foo->bar
        ModelRelationHasOneTest_Bar::insert([
            ['foo_id' => 0, 'x' => 2],
            ['foo_id' => 0, 'x' => 3],
        ]);

        $triggers = [];
        Database::setModelListener(function($type, $model) use (&$triggers) {
            // 可能会临时创建对象 获取其配置, BOOT 或多次启动, 排除他进行测试
            if ($model instanceof ModelRelationHasOneTest_Bar && $type !== Model::EVENT_STARTUP && $type !== Model::EVENT_BOOT) {
                $triggers[] = $type;
            }
        });
        $hold = $foo->bar()->hold(1, ['x' => 4]);
        Database::setModelListener(null);
        static::assertEquals([
            Model::EVENT_SAVING,
            Model::EVENT_UPDATING,
            Model::EVENT_UPDATED,
            Model::EVENT_SAVED
        ], $triggers);

        static::assertInstanceOf('ModelRelationHasOneTest_Bar', $hold);
        static::assertEquals(1, $hold->foo_id);
        static::assertEquals(4, $hold->x);
        static::assertEquals([
            ['x' => 4, 'foo_id' => 1, 'id' => 1],
            ['x' => 3, 'foo_id' => 0, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        static::assertEquals(1, $foo->bar->id);

        $foo->bar->update(['foo_id' => 0]); //hold 不会检测重复性, 手工更新 bar[id=1] 的 foo_id 再 hold
        static::assertInstanceOf('ModelRelationHasOneTest_Bar', $foo->bar()->hold(2));
        static::assertEquals(1, $foo->bar->id);
        $foo->loadRelation('bar');
        static::assertEquals(2, $foo->bar->id);

        static::assertInstanceOf('ModelRelationHasOneTest_Bar', $foo->bar()->hold(3));
        static::assertEquals([
            ['x' => 4, 'foo_id' => 0, 'id' => 1],
            ['x' => 3, 'foo_id' => 1, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        // hold foo->biz
        ModelRelationHasOneTest_Biz::insert([
            ['x' => 2],
            ['x' => 3],
        ]);
        static::assertTrue(true);

        $hold = $foo->biz()->hold(2, ['x' => 4], ['x' => 8]);
        static::assertInstanceOf('ModelRelationHasOneTest_Biz', $hold);
        static::assertTrue((bool) $hold->pivot);
        static::assertInstanceOf('ModelRelationHasOneTest_Biz', $foo->biz);
        static::assertEquals(2, $foo->biz->id);
        static::assertEquals(4, $foo->biz->x);

        static::assertEquals([
            ['x' => 2, 'id' => 1],
            ['x' => 4, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 8, 'foo_id' => 1, 'biz_id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));

        $foo->biz()->hold(1, ['x' => 7], ['x' => 6]);
        static::assertEquals([
            ['x' => 7, 'id' => 1],
            ['x' => 4, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 8, 'foo_id' => 1, 'biz_id' => 2],
            ['x' => 6, 'foo_id' => 1, 'biz_id' => 1],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));


        // hold foo->que
        ModelRelationHasOneTest_Que::insert([
            ['x' => 2, 'qid' => 4],
            ['x' => 3, 'qid' => 5],
        ]);

        $foo->que()->hold(2, ['x' => 8], ['x' => 2]);
        static::assertEquals([
            ['x' => 2, 'id' => 1, 'qid' => 4],
            ['x' => 8, 'id' => 2, 'qid' => 5],
        ], $foo->connection()->fetchAll('SELECT * FROM `que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 2, 'foo_id' => 1, 'que_id' => 5],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));


        $foo->que()->hold(1, ['x' => 9], ['x' => 7]);
        static::assertEquals([
            ['x' => 9, 'id' => 1, 'qid' => 4],
            ['x' => 8, 'id' => 2, 'qid' => 5],
        ], $foo->connection()->fetchAll('SELECT * FROM `que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['x' => 2, 'foo_id' => 1, 'que_id' => 5],
            ['x' => 7, 'foo_id' => 1, 'que_id' => 4],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
    }

    public function testFreedMethod()
    {
        $fo = ModelRelationHasOneTest_Foo::createModel(['x' => 1]);
        $foo = ModelRelationHasOneTest_Foo::createModel(['x' => 1]);

        // freed foo->bar
        $fo->bar()->addModel(['x' => 1]);
        $foo->bar()->addModel(['x' => 2]);
        static::assertEquals([
            ['x' => 1, 'id' => 1, 'foo_id' => 1],
            ['x' => 2, 'id' => 2, 'foo_id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        $triggers = [];
        Database::setModelListener(function($type, $model) use (&$triggers) {
            // 可能会临时创建对象 获取其配置, BOOT 或多次启动, 排除他进行测试
            if ($model instanceof ModelRelationHasOneTest_Bar && $type !== Model::EVENT_STARTUP && $type !== Model::EVENT_BOOT) {
                $triggers[] = $type;
            }
        });
        // free 前并没有取出关联模型
        $foo->bar()->freed(['x' => 3]);
        Database::setModelListener(null);
        static::assertEquals([], $triggers);

        static::assertEquals([
            ['x' => 1, 'id' => 1, 'foo_id' => 1],
            ['x' => 3, 'id' => 2, 'foo_id' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        $foo->bar()->hold(2);

        $triggers = [];
        Database::setModelListener(function($type, $model) use (&$triggers) {
            // 可能会临时创建对象 获取其配置, BOOT 或多次启动, 排除他进行测试
            if ($model instanceof ModelRelationHasOneTest_Bar && $type !== Model::EVENT_STARTUP && $type !== Model::EVENT_BOOT) {
                $triggers[] = $type;
            }
        });
        // free 前取出关联模型
        static::assertInstanceOf('ModelRelationHasOneTest_Bar', $foo->bar);
        $foo->bar()->setFreeValue(10)->freed(['x' => 3]);
        Database::setModelListener(null);
        static::assertEquals([
            Model::EVENT_SAVING,
            Model::EVENT_UPDATING,
            Model::EVENT_UPDATED,
            Model::EVENT_SAVED
        ], $triggers);

        static::assertEquals([
            ['x' => 1, 'id' => 1, 'foo_id' => 1],
            ['x' => 3, 'id' => 2, 'foo_id' => 10],
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));


        // freed foo->biz
        $fo->biz()->addModel(['x' => 1]);
        $foo->biz()->addModel(['x' => 2]);
        static::assertEquals([
            ['x' => 1, 'id' => 1],
            ['x' => 2, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `biz`', [], PDO::FETCH_ASSOC));

        static::assertEquals([
            ['foo_id' => 1, 'biz_id' => 1, 'x' => 0],
            ['foo_id' => 2, 'biz_id' => 2, 'x' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));

        $foo->biz()->freed(['x' => 3]);
        static::assertEquals([
            ['x' => 1, 'id' => 1],
            ['x' => 3, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => 1, 'biz_id' => 1, 'x' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));


        // freed foo->que
        $fo->que()->addModel(['x' => 1, 'qid' => 3]);
        $foo->que()->addModel(['x' => 2, 'qid' => 4]);
        static::assertEquals([
            ['x' => 1, 'qid' => 3, 'id' => 1],
            ['x' => 2, 'qid' => 4, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => 1, 'que_id' => 3, 'x' => 0],
            ['foo_id' => 2, 'que_id' => 4, 'x' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));

        $foo->que()->freed(['x' => 3]);
        static::assertEquals([
            ['x' => 1, 'qid' => 3, 'id' => 1],
            ['x' => 3, 'qid' => 4, 'id' => 2],
        ], $foo->connection()->fetchAll('SELECT * FROM `que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => 1, 'que_id' => 3, 'x' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
    }

    public function testRemoveMethod()
    {
        $fo = ModelRelationHasOneTest_Foo::createModel(['x' => 1]);
        $foo = ModelRelationHasOneTest_Foo::createModel(['x' => 1]);

        // remove foo->bar
        $fo->bar()->addModel(['x' => 1]);
        $foo->bar()->addModel(['x' => 2]);

        $triggers = [];
        Database::setModelListener(function($type, $model) use (&$triggers) {
            // 可能会临时创建对象 获取其配置, BOOT 或多次启动, 排除他进行测试
            if ($model instanceof ModelRelationHasOneTest_Bar && $type !== Model::EVENT_STARTUP && $type !== Model::EVENT_BOOT) {
                $triggers[] = $type;
            }
        });
        // remove 前没有取出关联模型
        $foo->bar()->remove();
        Database::setModelListener(null);
        static::assertEquals([], $triggers);

        static::assertEquals([
            ['id' => 1, 'x' => 1, 'foo_id' => 1]
        ], $foo->connection()->fetchAll('SELECT * FROM `bar`', [], PDO::FETCH_ASSOC));

        // remove foo->biz
        $fo->biz()->addModel(['x' => 1]);
        $foo->biz()->addModel(['x' => 2]);

        $triggers = [];
        Database::setModelListener(function($type, $model) use (&$triggers) {
            // 可能会临时创建对象 获取其配置, BOOT 或多次启动, 排除他进行测试
            if ($model instanceof ModelRelationHasOneTest_Biz && $type !== Model::EVENT_STARTUP && $type !== Model::EVENT_BOOT) {
                $triggers[] = $type;
            }
        });
        // remove 前取出关联模型
        static::assertInstanceOf('ModelRelationHasOneTest_Biz', $foo->biz);
        $foo->biz()->remove();
        Database::setModelListener(null);
        static::assertEquals([
            Model::EVENT_DROPPING,
            Model::EVENT_DROPPED,
        ], $triggers);

        static::assertEquals([
            ['x' => 1, 'id' => 1],
        ], $foo->connection()->fetchAll('SELECT * FROM `biz`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => 1, 'biz_id' => 1, 'x' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_biz`', [], PDO::FETCH_ASSOC));

        // remove foo->que
        $fo->que()->addModel(['x' => 1, 'qid' => 3]);
        $foo->que()->addModel(['x' => 2, 'qid' => 4]);

        $foo->que()->remove();
        static::assertEquals([
            ['x' => 1, 'qid' => 3, 'id' => 1],
        ], $foo->connection()->fetchAll('SELECT * FROM `que`', [], PDO::FETCH_ASSOC));
        static::assertEquals([
            ['foo_id' => 1, 'que_id' => 3, 'x' => 0],
        ], $foo->connection()->fetchAll('SELECT * FROM `foo_que`', [], PDO::FETCH_ASSOC));
    }
}


class ModelRelationHasOneTest_Foo extends Model
{
    protected $tableName = 'foo';

    protected $primaryColumn = 'fid';

    /**
     * @return Model\Relation\HasOne
     */
    public function bar()
    {
        return $this->hasOne('ModelRelationHasOneTest_Bar', 'foo_id', 'fid');
    }

    /**
     * @return Model\Relation\HasOne
     */
    public function biz()
    {
        return $this->hasOne('ModelRelationHasOneTest_Biz', 'id', 'fid')->throughTable('foo_biz', 'biz_id', 'foo_id');
    }

    /**
     * @return Model\Relation\HasOne
     */
    public function que()
    {
        return $this->hasOne('ModelRelationHasOneTest_Que', 'qid', 'fid')->throughTable('foo_que', 'que_id', 'foo_id');
    }
}

class ModelRelationHasOneTest_Bar extends Model
{
    protected $tableName = 'bar';
}

class ModelRelationHasOneTest_Biz extends Model
{
    protected $tableName = 'biz';
}

class ModelRelationHasOneTest_Que extends Model
{
    protected $tableName = 'que';
}

