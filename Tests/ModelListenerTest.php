<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelListenerTest extends TestCase
{
    protected static $dbPath = 'ModelListenerTest';

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
        ]], true);
        $connection = (new ModelListenerTest_Foo)->connection();
        $connection->execute("CREATE TABLE `foo` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `tid` mediumint
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
        $statement = 'DELETE FROM `foo`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'foo'");
        
        parent::tearDown();
    }


    public function testBootModelListener()
    {
        $foo_startup = 0;
        $bar_startup = 0;
        $boot = [];
        Database::setModelListener(function($event, $model) use (
            &$foo_startup,
            &$bar_startup,
            &$boot
        ) {
            if ($event === 'startup') {
                if ($model instanceof ModelListenerTest_Foo) {
                    $foo_startup++;
                } elseif ($model instanceof ModelListenerTest_Bar) {
                    $bar_startup++;
                }
            } elseif ($event === 'boot') {
                if ($model->hasAttribute('tid')) {
                    $boot[] = $model->tid;
                    if ($model instanceof ModelListenerTest_Foo) {
                        $model->tid++;
                    } elseif ($model instanceof ModelListenerTest_Bar) {
                        $model->tid++;
                    }
                }

            }
        });

        $foo = ModelListenerTest_Foo::newModel(['tid' => 2]);
        $bar = ModelListenerTest_Bar::newModel(['tid' => 3]);
        static::assertEquals(0, $foo_startup);
        static::assertEquals(1, $bar_startup);
        static::assertEquals([2, 3], $boot);
        static::assertEquals(3, $foo->tid);
        static::assertEquals(4, $bar->tid);


        $foo_startup = 0;
        $bar_startup = 0;
        $boot = [];
        $foo = ModelListenerTest_Foo::newModel(['tid' => 4]);
        $bar = ModelListenerTest_Bar::newModel(['tid' => 5]);
        static::assertEquals(0, $foo_startup);
        static::assertEquals(0, $bar_startup);
        static::assertEquals([4, 5], $boot);
        static::assertEquals(5, $foo->tid);
        static::assertEquals(6, $bar->tid);

        $boot = [];
        $foo = ModelListenerTest_Foo::createModel(['tid' => 1]);
        static::assertEquals([1], $boot);
        static::assertEquals(2, $foo->tid);


        $boot = [];
        $foo = ModelListenerTest_Foo::createModel(['tid' => 2]);
        static::assertEquals([2], $boot);
        static::assertEquals(3, $foo->tid);


        $boot = [];
        $foo = ModelListenerTest_Foo::find(1);
        static::assertEquals([2], $boot);
        static::assertEquals(3, $foo->tid);

        $boot = [];
        $foo = ModelListenerTest_Foo::find(2);
        static::assertEquals([3], $boot);
        static::assertEquals(4, $foo->tid);

        $boot = [];
        $foo = ModelListenerTest_Foo::findMany([1,2]);
        static::assertEquals([2,3], $boot);
        static::assertEquals(3, $foo[0]->tid);
        static::assertEquals(4, $foo[1]->tid);

        Database::setModelListener(null);
    }

    public function testCreateModelListener()
    {
        Database::setModelListener(function($event, $model) use (
            &$savingTid,
            &$creatingTid,
            &$createdId,
            &$savedId
        ) {
            if ($event === 'saving') {
                $savingTid[] = $model->tid;
                if ($model->tid === 3) {
                    return false;
                }
            } elseif ($event === 'creating') {
                $creatingTid[] = $model->tid;
                if ($model->tid === 4) {
                    return false;
                }
            } elseif ($event === 'created') {
                $createdId[] = $model->id;
            } elseif ($event === 'saved') {
                $savedId[] = [$model->id, $model->tid];
            }
            return null;
        });

        // model
        $savingTid = [];
        $creatingTid = [];
        $createdId = [];
        $savedId = [];
        static::assertFalse(ModelListenerTest_Foo::createModel(['tid' => 3]));
        static::assertEquals([3], $savingTid);
        static::assertEquals([], $creatingTid);
        static::assertEquals([], $createdId);
        static::assertEquals([], $savedId);

        $savingTid = [];
        $creatingTid = [];
        $createdId = [];
        $savedId = [];
        static::assertFalse(ModelListenerTest_Foo::createModel(['tid' => 4]));
        static::assertEquals([4], $savingTid);
        static::assertEquals([4], $creatingTid);
        static::assertEquals([], $createdId);
        static::assertEquals([], $savedId);

        $savingTid = [];
        $creatingTid = [];
        $createdId = [];
        $savedId = [];
        $model = ModelListenerTest_Foo::createModel(['tid' => 6]);
        static::assertInstanceOf('ModelListenerTest_Foo', $model);
        static::assertEquals(1, $model->id);
        static::assertEquals(6, $model->tid);
        static::assertEquals([6], $savingTid);
        static::assertEquals([6], $creatingTid);
        static::assertEquals([1], $createdId);
        static::assertEquals([[1,6]], $savedId);

        // collection
        $savingTid = [];
        $creatingTid = [];
        $createdId = [];
        $savedId = [];
        $collection = ModelListenerTest_Foo::createCollection([
            ['tid' => 2],
            ['tid' => 3],
            ['tid' => 5],
        ]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $collection);
        static::assertEquals([2, 3, 5], $savingTid);
        static::assertEquals([2, 5], $creatingTid);
        static::assertEquals([2, 3], $createdId);
        static::assertEquals([[2,2], [3, 5]], $savedId);

        static::assertEquals(2, $collection[0]->id);
        static::assertEquals(2, $collection[0]->tid);
        static::assertFalse($collection[0]->isNewRecord());

        static::assertFalse(isset($collection[1]['id']));
        static::assertEquals(3, $collection[1]->tid);
        static::assertTrue($collection[1]->isNewRecord());

        static::assertEquals(3, $collection[2]->id);
        static::assertEquals(5, $collection[2]->tid);
        static::assertFalse($collection[2]->isNewRecord());


        $savingTid = [];
        $creatingTid = [];
        $createdId = [];
        $savedId = [];
        $collection = ModelListenerTest_Foo::createCollection([
            ['tid' => 4],
            ['tid' => 8],
            ['tid' => 9],
        ]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $collection);
        static::assertEquals([4, 8, 9], $savingTid);
        static::assertEquals([4, 8, 9], $creatingTid);
        static::assertEquals([4, 5], $createdId);
        static::assertEquals([[4, 8], [5, 9]], $savedId);

        static::assertFalse(isset($collection[0]['id']));
        static::assertEquals(4, $collection[0]->tid);
        static::assertTrue($collection[0]->isNewRecord());

        static::assertEquals(4, $collection[1]->id);
        static::assertEquals(8, $collection[1]->tid);
        static::assertFalse($collection[1]->isNewRecord());

        static::assertEquals(5, $collection[2]->id);
        static::assertEquals(9, $collection[2]->tid);
        static::assertFalse($collection[2]->isNewRecord());

        Database::setModelListener(null);
    }

    public function testUpdateModelListener()
    {
        Database::setModelListener(function($event, $model) use (
            &$savingId,
            &$updatingId,
            &$updatedId,
            &$savedId
        ) {
            if ($event === 'saving') {
                $savingId[] = $model->id;
                if ($model->id === 2) {
                    return false;
                }
            } elseif ($event === 'updating') {
                $updatingId[] = $model->id;
                if ($model->id === 4) {
                    return false;
                }
            } elseif ($event === 'updated') {
                $updatedId[] = $model->id;
            } elseif ($event === 'saved') {
                $savedId[] = [$model->id, $model->tid];
            }
            return null;
        });
        ModelListenerTest_Foo::insert([
            ['tid' => 1],
            ['tid' => 2],
            ['tid' => 3],
            ['tid' => 4],
            ['tid' => 5],
            ['tid' => 6],
        ]);

        // model
        $savingId = [];
        $updatingId = [];
        $updatedId = [];
        $savedId = [];
        $model = ModelListenerTest_Foo::find(1);
        $model->tid = 15;
        static::assertEquals($model, $model->save());
        static::assertEquals([1], $savingId);
        static::assertEquals([1], $updatingId);
        static::assertEquals([1], $updatedId);
        static::assertEquals([[1, 15]], $savedId);
        static::assertEquals(15, $model->tid);
        static::assertEquals(15, $model->getOriginal('tid'));

        $savingId = [];
        $updatingId = [];
        $updatedId = [];
        $savedId = [];
        $model = ModelListenerTest_Foo::find(2);
        $model->tid = 15;
        static::assertFalse($model->save());
        static::assertEquals([2], $savingId);
        static::assertEquals([], $updatingId);
        static::assertEquals([], $updatedId);
        static::assertEquals([], $savedId);
        static::assertEquals(15, $model->tid);
        static::assertEquals(2, $model->getOriginal('tid'));


        $savingId = [];
        $updatingId = [];
        $updatedId = [];
        $savedId = [];
        $model = ModelListenerTest_Foo::find(4);
        $model->tid = 15;
        static::assertFalse($model->save());
        static::assertEquals([4], $savingId);
        static::assertEquals([4], $updatingId);
        static::assertEquals([], $updatedId);
        static::assertEquals([], $savedId);
        static::assertEquals(15, $model->tid);
        static::assertEquals(4, $model->getOriginal('tid'));


        // collection
        $savingId = [];
        $updatingId = [];
        $updatedId = [];
        $savedId = [];

        $collection = ModelListenerTest_Foo::findMany([1,2,3]);
        static::assertSame($collection, $collection->setAttribute('tid', 10));
        static::assertEquals(2, $collection->save());

        static::assertEquals([1, 2, 3], $savingId);
        static::assertEquals([1, 3], $updatingId);
        static::assertEquals([1, 3], $updatedId);
        static::assertEquals([[1, 10], [3, 10]], $savedId);

        static::assertEquals(1, $collection[0]->id);
        static::assertEquals(10, $collection[0]->tid);
        static::assertEquals(10, $collection[0]->getOriginal('tid'));

        static::assertEquals(2, $collection[1]->id);
        static::assertEquals(10, $collection[1]->tid);
        static::assertEquals(2, $collection[1]->getOriginal('tid'));

        static::assertEquals(3, $collection[2]->id);
        static::assertEquals(10, $collection[2]->tid);
        static::assertEquals(10, $collection[2]->getOriginal('tid'));


        $savingId = [];
        $updatingId = [];
        $updatedId = [];
        $savedId = [];

        $collection = ModelListenerTest_Foo::findMany([4,5,6]);
        static::assertSame($collection, $collection->setAttribute('tid', 20));
        static::assertEquals(2, $collection->save());

        static::assertEquals([4, 5, 6], $savingId);
        static::assertEquals([4, 5, 6], $updatingId);
        static::assertEquals([5, 6], $updatedId);
        static::assertEquals([[5, 20], [6, 20]], $savedId);

        static::assertEquals(4, $collection[0]->id);
        static::assertEquals(20, $collection[0]->tid);
        static::assertEquals(4, $collection[0]->getOriginal('tid'));

        static::assertEquals(5, $collection[1]->id);
        static::assertEquals(20, $collection[1]->tid);
        static::assertEquals(20, $collection[1]->getOriginal('tid'));

        static::assertEquals(6, $collection[2]->id);
        static::assertEquals(20, $collection[2]->tid);
        static::assertEquals(20, $collection[2]->getOriginal('tid'));


        Database::setModelListener(null);
    }

    public function testDropModelListener()
    {
        Database::setModelListener(function($event, $model) use (
            &$droppingId,
            &$droppedId
        ) {
            if ($event === 'dropping') {
                $droppingId[] = $model->id;
                if ($model->id === 2 || $model->id === 5) {
                    return false;
                }
            } elseif ($event === 'dropped') {
                $droppedId[] = $model->id;
            }
            return null;
        });
        ModelListenerTest_Foo::insert([
            ['tid' => 1],
            ['tid' => 2],
            ['tid' => 3],
            ['tid' => 4],
            ['tid' => 5],
            ['tid' => 6],
        ]);

        // model
        $droppingId = [];
        $droppedId = [];
        $model = ModelListenerTest_Foo::find(1);
        static::assertEquals($model, $model->drop());
        static::assertTrue($model->isNewRecord());
        static::assertEquals([1], $droppingId);
        static::assertEquals([1], $droppedId);
        static::assertFalse($model->connection()->fetchOne('SELECT * FROM `foo` WHERE id=?', [1]));

        $droppingId = [];
        $droppedId = [];
        $model = ModelListenerTest_Foo::find(2);
        static::assertFalse($model->drop());
        static::assertFalse($model->isNewRecord());
        static::assertEquals([2], $droppingId);
        static::assertEquals([], $droppedId);
        static::assertArrayHasKey('id', $model->connection()->fetchOne('SELECT * FROM `foo` WHERE id=?', [2], PDO::FETCH_ASSOC));

        // collection
        $droppingId = [];
        $droppedId = [];
        $collection = ModelListenerTest_Foo::findMany([3, 4]);
        static::assertEquals(2, $collection->drop());
        static::assertEquals([3, 4], $droppingId);
        static::assertEquals([3, 4], $droppedId);

        static::assertTrue($collection[0]->isNewRecord());
        static::assertEquals(3, $collection[0]->id);

        static::assertTrue($collection[1]->isNewRecord());
        static::assertEquals(4, $collection[1]->id);
        static::assertFalse($model->connection()->fetchOne('SELECT * FROM `foo` WHERE id IN (?,?)', [3, 4]));


        $droppingId = [];
        $droppedId = [];
        $collection = ModelListenerTest_Foo::findMany([5, 6]);
        static::assertEquals(1, $collection->drop());
        static::assertEquals([5, 6], $droppingId);
        static::assertEquals([6], $droppedId);

        static::assertFalse($collection[0]->isNewRecord());
        static::assertEquals(5, $collection[0]->id);

        static::assertTrue($collection[1]->isNewRecord());
        static::assertEquals(6, $collection[1]->id);
        static::assertArrayHasKey('id', $model->connection()->fetchOne('SELECT * FROM `foo` WHERE id=?', [5], PDO::FETCH_ASSOC));
        static::assertFalse($model->connection()->fetchOne('SELECT * FROM `foo` WHERE id=?', [6]));

        Database::setModelListener(null);
    }
}


class ModelListenerTest_Foo extends Model
{
    protected $tableName = 'foo';

    protected $casts = [
        'id' => self::INT,
        'tid' => self::INT,
    ];
}

class ModelListenerTest_Bar extends Model
{

}
