<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelCollectionActiveTest extends TestCase
{
    protected static $dbPath = 'ModelCollectionActiveTest';

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
            'options' => [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ],
        ]], true);
        $connection = (new ModelCollectionActiveTest_Foo)->connection();
        $connection->execute("CREATE TABLE `foo` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `mid` mediumint,
            `create` integer DEFAULT (0),
            `update` datetime DEFAULT ('')
        )");
        $connection->execute("CREATE TABLE `bar` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `tid` mediumint,
            `create` integer DEFAULT (0),
            `update` datetime DEFAULT ('')
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
        // clear table 'foo'
        $statement = 'DELETE FROM `foo`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'foo'");

        // clear table 'bar'
        $statement = 'DELETE FROM `bar`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'bar'");

        parent::tearDown();
    }

    public function testNewCollectionMethod()
    {
        $collection = ModelCollectionActiveTest_Foo::newCollection();
        static::assertEquals(0, $collection->count());
        static::assertEquals([], $collection->toArray());

        $arr = [
            ['foo' => 'bar', 'biz' => 'que'],
            ['foo' => 'bar2', 'biz' => 'que2'],
        ];
        $collection = ModelCollectionActiveTest_Foo::newCollection($arr);
        static::assertCount(2, $collection);
        static::assertEquals($arr, $collection->toArray());

        static::assertInstanceOf('ModelCollectionActiveTest_Foo', $collection[0]);
        static::assertEquals('bar', $collection[0]->foo);
        static::assertTrue($collection[0]->isNewRecord());
        static::assertTrue($collection[0]->isChanged());

        static::assertInstanceOf('ModelCollectionActiveTest_Foo', $collection[1]);
        static::assertEquals('bar2', $collection[1]->foo);
        static::assertTrue($collection[1]->isNewRecord());
        static::assertTrue($collection[1]->isChanged());

        static::assertEquals([$collection[0], $collection[1]], $collection->all());

        static::assertSame($collection[0], $collection->get());
        static::assertTrue($collection->hasNewRecord());
        static::assertSame($collection, $collection->syncOriginal());
        static::assertFalse($collection[0]->isChanged());
        static::assertFalse($collection[1]->isChanged());

        static::assertSame($collection, $collection->setAttribute('foo', 'foo'));
        static::assertEquals('foo', $collection[0]->foo);
        static::assertEquals('foo', $collection[1]->foo);

        $newCollection = $collection->popNewRecord();
        static::assertCount(0, $collection);
        static::assertCount(2, $newCollection);
        static::assertEquals([
            ['foo' => 'foo', 'biz' => 'que'],
            ['foo' => 'foo', 'biz' => 'que2']
        ], $newCollection->toArray());

        // 测试下 syncOriginal 部分字段
        static::assertSame($newCollection, $newCollection->setAttribute('biz', 'biz'));
        static::assertEquals(['foo' => 'bar', 'biz' => 'que'], $newCollection[0]->getOriginal());
        static::assertEquals(['foo' => 'bar2', 'biz' => 'que2'], $newCollection[1]->getOriginal());
        static::assertSame($newCollection, $newCollection->syncOriginal('foo'));
        static::assertEquals(['foo' => 'foo', 'biz' => 'que'], $newCollection[0]->getOriginal());
        static::assertEquals(['foo' => 'foo', 'biz' => 'que2'], $newCollection[1]->getOriginal());

        $arr = [
            ['foo' => 'bar'],
            (new ModelCollectionActiveTest_Foo(['foo' => 'bar3'])),
            ['foo' => 'bar2'],
        ];
        $collection = ModelCollectionActiveTest_Foo::newCollection($arr, false);
        static::assertCount(3, $collection);
        static::assertEquals([
            ['foo' => 'bar'],
            ['foo' => 'bar3'],
            ['foo' => 'bar2'],
        ], $collection->toArray());

        static::assertInstanceOf('ModelCollectionActiveTest_Foo', $collection[0]);
        static::assertEquals('bar', $collection[0]->foo);
        static::assertFalse($collection[0]->isNewRecord());
        static::assertEquals('bar3', $collection[1]->foo);
        static::assertTrue($collection[1]->isNewRecord());
        static::assertSame($collection[0], $collection->get());
    }

    public function testCollectionUnique()
    {
        $arr = [];
        $arr[] = $a = ModelCollectionActiveTest_Foo::instance()->newModel(['id' => 1, 'mid' => 1]);
        $arr[] = $b = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::instance()->newModel(['id' => 1, 'mid' => 2]);
        $arr[] = $c = ModelCollectionActiveTest_FooWithoutUpdateTimeColumn::instance()->newModel(['id' => 1, 'mid' => 3]);

        $arr[] = $d = ModelCollectionActiveTest_Bar::instance()->newModel(['id' => 1, 'mid' => 1]);
        $arr[] = $e = ModelCollectionActiveTest_BarWithoutCreateTimeColumn::instance()->newModel(['id' => 1, 'mid' => 1]);

        $collection = new Model\Collection($arr);
        static::assertSame($a, $collection->get());
        static::assertSame($b, $collection->get(1));
        static::assertSame($e, $collection->get(4));
        static::assertFalse($collection->get(6));
        static::assertFalse($collection->get(-6));
        static::assertSame($e, $collection->get(-1));
        static::assertSame($d, $collection->get(-2));
        static::assertSame($c, $collection->get(-3));

        $newCollection = $collection->unique();
        static::assertEquals([$a, $d], $newCollection->all());
    }

    public function testCollectionArrayMethod()
    {
        $arr = [];
        for($i = 1; $i < 11; $i++) {
            $arr[] = ['foo' => $i];
        }
        $collection = Model::newCollection($arr);

        // pop
        $last = array_pop($arr);
        $lastModel = $collection->pop();
        static::assertEquals($arr, $collection->toArray());
        static::assertInstanceOf(Model::class, $lastModel);
        static::assertEquals($last, $lastModel->toArray());

        // shift
        $first = array_shift($arr);
        $firstModel = $collection->shift();
        static::assertEquals($arr, $collection->toArray());
        static::assertInstanceOf(Model::class, $firstModel);
        static::assertEquals($first, $firstModel->toArray());

        // push
        array_push($arr, $last);
        static::assertSame($collection, $collection->push($lastModel));
        static::assertEquals($arr, $collection->toArray());

        // unshift
        array_unshift($arr, $first);
        static::assertSame($collection, $collection->unshift($firstModel));
        static::assertEquals($arr, $collection->toArray());

        // reverse
        $reverseArr = array_reverse($arr);
        $reverseCollection = $collection->reverse();
        static::assertEquals($arr, $collection->toArray());
        static::assertEquals($reverseArr, $reverseCollection->toArray());

        // shuffle
        static::assertSame($collection, $collection->shuffle());
        $shuffle = $collection->toArray();
        static::assertNotEquals($arr, $shuffle);
        sort($shuffle);
        static::assertEquals($arr, $shuffle);
    }

    public function testCollectionSortFilter()
    {
        $arr = [];
        $columnAuto = [];
        $columnIndex = [];
        for($i = 1; $i < 6; $i++) {
            $j = 6 - $i;
            $foo = $i === 3 ? 2 : $i;
            $arr[] = [
                'id' => $i,
                'mid' => $j,
                'foo' => $foo,
                'bol' => $i % 2 === 0,
            ];
            $columnAuto[] = $foo;
            $columnIndex[$i] = $foo;
        }
        $model = Model::instance('foo');
        $collection = $model->newCollection($arr);

        // column
        static::assertEquals($columnAuto, $collection->column('foo'));
        static::assertEquals($columnIndex, $collection->column('foo', 'id'));

        // sort
        static::assertSame($collection, $collection->sort());
        array_multisort($arr);
        static::assertEquals([1,2,3,4,5], $collection->column('id'));

        $collection->sort('id', SORT_DESC);
        static::assertEquals([5,4,3,2,1], $collection->column('id'));

        $collection->sort('mid');
        static::assertEquals([5,4,3,2,1], $collection->column('id'));

        $collection->sort('foo');
        static::assertEquals([1,2,3,4,5], $collection->column('id'));

        $collection->sort('foo', SORT_DESC);
        static::assertEquals([5,4,2,3,1], $collection->column('id'));

        $collection->sort('foo', SORT_DESC, 'id', SORT_DESC);
        static::assertEquals([5,4,3,2,1], $collection->column('id'));

        $collection->sort('foo', SORT_DESC, 'id', SORT_ASC);
        static::assertEquals([5,4,2,3,1], $collection->column('id'));

        // filter
        $newCollection = $collection->filter(function (Model $m){
            return $m->bol;
        });
        static::assertNotSame($collection, $newCollection);
        static::assertInstanceOf(Model\Collection::class, $newCollection);
        static::assertEquals([5,4,2,3,1], $collection->column('id'));
        static::assertEquals([4,2], $newCollection->column('id'));

        $newCollection = $collection->filter(function ($key){
            return $key < 3;
        }, ARRAY_FILTER_USE_KEY);
        static::assertEquals([5,4,2], $newCollection->column('id'));
    }

    public function testCreateCollectionByArray()
    {
        $now = time();

        // normal
        $collection = ModelCollectionActiveTest_Foo::createCollection([
            ['mid' => 2],
            ['mid' => 3],
        ]);
        static::assertInstanceOf(Model\Collection::class, $collection);
        static::assertCount(2, $collection);
        $model = $collection[0];
        static::assertInstanceOf('ModelCollectionActiveTest_Foo', $model);
        static::assertFalse($model->isNewRecord());
        static::assertEquals(1, $model['id']);
        static::assertEquals(2, $model['mid']);
        static::assertLessThanOrEqual(strtotime($model['create']), $now);
        static::assertLessThanOrEqual($model['update'], $now);

        static::assertFalse($collection[1]->isNewRecord());
        static::assertEquals(2, $collection[1]['id']);
        static::assertEquals(3, $collection[1]['mid']);
        static::assertLessThanOrEqual(strtotime($collection[1]['create']), $now);
        static::assertLessThanOrEqual($collection[1]['update'], $now);

        // normal with primary column
        $collection = ModelCollectionActiveTest_Foo::createCollection([
            ['id' => 5, 'mid' => 3],
            ['mid' => 2],
            ['mid' => 3],
            ['id' => 6, 'mid' => 3],
        ]);
        static::assertCount(4, $collection);
        static::assertEquals([5, 7, 8, 6], $collection->column('id'));
        static::assertEquals([3, 2, 3, 3], $collection->column('mid'));

        // without create time column
        $collection = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::createCollection([
            ['mid' => 11],
            ['mid' => 12],
        ]);
        static::assertCount(2, $collection);
        static::assertFalse($collection->hasNewRecord());

        $newCollection = $collection->popNewRecord();
        static::assertInstanceOf(Model\Collection::class, $newCollection);
        static::assertCount(0, $newCollection);
        $model = $collection[0];
        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutCreateTimeColumn', $model);
        static::assertFalse($model->isNewRecord());
        static::assertEquals(9, $model['id']);
        static::assertEquals(11, $model['mid']);
        static::assertFalse(isset($model['create']));
        static::assertLessThanOrEqual($model['update'], $now);

        static::assertEquals(10, $collection[1]['id']);
        static::assertEquals(12, $collection[1]['mid']);
        static::assertFalse(isset($collection[1]['create']));
        static::assertLessThanOrEqual($collection[1]['update'], $now);

        // fresh
        $newCollection = $collection->fresh();
        static::assertFalse(isset($collection[0]['create']));
        static::assertFalse(isset($collection[1]['create']));
        static::assertEquals('1970-01-01 00:00:00', $newCollection[0]['create']);
        static::assertEquals('1970-01-01 00:00:00', $newCollection[1]['create']);

        // without update time column
        $collection = ModelCollectionActiveTest_FooWithoutUpdateTimeColumn::createCollection([
            ['mid' => 14],
            (new ModelCollectionActiveTest_FooWithoutUpdateTimeColumn(['mid' => 15])),
        ]);
        static::assertCount(2, $collection);
        $model = $collection[0];
        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutUpdateTimeColumn', $model);
        static::assertFalse($model->isNewRecord());
        static::assertEquals(11, $model['id']);
        static::assertEquals(14, $model['mid']);
        static::assertLessThanOrEqual(strtotime($model['create']), $now);
        static::assertFalse(isset($model['update']));

        static::assertEquals(12, $collection[1]['id']);
        static::assertEquals(15, $collection[1]['mid']);
        static::assertLessThanOrEqual(strtotime($collection[1]['create']), $now);
        static::assertFalse(isset($collection[1]['update']));

        // fresh
        $newCollection = $collection->fresh();
        static::assertEquals(0, $newCollection[0]['update']);
        static::assertEquals(0, $newCollection[1]['update']);

        $list = $model->connection()->fetchAll('SELECT id FROM `foo`', [], PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1],
            ['id' => 2],
            ['id' => 5],
            ['id' => 6],
            ['id' => 7],
            ['id' => 8],
            ['id' => 9],
            ['id' => 10],
            ['id' => 11],
            ['id' => 12],
        ], $list);
    }

    public function testCollectionInsertAndUpdateAtSameTime()
    {
        $foo = ModelCollectionActiveTest_Foo::createModel([
            'mid' => 1
        ]);
        static::assertEquals(1, $foo->mid);
        $foo->mid = 8;
        $collection = ModelCollectionActiveTest_Foo::createCollection([
            ['mid' => 2],
            $foo,
            ['mid' => 3],
        ]);
        static::assertEquals(3, $collection->count());
        static::assertEquals([2, 1, 3], $collection->column('id'));
        static::assertEquals([2, 8, 3], $collection->column('mid'));

        $list = $foo->connection()->fetchAll('SELECT id,mid FROM `foo`', [], PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1, 'mid' => 8],
            ['id' => 2, 'mid' => 2],
            ['id' => 3, 'mid' => 3],
        ], $list);

        sleep(2);
        $update = $collection[0]['update'];
        $collection[0]->mid = 10;
        $collection[1]->mid = 20;
        $collection[2]->mid = 3;
        $collection->save();
        static::assertEquals([10, 20, 3], $collection->column('mid'));
        static::assertLessThan($collection[0]['update'], $update);
        static::assertLessThan($collection[1]['update'], $update);
        static::assertEquals($collection[2]['update'], $update);

        $list = $foo->connection()->fetchAll('SELECT id,mid FROM `foo`', [], PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1, 'mid' => 20],
            ['id' => 2, 'mid' => 10],
            ['id' => 3, 'mid' => 3],
        ], $list);
    }

    public function testCollectionSaveWithMultipleModel()
    {
        $now = time();
        $foo = ModelCollectionActiveTest_Foo::newModel([
            'mid' => 1
        ]);
        $bar = ModelCollectionActiveTest_Bar::newModel([
            'tid' => 1
        ]);
        $collection = ModelCollectionActiveTest_Foo::createCollection([
            ['mid' => 2],
            $foo,
            ['mid' => 3],
            $bar,
        ]);
        static::assertEquals(4, $collection->count());
        static::assertInstanceOf('ModelCollectionActiveTest_Foo', $collection[0]);
        static::assertInstanceOf('ModelCollectionActiveTest_Foo', $collection[1]);
        static::assertInstanceOf('ModelCollectionActiveTest_Foo', $collection[2]);
        static::assertInstanceOf('ModelCollectionActiveTest_Bar', $collection[3]);

        static::assertEquals([1,2,3,1], $collection->column('id'));
        static::assertEquals([2,1,3], $collection->column('mid'));
        static::assertEquals([1], $collection->column('tid'));

        static::assertLessThanOrEqual(strtotime($collection[0]['create']), $now);
        static::assertLessThanOrEqual($collection[0]['update'], $now);
        static::assertLessThanOrEqual(strtotime($collection[3]['create']), $now);
        static::assertLessThanOrEqual($collection[3]['update'], $now);

        $list = $foo->connection()->fetchAll('SELECT id,mid FROM `foo`', [], PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1, 'mid' => 2],
            ['id' => 2, 'mid' => 1],
            ['id' => 3, 'mid' => 3],
        ], $list);
        $list = $foo->connection()->fetchAll('SELECT id,tid FROM `bar`', [], PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1, 'tid' => 1],
        ], $list);

        sleep(2);
        $update = $collection[0]['update'];
        $collection[0]->mid = 10;
        $collection[1]->mid = 20;
        $collection[2]->mid = 3;
        $collection[3]->tid = 3;
        $collection->save();
        static::assertEquals([10, 20, 3], $collection->column('mid'));
        static::assertEquals([3], $collection->column('tid'));
        static::assertLessThan($collection[0]['update'], $update);
        static::assertLessThan($collection[1]['update'], $update);
        static::assertEquals($collection[2]['update'], $update);
        static::assertLessThan($collection[3]['update'], $update);

        $list = $foo->connection()->fetchAll('SELECT id,mid FROM `foo`', [], PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1, 'mid' => 10],
            ['id' => 2, 'mid' => 20],
            ['id' => 3, 'mid' => 3],
        ], $list);
        $list = $foo->connection()->fetchAll('SELECT id,tid FROM `bar`', [], PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1, 'tid' => 3],
        ], $list);
    }

    public function testCollectionFresh()
    {
        $foo = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::createModel([
            'mid' => 1
        ]);
        $foo2 = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::createModel([
            'mid' => 2
        ]);
        $foo3 = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::newModel([
            'mid' => 3
        ]);
        $bar = ModelCollectionActiveTest_BarWithoutCreateTimeColumn::createModel([
            'tid' => 1
        ]);
        $bar2 = ModelCollectionActiveTest_BarWithoutCreateTimeColumn::createModel([
            'tid' => 2
        ]);
        $bar3 = ModelCollectionActiveTest_BarWithoutCreateTimeColumn::newModel([
            'tid' => 3
        ]);
        $collection = $foo::newCollection([
           $foo, $bar, $foo2, $bar2, $foo3, $bar3
        ]);
        $this->checkCollectionBeforeFresh($collection);

        // fresh
        $newCollection = $collection->fresh();

        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutCreateTimeColumn', $newCollection[0]);
        static::assertEquals(1, $newCollection[0]['id']);
        static::assertEquals(1, $newCollection[0]['mid']);
        static::assertTrue(isset($newCollection[0]['create']));
        static::assertFalse($newCollection[0]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_BarWithoutCreateTimeColumn', $newCollection[1]);
        static::assertEquals(1, $newCollection[1]['id']);
        static::assertEquals(1, $newCollection[1]['tid']);
        static::assertTrue(isset($newCollection[1]['create']));
        static::assertFalse($newCollection[1]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutCreateTimeColumn', $newCollection[2]);
        static::assertEquals(2, $newCollection[2]['id']);
        static::assertEquals(2, $newCollection[2]['mid']);
        static::assertTrue(isset($newCollection[2]['create']));
        static::assertFalse($newCollection[2]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_BarWithoutCreateTimeColumn', $newCollection[3]);
        static::assertEquals(2, $newCollection[3]['id']);
        static::assertEquals(2, $newCollection[3]['tid']);
        static::assertTrue(isset($newCollection[3]['create']));
        static::assertFalse($newCollection[3]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutCreateTimeColumn', $newCollection[4]);
        static::assertFalse(isset($newCollection[4]['id']));
        static::assertEquals(3, $newCollection[4]['mid']);
        static::assertFalse(isset($newCollection[4]['create']));
        static::assertTrue($newCollection[4]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_BarWithoutCreateTimeColumn', $newCollection[5]);
        static::assertFalse(isset($newCollection[5]['id']));
        static::assertEquals(3, $newCollection[5]['tid']);
        static::assertFalse(isset($newCollection[5]['create']));
        static::assertTrue($newCollection[5]->isNewRecord());

        // collection not change after fresh
        $this->checkCollectionBeforeFresh($collection);

        // edit newCollection, collection not change
        $newCollection[0]->mid = 2;
        static::assertEquals(1, $collection[0]['mid']);
        static::assertEquals(2, $newCollection[0]['mid']);

        // refresh, collection load new data
        $collection->refresh();
        static::assertEquals(1, $collection[0]['id']);
        static::assertEquals(1, $collection[0]['mid']);
        static::assertTrue(isset($collection[0]['create']));
        static::assertFalse($collection[0]->isNewRecord());
    }

    /**
     * @param Model\Collection $collection
     */
    protected function checkCollectionBeforeFresh($collection)
    {
        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutCreateTimeColumn', $collection[0]);
        static::assertEquals(1, $collection[0]['id']);
        static::assertEquals(1, $collection[0]['mid']);
        static::assertFalse(isset($collection[0]['create']));
        static::assertFalse($collection[0]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_BarWithoutCreateTimeColumn', $collection[1]);
        static::assertEquals(1, $collection[1]['id']);
        static::assertEquals(1, $collection[1]['tid']);
        static::assertFalse(isset($collection[1]['create']));
        static::assertFalse($collection[1]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutCreateTimeColumn', $collection[2]);
        static::assertEquals(2, $collection[2]['id']);
        static::assertEquals(2, $collection[2]['mid']);
        static::assertFalse(isset($collection[2]['create']));
        static::assertFalse($collection[2]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_BarWithoutCreateTimeColumn', $collection[3]);
        static::assertEquals(2, $collection[3]['id']);
        static::assertEquals(2, $collection[3]['tid']);
        static::assertFalse(isset($collection[3]['create']));
        static::assertFalse($collection[3]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_FooWithoutCreateTimeColumn', $collection[4]);
        static::assertFalse(isset($collection[4]['id']));
        static::assertEquals(3, $collection[4]['mid']);
        static::assertFalse(isset($collection[4]['create']));
        static::assertTrue($collection[4]->isNewRecord());

        static::assertInstanceOf('ModelCollectionActiveTest_BarWithoutCreateTimeColumn', $collection[5]);
        static::assertFalse(isset($collection[5]['id']));
        static::assertEquals(3, $collection[5]['tid']);
        static::assertFalse(isset($collection[5]['create']));
        static::assertTrue($collection[5]->isNewRecord());
    }

    public function testFindCollectionMethod()
    {
        ModelCollectionActiveTest_Foo::insert([
            ['mid' => 1],
            ['mid' => 2],
            ['mid' => 3],
            ['mid' => 4],
            ['mid' => 5],
            ['mid' => 6],
            ['mid' => 7],
        ]);
        $collection = ModelCollectionActiveTest_Foo::findMany();
        static::assertCount(7, $collection);
        static::assertEquals(1, $collection[0]->mid);
        static::assertEquals(7, $collection[6]->mid);

        $collection = ModelCollectionActiveTest_Foo::findMany([1, 3, 5]);
        static::assertCount(3, $collection);
        static::assertEquals(1, $collection[0]->mid);
        static::assertEquals(5, $collection[2]->mid);

        $collection = ModelCollectionActiveTest_Foo::wherePrimary([2, 4, 6])->findMany();
        static::assertCount(3, $collection);
        static::assertEquals(2, $collection[0]->mid);
        static::assertEquals(6, $collection[2]->mid);

        $collection = ModelCollectionActiveTest_Foo::where('mid', 5)->findMany();
        static::assertCount(1, $collection);
        static::assertEquals(5, $collection[0]->mid);

        $collection = ModelCollectionActiveTest_Foo::where('mid', '>', 2)->findMany();
        static::assertCount(5, $collection);
        static::assertEquals(3, $collection[0]->mid);
        static::assertEquals(7, $collection[4]->mid);
    }

    public function testCollectionDropMethod()
    {
        $foo = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::createModel([
            'mid' => 1
        ]);
        $foo2 = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::createModel([
            'mid' => 2
        ]);
        $foo3 = ModelCollectionActiveTest_FooWithoutCreateTimeColumn::newModel([
            'mid' => 3
        ]);
        $bar = ModelCollectionActiveTest_BarWithoutCreateTimeColumn::createModel([
            'tid' => 1
        ]);
        $bar2 = ModelCollectionActiveTest_BarWithoutCreateTimeColumn::createModel([
            'tid' => 2
        ]);
        $bar3 = ModelCollectionActiveTest_BarWithoutCreateTimeColumn::newModel([
            'tid' => 3
        ]);
        $collection = $foo::newCollection([
            $foo, $bar, $foo2, $bar2, $foo3, $bar3
        ]);
        static::assertEquals(2,$collection->drop());

        $list = $foo->connection()->fetchOne('SELECT COUNT(*) AS d FROM `foo`');
        static::assertEquals(0, $list->d);

        $list = $foo->connection()->fetchOne('SELECT COUNT(*) AS d FROM `bar`');
        static::assertEquals(0, $list->d);
    }
}


class ModelCollectionActiveTest_Foo extends Model
{
    protected $tableName = 'foo';

    protected $casts = [
        'create' => self::TIME,
        'update' => self::TIMESTAMP,
    ];

    protected $createTimeColumn = 'create';

    protected $updateTimeColumn = 'update';
}

class ModelCollectionActiveTest_FooWithoutCreateTimeColumn extends Model
{
    protected $tableName = 'foo';

    protected $casts = [
        'create' => self::TIME,
        'update' => self::TIMESTAMP,
    ];

    protected $updateTimeColumn = 'update';
}

class ModelCollectionActiveTest_FooWithoutUpdateTimeColumn extends Model
{
    protected $tableName = 'foo';

    protected $casts = [
        'create' => self::TIME,
        'update' => self::TIMESTAMP,
    ];

    protected $createTimeColumn = 'create';
}

class ModelCollectionActiveTest_Bar extends Model
{
    protected $tableName = 'bar';

    protected $casts = [
        'create' => self::TIME,
        'update' => self::TIMESTAMP,
    ];

    protected $createTimeColumn = 'create';

    protected $updateTimeColumn = 'update';
}

class ModelCollectionActiveTest_BarWithoutCreateTimeColumn extends Model
{
    protected $tableName = 'bar';

    protected $casts = [
        'create' => self::TIME,
        'update' => self::TIMESTAMP,
    ];

    protected $updateTimeColumn = 'update';
}
