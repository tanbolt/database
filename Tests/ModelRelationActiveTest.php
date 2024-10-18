<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;
use Tanbolt\Database\Model\Helper;

class ModelRelationActiveTest extends TestCase
{
    protected static $dbPath = 'ModelRelationActiveTest';

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);

        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
        ]], true);
        $connection = (new ModelRelationActiveTest_Foo)->connection();

        // foo
        $connection->execute("CREATE TABLE `foo` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` mediumint
        )");

        // baz
        $connection->execute("CREATE TABLE `baz` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `foo_id` INTEGER,
            `x` mediumint
        )");

        // bar
        $connection->execute("CREATE TABLE `bar` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` mediumint
        )");
        // pivot
        $connection->execute("CREATE TABLE `pivot` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `foo_id` INTEGER,
            `bar_id` INTEGER,
            `x` mediumint
        )");

        
        // que
        $connection->execute("CREATE TABLE `que` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `bar_id` INTEGER,
            `x` mediumint
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

        // clear baz
        $statement = 'DELETE FROM `baz`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'baz'");

        // clear pivot
        $statement = 'DELETE FROM `pivot`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'pivot'");

        // clear que
        $statement = 'DELETE FROM `que`';
        Database::getNode()->execute($statement);
        Database::getNode()->statement("DELETE FROM `SQLITE_SEQUENCE` WHERE `name` = 'que'");
        parent::tearDown();
    }

    public function testModelOneWithOneRelation()
    {
        ModelRelationActiveTest_Foo::insert([
            ['x' => 1],
        ]);

        ModelRelationActiveTest_Bar::insert([
            ['x' => 11],
        ]);

        ModelRelationActiveTest_Pivot::insert([
            ['x' => 31, 'foo_id' => 1, 'bar_id' => 1],
        ]);

        // pivot table
        $foo = ModelRelationActiveTest_Foo::with('bar_table')->find(1);
        static::assertEquals(1, $foo->x);
        static::assertEquals(11, $foo->bar_table->x);
        static::assertEquals(31, $foo->bar_table->pivot->x);
        static::assertEquals('bar_id', $foo->bar_table->pivot->relationKey());
        static::assertEquals('foo_id', $foo->bar_table->pivot->parentKey());
        static::assertEquals(['bar_id', 'foo_id'], $foo->bar_table->pivot->getPrimaryColumn());

        $foo->x = 41;
        $foo->bar_table->x = 51;
        $foo->bar_table->pivot->x = 61;

        static::assertTrue($foo->isChanged());
        static::assertTrue($foo->bar_table->isChanged());
        static::assertTrue($foo->bar_table->pivot->isChanged());

        // only save foo
        $foo->save();
        static::assertEquals(41, $foo->x);
        static::assertEquals(51, $foo->bar_table->x);
        static::assertEquals(61, $foo->bar_table->pivot->x);
        static::assertFalse($foo->isChanged());
        static::assertTrue($foo->bar_table->isChanged());
        static::assertTrue($foo->bar_table->pivot->isChanged());

        static::assertEquals(
            ['x' => 41],
            $foo->connection()->fetchOne('SELECT x from `foo` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            ['x' => 11],
            $foo->connection()->fetchOne('SELECT x from `bar` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            ['x' => 31],
            $foo->connection()->fetchOne('SELECT x from `pivot` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );

        // save all relations
        $foo->x = 1;
        $foo->saveWithRelation();
        static::assertEquals(1, $foo->x);
        static::assertEquals(51, $foo->bar_table->x);
        static::assertEquals(61, $foo->bar_table->pivot->x);
        static::assertFalse($foo->isChanged());
        static::assertFalse($foo->bar_table->isChanged());
        static::assertFalse($foo->bar_table->pivot->isChanged());
        static::assertEquals(
            ['x' => 1],
            $foo->connection()->fetchOne('SELECT x from `foo` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            ['x' => 51],
            $foo->connection()->fetchOne('SELECT x from `bar` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            ['x' => 61],
            $foo->connection()->fetchOne('SELECT x from `pivot` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );

        // drop
        $foo->drop();
        static::assertTrue($foo->isNewRecord());
        static::assertFalse(ModelRelationActiveTest_Foo::with('bar_table')->find(1));
        static::assertFalse(
            $foo->connection()->fetchOne('SELECT x from `foo` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            ['x' => 51],
            $foo->connection()->fetchOne('SELECT x from `bar` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            ['x' => 61],
            $foo->connection()->fetchOne('SELECT x from `pivot` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );

        // drop all
        ModelRelationActiveTest_Foo::insert([
            ['id' => 1, 'x' => 1],
        ]);
        $foo = ModelRelationActiveTest_Foo::with('bar_table')->find(1);
        $foo->dropWithRelation();
        static::assertTrue($foo->isNewRecord());
        static::assertTrue($foo->bar_table->isNewRecord());
        static::assertTrue($foo->bar_table->pivot->isNewRecord());
        static::assertFalse(ModelRelationActiveTest_Foo::with('bar_table')->find(1));
        static::assertFalse(
            $foo->connection()->fetchOne('SELECT x from `foo` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertFalse(
            $foo->connection()->fetchOne('SELECT x from `bar` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
        static::assertFalse(
            $foo->connection()->fetchOne('SELECT x from `pivot` WHERE `id` = ?', [1], PDO::FETCH_ASSOC)
        );
    }

    public function testCollectionOneWithOneRelation()
    {
        ModelRelationActiveTest_Foo::insert([
            ['x' => 1],
            ['x' => 2],
            ['x' => 3],
        ]);

        ModelRelationActiveTest_Bar::insert([
            ['x' => 51],
            ['x' => 12],
            ['x' => 13],
        ]);

        ModelRelationActiveTest_Pivot::insert([
            ['x' => 61, 'foo_id' => 1, 'bar_id' => 1],
            ['x' => 32, 'foo_id' => 2, 'bar_id' => 3],
            ['x' => 33, 'foo_id' => 3, 'bar_id' => 2],
        ]);

        // collection test
        $foo = ModelRelationActiveTest_Foo::with('bar_table')->findMany();
        static::assertCount(3, $foo);

        $foo[0]->x = 41;
        $foo[1]->x = 42;
        $foo[2]->x = 43;

        $foo[0]->bar_table->x = 51;
        $foo[1]->bar_table->x = 52;
        $foo[2]->bar_table->x = 53;

        $foo[0]->bar_table->pivot->x = 61;
        $foo[1]->bar_table->pivot->x = 62;
        $foo[2]->bar_table->pivot->x = 63;

        static::assertTrue($foo[0]->isChanged());
        static::assertFalse($foo[0]->bar_table->isChanged());
        static::assertFalse($foo[0]->bar_table->pivot->isChanged());

        static::assertTrue($foo[1]->isChanged());
        static::assertTrue($foo[1]->bar_table->isChanged());
        static::assertTrue($foo[1]->bar_table->pivot->isChanged());

        static::assertTrue($foo[2]->isChanged());
        static::assertTrue($foo[2]->bar_table->isChanged());
        static::assertTrue($foo[2]->bar_table->pivot->isChanged());

        // only save foo
        $foo->save();
        static::assertEquals(41, $foo[0]->x);
        static::assertEquals(51, $foo[0]->bar_table->x);
        static::assertEquals(61, $foo[0]->bar_table->pivot->x);

        static::assertEquals(42, $foo[1]->x);
        static::assertEquals(52, $foo[1]->bar_table->x);
        static::assertEquals(62, $foo[1]->bar_table->pivot->x);

        static::assertEquals(43, $foo[2]->x);
        static::assertEquals(53, $foo[2]->bar_table->x);
        static::assertEquals(63, $foo[2]->bar_table->pivot->x);

        static::assertFalse($foo[0]->isChanged());
        static::assertFalse($foo[0]->bar_table->isChanged());
        static::assertFalse($foo[0]->bar_table->pivot->isChanged());

        static::assertFalse($foo[1]->isChanged());
        static::assertTrue($foo[1]->bar_table->isChanged());
        static::assertTrue($foo[1]->bar_table->pivot->isChanged());

        static::assertFalse($foo[2]->isChanged());
        static::assertTrue($foo[2]->bar_table->isChanged());
        static::assertTrue($foo[2]->bar_table->pivot->isChanged());

        static::assertEquals(
            [
                ['x' => 41],
                ['x' => 42],
                ['x' => 43],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 51],
                ['x' => 12],
                ['x' => 13],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 61],
                ['x' => 32],
                ['x' => 33],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // save all relations
        $foo[0]->x = 1;
        $foo[1]->x = 2;
        $foo[2]->x = 3;
        $foo->saveWithRelation();

        static::assertEquals(1, $foo[0]->x);
        static::assertEquals(51, $foo[0]->bar_table->x);
        static::assertEquals(61, $foo[0]->bar_table->pivot->x);

        static::assertEquals(2, $foo[1]->x);
        static::assertEquals(52, $foo[1]->bar_table->x);
        static::assertEquals(62, $foo[1]->bar_table->pivot->x);

        static::assertEquals(3, $foo[2]->x);
        static::assertEquals(53, $foo[2]->bar_table->x);
        static::assertEquals(63, $foo[2]->bar_table->pivot->x);

        static::assertFalse($foo[0]->isChanged());
        static::assertFalse($foo[0]->bar_table->isChanged());
        static::assertFalse($foo[0]->bar_table->pivot->isChanged());

        static::assertFalse($foo[1]->isChanged());
        static::assertFalse($foo[1]->bar_table->isChanged());
        static::assertFalse($foo[1]->bar_table->pivot->isChanged());

        static::assertFalse($foo[2]->isChanged());
        static::assertFalse($foo[2]->bar_table->isChanged());
        static::assertFalse($foo[2]->bar_table->pivot->isChanged());

        static::assertEquals(
            [
                ['x' => 1],
                ['x' => 2],
                ['x' => 3],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 51],
                ['x' => 53],
                ['x' => 52],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 61],
                ['x' => 62],
                ['x' => 63],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // drop
        $foo->drop();
        static::assertTrue($foo[0]->isNewRecord());
        static::assertTrue($foo[1]->isNewRecord());

        static::assertFalse($foo[0]->bar_table->isNewRecord());
        static::assertFalse($foo[0]->bar_table->pivot->isNewRecord());

        static::assertCount(0, ModelRelationActiveTest_Foo::with('bar_table')->findMany());
        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 51],
                ['x' => 53],
                ['x' => 52],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 61],
                ['x' => 62],
                ['x' => 63],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // drop all
        ModelRelationActiveTest_Foo::insert([
            ['id' => 1, 'x' => 1],
            ['id' => 2, 'x' => 2],
            ['id' => 3, 'x' => 3],
        ]);

        $foo = ModelRelationActiveTest_Foo::with('bar_table')->findMany();
        static::assertCount(3, $foo);
        $foo->dropWithRelation();

        static::assertTrue($foo[0]->isNewRecord());
        static::assertTrue($foo[1]->isNewRecord());

        static::assertTrue($foo[0]->bar_table->isNewRecord());
        static::assertTrue($foo[0]->bar_table->pivot->isNewRecord());

        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
    }

    public function testModelOneWithManyRelation()
    {
        ModelRelationActiveTest_Foo::insert([
            ['x' => 1],
        ]);

        ModelRelationActiveTest_Bar::insert([
            ['x' => 11],
            ['x' => 12],
            ['x' => 13],
        ]);

        ModelRelationActiveTest_Pivot::insert([
            ['x' => 31, 'foo_id' => 1, 'bar_id' => 1],
            ['x' => 32, 'foo_id' => 1, 'bar_id' => 2],
            ['x' => 33, 'foo_id' => 1, 'bar_id' => 3],
        ]);

        $foo = ModelRelationActiveTest_Foo::with('bars_table')->find(1);
        static::assertEquals(1, $foo->x);
        static::assertCount(3, $foo->bars_table);

        static::assertEquals(11, $foo->bars_table[0]->x);
        static::assertEquals(12, $foo->bars_table[1]->x);
        static::assertEquals(13, $foo->bars_table[2]->x);

        static::assertEquals(31, $foo->bars_table[0]->pivot->x);
        static::assertEquals(32, $foo->bars_table[1]->pivot->x);
        static::assertEquals(33, $foo->bars_table[2]->pivot->x);

        static::assertEquals('bar_id', $foo->bars_table[0]->pivot->relationKey());
        static::assertEquals('foo_id', $foo->bars_table[0]->pivot->parentKey());
        static::assertEquals('id', $foo->bars_table[0]->pivot->getPrimaryColumn());

        $foo->x = 41;
        $foo->bars_table[0]->x = 51;
        $foo->bars_table[1]->x = 12;
        $foo->bars_table[2]->x = 53;

        $foo->bars_table[0]->pivot->x = 61;
        $foo->bars_table[1]->pivot->x = 62;
        $foo->bars_table[2]->pivot->x = 33;

        static::assertTrue($foo->isChanged());
        static::assertTrue($foo->bars_table[0]->isChanged());
        static::assertFalse($foo->bars_table[1]->isChanged());
        static::assertTrue($foo->bars_table[2]->isChanged());
        static::assertTrue($foo->bars_table[0]->pivot->isChanged());
        static::assertTrue($foo->bars_table[1]->pivot->isChanged());
        static::assertFalse($foo->bars_table[2]->pivot->isChanged());

        // only save foo
        $foo->save();
        static::assertEquals(41, $foo->x);

        static::assertEquals(51, $foo->bars_table[0]->x);
        static::assertEquals(12, $foo->bars_table[1]->x);
        static::assertEquals(53, $foo->bars_table[2]->x);

        static::assertEquals(61, $foo->bars_table[0]->pivot->x);
        static::assertEquals(62, $foo->bars_table[1]->pivot->x);
        static::assertEquals(33, $foo->bars_table[2]->pivot->x);

        static::assertFalse($foo->isChanged());
        static::assertTrue($foo->bars_table[0]->isChanged());
        static::assertFalse($foo->bars_table[1]->isChanged());
        static::assertTrue($foo->bars_table[2]->isChanged());
        static::assertTrue($foo->bars_table[0]->pivot->isChanged());
        static::assertTrue($foo->bars_table[1]->pivot->isChanged());
        static::assertFalse($foo->bars_table[2]->pivot->isChanged());

        static::assertEquals(
            [
                ['x' => 41],
            ],
            $foo->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 11],
                ['x' => 12],
                ['x' => 13],
            ],
            $foo->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 31],
                ['x' => 32],
                ['x' => 33],
            ],
            $foo->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // save all relations
        $foo->x = 1;
        $foo->saveWithRelation();

        static::assertEquals(1, $foo->x);

        static::assertEquals(51, $foo->bars_table[0]->x);
        static::assertEquals(12, $foo->bars_table[1]->x);
        static::assertEquals(53, $foo->bars_table[2]->x);

        static::assertEquals(61, $foo->bars_table[0]->pivot->x);
        static::assertEquals(62, $foo->bars_table[1]->pivot->x);
        static::assertEquals(33, $foo->bars_table[2]->pivot->x);

        static::assertFalse($foo->isChanged());
        static::assertFalse($foo->bars_table[0]->isChanged());
        static::assertFalse($foo->bars_table[1]->isChanged());
        static::assertFalse($foo->bars_table[2]->isChanged());
        static::assertFalse($foo->bars_table[0]->pivot->isChanged());
        static::assertFalse($foo->bars_table[1]->pivot->isChanged());
        static::assertFalse($foo->bars_table[2]->pivot->isChanged());

        static::assertEquals(
            [
                ['x' => 1],
            ],
            $foo->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 51],
                ['x' => 12],
                ['x' => 53],
            ],
            $foo->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 61],
                ['x' => 62],
                ['x' => 33],
            ],
            $foo->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // drop
        $foo->drop();
        static::assertFalse(ModelRelationActiveTest_Foo::with('bars_table')->find(1));

        static::assertEquals(
            [],
            $foo->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 51],
                ['x' => 12],
                ['x' => 53],
            ],
            $foo->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 61],
                ['x' => 62],
                ['x' => 33],
            ],
            $foo->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // drop all
        ModelRelationActiveTest_Foo::insert([
            ['id' => 1, 'x' => 1],
        ]);
        ModelRelationActiveTest_Foo::with('bars_table')->find(1)->dropWithRelation();
        static::assertFalse(ModelRelationActiveTest_Foo::with('bar_table')->find(1));
        static::assertEquals(
            [],
            $foo->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [],
            $foo->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [],
            $foo->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
    }

    public function testCollectionOneWithManyRelation()
    {
        ModelRelationActiveTest_Foo::insert([
            ['x' => 1],
            ['x' => 2],
        ]);

        ModelRelationActiveTest_Bar::insert([
            ['x' => 11],
            ['x' => 12],
            ['x' => 13],
        ]);

        ModelRelationActiveTest_Pivot::insert([
            ['x' => 31, 'foo_id' => 1, 'bar_id' => 1],
            ['x' => 32, 'foo_id' => 2, 'bar_id' => 2],
            ['x' => 33, 'foo_id' => 2, 'bar_id' => 3],
        ]);

        // collection test
        $foo = ModelRelationActiveTest_Foo::with('bars_table')->findMany();
        static::assertCount(2, $foo);

        $foo[0]->x = 41;
        $foo[1]->x = 42;

        $foo[0]->bars_table[0]->x = 51;
        $foo[0]->bars_table[0]->pivot->x = 61;

        $foo[1]->bars_table[0]->x = 52;
        $foo[1]->bars_table[1]->x = 13;

        $foo[1]->bars_table[0]->pivot->x = 62;
        $foo[1]->bars_table[1]->pivot->x = 33;

        static::assertTrue($foo[0]->isChanged());
        static::assertTrue($foo[0]->bars_table[0]->isChanged());
        static::assertTrue($foo[0]->bars_table[0]->pivot->isChanged());

        static::assertTrue($foo[1]->isChanged());
        static::assertTrue($foo[1]->bars_table[0]->isChanged());
        static::assertTrue($foo[1]->bars_table[0]->pivot->isChanged());
        static::assertFalse($foo[1]->bars_table[1]->isChanged());
        static::assertFalse($foo[1]->bars_table[1]->pivot->isChanged());

        // only save foo
        $foo->save();
        static::assertEquals(41, $foo[0]->x);
        static::assertEquals(51, $foo[0]->bars_table[0]->x);
        static::assertEquals(61, $foo[0]->bars_table[0]->pivot->x);

        static::assertEquals(42, $foo[1]->x);
        static::assertEquals(52, $foo[1]->bars_table[0]->x);
        static::assertEquals(13, $foo[1]->bars_table[1]->x);
        static::assertEquals(62, $foo[1]->bars_table[0]->pivot->x);
        static::assertEquals(33, $foo[1]->bars_table[1]->pivot->x);

        static::assertFalse($foo[0]->isChanged());
        static::assertTrue($foo[0]->bars_table[0]->isChanged());
        static::assertTrue($foo[0]->bars_table[0]->pivot->isChanged());

        static::assertFalse($foo[1]->isChanged());
        static::assertTrue($foo[1]->bars_table[0]->isChanged());
        static::assertTrue($foo[1]->bars_table[0]->pivot->isChanged());
        static::assertFalse($foo[1]->bars_table[1]->isChanged());
        static::assertFalse($foo[1]->bars_table[1]->pivot->isChanged());

        static::assertEquals(
            [
                ['x' => 41],
                ['x' => 42],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 11],
                ['x' => 12],
                ['x' => 13],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 31],
                ['x' => 32],
                ['x' => 33],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // save all relations
        $foo[0]->x = 1;
        $foo[1]->x = 2;
        $foo->saveWithRelation();

        static::assertEquals(1, $foo[0]->x);
        static::assertEquals(51, $foo[0]->bars_table[0]->x);
        static::assertEquals(61, $foo[0]->bars_table[0]->pivot->x);

        static::assertEquals(2, $foo[1]->x);
        static::assertEquals(52, $foo[1]->bars_table[0]->x);
        static::assertEquals(13, $foo[1]->bars_table[1]->x);
        static::assertEquals(62, $foo[1]->bars_table[0]->pivot->x);
        static::assertEquals(33, $foo[1]->bars_table[1]->pivot->x);

        static::assertFalse($foo[0]->isChanged());
        static::assertFalse($foo[0]->bars_table[0]->isChanged());
        static::assertFalse($foo[0]->bars_table[0]->pivot->isChanged());

        static::assertFalse($foo[1]->isChanged());
        static::assertFalse($foo[1]->bars_table[0]->isChanged());
        static::assertFalse($foo[1]->bars_table[0]->pivot->isChanged());
        static::assertFalse($foo[1]->bars_table[1]->isChanged());
        static::assertFalse($foo[1]->bars_table[1]->pivot->isChanged());

        static::assertEquals(
            [
                ['x' => 1],
                ['x' => 2],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 51],
                ['x' => 52],
                ['x' => 13],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 61],
                ['x' => 62],
                ['x' => 33],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // drop
        $foo->drop();
        static::assertCount(0, ModelRelationActiveTest_Foo::with('bar_table')->findMany());
        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 51],
                ['x' => 52],
                ['x' => 13],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 61],
                ['x' => 62],
                ['x' => 33],
            ],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // drop all
        ModelRelationActiveTest_Foo::insert([
            ['id' => 1, 'x' => 1],
            ['id' => 2, 'x' => 2],
        ]);

        $foo = ModelRelationActiveTest_Foo::with('bars_table')->findMany();
        static::assertCount(2, $foo);
        $foo->dropWithRelation();
        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [],
            $foo[0]->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
    }

    public function testModelActiveWithMultiRelation()
    {
        ModelRelationActiveTest_Foo::insert([
            ['x' => 1],
            ['x' => 2],
        ]);

        ModelRelationActiveTest_Baz::insert([
            ['foo_id' => 1, 'x' => 21],
            ['foo_id' => 2, 'x' => 22],
            ['foo_id' => 2, 'x' => 23],
        ]);

        ModelRelationActiveTest_Bar::insert([
            ['x' => 11],
            ['x' => 12],
            ['x' => 13],
        ]);

        ModelRelationActiveTest_Pivot::insert([
            ['x' => 31, 'foo_id' => 1, 'bar_id' => 1],
            ['x' => 32, 'foo_id' => 2, 'bar_id' => 2],
            ['x' => 33, 'foo_id' => 2, 'bar_id' => 3],
        ]);

        ModelRelationActiveTest_Que::insert([
            ['bar_id' => 1, 'x' => 41],
            ['bar_id' => 2, 'x' => 42],
        ]);

        // flattenModel by collection
        $collection = ModelRelationActiveTest_Foo::with('baz', 'bar_table.que')->findMany([1, 2]);
        $sorting = [];
        foreach (Helper::flattenModels($collection) as $k=>$d) {
            $sorting[$k] = count($d);
        }
        ksort($sorting);
        static::assertEquals([
            'Tanbolt\Database\Model\Pivot' => 2,
            'ModelRelationActiveTest_Bar' => 2,
            'ModelRelationActiveTest_Baz' => 3,
            'ModelRelationActiveTest_Foo' => 2,
            'ModelRelationActiveTest_Que' => 2,
        ], $sorting);

        // flattenModel by model
        $foo = ModelRelationActiveTest_Foo::with('baz', 'bars_table.que')->find(2);
        $sorting = [];
        foreach (Helper::flattenModels($foo) as $k=>$d) {
            $sorting[$k] = count($d);
        }
        ksort($sorting);
        static::assertEquals([
            'Tanbolt\Database\Model\Pivot' => 2,
            'ModelRelationActiveTest_Bar' => 2,
            'ModelRelationActiveTest_Baz' => 2,
            'ModelRelationActiveTest_Foo' => 1,
            'ModelRelationActiveTest_Que' => 1,
        ], $sorting);

        // save
        $foo->x = 52;
        $foo->baz[0]->x = 62;
        $foo->baz[1]->x = 63;
        $foo->bars_table[0]->x = 72;
        $foo->bars_table[1]->pivot->x = 83;
        $foo->bars_table[0]->que->x = 92;
        $foo->saveWithRelation();
        static::assertEquals(
            [
                ['x' => 1],
                ['x' => 52],
            ],
            $foo->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 21],
                ['x' => 62],
                ['x' => 63],
            ],
            $foo->connection()->fetchAll('SELECT x from `baz` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 11],
                ['x' => 72],
                ['x' => 13],
            ],
            $foo->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 31],
                ['x' => 32],
                ['x' => 83],
            ],
            $foo->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 41],
                ['x' => 92],
            ],
            $foo->connection()->fetchAll('SELECT x from `que` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );

        // drop
        $foo->dropWithRelation();
        static::assertEquals(
            [
                ['x' => 1],
            ],
            $foo->connection()->fetchAll('SELECT x from `foo` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 21],
            ],
            $foo->connection()->fetchAll('SELECT x from `baz` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 11],
            ],
            $foo->connection()->fetchAll('SELECT x from `bar` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 31],
            ],
            $foo->connection()->fetchAll('SELECT x from `pivot` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
        static::assertEquals(
            [
                ['x' => 41],
            ],
            $foo->connection()->fetchAll('SELECT x from `que` ORDER BY id ASC', [], PDO::FETCH_ASSOC)
        );
    }
}


class ModelRelationActiveTest_Foo extends Model
{
    protected $tableName = 'foo';

    public function baz()
    {
        return $this->oneToMany('ModelRelationActiveTest_Baz', 'foo_id', 'id');
    }

    public function barTable()
    {
        return $this->oneToOne('ModelRelationActiveTest_Bar', 'id', 'id')
            ->throughTable('pivot', 'bar_id', 'foo_id')->withPivot('x');
    }

    public function barsTable()
    {
        return $this->oneToMany('ModelRelationActiveTest_Bar', 'id', 'id')
            ->throughTable('pivot', 'bar_id', 'foo_id', 'id')->withPivot('x');
    }
}

class ModelRelationActiveTest_Baz extends Model
{
    protected $tableName = 'baz';
}

class ModelRelationActiveTest_Bar extends Model
{
    protected $tableName = 'bar';

    public function que()
    {
        return $this->oneToOne('ModelRelationActiveTest_Que', 'bar_id', 'id');
    }
}

class ModelRelationActiveTest_Que extends Model
{
    protected $tableName = 'que';
}

class ModelRelationActiveTest_Pivot extends Model
{
    protected $tableName = 'pivot';
}
