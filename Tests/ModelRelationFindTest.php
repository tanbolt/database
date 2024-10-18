<?php
use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelRelationFindTest extends TestCase
{
    protected static $dbPath = 'ModelRelationFindTest';

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
        ]], true);
        $connection = (new ModelRelationFindTest_Foo)->connection();

        // foo
        $connection->execute("CREATE TABLE `foo` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` mediumint
        )");

        // bar
        $connection->execute("CREATE TABLE `bar` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `foo_id` INTEGER,
            `x` mediumint
        )");

        // baz
        $connection->execute("CREATE TABLE `baz` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `bar_id` INTEGER,
            `x` mediumint
        )");

        // que
        $connection->execute("CREATE TABLE `que` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` mediumint
        )");

        // pivot
        $connection->execute("CREATE TABLE `pivot` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `baz_id` INTEGER,
            `que_id` INTEGER,
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

    public function testRelationBasicMethod()
    {
        ModelRelationFindTest_Foo::insert([
            ['x' => 1],
            ['x' => 2],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
            ['x' => 6],
        ]);

        ModelRelationFindTest_Bar::insert([
            ['x' => 11, 'foo_id' => 1],
            ['x' => 12, 'foo_id' => 2],
            ['x' => 13, 'foo_id' => 2],
            ['x' => 14, 'foo_id' => 2],
            ['x' => 15, 'foo_id' => 3],
            ['x' => 16, 'foo_id' => 4],
        ]);

        ModelRelationFindTest_Baz::insert([
            ['x' => 21, 'bar_id' => 1],
            ['x' => 22, 'bar_id' => 2],
            ['x' => 23, 'bar_id' => 2],
            ['x' => 24, 'bar_id' => 2],
        ]);

        // one to one
        $foo = ModelRelationFindTest_Foo::find(1);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(11, $foo->bar->x);

        $foo = ModelRelationFindTest_Foo::find(2);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(12, $foo->bar->x);

        // one to many
        $foo = ModelRelationFindTest_Foo::find(2);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(3, $foo->bars);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bars[0]);
        static::assertEquals(12, $foo->bars[0]->x);
        static::assertEquals(13, $foo->bars[1]->x);
        static::assertEquals(14, $foo->bars[2]->x);

        // relation with custom constraint
        $bar = $foo->bar()->where('x', 13)->find();
        static::assertInstanceOf('ModelRelationFindTest_Bar', $bar);
        static::assertEquals(13, $bar->x);

        $bars = $foo->bar()->where('x', '>', 12)->findMany();
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bars);
        static::assertCount(2, $bars);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $bars[0]);
        static::assertEquals(13, $bars[0]->x);
        static::assertEquals(14, $bars[1]->x);

        // relation not exist
        static::assertNull(ModelRelationFindTest_Foo::find(6)->bar);
        $bars = ModelRelationFindTest_Foo::find(6)->bars;
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bars);
        static::assertCount(0, $bars);

        // many
        $bar = ModelRelationFindTest_Bar::find(1);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar->baz);
        static::assertCount(1, $bar->baz);
        static::assertInstanceOf('ModelRelationFindTest_Baz', $bar->baz[0]);
        static::assertEquals(21, $bar->baz[0]->x);

        $bar = ModelRelationFindTest_Bar::find(2);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bar->baz);
        static::assertCount(3, $bar->baz);
        static::assertInstanceOf('ModelRelationFindTest_Baz', $bar->baz[0]);
        static::assertEquals(22, $bar->baz[0]->x);
        static::assertEquals(23, $bar->baz[1]->x);
        static::assertEquals(24, $bar->baz[2]->x);

        // lazy load relation
        $bars = ModelRelationFindTest_Bar::findMany([1, 2]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $bars);
        static::assertCount(2, $bars);

        $baz = $bars[0]->baz;
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $baz);
        static::assertCount(1, $baz);
        static::assertInstanceOf('ModelRelationFindTest_Baz', $baz[0]);
        static::assertEquals(21, $baz[0]->x);

        $baz = $bars[1]->baz;
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $baz);
        static::assertCount(3, $baz);
        static::assertInstanceOf('ModelRelationFindTest_Baz', $baz[0]);
        static::assertEquals(22, $baz[0]->x);
        static::assertEquals(23, $baz[1]->x);
        static::assertEquals(24, $baz[2]->x);
    }

    public function testRelationThroughPivot()
    {
        ModelRelationFindTest_Baz::insert([
            ['x' => 1],
            ['x' => 2],
            ['x' => 3],
        ]);

        ModelRelationFindTest_Que::insert([
            ['x' => 11],
            ['x' => 12],
            ['x' => 13],
            ['x' => 14],
        ]);

        ModelRelationFindTest_Pivot::insert([
            ['x' => 21, 'baz_id' => 1, 'que_id' => 1],
            ['x' => 22, 'baz_id' => 2, 'que_id' => 2],
            ['x' => 23, 'baz_id' => 2, 'que_id' => 3],
            ['x' => 24, 'baz_id' => 2, 'que_id' => 4],
        ]);

        // pivot table : one to one
        $baz = ModelRelationFindTest_Baz::find(1);
        static::assertInstanceOf('ModelRelationFindTest_Que', $baz->que_table);
        static::assertEquals(11, $baz->que_table->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $baz->que_table->pivot);
        static::assertEquals('que_id', $baz->que_table->pivot->relationKey());
        static::assertEquals('baz_id', $baz->que_table->pivot->parentKey());
        static::assertEquals(1, $baz->que_table->pivot->baz_id);
        static::assertEquals(1, $baz->que_table->pivot->que_id);
        static::assertFalse(isset($baz->que_table->pivot['x']));

        // pivot table : one to one with pivot column
        $baz = ModelRelationFindTest_Baz::find(1);
        $que = $baz->queTable()->withPivot('x')->findResults();
        static::assertInstanceOf('ModelRelationFindTest_Que', $que);
        static::assertEquals(11, $que->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $que->pivot);
        static::assertEquals(1, $que->pivot->baz_id);
        static::assertEquals(1, $que->pivot->que_id);
        static::assertEquals(21, $que->pivot->x);

        // pivot table : one to many
        $baz = ModelRelationFindTest_Baz::find(2);
        $ques = $baz->ques_table;
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $ques);
        static::assertCount(3, $ques);
        static::assertInstanceOf('ModelRelationFindTest_Que', $ques[0]);
        static::assertEquals(12, $ques[0]->x);
        static::assertEquals(13, $ques[1]->x);
        static::assertEquals(14, $ques[2]->x);
        $pivot = $ques[0]->pivot;
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $pivot);
        static::assertEquals(2, $pivot->baz_id);
        static::assertEquals(2, $pivot->que_id);
        static::assertEquals(22, $pivot->x);

        // pivot table : one to many with relation constraint
        $baz = ModelRelationFindTest_Baz::find(2);
        $ques = $baz->quesTable()->where('x', '>', 13)->findResults();
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $ques);
        static::assertCount(1, $ques);
        static::assertInstanceOf('ModelRelationFindTest_Que', $ques[0]);
        static::assertEquals(14, $ques[0]->x);
        $pivot = $ques[0]->pivot;
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $pivot);
        static::assertEquals(2, $pivot->baz_id);
        static::assertEquals(4, $pivot->que_id);
        static::assertEquals(24, $pivot->x);

        // pivot table : one to many with pivot constraint
        $baz = ModelRelationFindTest_Baz::find(2);
        $ques = $baz->quesTable()->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
            $clause->where('x', '>', 22);
        })->findResults();
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $ques);
        static::assertCount(2, $ques);
        static::assertInstanceOf('ModelRelationFindTest_Que', $ques[0]);
        static::assertEquals(13, $ques[0]->x);
        static::assertEquals(14, $ques[1]->x);
        $pivot = $ques[0]->pivot;
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $pivot);
        static::assertEquals(2, $pivot->baz_id);
        static::assertEquals(3, $pivot->que_id);
        static::assertEquals(23, $pivot->x);

        // pivot table : one to many with relation constraint and pivot constraint
        $baz = ModelRelationFindTest_Baz::find(2);
        $ques = $baz->quesTable()->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
            $clause->where('x', '>', 22);
        })->where('x', '<', 14)->findResults();
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $ques);
        static::assertCount(1, $ques);
        static::assertInstanceOf('ModelRelationFindTest_Que', $ques[0]);
        static::assertEquals(13, $ques[0]->x);
        $pivot = $ques[0]->pivot;
        static::assertInstanceOf('Tanbolt\Database\Model\Pivot', $pivot);
        static::assertEquals(2, $pivot->baz_id);
        static::assertEquals(3, $pivot->que_id);
        static::assertEquals(23, $pivot->x);

        // pivot model : one to one
        $baz = ModelRelationFindTest_Baz::find(1);
        $que = $baz->que_model;
        static::assertInstanceOf('ModelRelationFindTest_Que', $que);
        static::assertEquals(11, $que->x);
        static::assertInstanceOf('ModelRelationFindTest_Pivot', $que->pivot);
        static::assertEquals(1, $que->pivot->baz_id);
        static::assertEquals(1, $que->pivot->que_id);
        static::assertEquals(21, $que->pivot->x);

        // pivot model : one to many  (already has pivot constraint)
        $baz = ModelRelationFindTest_Baz::find(2);
        $ques = $baz->ques_model;
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $ques);
        static::assertCount(2, $ques);
        static::assertInstanceOf('ModelRelationFindTest_Que', $ques[0]);
        static::assertEquals(13, $ques[0]->x);
        static::assertEquals(14, $ques[1]->x);
        $pivot = $ques[0]->pivot;
        static::assertInstanceOf('ModelRelationFindTest_Pivot', $pivot);
        static::assertEquals(2, $pivot->baz_id);
        static::assertEquals(3, $pivot->que_id);
        static::assertFalse(isset($pivot['x']));

        // pivot model : one to many with relation constraint (already has pivot constraint)
        $baz = ModelRelationFindTest_Baz::find(2);
        $ques = $baz->quesModel()->where('x', '>', 13)->findResults();
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $ques);
        static::assertCount(1, $ques);
        static::assertInstanceOf('ModelRelationFindTest_Que', $ques[0]);
        static::assertEquals(14, $ques[0]->x);
        $pivot = $ques[0]->pivot;
        static::assertInstanceOf('ModelRelationFindTest_Pivot', $pivot);
        static::assertEquals(2, $pivot->baz_id);
        static::assertEquals(4, $pivot->que_id);
        static::assertFalse(isset($pivot['x']));

        $blankQues = $baz->quesModel()->where('x', '<', 13)->findResults();
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $blankQues);
        static::assertCount(0, $blankQues);

        // pivot model : one to many  (reset pivot constraint)
        $baz = ModelRelationFindTest_Baz::find(2);
        $ques = $baz->quesModel()->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
            $clause->where('x', '>', 23);
        })->withPivot('x')->findResults();
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $ques);
        static::assertCount(1, $ques);
        static::assertInstanceOf('ModelRelationFindTest_Que', $ques[0]);
        static::assertEquals(14, $ques[0]->x);
        $pivot = $ques[0]->pivot;
        static::assertInstanceOf('ModelRelationFindTest_Pivot', $pivot);
        static::assertEquals(2, $pivot->baz_id);
        static::assertEquals(4, $pivot->que_id);
        static::assertEquals(24, $pivot->x);
    }

    public function testLoadRelation()
    {
        ModelRelationFindTest_Foo::insert([
            ['x' => 1],
            ['x' => 2],
            ['x' => 3],
            ['x' => 4],
            ['x' => 5],
            ['x' => 6],
        ]);

        ModelRelationFindTest_Bar::insert([
            ['x' => 11, 'foo_id' => 1],
            ['x' => 12, 'foo_id' => 2],
            ['x' => 13, 'foo_id' => 2],
            ['x' => 14, 'foo_id' => 2],
            ['x' => 15, 'foo_id' => 3],
            ['x' => 16, 'foo_id' => 4],
        ]);

        ModelRelationFindTest_Baz::insert([
            ['x' => 21, 'bar_id' => 1],
            ['x' => 22, 'bar_id' => 2],
            ['x' => 23, 'bar_id' => 2],
            ['x' => 24, 'bar_id' => 3],
        ]);

        ModelRelationFindTest_Que::insert([
            ['x' => 11],
            ['x' => 12],
            ['x' => 13],
            ['x' => 14],
        ]);

        ModelRelationFindTest_Pivot::insert([
            ['x' => 31, 'baz_id' => 1, 'que_id' => 1],
            ['x' => 32, 'baz_id' => 2, 'que_id' => 2],
            ['x' => 33, 'baz_id' => 2, 'que_id' => 3],
            ['x' => 34, 'baz_id' => 2, 'que_id' => 4],
        ]);


        $query = 0;
        Database::getNode()->setListener(function() use (&$query) {
            $query++;
        });

        // one with one
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bar')->find(1);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(11, $foo->bar->x);
        static::assertEquals(2, $query);

        // one load one
        $query = 0;
        $foo = ModelRelationFindTest_Foo::find(1);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertEquals(1, $query);
        $foo->loadRelation('bar');
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(11, $foo->bar->x);
        static::assertEquals(2, $query);

        // one with many
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bars')->find(2);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(3, $foo->bars);
        static::assertEquals(2, $query);

        // one load many
        $query = 0;
        $foo = ModelRelationFindTest_Foo::find(2);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertEquals(1, $query);
        $foo->loadRelation('bars');
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(3, $foo->bars);
        static::assertEquals(2, $query);

        // many with one
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bar')->findMany([1, 2]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[0]->bar);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[1]->bar);
        static::assertEquals(2, $query);

        // many load one
        $query = 0;
        $foo = ModelRelationFindTest_Foo::findMany([1, 2]);
        static::assertEquals(1, $query);
        $foo->loadRelation('bar');
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[0]->bar);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[1]->bar);
        static::assertEquals(2, $query);

        // many with many
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bars')->findMany([1, 2]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bars);
        static::assertCount(1, $foo[0]->bars);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bars);
        static::assertCount(3, $foo[1]->bars);
        static::assertEquals(2, $query);

        // many load many
        $query = 0;
        $foo = ModelRelationFindTest_Foo::findMany([1, 2]);
        static::assertEquals(1, $query);
        $foo->loadRelation('bars');
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bars);
        static::assertCount(1, $foo[0]->bars);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bars);
        static::assertCount(3, $foo[1]->bars);
        static::assertEquals(2, $query);

        // one with one   (custom constraint)
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with(['bar' => function(Model\Relation $relation) {
            $relation->where('x', '>', 12);
        }])->find(1);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertNull($foo->bar);
        static::assertEquals(2, $query);

        // one load one   (custom constraint)
        $query = 0;
        $foo = ModelRelationFindTest_Foo::find(1);
        static::assertEquals(1, $query);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        $foo->loadRelation(['bar' => function(Model\Relation $relation) {
            $relation->with('baz');
        }]);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(11, $foo->bar->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bar->baz);
        static::assertCount(1, $foo->bar->baz);
        static::assertEquals(21, $foo->bar->baz[0]->x);
        static::assertEquals(3, $query);

        // one with many   (custom constraint)
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with(['bars' => function(Model\Relation $relation) {
            $relation->where('x', '<', 14)->with('baz');
        }])->find(2);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(2, $foo->bars);
        static::assertEquals(12, $foo->bars[0]->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars[0]->baz);
        static::assertCount(2, $foo->bars[0]->baz);
        static::assertEquals(22, $foo->bars[0]->baz[0]->x);
        static::assertEquals(23, $foo->bars[0]->baz[1]->x);
        static::assertEquals(13, $foo->bars[1]->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars[1]->baz);
        static::assertCount(1, $foo->bars[1]->baz);
        static::assertEquals(24, $foo->bars[1]->baz[0]->x);
        static::assertEquals(3, $query);

        // one load many   (custom constraint)
        $query = 0;
        $foo = ModelRelationFindTest_Foo::find(2);
        static::assertEquals(1, $query);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        $foo->loadRelation(['bars' => function(Model\Relation $relation) {
            $relation->where('x', '>', 13);
        }]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(1, $foo->bars);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bars[0]);
        static::assertEquals(14, $foo->bars[0]->x);
        static::assertEquals(2, $query);

        // many with many   (custom constraint)
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with(['bars' => function(Model\Relation $relation) {
            $relation->with('baz');
        }])->findMany([1, 2]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertEquals(1, $foo[0]->x);
        static::assertEquals(2, $foo[1]->x);

        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bars);
        static::assertCount(1, $foo[0]->bars);
        static::assertEquals(11, $foo[0]->bars[0]->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bars[0]->baz);
        static::assertCount(1, $foo[0]->bars[0]->baz);
        static::assertEquals(21, $foo[0]->bars[0]->baz[0]->x);

        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bars);
        static::assertCount(3, $foo[1]->bars);
        static::assertEquals(12, $foo[1]->bars[0]->x);
        static::assertEquals(13, $foo[1]->bars[1]->x);
        static::assertEquals(14, $foo[1]->bars[2]->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bars[0]->baz);
        static::assertCount(2, $foo[1]->bars[0]->baz);
        static::assertEquals(22, $foo[1]->bars[0]->baz[0]->x);
        static::assertEquals(23, $foo[1]->bars[0]->baz[1]->x);
        static::assertEquals(3, $query);

        // many load many   (custom constraint)
        $query = 0;
        $foo = ModelRelationFindTest_Foo::findMany([1, 2]);
        static::assertEquals(1, $query);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertEquals(1, $foo[0]->x);
        static::assertEquals(2, $foo[1]->x);

        $foo->loadRelation(['bars' => function(Model\Relation $relation) {
            $relation->where('x', '<', 13);
        }]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bars);
        static::assertCount(1, $foo[0]->bars);
        static::assertEquals(11, $foo[0]->bars[0]->x);

        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bars);
        static::assertCount(1, $foo[1]->bars);
        static::assertEquals(12, $foo[1]->bars[0]->x);
        static::assertEquals(2, $query);

        // one with multi relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bar', 'bars')->find(1);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertEquals(1, $foo->x);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(11, $foo->bar->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(1, $foo->bars);
        static::assertEquals(3, $query);

        // one load multi relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::find(1);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertEquals(1, $query);
        $foo->loadRelation('bar', 'bars');
        static::assertEquals(1, $foo->x);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(11, $foo->bar->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(1, $foo->bars);
        static::assertEquals(3, $query);

        // many with multi relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bar', 'bars')->findMany([1, 2]);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[0]->bar);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bars);
        static::assertCount(1, $foo[0]->bars);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[1]->bar);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bars);
        static::assertCount(3, $foo[1]->bars);
        static::assertEquals(3, $query);

        // many load multi relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::findMany([1, 2]);
        static::assertEquals(1, $query);
        $foo->loadRelation('bar', 'bars');
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[0]->bar);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bars);
        static::assertCount(1, $foo[0]->bars);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[1]->bar);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bars);
        static::assertCount(3, $foo[1]->bars);
        static::assertEquals(3, $query);

        // one with nested relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bar.baz')->find(1);
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertEquals(1, $foo->x);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo->bar);
        static::assertEquals(11, $foo->bar->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bar->baz);
        static::assertCount(1, $foo->bar->baz);
        static::assertEquals(21, $foo->bar->baz[0]->x);
        static::assertEquals(3, $query);

        // one load nested relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::find(2);
        static::assertEquals(1, $query);
        $foo->loadRelation('bars.baz.que_table');
        static::assertInstanceOf('ModelRelationFindTest_Foo', $foo);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars);
        static::assertCount(3, $foo->bars);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars[0]->baz);
        static::assertCount(2, $foo->bars[0]->baz);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars[1]->baz);
        static::assertCount(1, $foo->bars[1]->baz);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo->bars[2]->baz);
        static::assertCount(0, $foo->bars[2]->baz);
        static::assertInstanceOf('ModelRelationFindTest_Que', $foo->bars[0]->baz[0]->que_table);
        static::assertEquals(12, $foo->bars[0]->baz[0]->que_table->x);
        static::assertEquals(4, $query);

        // many load nested relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::with('bars.baz.ques_table')->findMany([1,2]);
        static::assertEquals([
            [
                'id' => 1,
                'x' => 1,
                'bars' => [
                    [
                        'id' => 1,
                        'foo_id' => 1,
                        'x' => 11,
                        'baz' => [
                            [
                                'id' => 1,
                                'bar_id' => 1,
                                'x' => 21,
                                'ques_table' => [
                                    [
                                        'id' => 1,
                                        'x' => 11,
                                        'pivot' => [
                                            'baz_id' => 1,
                                            'que_id' => 1,
                                            'x' => 31
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],

            [
                'id' => 2,
                'x' => 2,
                'bars' => [
                    [
                        'id' => 2,
                        'foo_id' => 2,
                        'x' => 12,
                        'baz' => [
                            [
                                'id' => 2,
                                'bar_id' => 2,
                                'x' => 22,
                                'ques_table' => [
                                    [
                                        'id' => 2,
                                        'x' => 12,
                                        'pivot' => [
                                            'baz_id' => 2,
                                            'que_id' => 2,
                                            'x' => 32,
                                        ]
                                    ],
                                    [
                                        'id' => 3,
                                        'x' => 13,
                                        'pivot' => [
                                            'baz_id' => 2,
                                            'que_id' => 3,
                                            'x' => 33
                                        ]
                                    ],
                                    [
                                        'id' => 4,
                                        'x' => 14,
                                        'pivot' => [
                                            'baz_id' => 2,
                                            'que_id' => 4,
                                            'x' => 34
                                        ]
                                    ],
                                ]
                            ],
                            [
                                'id' => 3,
                                'bar_id' => 2,
                                'x' => 23,
                                'ques_table' => []
                            ]
                        ]
                    ],
                    [
                        'id' => 3,
                        'foo_id' => 2,
                        'x' => 13,
                        'baz' => [
                            [
                                'id' => 4,
                                'bar_id' => 3,
                                'x' => 24,
                                'ques_table' => []
                            ]
                        ]
                    ],
                    [
                        'id' => 4,
                        'foo_id' => 2,
                        'x' => 14,
                        'baz' => []
                    ]
                ]
            ]
        ], $foo->toArray(true));
        static::assertEquals(4, $query);

        // many load nested relation
        $query = 0;
        $foo = ModelRelationFindTest_Foo::findMany([1,2]);
        static::assertEquals(1, $query);

        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo);
        static::assertCount(2, $foo);
        $foo->loadRelation('bar.baz.que_table');

        static::assertEquals(1, $foo[0]->x);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[0]->bar);
        static::assertEquals(11, $foo[0]->bar->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[0]->bar->baz);
        static::assertCount(1, $foo[0]->bar->baz);
        static::assertEquals(21, $foo[0]->bar->baz[0]->x);
        static::assertInstanceOf('ModelRelationFindTest_Que', $foo[0]->bar->baz[0]->que_table);
        static::assertEquals(11, $foo[0]->bar->baz[0]->que_table->x);

        static::assertEquals(2, $foo[1]->x);
        static::assertInstanceOf('ModelRelationFindTest_Bar', $foo[1]->bar);
        static::assertEquals(12, $foo[1]->bar->x);
        static::assertInstanceOf('Tanbolt\Database\Model\Collection', $foo[1]->bar->baz);
        static::assertCount(2, $foo[1]->bar->baz);
        static::assertEquals(22, $foo[1]->bar->baz[0]->x);
        static::assertEquals(23, $foo[1]->bar->baz[1]->x);
        static::assertInstanceOf('ModelRelationFindTest_Que', $foo[1]->bar->baz[0]->que_table);
        static::assertEquals(12, $foo[1]->bar->baz[0]->que_table->x);
        static::assertNull($foo[1]->bar->baz[1]->que_table);
        static::assertEquals(4, $query);

        Database::getNode()->setListener();
    }
}


class ModelRelationFindTest_Foo extends Model
{
    protected $tableName = 'foo';

    public function bar()
    {
        return $this->oneToOne('ModelRelationFindTest_Bar', 'foo_id', 'id');
    }

    public function bars()
    {
        return $this->oneToMany('ModelRelationFindTest_Bar', 'foo_id', 'id');
    }
}

class ModelRelationFindTest_Bar extends Model
{
    protected $tableName = 'bar';

    public function baz()
    {
        return $this->oneToMany('ModelRelationFindTest_Baz', 'bar_id', 'id');
    }
}

class ModelRelationFindTest_Baz extends Model
{
    protected $tableName = 'baz';

    public function queTable()
    {
        return $this->oneToOne('ModelRelationFindTest_Que', 'id', 'id')
            ->throughTable('pivot', 'que_id', 'baz_id');
    }

    public function quesTable()
    {
        return $this->oneToMany('ModelRelationFindTest_Que', 'id', 'id')
            ->throughTable('pivot', 'que_id', 'baz_id')->withPivot('x');
    }

    public function queModel()
    {
        return $this->oneToOne('ModelRelationFindTest_Que', 'id', 'id')
            ->throughModel('ModelRelationFindTest_Pivot', 'que_id', 'baz_id')->withPivot('x');
    }

    public function quesModel()
    {
        return $this->oneToMany('ModelRelationFindTest_Que', 'id', 'id')
            ->throughModel('ModelRelationFindTest_Pivot', 'que_id', 'baz_id')
            ->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
                $clause->where('x', '>', 22);
            });
    }
}

class ModelRelationFindTest_Pivot extends Model
{
    protected $tableName = 'pivot';
}

class ModelRelationFindTest_Que extends Model
{
    protected $tableName = 'que';
}
