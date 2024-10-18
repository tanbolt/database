<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelRelationQueryTest extends TestCase
{
    public static function setUpBeforeClass():void
    {
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]], true);
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass():void
    {
        Database::getNode()->disconnect();
        Database::clearNode();
        parent::tearDownAfterClass();
    }

    public function testRelationProperty()
    {
        $relation = (new ModelRelationQueryTest_FOO(['local' => 10]))->bar();
        static::assertInstanceOf('ModelRelationQueryTest_Bar', $relation->getModel());
        static::assertInstanceOf('ModelRelationQueryTest_FOO', $relation->getParent());
        static::assertFalse($relation->isMany());
        static::assertEquals('foreign', $relation->getForeignKey());
        static::assertEquals('local', $relation->getLocalKey());
        static::assertNull($relation->getPivot());
        static::assertFalse($relation->isTablePivot());
        static::assertNull($relation->getPivotRelationKey());
        static::assertNull($relation->getPivotParentKey());
        static::assertNull($relation->getPivotPrimaryKey());

        static::assertEquals(0, $relation->getFreeValue());
        static::assertSame($relation, $relation->setFreeValue(null));
        static::assertNull($relation->getFreeValue());
        static::assertSame($relation, $relation->setFreeValue('str'));
        static::assertEquals('str', $relation->getFreeValue());

        $relation = (new ModelRelationQueryTest_FOO(['local' => 10]))->biz();
        static::assertInstanceOf('ModelRelationQueryTest_Biz', $relation->getModel());
        static::assertInstanceOf('ModelRelationQueryTest_FOO', $relation->getParent());
        static::assertTrue($relation->isMany());
        static::assertEquals('foreign', $relation->getForeignKey());
        static::assertEquals('local', $relation->getLocalKey());
        static::assertEquals('middle', $relation->getPivot());
        static::assertTrue($relation->isTablePivot());
        static::assertEquals('middle_foreign', $relation->getPivotRelationKey());
        static::assertEquals('middle_local', $relation->getPivotParentKey());
        static::assertNull($relation->getPivotPrimaryKey());

        $relation = (new ModelRelationQueryTest_FOO(['local' => 10]))->biz2();
        static::assertInstanceOf('ModelRelationQueryTest_Biz', $relation->getModel());
        static::assertInstanceOf('ModelRelationQueryTest_FOO', $relation->getParent());
        static::assertTrue($relation->isMany());
        static::assertEquals('foreign', $relation->getForeignKey());
        static::assertEquals('local', $relation->getLocalKey());
        static::assertEquals('ModelRelationQueryTest_Bar', $relation->getPivot());
        static::assertFalse($relation->isTablePivot());
        static::assertEquals('middle_foreign', $relation->getPivotRelationKey());
        static::assertEquals('middle_local', $relation->getPivotParentKey());
        static::assertEquals('bid', $relation->getPivotPrimaryKey());
    }

    public function testActiveRecordWithMethod()
    {
        $bizFunc = function () {};
        $queFunc = function () {};
        $activeRecord = ModelRelationQueryTest_FOO::with(
            'foo',
            ['bar', ['biz.one' => $bizFunc]],
            ['que.one.two' => $queFunc],
            'jack.neo.one',
            'bill.one.two.three'
        );
        static::assertEquals([
            'foo',
            'bar',
            ['biz.one' => $bizFunc],
            ['que.one.two' => $queFunc],
            'jack.neo.one',
            'bill.one.two.three',
        ], $activeRecord->withWhich());

        static::assertSame($activeRecord, $activeRecord->withOut(
            'foo',
            ['biz', 'neo.one'],
            'bill.one'
        ));
        static::assertEquals([
            'bar',
            ['que.one.two' => $queFunc],
            'jack.neo.one'
        ], $activeRecord->withWhich());

        static::assertSame($activeRecord, $activeRecord->withNone());
        static::assertEquals([], $activeRecord->withWhich());
    }

    public function testSelectMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));

        $relation = $model->bar();
        static::assertEquals('SELECT * FROM `bar` WHERE `bar`.`foreign` = ?', $relation->query());
        static::assertEquals([10], $relation->getBindings());
        static::assertEquals([10], $model->bar()->getBindings());

        static::assertEquals(
            'FROM `bar` WHERE `bar`.`foreign` = ?',
            $model->bar()->select(null)->query()
        );
        static::assertEquals(
            'SELECT `bar`.*, `bar`.`id`, `bar`.`foo`, `bar`.`bar` FROM `bar` WHERE `bar`.`foreign` = ?',
            $model->bar()->select(['*', 'id', 'foo', 'bar'])->query()
        );
        static::assertEquals(
            'SELECT `bar`.`id`, `bar`.`foo`, `bar`.`bar` FROM `bar` WHERE `bar`.`foreign` = ?',
            $model->bar()->select('id', 'foo', 'bar', 'foo')->query()
        );
        static::assertEquals(
            'SELECT `bar`.`id`, `bar`.`foo`, `bar`.`bar` FROM `bar` WHERE `bar`.`foreign` = ?',
            $model->bar()->select('id')->addSelect(['foo', 'bar'])->query()
        );
        static::assertEquals(
            'SELECT `bar`.`id`, `bar`.`foo`, `bar`.`bar` FROM `bar` WHERE `bar`.`foreign` = ?',
            $model->bar()->select('id')->addSelect('foo', 'bar')->query()
        );
    }

    public function testSelectPivotMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));
        $relation = $model->biz();
        static::assertEquals(
            'SELECT `biz`.*, `pivot`.`middle_foreign` AS `pivot_middle_foreign`, '.
            '`pivot`.`middle_local` AS `pivot_middle_local` FROM `biz` '.
            'INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = ?',
            $relation->query()
        );
        static::assertEquals([10], $relation->getBindings());
        static::assertEquals([10], $model->bar()->getBindings());

        static::assertEquals(
            'SELECT `biz`.*, '.
            '`pivot`.`foo` AS `pivot_foo`, `pivot`.`bar` AS `pivot_bar`, '.
            '`pivot`.`middle_foreign` AS `pivot_middle_foreign`, `pivot`.`middle_local` AS `pivot_middle_local` '.
            'FROM `biz` '.
            'INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = ?',
            $model->biz()->withPivot('foo', 'bar')->query()
        );
    }

    public function testWhereMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));

        $relation = $model->bar()->where('foo', 1);
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foo` = ? AND `bar`.`foreign` = ?',
            $relation->query()
        );
        static::assertEquals([1, 10],  $relation->getBindings());

        $relation = $model->bar()->whereOn('foo', 'biz');
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foo` = `biz` AND `bar`.`foreign` = ?',
            $relation->query()
        );
        static::assertEquals([10],  $relation->getBindings());

        $relation = $model->bar()->whereNull('foo');
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foo` IS NULL AND `bar`.`foreign` = ?',
            $relation->query()
        );
        static::assertEquals([10],  $relation->getBindings());

        $relation = $model->bar()->where('foo', 1)->orWhere('bar', 2)->whereNull('biz');
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foo` = ? OR `bar`.`bar` = ? AND `bar`.`biz` IS NULL AND `bar`.`foreign` = ?',
            $relation->query()
        );
        static::assertEquals([1,2, 10],  $relation->getBindings());

        //
        $relation = $model->bar2()->where('foo', 1);
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foo` = ? AND ( `bar`.`hello` = ? OR `bar`.`world` = ? ) AND `bar`.`foreign` = ?',
            $relation->query()
        );
        static::assertEquals([1, 2, 3, 10],  $relation->getBindings());
    }

    public function testHavingMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));

        $relation = $model->bar()->group('foo');
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? GROUP BY `bar`.`foo`',
            $relation->query()
        );
        static::assertEquals([10],  $relation->getBindings());

        $relation = $model->bar()->group('foo')->having('foo', 1);
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? GROUP BY `bar`.`foo` HAVING `bar`.`foo` = ?',
            $relation->query()
        );
        static::assertEquals([10,1],  $relation->getBindings());

        $relation = $model->bar()->group('foo')->havingOn('foo', 'bar');
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? GROUP BY `bar`.`foo` HAVING `bar`.`foo` = `bar`',
            $relation->query()
        );
        static::assertEquals([10],  $relation->getBindings());

        $relation = $model->bar()->group('foo')->havingNull('foo');
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? GROUP BY `bar`.`foo` HAVING `bar`.`foo` IS NULL',
            $relation->query()
        );
        static::assertEquals([10],  $relation->getBindings());

        $relation = $model->bar()->group('foo')->having('foo', 1)->orHaving('bar', 2)->havingNull('biz');
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? GROUP BY `bar`.`foo` '.
            'HAVING `bar`.`foo` = ? OR `bar`.`bar` = ? AND `bar`.`biz` IS NULL',
            $relation->query()
        );
        static::assertEquals([10,1,2],  $relation->getBindings());
    }

    public function testOrderMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));

        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? ORDER BY `bar`.`foo` DESC',
            $model->bar()->order('foo')->query()
        );
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? ORDER BY `bar`.`foo` ASC',
            $model->bar()->order('foo', true)->query()
        );

        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? LIMIT 10',
            $model->bar()->limit(10)->query()
        );
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? LIMIT 10 OFFSET 5',
            $model->bar()->limit(10)->offset(5)->query()
        );
    }

    public function testWherePivotMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));

        $relation = $model->biz()->where('foo', 1);
        static::assertEquals(
            'SELECT `biz`.*, `pivot`.`middle_foreign` AS `pivot_middle_foreign`, `pivot`.`middle_local` AS `pivot_middle_local` FROM `biz` '.
            'INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `biz`.`foo` = ? AND `pivot`.`middle_local` = ?',
            $relation->query()
        );
        static::assertEquals([1, 10], $relation->getBindings());

        $relation = $model->biz()->where('foo', 1)->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
            $clause->where('hello', 2)->orWhere('world', '>', 1);
        });
        static::assertEquals(
            'SELECT `biz`.*, `pivot`.`middle_foreign` AS `pivot_middle_foreign`, `pivot`.`middle_local` AS `pivot_middle_local` FROM `biz` '.
            'INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `biz`.`foo` = ? AND `pivot`.`middle_local` = ? '.
            'AND ( `pivot`.`hello` = ? OR `pivot`.`world` > ? )',
            $relation->query()
        );
        static::assertEquals([1, 10, 2, 1], $relation->getBindings());
    }

    public function testRelationHasMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));
        static::assertEquals(
            'SELECT * FROM `bar` WHERE EXISTS (SELECT * FROM `biz` WHERE `biz`.`fo` = `bar`.`co`) AND `bar`.`foreign` = ?',
            $model->bar()->has('biz')->query()
        );

        static::assertEquals(
            'SELECT * FROM `bar` WHERE NOT EXISTS (SELECT * FROM `biz` WHERE `biz`.`fo` = `bar`.`co`) AND `bar`.`foreign` = ?',
            $model->bar()->notHas('biz')->query()
        );

        static::assertEquals(
            'SELECT * FROM `bar` WHERE EXISTS (SELECT * FROM `biz` WHERE `biz`.`column` = ? AND `biz`.`fo` = `bar`.`co`) AND `bar`.`foreign` = ?',
            $model->bar()->whereHas('biz', function(Model\Relation $relation) {
                $relation->where('column', 10);
            })->query()
        );
    }

    public function testRelationScopeMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));
        $relation = $model->bar()->restrict()->user();
        static::assertEquals(
            'SELECT * FROM `bar` WHERE `bar`.`foreign` = ? AND `bar`.`scope` > ? AND `bar`.`user` = ?',
            $relation->query()
        );
        static::assertEquals([10, 8, 7], $relation->getBindings());

        $relation = $model->biz()->restrict();
        static::assertEquals(
            'SELECT `biz`.*, `pivot`.`middle_foreign` AS `pivot_middle_foreign`, `pivot`.`middle_local` AS `pivot_middle_local` FROM `biz` '.
            'INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = ? AND `biz`.`scope` > ?',
            $relation->query()
        );
        static::assertEquals([10, 8], $relation->getBindings());
    }

    public function testRelationQueryMethod()
    {
        $model = (new ModelRelationQueryTest_FOO(['local' => 10]));

        $relation = $model->bar()->select('*', 'id')->where('foo', 1)->orWhere('bar', 2)->whereNull('biz')
            ->group('foo')->having('foo', 1)->orHaving('bar', 2)->havingNull('biz')->has('biz')->restrict();

        static::assertEquals(
            'SELECT `bar`.*, `bar`.`id` FROM `bar` '.
            'WHERE `bar`.`foo` = ? OR `bar`.`bar` = ? AND `bar`.`biz` IS NULL '.
            'AND EXISTS (SELECT * FROM `biz` WHERE `biz`.`fo` = `bar`.`co`) '.
            'AND `bar`.`foreign` = ? AND `bar`.`scope` > ? '.
            'GROUP BY `bar`.`foo` HAVING `bar`.`foo` = ? OR `bar`.`bar` = ? AND `bar`.`biz` IS NULL',
            $relation->query()
        );
        static::assertEquals([1,2,10,8,1,2], $relation->getBindings());

        $relation = $model->biz()->select('*', 'id')->where('foo', 1)->orWhere('bar', 2)->whereNull('biz')
            ->group('foo')->having('foo', 1)->orHaving('bar', 2)->havingNull('biz')->has('bar')->restrict()
            ->withPivot('hello', 'world')->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
                $clause->where('hello', 11)->orWhere('world', '>', 12);
            });
        static::assertEquals(
            'SELECT `biz`.*, `biz`.`id`, '.
            '`pivot`.`hello` AS `pivot_hello`, `pivot`.`world` AS `pivot_world`, '.
            '`pivot`.`middle_foreign` AS `pivot_middle_foreign`, `pivot`.`middle_local` AS `pivot_middle_local` '.
            'FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `biz`.`foo` = ? OR `biz`.`bar` = ? AND `biz`.`biz` IS NULL '.
            'AND EXISTS (SELECT * FROM `bar` WHERE `bar`.`fo` = `biz`.`co`) '.
            'AND `pivot`.`middle_local` = ? '.
            'AND ( `pivot`.`hello` = ? OR `pivot`.`world` > ? ) '.
            'AND `biz`.`scope` > ? '.
            'GROUP BY `biz`.`foo` HAVING `biz`.`foo` = ? OR `biz`.`bar` = ? AND `biz`.`biz` IS NULL',
            $relation->query()
        );
        static::assertEquals([1,2,10,11, 12, 8,1,2], $relation->getBindings());
    }

    public function testIncrementColumn()
    {
        $relation = (new ModelRelationQueryTest_FOO(['local' => 10]))->bar()->setIncrementColumn('foo');
        static::assertEquals('foo', $relation->getIncrementColumn());
    }
}


class ModelRelationQueryTest_FOO extends Model
{
    protected $tableName = 'foo';


    public function bar()
    {
        return $this->oneToOne('ModelRelationQueryTest_Bar', 'foreign', 'local');
    }

    public function bar2()
    {
        return $this->oneToOne(
            'ModelRelationQueryTest_Bar', 'foreign', 'local', 
            function(\Tanbolt\Database\Query\WhereClause $clause) {
                $clause->where('hello', 2)->orWhere('world', 3);
            }
        );
    }

    public function biz()
    {
        return $this->oneToMany('ModelRelationQueryTest_Biz', 'foreign', 'local')
            ->throughTable('middle', 'middle_foreign', 'middle_local');
    }

    public function biz2()
    {
        return $this->oneToMany('ModelRelationQueryTest_Biz', 'foreign', 'local')
            ->throughModel('ModelRelationQueryTest_Bar', 'middle_foreign', 'middle_local');
    }
}


class ModelRelationQueryTest_Bar extends Model
{
    protected $primaryColumn = 'bid';
    protected $tableName = 'bar';

    public function biz()
    {
        return $this->oneToOne('ModelRelationQueryTest_Biz', 'fo', 'co');
    }

    public function restrict()
    {
        return $this->where('scope', '>', 8);
    }

    public function user()
    {
        return $this->where('user', '=', 7);
    }
}

class ModelRelationQueryTest_Biz extends Model
{
    protected $tableName = 'biz';

    public function bar()
    {
        return $this->oneToOne('ModelRelationQueryTest_Bar', 'fo', 'co');
    }

    public function restrict()
    {
        return $this->where('scope', '>', 8);
    }
}


