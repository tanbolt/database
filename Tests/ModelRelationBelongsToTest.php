<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;


class ModelRelationBelongsToTest extends TestCase
{
    protected static $dbPath = 'ModelRelationBelongsToTest';

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
        ]], true);

        $connection = (new ModelRelationBelongsToTest_Foo)->connection();

        $connection->execute("CREATE TABLE `foo` (
            `fid`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `qid` INTEGER DEFAULT 0,
            `x` INTEGER
        )");
        $connection->execute("CREATE TABLE `bar` (
            `bid`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `foo_id` INTEGER DEFAULT 0,
            `x` INTEGER
        )");


        $connection->execute("CREATE TABLE `biz` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` INTEGER
        )");
        $connection->execute("CREATE TABLE `foo_biz` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
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

    public function testOneToOneAssociateMethod()
    {
        // new model
        $bar = new ModelRelationBelongsToTest_Bar();

        static::assertSame($bar, $bar->foo()->associate(1));
        static::assertEquals(1, $bar->foo_id);
        static::assertTrue($bar->isChanged('foo_id'));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $bar->foo);
        static::assertEquals(1, $bar->foo->fid);

        static::assertSame($bar, $bar->foo()->associate(2));
        static::assertEquals(2, $bar->foo_id);
        static::assertTrue($bar->isChanged('foo_id'));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $bar->foo);
        static::assertEquals(2, $bar->foo->fid);

        // exist model
        $bar = ModelRelationBelongsToTest_Bar::createModel(['x' => 1])->fresh();
        static::assertEquals(0, $bar->foo_id);

        static::assertSame($bar, $bar->foo()->associate(1));
        static::assertTrue($bar->isChanged('foo_id'));
        static::assertEquals(1, $bar->foo_id);
        static::assertEquals(
            ['foo_id' => 0],
            $bar->connection()->fetchOne('SELECT foo_id from `bar` WHERE `bid` = ?', [1], PDO::FETCH_ASSOC)
        );
        $bar->save();
        static::assertEquals(1, $bar->foo_id);
        static::assertEquals(
            ['foo_id' => 1],
            $bar->connection()->fetchOne('SELECT foo_id from `bar` WHERE `bid` = ?', [1], PDO::FETCH_ASSOC)
        );
    }

    public function testThroughPrimaryAssociateMethod()
    {
        ModelRelationBelongsToTest_Foo::insert([
            ['x' => 2],
            ['x' => 3],
        ]);

        ModelRelationBelongsToTest_Biz::insert([
            'x' => 44
        ]);

        // new model
        $biz = new ModelRelationBelongsToTest_Biz([
            'id' => 2
        ]);
        static::assertTrue($biz->isNewRecord());

        static::assertSame($biz, $biz->foo()->associate(2, ['x' => 4]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $biz->foo);
        static::assertEquals(2, $biz->foo->fid);
        static::assertFalse($biz->foo->isChanged('fid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $biz->foo->pivot);
        static::assertTrue($biz->foo->pivot->isNewRecord());
        static::assertTrue($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(2, $biz->foo->pivot->foo_id);
        static::assertTrue($biz->foo->pivot->isChanged('biz_id'));
        static::assertEquals(2, $biz->foo->pivot->biz_id);

        static::assertCount(
            0,
            $biz->connection()->fetchAll('SELECT id,foo_id,biz_id,x from `foo_biz`', [], PDO::FETCH_ASSOC)
        );

        $biz->saveWithRelation();
        static::assertFalse($biz->isNewRecord());

        static::assertFalse($biz->foo->pivot->isNewRecord());
        static::assertFalse($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(2, $biz->foo->pivot->foo_id);
        static::assertFalse($biz->foo->pivot->isChanged('biz_id'));
        static::assertEquals(2, $biz->foo->pivot->biz_id);

        static::assertEquals(
            ['id' => 2],
            $biz->connection()->fetchOne('SELECT id from `biz` WHERE `id` = ?', [2], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['id' => 1, 'foo_id' => 2, 'biz_id' => 2, 'x' => 4],
            ],
            $biz->connection()->fetchAll('SELECT id,foo_id,biz_id,x from `foo_biz`', [], PDO::FETCH_ASSOC)
        );

        // exist model with relation
        $biz = ModelRelationBelongsToTest_Biz::find(2);
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $biz->foo);

        static::assertSame($biz, $biz->foo()->associate(1, ['x' => 5]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $biz->foo);
        static::assertEquals(1, $biz->foo->fid);
        static::assertFalse($biz->foo->isChanged('fid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $biz->foo->pivot);
        static::assertFalse($biz->foo->pivot->isNewRecord());
        static::assertTrue($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(1, $biz->foo->pivot->foo_id);
        static::assertFalse($biz->foo->pivot->isChanged('biz_id'));
        static::assertEquals(2, $biz->foo->pivot->biz_id);

        $biz->saveWithRelation();
        static::assertFalse($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(
            [
                ['id' => 1, 'foo_id' => 1, 'biz_id' => 2, 'x' => 5],
            ],
            $biz->connection()->fetchAll('SELECT id,foo_id,biz_id, x from `foo_biz`', [], PDO::FETCH_ASSOC)
        );

        // exist model without relation (pivot exist)
        $biz = ModelRelationBelongsToTest_Biz::find(2);

        static::assertSame($biz, $biz->foo()->associate(2, ['x' => 7]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $biz->foo);
        static::assertEquals(2, $biz->foo->fid);
        static::assertFalse($biz->foo->isChanged('fid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $biz->foo->pivot);
        static::assertFalse($biz->foo->pivot->isNewRecord());
        static::assertTrue($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(2, $biz->foo->pivot->foo_id);
        static::assertFalse($biz->foo->pivot->isChanged('biz_id'));
        static::assertEquals(2, $biz->foo->pivot->biz_id);

        $biz->saveWithRelation();
        static::assertFalse($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(
            [
                ['id' => 1, 'foo_id' => 2, 'biz_id' => 2, 'x' => 7],
            ],
            $biz->connection()->fetchAll('SELECT id,foo_id,biz_id,x from `foo_biz`', [], PDO::FETCH_ASSOC)
        );

        // exist model without relation (pivot not exist)
        $biz = ModelRelationBelongsToTest_Biz::find(1);

        static::assertSame($biz, $biz->foo()->associate(1, ['x' => 8]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $biz->foo);
        static::assertEquals(1, $biz->foo->fid);
        static::assertFalse($biz->foo->isChanged('fid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $biz->foo->pivot);
        static::assertTrue($biz->foo->pivot->isNewRecord());
        static::assertTrue($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(1, $biz->foo->pivot->foo_id);
        static::assertTrue($biz->foo->pivot->isChanged('biz_id'));
        static::assertEquals(1, $biz->foo->pivot->biz_id);

        $biz->saveWithRelation();
        static::assertFalse($biz->foo->pivot->isChanged('foo_id'));
        static::assertEquals(
            [
                ['id' => 1, 'foo_id' => 2, 'biz_id' => 2, 'x' => 7],
                ['id' => 2, 'foo_id' => 1, 'biz_id' => 1, 'x' => 8],
            ],
            $biz->connection()->fetchAll('SELECT id,foo_id,biz_id,x from `foo_biz`', [], PDO::FETCH_ASSOC)
        );
    }

    public function testThroughColumnAssociateMethod()
    {
        ModelRelationBelongsToTest_Foo::insert([
            ['qid' => 2, 'x' => 12],
            ['qid' => 3, 'x' => 13],
        ]);

        // new model
        $que = new ModelRelationBelongsToTest_Que([
            'id' => 1,
            'qid' => 4
        ]);
        static::assertTrue($que->isNewRecord());

        try {
            static::assertSame($que, $que->foo()->associate(3, ['x' => 4]));
            static::fail('It should throw exception if model not exist');
        } catch (\Tanbolt\Database\Exception\ModelNotFoundException $e) {
            static::assertTrue(true);
        }

        static::assertSame($que, $que->foo()->associate(1, ['x' => 4]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $que->foo);
        static::assertEquals(1, $que->foo->fid);
        static::assertFalse($que->foo->isChanged('fid'));

        static::assertEquals(2, $que->foo->qid);
        static::assertFalse($que->foo->isChanged('qid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $que->foo->pivot);
        static::assertTrue($que->foo->pivot->isNewRecord());
        static::assertTrue($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(2, $que->foo->pivot->foo_id);
        static::assertTrue($que->foo->pivot->isChanged('que_id'));
        static::assertEquals(4, $que->foo->pivot->que_id);

        static::assertCount(
            0,
            $que->connection()->fetchAll('SELECT foo_id,que_id, x from `foo_que`', [], PDO::FETCH_ASSOC)
        );

        $que->saveWithRelation();
        static::assertFalse($que->isNewRecord());

        static::assertFalse($que->foo->pivot->isNewRecord());
        static::assertFalse($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(2, $que->foo->pivot->foo_id);
        static::assertFalse($que->foo->pivot->isChanged('que_id'));
        static::assertEquals(4, $que->foo->pivot->que_id);

        static::assertEquals(
            ['id' => 1, 'qid' => 4],
            $que->connection()->fetchOne('SELECT id,qid from `que` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['foo_id' => 2, 'que_id' => 4, 'x' => 4],
            ],
            $que->connection()->fetchAll('SELECT foo_id,que_id, x from `foo_que`', [], PDO::FETCH_ASSOC)
        );

        // exist model with relation
        $que = ModelRelationBelongsToTest_Que::find(1);
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $que->foo);

        static::assertSame($que, $que->foo()->associate(2, ['x' => 5]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $que->foo);
        static::assertEquals(2, $que->foo->fid);
        static::assertFalse($que->foo->isChanged('fid'));

        static::assertEquals(3, $que->foo->qid);
        static::assertFalse($que->foo->isChanged('qid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $que->foo->pivot);
        static::assertFalse($que->foo->pivot->isNewRecord());
        static::assertTrue($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(3, $que->foo->pivot->foo_id);
        static::assertFalse($que->foo->pivot->isChanged('que_id'));
        static::assertEquals(4, $que->foo->pivot->que_id);

        $que->saveWithRelation();
        static::assertFalse($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(
            [
                ['foo_id' => 3, 'que_id' => 4, 'x' => 5],
            ],
            $que->connection()->fetchAll('SELECT foo_id,que_id, x from `foo_que`', [], PDO::FETCH_ASSOC)
        );

        // exist model without relation (pivot exist)
        $que = ModelRelationBelongsToTest_Que::find(1);

        static::assertSame($que, $que->foo()->associate(1, ['x' => 7]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $que->foo);
        static::assertEquals(1, $que->foo->fid);
        static::assertFalse($que->foo->isChanged('fid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $que->foo->pivot);
        static::assertFalse($que->foo->pivot->isNewRecord());
        static::assertTrue($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(2, $que->foo->pivot->foo_id);
        static::assertFalse($que->foo->pivot->isChanged('que_id'));
        static::assertEquals(4, $que->foo->pivot->que_id);

        $que->saveWithRelation();
        static::assertFalse($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(
            [
                ['foo_id' => 2, 'que_id' => 4, 'x' => 7],
            ],
            $que->connection()->fetchAll('SELECT foo_id,que_id, x from `foo_que`', [], PDO::FETCH_ASSOC)
        );

        // exist model without relation (pivot not exist)
        $que = ModelRelationBelongsToTest_Que::createModel([
            'qid' => 6,
            'x' => 44
        ]);
        static::assertSame($que, $que->foo()->associate(2, ['x' => 8]));
        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $que->foo);
        static::assertEquals(2, $que->foo->fid);
        static::assertFalse($que->foo->isChanged('fid'));

        static::assertInstanceOf('Tanbolt\\Database\\Model\\Pivot', $que->foo->pivot);
        static::assertTrue($que->foo->pivot->isNewRecord());
        static::assertTrue($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(3, $que->foo->pivot->foo_id);
        static::assertTrue($que->foo->pivot->isChanged('que_id'));
        static::assertEquals(6, $que->foo->pivot->que_id);

        $que->saveWithRelation();
        static::assertFalse($que->foo->pivot->isChanged('foo_id'));
        static::assertEquals(
            [
                ['foo_id' => 2, 'que_id' => 4, 'x' => 7],
                ['foo_id' => 3, 'que_id' => 6, 'x' => 8],
            ],
            $que->connection()->fetchAll('SELECT foo_id,que_id, x from `foo_que`', [], PDO::FETCH_ASSOC)
        );
    }

    public function testDissociateOneToOneMethod()
    {
        // new
        $bar = new ModelRelationBelongsToTest_Bar();
        static::assertSame($bar, $bar->foo()->associate(1));
        static::assertEquals(1, $bar->foo_id);

        static::assertSame($bar, $bar->foo()->dissociate());
        static::assertEquals(0, $bar->foo_id);
        $bar->foo()->associate(1)->save();
        static::assertEquals(
            ['foo_id' => 1],
            $bar->connection()->fetchOne('SELECT foo_id from `bar` WHERE `bid` = ?', [1], PDO::FETCH_ASSOC)
        );

        // exist
        $bar = ModelRelationBelongsToTest_Bar::find(1);
        static::assertEquals(1, $bar->foo_id);
        static::assertSame($bar, $bar->foo()->dissociate());
        static::assertEquals(0, $bar->foo_id);
        static::assertEquals(
            ['foo_id' => 1],
            $bar->connection()->fetchOne('SELECT foo_id from `bar` WHERE `bid` = ?', [1], PDO::FETCH_ASSOC)
        );
        $bar->save();
        static::assertEquals(0, $bar->foo_id);
        static::assertEquals(
            ['foo_id' => 0],
            $bar->connection()->fetchOne('SELECT foo_id from `bar` WHERE `bid` = ?', [1], PDO::FETCH_ASSOC)
        );
    }

    public function testDissociateOneThroughOneMethod()
    {
        ModelRelationBelongsToTest_Foo::insert([
            ['x' => 2],
            ['x' => 3],
        ]);

        $biz = ModelRelationBelongsToTest_Biz::createModel([
            'x' => 44
        ]);
        $que = ModelRelationBelongsToTest_Que::createModel([
            'id' => 1,
            'qid' => 4
        ]);

        $biz->foo()->associate(1)->saveWithRelation();
        $que->foo()->associate(2)->saveWithRelation();

        static::assertInstanceOf('ModelRelationBelongsToTest_Foo', $biz->foo);
        $biz->foo()->dissociate();
        static::assertCount(
            0,
            $biz->connection()->fetchAll('SELECT id,foo_id,biz_id,x from `foo_biz`', [], PDO::FETCH_ASSOC)
        );

        $que->foo()->dissociate();
        static::assertCount(
            0,
            $que->connection()->fetchAll('SELECT foo_id,que_id, x from `foo_que`', [], PDO::FETCH_ASSOC)
        );
    }
}


class ModelRelationBelongsToTest_Foo extends Model
{
    protected $tableName = 'foo';

    protected $primaryColumn = 'fid';
}

class ModelRelationBelongsToTest_Bar extends Model
{
    protected $tableName = 'bar';

    protected $primaryColumn = 'bid';

    /**
     * @return Model\Relation\BelongsTo
     */
    public function foo()
    {
        return $this->belongsTo(
            'ModelRelationBelongsToTest_Foo', 'fid', 'foo_id'
        )->setFreeValue(0);
    }
}

class ModelRelationBelongsToTest_Biz extends Model
{
    protected $tableName = 'biz';

    protected $primaryColumn = 'id';

    /**
     * @return Model\Relation\BelongsTo
     */
    public function foo()
    {
        return $this->belongsTo('ModelRelationBelongsToTest_Foo', 'fid', 'id')
            ->throughTable('foo_biz', 'foo_id', 'biz_id', ['foo_id', 'biz_id']);
    }
}

class ModelRelationBelongsToTest_Que extends Model
{
    protected $tableName = 'que';

    /**
     * @return Model\Relation\BelongsTo
     */
    public function foo()
    {
        return $this->belongsTo('ModelRelationBelongsToTest_Foo', 'qid', 'qid')
            ->throughTable('foo_que', 'foo_id', 'que_id');
    }
}

