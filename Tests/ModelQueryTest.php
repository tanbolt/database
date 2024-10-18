<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelQueryTest extends TestCase
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

    // 以下 select where having order 是通过 Query Builder 执行的, 由于 Builder 有单独的单元测试, 这里仅简单测试一下
    public function testSelectMethod()
    {
        static::assertEquals('SELECT * FROM `foo`', (new ModelQuery_FOO)->query());
        static::assertEquals(
            'SELECT `id`, `foo`, `bar` FROM `foo`',
            ModelQuery_FOO::select(['id', 'foo', 'bar'])->query()
        );
        static::assertEquals(
            'SELECT `id`, `foo`, `bar` FROM `foo`',
            ModelQuery_FOO::select('id', 'foo', 'bar')->query()
        );
        static::assertEquals(
            'SELECT `id`, `foo`, `bar` FROM `foo`',
            ModelQuery_FOO::select('id')->addSelect(['foo', 'bar'])->query()
        );
        static::assertEquals(
            'SELECT `id`, `foo`, `bar` FROM `foo`',
            ModelQuery_FOO::select('id')->addSelect('foo', 'bar')->query()
        );
    }

    public function testWhereMethod()
    {
        $model = new ModelQuery_FOO;
        static::assertEquals('SELECT * FROM `foo`',  $model->query());
        static::assertEquals([],  $model->getBindings());

        $model = ModelQuery_FOO::where('foo', 1);
        static::assertEquals('SELECT * FROM `foo` WHERE `foo` = ?', $model->query());
        static::assertEquals([1],  $model->getBindings());

        $model = ModelQuery_FOO::whereOn('foo', 'bar');
        static::assertEquals('SELECT * FROM `foo` WHERE `foo` = `bar`', $model->query());
        static::assertEquals([],  $model->getBindings());

        $model = ModelQuery_FOO::whereNull('foo');
        static::assertEquals('SELECT * FROM `foo` WHERE `foo` IS NULL', $model->query());
        static::assertEquals([],  $model->getBindings());

        $model = ModelQuery_FOO::where('foo', 1)->orWhere('bar', 2)->whereNull('biz');
        static::assertEquals('SELECT * FROM `foo` WHERE `foo` = ? OR `bar` = ? AND `biz` IS NULL', $model->query());
        static::assertEquals([1, 2],  $model->getBindings());
    }

    public function testHavingMethod()
    {
        static::assertEquals('SELECT * FROM `foo` GROUP BY `foo`',  ModelQuery_FOO::group('foo')->query());

        $model = ModelQuery_FOO::group('foo')->having('foo', 1);
        static::assertEquals('SELECT * FROM `foo` GROUP BY `foo` HAVING `foo` = ?', $model->query());
        static::assertEquals([1],  $model->getBindings());

        $model = ModelQuery_FOO::group('foo')->havingOn('foo', 'bar');
        static::assertEquals('SELECT * FROM `foo` GROUP BY `foo` HAVING `foo` = `bar`', $model->query());
        static::assertEquals([],  $model->getBindings());

        $model = ModelQuery_FOO::group('foo')->havingNull('foo');
        static::assertEquals('SELECT * FROM `foo` GROUP BY `foo` HAVING `foo` IS NULL', $model->query());
        static::assertEquals([],  $model->getBindings());

        $model = ModelQuery_FOO::group('foo')->having('foo', 1)->orHaving('bar', 2)->havingNull('biz');
        static::assertEquals('SELECT * FROM `foo` GROUP BY `foo` HAVING `foo` = ? OR `bar` = ? AND `biz` IS NULL', $model->query());
        static::assertEquals([1, 2],  $model->getBindings());
    }

    public function testOrderMethod()
    {
        static::assertEquals('SELECT * FROM `foo` ORDER BY `foo` DESC', ModelQuery_FOO::order('foo')->query());
        static::assertEquals('SELECT * FROM `foo` ORDER BY `foo` ASC', ModelQuery_FOO::order('foo', true)->query());

        static::assertEquals('SELECT * FROM `foo` LIMIT 10', ModelQuery_FOO::limit(10)->query());
        static::assertEquals('SELECT * FROM `foo` LIMIT 10 OFFSET 5', ModelQuery_FOO::limit(10)->offset(5)->query());
    }

    public function testWherePrimaryMethod()
    {
        $model = ModelQuery_FOO::wherePrimary(10);
        static::assertEquals('SELECT * FROM `foo` WHERE `id` = ?', $model->query());
        static::assertEquals([10], $model->getBindings());

        $model = ModelQuery_FOO::wherePrimary([5,6]);
        static::assertEquals('SELECT * FROM `foo` WHERE `id` IN (?,?)', $model->query());
        static::assertEquals([5,6], $model->getBindings());

        $model = (new ModelQuery_FOO)->setPrimaryColumn(['id', 'sid'])->wherePrimary([10,20]);
        static::assertEquals('SELECT * FROM `foo` WHERE `id` = ? AND `sid` = ?', $model->query());
        static::assertEquals([10, 20], $model->getBindings());

        $model = (new ModelQuery_FOO)->setPrimaryColumn(['id', 'sid'])->wherePrimary(['sid' => 20, 'id' => 10]);
        static::assertEquals('SELECT * FROM `foo` WHERE `id` = ? AND `sid` = ?', $model->query());
        static::assertEquals([10, 20], $model->getBindings());

        $model = (new ModelQuery_FOO)->setPrimaryColumn(['id', 'sid'])->wherePrimary([[10,20], [11,21]]);
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS '.
            '(SELECT NULL FROM '.
            '(SELECT ? AS `id`, ? AS `sid` UNION ALL SELECT ? AS `id`, ? AS `sid`) AS `tanbolt_tempin` '.
            'WHERE `tanbolt_tempin`.`id` =`foo`.`id` AND `tanbolt_tempin`.`sid` =`foo`.`sid`)',
            $model->query()
        );
        static::assertEquals([10, 20, 11, 21], $model->getBindings());

        $model = (new ModelQuery_FOO)->setPrimaryColumn(['id', 'sid'])->wherePrimary([['sid' => 20, 'id' => 10], [11,21]]);
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS '.
            '(SELECT NULL FROM '.
            '(SELECT ? AS `id`, ? AS `sid` UNION ALL SELECT ? AS `id`, ? AS `sid`) AS `tanbolt_tempin` '.
            'WHERE `tanbolt_tempin`.`id` =`foo`.`id` AND `tanbolt_tempin`.`sid` =`foo`.`sid`)',
            $model->query()
        );
        static::assertEquals([10, 20, 11, 21], $model->getBindings());
    }

    public function testHasMethod()
    {
        $model = ModelQuery_FOO::has('bar');
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS (SELECT * FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`)',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());

        $model = ModelQuery_FOO::has('bar', '>', 2);
        static::assertEquals(
            'SELECT * FROM `foo` WHERE (SELECT COUNT(*) FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`) > 2',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());

        $model = ModelQuery_FOO::has('bar')->orHas('biz');
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS (SELECT * FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`) '.
            'OR EXISTS ('.
            'SELECT * FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = `foo`.`local`'.
            ')',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());

        $model = ModelQuery_FOO::has('bar', '>', 2)->orHas('Biz', '<', 4);
        static::assertEquals(
            'SELECT * FROM `foo` WHERE (SELECT COUNT(*) FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`) > 2 '.
            'OR ('.
            'SELECT COUNT(*) FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = `foo`.`local`'.
            ') < 4',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());
    }

    public function testNotHasMethod()
    {
        $model = ModelQuery_FOO::notHas('bar');
        static::assertEquals(
            'SELECT * FROM `foo` WHERE NOT EXISTS (SELECT * FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`)',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());

        $model = ModelQuery_FOO::notHas('bar')->orNotHas('biz');
        static::assertEquals(
            'SELECT * FROM `foo` WHERE NOT EXISTS (SELECT * FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`) '.
            'OR NOT EXISTS ('.
            'SELECT * FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = `foo`.`local`'.
            ')',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());

        $model = ModelQuery_FOO::notHas('bar', function(Model\Relation $relation) {
            $relation->where('id', 2);
        });
        static::assertEquals(
           'SELECT * FROM `foo` WHERE NOT EXISTS (SELECT * FROM `bar` WHERE `bar`.`id` = ? AND `bar`.`foreign` = `foo`.`local`)',
            $model->query()
        );
        static::assertEquals([2], $model->getBindings());


        $model = ModelQuery_FOO::notHas('biz', function(Model\Relation $relation) {
            $relation->where('id', 2)->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
                $clause->where('sid', '>', 5);
            });
        });
        static::assertEquals(
            'SELECT * FROM `foo` WHERE NOT EXISTS ('.
            'SELECT * FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `biz`.`id` = ? AND `pivot`.`middle_local` = `foo`.`local` AND ( `pivot`.`sid` > ? )'.
            ')',
            $model->query()
        );
        static::assertEquals([2, 5], $model->getBindings());
    }

    public function testWhereHasMethod()
    {
        $model = ModelQuery_FOO::whereHas('bar');
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS (SELECT * FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`)',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());

        $model = ModelQuery_FOO::whereHas('bar')->orWhereHas('biz');
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS (SELECT * FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`) '.
            'OR EXISTS ('.
            'SELECT * FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = `foo`.`local`'.
            ')',
            $model->query()
        );
        static::assertEquals([], $model->getBindings());

        $model = ModelQuery_FOO::whereHas('bar', function(Model\Relation $relation) {
            $relation->where('id', 2);
        });
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS (SELECT * FROM `bar` WHERE `bar`.`id` = ? AND `bar`.`foreign` = `foo`.`local`)',
            $model->query()
        );
        static::assertEquals([2], $model->getBindings());

        $model = ModelQuery_FOO::whereHas('biz', function(Model\Relation $relation) {
            $relation->where('id', 2)->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
                $clause->where('sid', '>', 5);
            });
        });
        static::assertEquals(
            'SELECT * FROM `foo` WHERE EXISTS ('.
            'SELECT * FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `biz`.`id` = ? AND `pivot`.`middle_local` = `foo`.`local` AND ( `pivot`.`sid` > ? )'.
            ')',
            $model->query()
        );
        static::assertEquals([2, 5], $model->getBindings());

        $model = ModelQuery_FOO::whereHas('biz', function(Model\Relation $relation) {
            $relation->where('id', 2)->wherePivot(function(\Tanbolt\Database\Query\WhereClause $clause) {
                $clause->where('sid', '>', 5);
            });
        }, '>', 2);
        static::assertEquals(
            'SELECT * FROM `foo` WHERE ('.
            'SELECT COUNT(*) FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `biz`.`id` = ? AND `pivot`.`middle_local` = `foo`.`local` AND ( `pivot`.`sid` > ? )'.
            ') > 2',
            $model->query()
        );
        static::assertEquals([2, 5], $model->getBindings());
    }

    public function testQueryMethod()
    {
        $model = ModelQuery_FOO::select('id', 'foo', 'bar')
            ->where('foo', 1)->orWhere('bar', 2)->whereNull('biz')
            ->group('foo')->having('foo', 3)->orHaving('bar', 4)->havingNull('biz')
            ->has('bar')->orNotHas('biz')
            ->restrict()
            ->limit(10)->offset(5);

        static::assertEquals(
            'SELECT `id`, `foo`, `bar` FROM `foo` '.
            'WHERE `foo` = ? OR `bar` = ? AND `biz` IS NULL '.
            'AND EXISTS (SELECT * FROM `bar` WHERE `bar`.`foreign` = `foo`.`local`) '.
            'OR NOT EXISTS ('.
            'SELECT * FROM `biz` INNER JOIN `middle` AS `pivot` ON `biz`.`foreign` = `pivot`.`middle_foreign` '.
            'WHERE `pivot`.`middle_local` = `foo`.`local`'.
            ') '.
            'AND `scope` > ? '.
            'GROUP BY `foo` HAVING `foo` = ? OR `bar` = ? AND `biz` IS NULL '.
            'LIMIT 10 OFFSET 5',
            $model->query()
        );
        static::assertEquals([
            1,2,8,3,4
        ], $model->getBindings());
    }

    public function testScopeMethod()
    {
        // 动态函数 调用
        $model = ModelQuery_QUE::instance()->restrict();
        static::assertEquals('SELECT * FROM `que` WHERE `scope` > ? AND `global` = ?', $model->query());
        static::assertEquals([8, 1], $model->getBindings());

        $model = ModelQuery_QUE::instance()->withoutGlobalScope()->restrict();
        static::assertEquals('SELECT * FROM `que` WHERE `scope` > ?', $model->query());
        static::assertEquals([8], $model->getBindings());

        $model = ModelQuery_QUE::instance()->restrict()->where('foo', 4);
        static::assertEquals('SELECT * FROM `que` WHERE `scope` > ? AND `foo` = ? AND `global` = ?', $model->query());
        static::assertEquals([8, 4, 1], $model->getBindings());

        $model = ModelQuery_QUE::instance()->where('foo', 4)->restrict();
        static::assertEquals('SELECT * FROM `que` WHERE `foo` = ? AND `scope` > ? AND `global` = ?', $model->query());
        static::assertEquals([4, 8, 1], $model->getBindings());

        $model = ModelQuery_QUE::instance()->restrict()->user()->where('foo', 4);
        static::assertEquals('SELECT * FROM `que` WHERE `scope` > ? AND `foo` = ? AND `user` = ? AND `global` = ?', $model->query());
        static::assertEquals([8, 4, 7, 1], $model->getBindings());

        // 静态调用 (自定义 scope 函数不能第一个使用)
        $model = ModelQuery_QUE::where('foo', 4)->restrict();
        static::assertEquals('SELECT * FROM `que` WHERE `foo` = ? AND `scope` > ? AND `global` = ?', $model->query());
        static::assertEquals([4, 8, 1], $model->getBindings());

        $model = ModelQuery_QUE::withoutGlobalScope()->where('foo', 4)->restrict();
        static::assertEquals('SELECT * FROM `que` WHERE `foo` = ? AND `scope` > ?', $model->query());
        static::assertEquals([4, 8], $model->getBindings());

        $model = ModelQuery_QUE::withoutGlobalScope()->where('foo', 4)->restrict()->withGlobalScope();
        static::assertEquals('SELECT * FROM `que` WHERE `foo` = ? AND `scope` > ? AND `global` = ?', $model->query());
        static::assertEquals([4, 8, 1], $model->getBindings());
    }

    public function testIncrementColumn()
    {
        $model = ModelQuery_FOO::setIncrementColumn('foo');
        static::assertEquals('foo', $model->getIncrementColumn());
    }
}

class ModelQuery_QUE extends Model
{
    protected $tableName = 'que';

    public function globalScope()
    {
        return $this->where('global', 1);
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

class ModelQuery_FOO extends Model
{
    protected $tableName = 'foo';

    public function bar()
    {
        return $this->hasOne('ModelQuery_Bar', 'foreign', 'local');
    }

    public function biz()
    {
        return $this->hasMany('ModelQuery_Biz', 'foreign', 'local')->throughTable('middle', 'middle_foreign', 'middle_local');
    }

    public function restrict()
    {
        return $this->where('scope', '>', 8);
    }
}

class ModelQuery_Bar extends Model
{
    protected $tableName = 'bar';
}

class ModelQuery_Biz extends Model
{
    protected $tableName = 'biz';
}

