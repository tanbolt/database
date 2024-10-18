<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Connection;
use Tanbolt\Database\Query\Builder;
use Tanbolt\Database\Query\WhereClause;

abstract class DatabaseBuilderBasic extends TestCase
{
    /**
     * @var Connection
     */
    protected static $connection = null;

    public static function setUpBeforeClass():void
    {
        $database = substr(get_called_class(), 15);
        $config = include (__DIR__.'/../Config/'.$database.'.php');
        self::$connection = new Connection('PHPUNIT_CONNECTION', $config['config']);
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass():void
    {
        self::$connection->disconnect();
        self::$connection = null;
        parent::tearDownAfterClass();
    }

    /**
     * @param null $table
     * @param null $as
     * @return Builder
     */
    protected function builder($table = null, $as = null)
    {
        return new Builder(self::$connection, $table, $as);
    }

    public function testFromMethod()
    {
        $prefix = self::$connection->prefix;

        $query = 'SELECT * FROM `foo`';
        self::$connection->setPrefix(null);
        static::assertEquals($query, $this->builder('foo')->query());
        static::assertEquals($query, $this->builder('foo')->select('*')->query());
        static::assertEquals($query, $this->builder()->from('foo')->query());
        static::assertEquals($query, $this->builder()->from('foo')->select('*')->query());
        static::assertEquals('SELECT * FROM `fo"o`', $this->builder('fo"o')->query());
        static::assertEquals("SELECT * FROM `fo'o`", $this->builder("fo'o")->query());

        $query = 'SELECT * FROM `db_foo`';
        self::$connection->setPrefix('db_');
        static::assertEquals($query, $this->builder('foo')->query());
        static::assertEquals($query, $this->builder('foo')->select('*')->query());
        static::assertEquals($query, $this->builder()->from('foo')->query());
        static::assertEquals($query, $this->builder()->from('foo')->select('*')->query());
        static::assertEquals('SELECT * FROM `db_fo"o`', $this->builder('fo"o')->query());
        static::assertEquals("SELECT * FROM `db_fo'o`", $this->builder("fo'o")->query());

        self::$connection->setPrefix($prefix);
    }

    public function testChangePrefixDynamic()
    {
        $prefix = self::$connection->prefix;
        $builder = $this->builder('foo');

        self::$connection->setPrefix(null);
        static::assertEquals('SELECT * FROM `foo`', $builder->query());
        self::$connection->setPrefix('db_');
        static::assertEquals('SELECT * FROM `db_foo`', $builder->query());

        self::$connection->setPrefix($prefix);
    }

    public function testSelectColumns()
    {
        $prefix = self::$connection->prefix;

        $query = 'SELECT `foo`, `bar`, `baz` FROM `foo`';
        self::$connection->setPrefix(null);
        static::assertEquals($query, $this->builder('foo')->select('foo', 'bar', 'baz')->query());
        static::assertEquals($query, $this->builder('foo')->select(['foo','bar', 'baz', ''])->query());
        static::assertEquals($query, $this->builder('foo')->select('foo',['bar', 'baz'], 'baz')->query());
        static::assertEquals(
            'SELECT `foo`.`bar` FROM `foo`',
            $this->builder('foo')->select('foo.bar')->query()
        );
        static::assertEquals(
            'SELECT `bar` AS `alias` FROM `foo`',
            $this->builder('foo')->select('bar as alias')->query()
        );
        static::assertEquals(
            'SELECT `foo`.`bar` AS `alias` FROM `foo`',
            $this->builder('foo')->select('foo.bar as alias')->query()
        );
        static::assertEquals(
            'SELECT `foo`.`bar` AS `alias`, `bar`.`foo` AS `alias2` FROM `foo` AS `bar`',
            $this->builder('foo','bar')->select('foo.bar as alias', 'bar.foo as alias2')->query()
        );
        static::assertEquals(
            'SELECT a + b AS `c` FROM `foo` AS `bar`',
            $this->builder('foo','bar')->select('a + b as c')->query()
        );

        self::$connection->setPrefix('db_');
        static::assertEquals(
            'SELECT `foo`, `bar`, `baz` FROM `db_foo`',
            $this->builder('foo')->select('foo', 'bar', 'baz')->query()
        );
        static::assertEquals(
            'SELECT `db_foo`.`bar` FROM `db_foo`',
            $this->builder('foo')->select('foo.bar')->query()
        );
        static::assertEquals(
            'SELECT `bar` AS `alias` FROM `db_foo`',
            $this->builder('foo')->select('bar as alias')->query()
        );
        static::assertEquals(
            'SELECT `db_foo`.`bar` AS `alias` FROM `db_foo`',
            $this->builder('foo')->select('foo.bar as alias')->query()
        );
        static::assertEquals(
            'SELECT `db_foo`.`bar` AS `alias`, `bar`.`foo` AS `alias2` FROM `db_foo` AS `bar`',
            $this->builder('foo','bar')->select('foo.bar as alias', 'bar.foo as alias2')->query()
        );
        static::assertEquals(
            'SELECT a + b AS `c` FROM `db_foo` AS `bar`',
            $this->builder('foo','bar')->select('a + b as c')->query()
        );
        self::$connection->setPrefix($prefix);
    }

    public function testSelectSpecialColumn()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $builder = $this->builder('foo');
        static::assertEquals(
            'FROM `foo`',
            $builder->select(null)->query()
        );
        static::assertEquals(
            'SELECT count(*) AS `d` FROM `foo`',
            $builder->select('count(*) as d')->query()
        );
        static::assertEquals(
            'SELECT count(*) AS `d` FROM `foo`',
            $builder->select('count(*) as `d`')->query()
        );
        static::assertEquals(
            'SELECT a+b AS `d` FROM `foo`',
            $builder->select('a+b as `d`')->query()
        );
        static::assertEquals(
            'SELECT a +b as d FROM `foo`',
            $builder->select($builder::raw('a +b as d'))->query()
        );
        static::assertEquals(
            'SELECT a as d FROM `foo`',
            $builder->select($builder::raw('a as d'))->query()
        );
        self::$connection->setPrefix($prefix);
    }

    public function testSelectColumnsDistinct()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        static::assertEquals(
            'SELECT DISTINCT `bar` FROM `foo`',
            $this->builder('foo')->select('bar')->distinct()->query()
        );

        self::$connection->setPrefix('db_');
        static::assertEquals(
            'SELECT DISTINCT `bar` FROM `db_foo`',
            $this->builder('foo')->select('bar')->distinct()->query()
        );
        self::$connection->setPrefix($prefix);
    }

    public function testSelectAggregateFunction()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        $builder = $this->builder('foo')->select('bar')->distinct();
        static::assertEquals('SELECT DISTINCT `bar` FROM `foo`',  $builder->query());

        $builder->aggregate('count','*',false);
        static::assertEquals('SELECT count(DISTINCT *) AS `aggregate` FROM `foo`',  $builder->query());

        $builder->aggregate(null)->distinct(false);
        static::assertEquals('SELECT `bar` FROM `foo`',  $builder->query());

        self::$connection->setPrefix($prefix);
    }

    public function testSelectSubQuery()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        $builder = $this->builder('foo');
        $subBuilder = $this->builder('bar')->select('name');

        static::assertEquals(
            'SELECT (SELECT `name` FROM `bar`) FROM `foo`',
            $builder->select($subBuilder)->query()
        );
        static::assertEquals(
            'SELECT `id`, (SELECT `name` FROM `bar`) AS `name` FROM `foo`',
            $builder->select('id', $subBuilder->alias('name'))->query()
        );

        self::$connection->setPrefix('db_');
        static::assertEquals(
            'SELECT (SELECT `name` FROM `db_bar`) FROM `db_foo`',
            $builder->select($subBuilder->alias(null))->query()
        );
        static::assertEquals(
            'SELECT `id`, (SELECT `name` FROM `db_bar`) AS `name` FROM `db_foo`',
            $builder->select('id', $subBuilder->alias('name'))->query()
        );

        self::$connection->setPrefix($prefix);
    }

    public function testSelectFromSubQuery()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        $builder = $this->builder('foo');
        $subBuilder = $this->builder('bar')->select('name');

        static::assertEquals(
            'SELECT `name` FROM (SELECT `name` FROM `bar`) AS `foo`',
            $builder->select('name')->from($subBuilder->alias('foo'))->query()
        );

        self::$connection->setPrefix('db_');
        static::assertEquals(
            'SELECT `name` FROM (SELECT `name` FROM `db_bar`) AS `foo`',
            $builder->select('name')->from($subBuilder->alias('foo'))->query()
        );

        self::$connection->setPrefix($prefix);
    }

    public function testJoinUsingMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        $subBuilder = $this->builder('x')->where('x','x');
        $subFunction = function(Builder $builder) {
            $builder->from('y')->where('y','y');
        };

        // basic
        $builder = $this->builder('foo')->joinUsing('bar', 'biz');
        static::assertEquals(
            'SELECT * FROM `foo` INNER JOIN `bar` USING(`biz`)',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // set joinType
        $builder = $this->builder('foo')->joinUsing('bar', 'biz', 'left');
        static::assertEquals('SELECT * FROM `foo` LEFT JOIN `bar` USING(`biz`)',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // use subQuery
        $subQuery = 'SELECT * FROM `x` WHERE `x` = ?';
        $subString = 'SELECT * FROM `y` WHERE `y` = ?';
        $builder = $this->builder($subBuilder)->joinUsing($subFunction, 'biz', 'left');
        static::assertEquals('SELECT * FROM ('.$subQuery.') LEFT JOIN ('.$subString.') USING(`biz`)',
            $builder->query()
        );
        static::assertEquals(['x','y'], $builder->getBindings());

        // join alias
        $builder = $this->builder('foo')->select('k','foo.m','bar.n','biz.q')
            ->joinUsing('bar as biz', ['a','foo.b','bar.c','biz.d']);
        static::assertEquals('SELECT `k`, `foo`.`m`, `bar`.`n`, `biz`.`q` FROM `foo` INNER JOIN `bar` AS `biz` '.
            'USING(`a`, `foo`.`b`, `bar`.`c`, `biz`.`d`)',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // table join alias
        $builder = $this->builder('foo as al')->select('k','foo.m','bar.n','ali.q','al.v','biz.k')
            ->joinUsing('bar as ali', ['a','foo.b','bar.c','ali.d','al.x','biz.k']);
        static::assertEquals(
            'SELECT `k`, `foo`.`m`, `bar`.`n`, `ali`.`q`, `al`.`v`, `biz`.`k` FROM `foo` AS `al` INNER JOIN `bar` AS `ali` '.
            'USING(`a`, `foo`.`b`, `bar`.`c`, `ali`.`d`, `al`.`x`, `biz`.`k`)',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        self::$connection->setPrefix('db_');

        // basic
        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN `db_bar` USING(`biz`)',
            $this->builder('foo')->joinUsing('bar', 'biz')->query()
        );

        // set joinType
        static::assertEquals(
            'SELECT * FROM `db_foo` LEFT JOIN `db_bar` USING(`biz`)',
            $this->builder('foo')->joinUsing('bar', 'biz', 'left')->query()
        );

        // use subQuery
        $subQuery = 'SELECT * FROM `db_x` WHERE `x` = ?';
        $subString = 'SELECT * FROM `db_y` WHERE `y` = ?';
        $builder = $this->builder($subBuilder)->joinUsing($subFunction, 'biz', 'left');
        static::assertEquals('SELECT * FROM ('.$subQuery.') LEFT JOIN ('.$subString.') USING(`biz`)',
            $builder->query()
        );
        static::assertEquals(['x','y'], $builder->getBindings());

        // use subQuery alias
        $subQuery = 'SELECT * FROM `db_x` AS `p` WHERE `x` = ?';
        $subString = 'SELECT * FROM `db_y` WHERE `y` = ?';
        $builder = $this->builder($subBuilder->from('x','p')->alias('ali'))
            ->joinUsing($subFunction, ['biz', 'x.a', 'p.b', 'ali.c'], 'left');
        static::assertEquals('SELECT * FROM ('.$subQuery.') AS `ali` LEFT JOIN ('.$subString.') '.
            'USING(`biz`, `db_x`.`a`, `db_p`.`b`, `ali`.`c`)',
            $builder->query()
        );
        static::assertEquals(['x','y'], $builder->getBindings());

        // join alias
        static::assertEquals(
            'SELECT `k`, `db_foo`.`m`, `db_bar`.`n`, `db_ali`.`q`, `biz`.`k` FROM `db_foo` INNER JOIN `db_bar` AS `biz` '.
            'USING(`a`, `db_foo`.`b`, `db_bar`.`c`, `db_ali`.`d`, `biz`.`k`)',
            $this->builder('foo')->select('k','foo.m','bar.n','ali.q', 'biz.k')
                ->joinUsing('bar as biz', ['a','foo.b','bar.c','ali.d', 'biz.k'])->query()
        );

        // table join alias
        static::assertEquals(
            'SELECT `k`, `db_foo`.`m`, `db_bar`.`n`, `ali`.`q`, `al`.`v`, `db_biz`.`k` FROM `db_foo` AS `al` INNER JOIN `db_bar` AS `ali` '.
            'USING(`a`, `db_foo`.`b`, `db_bar`.`c`, `ali`.`d`, `al`.`x`, `db_biz`.`k`)',
            $this->builder('foo as al')->select('k','foo.m','bar.n','ali.q','al.v','biz.k')
                ->joinUsing('bar as ali', ['a','foo.b','bar.c','ali.d','al.x','biz.k'])->query()
        );

        self::$connection->setPrefix($prefix);
    }

    public function testJoinOnMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        $subFunction = function(Builder $builder) {
            $builder->from('y')->where('y','y');
        };
        $subBuilder = $this->builder('x')->where('x','x');

        // joinOn(table, left, right)
        $builder = $this->builder('foo')->joinOn('bar', 'foo.a', 'bar.b');
        static::assertEquals(
            'SELECT * FROM `foo` INNER JOIN `bar` ON `foo`.`a` = `bar`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // joinOn(table as ali, left, right)
        $builder = $this->builder('foo as biz')->joinOn('bar as ali', 'biz.a', 'ali.b');
        static::assertEquals(
            'SELECT * FROM `foo` AS `biz` INNER JOIN `bar` AS `ali` ON `biz`.`a` = `ali`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // joinOn(function, left,  right)
        $subString = 'SELECT * FROM `y` WHERE `y` = ?';
        $builder = $this->builder('foo')->joinOn($subFunction, 'foo.a', 'bar.b');
        static::assertEquals(
            'SELECT * FROM `foo` INNER JOIN ('.$subString.') ON `foo`.`a` = `bar`.`b`',
            $builder->query()
        );
        static::assertEquals(['y'], $builder->getBindings());

        // joinOn(subQuery, left,  right)
        $subQuery = 'SELECT * FROM `x` WHERE `x` = ?';
        $builder = $this->builder('foo as bar')->joinOn($subBuilder->alias('biz'), 'bar.a', 'biz.b');
        static::assertEquals(
            'SELECT * FROM `foo` AS `bar` INNER JOIN ('.$subQuery.') AS `biz` ON `bar`.`a` = `biz`.`b`',
            $builder->query()
        );
        static::assertEquals(['x'], $builder->getBindings());

        // joinOn(table, left, operator, right)
        $builder = $this->builder('foo')->joinOn('bar', 'foo.a', '!=', 'bar.b');
        static::assertEquals(
            'SELECT * FROM `foo` INNER JOIN `bar` ON `foo`.`a` != `bar`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // joinOn(table, left, operator, right, type)
        $builder = $this->builder('foo')->joinOn('bar', 'foo.a', '>', 'bar.b', 'OUT inner');
        static::assertEquals(
            'SELECT * FROM `foo` OUT INNER JOIN `bar` ON `foo`.`a` > `bar`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());


        self::$connection->setPrefix('db_');

        // joinOn(table, left, right)
        $builder = $this->builder('foo')->joinOn('bar', 'foo.a', 'bar.b');
        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN `db_bar` ON `db_foo`.`a` = `db_bar`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // joinOn(table as ali, left, right)
        $builder = $this->builder('foo as biz')->joinOn('bar as ali', 'biz.a', 'ali.b');
        static::assertEquals(
            'SELECT * FROM `db_foo` AS `biz` INNER JOIN `db_bar` AS `ali` ON `biz`.`a` = `ali`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN `db_bar` AS `ali` ON `db_foo`.`a` = `ali`.`b`',
            $this->builder('foo')->joinOn('bar as ali', 'foo.a', 'ali.b')->query()
        );

        // joinOn(function, left,  right)
        $subString = 'SELECT * FROM `db_y` WHERE `y` = ?';
        $builder = $this->builder('foo')->joinOn($subFunction, 'foo.a', 'bar.b');
        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN ('.$subString.') ON `db_foo`.`a` = `db_bar`.`b`',
            $builder->query()
        );
        static::assertEquals(['y'], $builder->getBindings());

        // joinOn(subQuery, left,  right)
        $subQuery = 'SELECT * FROM `db_x` WHERE `x` = ?';
        $builder = $this->builder('foo as bar')->joinOn($subBuilder->alias('biz'), 'bar.a', 'biz.b');
        static::assertEquals(
            'SELECT * FROM `db_foo` AS `bar` INNER JOIN ('.$subQuery.') AS `biz` ON `bar`.`a` = `biz`.`b`',
            $builder->query()
        );
        static::assertEquals(['x'], $builder->getBindings());

        // joinOn(table, left, operator, right)
        $builder = $this->builder('foo')->joinOn('bar', 'foo.a', '!=', 'bar.b');
        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN `db_bar` ON `db_foo`.`a` != `db_bar`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // joinOn(table, left, operator, right, type)
        $builder = $this->builder('foo')->joinOn('bar', 'foo.a', '>', 'bar.b', 'OUT inner');
        static::assertEquals(
            'SELECT * FROM `db_foo` OUT INNER JOIN `db_bar` ON `db_foo`.`a` > `db_bar`.`b`',
            $builder->query()
        );
        static::assertEquals([], $builder->getBindings());

        // join multiple
        $subBuilder = $this
            ->builder('sub as tab')
            ->joinOn('sub1 as tab1', 'a', 'b')
            ->joinOn('sub2 as tab2', 'tab2.a', 'tab1.b')
            ->joinOn('sub3 as tab3', 'tab3.a', 'biz1.b')
        ;
        $subQuery = 'SELECT * FROM `db_sub` AS `tab` '.
            'INNER JOIN `db_sub1` AS `tab1` ON `a` = `b` '.
            'INNER JOIN `db_sub2` AS `tab2` ON `tab2`.`a` = `tab1`.`b` '.
            'INNER JOIN `db_sub3` AS `tab3` ON `tab3`.`a` = `db_biz1`.`b`';
        $builder = $this
            ->builder('foo')
            ->joinOn('bar', 'a', 'b')
            ->joinOn('bar1 as biz1', 'bar1.a', 'foo.b')
            ->joinOn('bar2 as biz2', 'biz2.a', 'bar1.b')
            ->joinOn('bar3 as biz3', 'biz2.a', 'biz3.b')
            ->joinOn('bar4 as biz4', 'biz4.a', 'biz5.b')
            ->joinOn($subBuilder->alias('biz5'), 'biz1.a', 'biz5.b')
        ;
        static::assertEquals($builder->query(),
            'SELECT * FROM `db_foo` '.
            'INNER JOIN `db_bar` ON `a` = `b` '.
            'INNER JOIN `db_bar1` AS `biz1` ON `db_bar1`.`a` = `db_foo`.`b` '.
            'INNER JOIN `db_bar2` AS `biz2` ON `biz2`.`a` = `db_bar1`.`b` '.
            'INNER JOIN `db_bar3` AS `biz3` ON `biz2`.`a` = `biz3`.`b` '.
            'INNER JOIN `db_bar4` AS `biz4` ON `biz4`.`a` = `db_biz5`.`b` '.
            'INNER JOIN ('.$subQuery.') AS `biz5` ON `biz1`.`a` = `biz5`.`b`'
        );

        self::$connection->setPrefix($prefix);
    }

    public function testJoinWhereMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        $subFunction = function(Builder $builder) {
            $builder->from('y')->where('y','y');
        };
        $subBuilder = $this->builder('x')->where('x','x');

        // joinWhere(table, left, operator, right)
        $builder = $this->builder('foo')->joinWhere('bar', 'bar.a', '>', 0);
        static::assertEquals(
            'SELECT * FROM `foo` INNER JOIN `bar` ON `bar`.`a` > ?',
            $builder->query()
        );
        static::assertEquals([0], $builder->getBindings());

        // joinWhere(table, left, operator, right, type)
        $builder = $this->builder('foo')->joinWhere('bar', 'bar.a', '=', 'f', 'left');
        static::assertEquals(
            'SELECT * FROM `foo` LEFT JOIN `bar` ON `bar`.`a` = ?',
            $builder->query()
        );
        static::assertEquals(['f'], $builder->getBindings());

        // joinWhere(table, whereClause)
        $whereClause = new WhereClause($this->builder()->select(false));
        $whereClause->whereOn('a','b')->where('c','f')->orWhere('bar.d', '>', 0);
        $builder = $this->builder('foo')->joinWhere('bar', $whereClause);
        static::assertEquals(
            'SELECT * FROM `foo` INNER JOIN `bar` ON `a` = `b` AND `c` = ? OR `bar`.`d` > ?',
            $builder->query()
        );
        static::assertEquals(['f', 0], $builder->getBindings());

        // joinWhere(table, left, right)
        $builder = $this->builder('foo')->joinWhere('bar', 'bar.a', '=', 'f');
        static::assertEquals(
            'SELECT * FROM `foo` INNER JOIN `bar` ON `bar`.`a` = ?',
            $builder->query()
        );
        static::assertEquals(['f'], $builder->getBindings());

        // joinWhere(table, whereClause, type)
        $builder = $this->builder('foo')->joinWhere($subBuilder->alias('bar'), function(WhereClause $clause) use ($subFunction) {
            $clause->whereOn('bar.a','foo.b')->where('f', '!=', 9)->orWhere('bar.d', $subFunction);
        }, 'left');
        $subQuery = 'SELECT * FROM `x` WHERE `x` = ?';
        $subString = 'SELECT * FROM `y` WHERE `y` = ?';
        static::assertEquals(
            'SELECT * FROM `foo` LEFT JOIN ('.$subQuery.') AS `bar` ON '.
            '`bar`.`a` = `foo`.`b` AND `f` != ? OR `bar`.`d` = ('.$subString.')',
            $builder->query()
        );
        static::assertEquals(['x', 9, 'y'], $builder->getBindings());

        self::$connection->setPrefix('db_');

        // joinWhere(table, left, operator, right)
        $builder = $this->builder('foo')->joinWhere('bar', 'bar.a', '>', 0);
        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN `db_bar` ON `db_bar`.`a` > ?',
            $builder->query()
        );
        static::assertEquals([0], $builder->getBindings());

        // joinWhere(table, left, operator, right, type)
        $builder = $this->builder('foo')->joinWhere('bar', 'bar.a', '=', 'f', 'left');
        static::assertEquals(
            'SELECT * FROM `db_foo` LEFT JOIN `db_bar` ON `db_bar`.`a` = ?',
            $builder->query()
        );
        static::assertEquals(['f'], $builder->getBindings());

        // joinWhere(table, whereClause)
        $whereClause = new WhereClause($this->builder()->select(false));
        $whereClause->whereOn('foo.a','biz.b')->orWhere('foo.a', '>', 1)->where('biz.c','f')->orWhere('bar.d', '>', 0);
        $builder = $this->builder('foo')->joinWhere('bar as biz', $whereClause);
        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN `db_bar` AS `biz` ON '.
            '`db_foo`.`a` = `biz`.`b` OR `db_foo`.`a` > ? AND `biz`.`c` = ? OR `db_bar`.`d` > ?',
            $builder->query()
        );
        static::assertEquals([1, 'f', 0], $builder->getBindings());

        // joinWhere(table, left, right)
        $builder = $this->builder('foo')->joinWhere('bar', 'bar.a', 'f');
        static::assertEquals(
            'SELECT * FROM `db_foo` INNER JOIN `db_bar` ON `db_bar`.`a` = ?',
            $builder->query()
        );
        static::assertEquals(['f'], $builder->getBindings());

        // joinWhere(table, whereClause, type)
        $builder = $this->builder('foo')->joinWhere(function(Builder $builder) {
            $builder->from('y')->where('bar.y','y')->alias('bar');
        }, function(WhereClause $clause) use ($subBuilder) {
            $clause->whereOn('bar.a','foo.b')->where('f', '!=', 9)->orWhere('bar.d', $subBuilder);
        }, 'left');
        $subString = 'SELECT * FROM `db_y` WHERE `db_bar`.`y` = ?';
        $subQuery = 'SELECT * FROM `db_x` WHERE `x` = ?';
        static::assertEquals(
            'SELECT * FROM `db_foo` LEFT JOIN ('.$subString.') AS `bar` ON '.
            '`bar`.`a` = `db_foo`.`b` AND `f` != ? OR `bar`.`d` = ('.$subQuery.')',
            $builder->query()
        );
        static::assertEquals(['y', 9, 'x'], $builder->getBindings());

        self::$connection->setPrefix($prefix);
    }

    /**
     * @param bool $having
     */
    public function whereClauseBasicTest($having = false)
    {
        $builder = $this->builder()->select(false);
        $select = '';
        $query = 'WHERE';
        if ($having) {
            $builder = $this->builder('foo')->group('foo');
            $select = 'SELECT * FROM `foo` GROUP BY `foo`';
            $query = $select.' HAVING';
        }
        $method = $having ? 'having' : 'where';
        $ucMethod = ucfirst($method);
        $orMethod = 'or'.$ucMethod;
        $onMethod = $method.'On';
        $orOnMethod = 'or'.$ucMethod.'On';

        $methodExists = $method.'Exists';
        $orMethodExists = 'or'.$ucMethod.'Exists';
        $methodNotExists = $method.'NotExists';
        $orMethodNotExists = 'or'.$ucMethod.'NotExists';

        $methodNull = $method.'Null';
        $orMethodNull = 'or'.$ucMethod.'Null';
        $methodNotNull = $method.'NotNull';
        $orMethodNotNull = 'or'.$ucMethod.'NotNull';

        $subBuilder = $this->builder('x')->where('x','x');
        $subQuery = 'SELECT * FROM `x` WHERE `x` = ?';
        $subFunction = function(Builder $builder) {
            $builder->from('y')->where('y','y');
        };
        $subString = 'SELECT * FROM `y` WHERE `y` = ?';

        $bindings = [];

        // where(left, right)
        static::assertEquals(
            ($query = $query.' `foo` = ?'),
            $builder->$method('foo', 0)->query()
        );
        array_push($bindings, 0);
        static::assertEquals($bindings, $builder->getBindings());

        // where(left, right) test and
        static::assertEquals(
            ($query = $query.' AND `foo1` = ?'),
            $builder->$method('foo1', 1)->query()
        );
        array_push($bindings, 1);
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere(left,right)
        static::assertEquals(
            ($query = $query.' OR `foo2` = ?'),
            $builder->$orMethod('foo2', 2)->query()
        );
        array_push($bindings, 2);
        static::assertEquals($bindings, $builder->getBindings());

        // where(left, Builder $right)
        static::assertEquals(
            ($query = $query.' AND `sx` = ('.$subQuery.')'),
            $builder->$method('sx', $subBuilder)->query()
        );
        array_push($bindings, 'x');
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere(left, Builder $right)
        static::assertEquals(
            ($query = $query.' OR `sx2` = ('.$subQuery.')'),
            $builder->$orMethod('sx2', $subBuilder)->query()
        );
        array_push($bindings, 'x');
        static::assertEquals($bindings, $builder->getBindings());

        // where(left, Closure $right)
        static::assertEquals(
            ($query = $query.' AND `sy` = ('.$subString.')'),
            $builder->$method('sy', $subFunction)->query()
        );
        array_push($bindings, 'y');
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere(left, Closure $right)
        static::assertEquals(
            ($query = $query.' OR `sy2` = ('.$subString.')'),
            $builder->$orMethod('sy2', $subFunction)->query()
        );
        array_push($bindings, 'y');
        static::assertEquals($bindings, $builder->getBindings());

        // where(left, Raw $right)
        static::assertEquals(
            ($query = $query.' AND `sz` = "x"'),
            $builder->$method('sz', $builder::raw('"x"'))->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere(left, Raw $right)
        static::assertEquals(
            ($query = $query.' OR `sz2` = ?'),
            $builder->$orMethod('sz2', $builder::raw('?','='))->query()
        );
        array_push($bindings, '=');
        static::assertEquals($bindings, $builder->getBindings());

        // where(left,operator,right)
        static::assertEquals(
            ($query = $query.' AND `foo3` > ?'),
            $builder->$method('foo3', '>', 3)->query()
        );
        array_push($bindings, 3);
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere(left,operator,right)
        static::assertEquals(
            ($query = $query.' OR `foo4` < LOW(?)'),
            $builder->$orMethod('foo4', '<', Builder::raw('LOW(?)', 4))->query()
        );
        array_push($bindings, 4);
        static::assertEquals($bindings, $builder->getBindings());

        // whereOn(left, right)
        static::assertEquals(
            ($query = $query.' AND `foo7` = `bar7`'),
            $builder->$onMethod('foo7', 'bar7')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // orWhereOn(left, right)
        static::assertEquals(
            ($query = $query.' OR `foo8` = `bar8`'),
            $builder->$orOnMethod('foo8', 'bar8')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // whereOn(left, operator, right)
        static::assertEquals(
            ($query = $query.' AND `foo9` > `bar9`'),
            $builder->$onMethod('foo9', '>', 'bar9')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // orWhereOn(left, operator, right)
        static::assertEquals(
            ($query = $query.' OR `foo0` < `bar0`'),
            $builder->$orOnMethod('foo0', '<', 'bar0')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // whereNull(column)
        static::assertEquals(
            ($query = $query.' AND `bar1` IS NULL'),
            $builder->$methodNull('bar1')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // orWhereNull(column)
        static::assertEquals(
            ($query = $query.' OR `bar2` IS NULL'),
            $builder->$orMethodNull('bar2')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // whereNotNull(column)
        static::assertEquals(
            ($query = $query.' AND `bar3` IS NOT NULL'),
            $builder->$methodNotNull('bar3')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // orWhereNotNull(column)
        static::assertEquals(
            ($query = $query.' OR `bar4` IS NOT NULL'),
            $builder->$orMethodNotNull('bar4')->query()
        );
        static::assertEquals($bindings, $builder->getBindings());

        // where EXISTS
        static::assertEquals(
            ($query = $query.' AND EXISTS ('.$subQuery.') OR EXISTS ('.$subQuery.')'),
            $builder->$methodExists($subBuilder)->$orMethodExists($subBuilder)->query()
        );
        array_push($bindings, 'x','x');
        static::assertEquals($bindings, $builder->getBindings());

        // where NOT EXISTS
        static::assertEquals(
            ($query = $query.' AND NOT EXISTS ('.$subString.') OR NOT EXISTS ('.$subString.')'),
            $builder->$methodNotExists($subFunction)->$orMethodNotExists($subFunction)->query()
        );
        array_push($bindings, 'y','y');
        static::assertEquals($bindings, $builder->getBindings());

        //where IN
        static::assertEquals(
            ($query = $query.' AND `k` IN (?) OR `k2` IN (?,?)'),
            $builder->$method('k', ' in ', 'p')->$orMethod('k2', ' IN ', ['p1', 'p2'])->query()
        );
        array_push($bindings, 'p','p1','p2');
        static::assertEquals($bindings, $builder->getBindings());

        //where NOT IN
        static::assertEquals(
            ($query = $query.' AND `k3` NOT IN ('.$subQuery.') OR `k4` NOT IN (?,LOW(?))'),
            $builder->$method('k3', 'NOT  in ', $subBuilder)
                ->$orMethod('k4', ' nOT IN ', ['p3', Builder::raw('LOW(?)', 'p4')])->query()
        );
        array_push($bindings, 'x','p3','p4');
        static::assertEquals($bindings, $builder->getBindings());

        //where BETWEEN
        static::assertEquals(
            ($query = $query.' AND `t` BETWEEN ? AND ? OR `t2` BETWEEN ? AND ?'),
            $builder->$method('t', ' between ', [9,20])->$orMethod('t2', '  betWEEN', [4, 100])->query()
        );
        array_push($bindings, 9,20,4,100);
        static::assertEquals($bindings, $builder->getBindings());

        //where NOT BETWEEN
        static::assertEquals(
            ($query = $query.' AND `t3` NOT BETWEEN ? AND ? OR `t4` NOT BETWEEN ? AND ?'),
            $builder->$method('t3', ' Not between ', [1,10])->$orMethod('t4', 'not  betWEEN', [20,30])->query()
        );
        array_push($bindings, 1,10,20,30);
        static::assertEquals($bindings, $builder->getBindings());

        if ($having) {
            static::assertSame($builder, $builder->clearHaving());
        } else {
            static::assertSame($builder, $builder->clearWhere());
        }
        static::assertEquals($select, $builder->query());
        static::assertEquals([], $builder->getBindings());
    }

    /**
     * @param bool $having
     */
    public function whereClauseNestTest($having = false)
    {
        /** @var Builder $builder */
        $builder = $this->builder()->select(false);
        $query = 'WHERE ';
        if ($having) {
            $builder = $this->builder('foo')->group('foo');
            $query = 'SELECT * FROM `foo` GROUP BY `foo` HAVING ';
        }
        $method = $having ? 'having' : 'where';
        $ucMethod = ucfirst($method);
        $orMethod = 'or'.$ucMethod;

        $where = '`foo` = ? AND `bar` > ? OR `biz` != ? AND `foo` = `biz` OR `bar` != `biz`';
        $whereArray = [
            ['foo', 1],
            ['bar', '>', 2],
            ['biz', '!=', 3, 'or'],
            ['foo', '=', 'biz', 'on'],
            ['bar', '!=', 'biz', 'or', 'on'],
        ];
        $query1 = $query.$where;
        $query2 = $query.$where. ' OR ( '.$where.' )';

        // where(array basic left)
        static::assertEquals($query1, $builder->$method($whereArray)->query());
        static::assertEquals([1,2,3], $builder->getBindings());

        // orWhere(array basic left)
        static::assertEquals($query2, $builder->$orMethod($whereArray)->query());
        static::assertEquals([1,2,3,1,2,3], $builder->getBindings());
        if ($having) {
            static::assertSame($builder, $builder->clearHaving());
        } else {
            static::assertSame($builder, $builder->clearWhere());
        }

        $whereClosure = function(WhereClause $clause){
            $clause->where('foo',1)->where('bar','>',2)->orWhere('biz','!=',3)
                ->whereOn('foo','biz')->orWhereOn('bar','!=','biz');
        };

        // where(Closure basic left)
        static::assertEquals($query1, $builder->$method($whereClosure)->query());
        static::assertEquals([1,2,3], $builder->getBindings());

        // orWhere(Closure basic left)
        static::assertEquals($query2, $builder->$orMethod($whereClosure)->query());
        static::assertEquals([1,2,3,1,2,3], $builder->getBindings());
        if ($having) {
            static::assertSame($builder, $builder->clearHaving());
        } else {
            static::assertSame($builder, $builder->clearWhere());
        }

        $subBuilder = $this->builder('x')->where('x','x');
        $subQuery = 'SELECT * FROM `x` WHERE `x` = ?';

        $where = $query.'`foo` = ('.$subQuery.') '.
            'AND ( ' .
                '`foo_1_1` = ? '.
                'OR ( '.
                    '`foo_1_2_1` = ? AND `foo_1_2_2` = `bar_1_2_2` '.
                ') '.
                'AND `foo_1_3` BETWEEN ? AND ? '.
            ') ' .
            'OR ( '.
                '`foo_2_1` > ? '.
                'AND ( '.
                    '`foo_2_2_1` = ? OR `foo_2_2_2` = `bar_2_2_2` '.
                ') '.
                'OR `foo_2_3` IN (?,?) '.
            ')' .
            ' OR `biz` != ?';

        // where(array nest left)
        $whereArray = [
            ['foo', $subBuilder],
            [[
                ['foo_1_1', 0],
                [[
                    ['foo_1_2_1', 2],
                    ['foo_1_2_2', '=', 'bar_1_2_2', 'on'],
                ], 'or'],
                ['foo_1_3', 'between', [3,100]]
            ]],
            [[
                ['foo_2_1', '>', 4],
                [[
                    ['foo_2_2_1', 5],
                    ['foo_2_2_2', '=', 'bar_2_2_2', 'or', 'on'],
                ]],
                ['foo_2_3', 'in', [6,7], 'or']
            ], 'or'],
            ['biz', '!=', 'str', 'or'],
        ];
        static::assertEquals($where, $builder->$method($whereArray)->query());
        static::assertEquals(['x',0,2,3,100,4,5,6,7,'str'], $builder->getBindings());
        if ($having) {
            static::assertSame($builder, $builder->clearHaving());
        } else {
            static::assertSame($builder, $builder->clearWhere());
        }

        // where(Closure nest left)
        $builder->$method('foo',$subBuilder)->$method(function(WhereClause $clause) {

            $clause->where('foo_1_1',0)->orWhere(function(WhereClause $subClause){
                $subClause->where('foo_1_2_1',2)->whereOn('foo_1_2_2', 'bar_1_2_2');
            })->where('foo_1_3', 'between', [3,100]);

        })->$orMethod(function(WhereClause $clause){

            $clause->where('foo_2_1', '>', 4)->where(function(WhereClause $subClause){
                $subClause->where('foo_2_2_1',5)->orWhereOn('foo_2_2_2', 'bar_2_2_2');
            })->orWhere('foo_2_3', 'in', [6,7]);

        })->$orMethod('biz', '!=', 'str');
        static::assertEquals($where, $builder->query());
        static::assertEquals(['x',0,2,3,100,4,5,6,7,'str'], $builder->getBindings());
    }

    public function testWhereBasic()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $this->whereClauseBasicTest();
        self::$connection->setPrefix($prefix);
    }

    public function testWhereNest()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $this->whereClauseNestTest();
        self::$connection->setPrefix($prefix);
    }

    public function testSelectGroupBy()
    {
        $prefix = self::$connection->prefix;
        $builder = $this->builder('foo');

        self::$connection->setPrefix(null);
        static::assertEquals('SELECT * FROM `foo` GROUP BY `bar`', $builder->group('bar')->query());
        static::assertEquals('SELECT * FROM `foo` GROUP BY `foo`, `bar`', $builder->group(['foo','bar'])->query());

        self::$connection->setPrefix('db_');
        static::assertEquals('SELECT * FROM `db_foo` GROUP BY `bar`', $builder->group('bar')->query());
        static::assertEquals(
            'SELECT * FROM `db_foo` AS `ali` GROUP BY `db_foo`.`foo`, `ali`.`bar`',
            $builder->from('foo as ali')->group(['foo.foo','ali.bar'])->query()
        );
        self::$connection->setPrefix($prefix);
    }

    public function testGroupHavingBasic()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $this->whereClauseBasicTest(true);
        $builder = $this->builder('foo');
        static::assertEquals("SELECT * FROM `foo`", $builder->having('a',2)->query());
        self::$connection->setPrefix($prefix);
    }

    public function testGroupHavingNest()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $this->whereClauseNestTest(true);
        self::$connection->setPrefix($prefix);
    }

    public function testOrderMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        static::assertEquals(
            'SELECT * FROM `foo` ORDER BY `id` DESC',
            $this->builder('foo')->order('id')->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` ORDER BY `id` ASC',
            $this->builder('foo')->order('id', true)->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` ORDER BY `id` ASC',
            $this->builder('foo')->order('id', true)->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` ORDER BY `id`, `aid` ASC, `name` DESC',
            $this->builder('foo')->order(['id','aid'], true)->order('name')->query()
        );

        self::$connection->setPrefix('db_');

        static::assertEquals(
            'SELECT * FROM `db_foo` AS `al` INNER JOIN `db_bar` AS `ali` ON `a` = `b` '.
            'ORDER BY `id`, `db_foo`.`aid`, `al`.`bid`, `db_bar`.`cid`, `ali`.`did` ASC',
            $this->builder('foo as al')->joinOn('bar as ali','a','b')
                ->order(['id','foo.aid','al.bid','bar.cid','ali.did'], true)
                ->query()
        );
        $subBuilder = $this->builder('subFoo as subAl')->joinOn('subBar as subAli','aa','bb')
                        ->order([
                            'id','subFoo.aid','subAl.bid','subBar.cid','subAli.did',
                            'foo.eid', 'bar.fid', 'al.gid', 'ali.hid'
                        ]);
        $subQuery = 'SELECT * FROM `db_subFoo` AS `subAl` INNER JOIN `db_subBar` AS `subAli` ON `aa` = `bb` '.
            'ORDER BY `id`, `db_subFoo`.`aid`, `subAl`.`bid`, `db_subBar`.`cid`, `subAli`.`did`, '.
            '`db_foo`.`eid`, `db_bar`.`fid`, `db_al`.`gid`, `db_ali`.`hid` DESC';

        static::assertEquals(
            $subQuery,
            $subBuilder->query()
        );
        static::assertEquals(
            'SELECT * FROM `db_foo` AS `al` INNER JOIN ('.$subQuery.') AS `ali` ON `a` = `b` '.
            'ORDER BY `id`, `db_foo`.`aid`, `al`.`bid`, `db_bar`.`cid`, `ali`.`did`, '.
            '`db_subAl`.`eid`, `db_subAli`.`fid`, `db_subFoo`.`gid` ASC',
            $this->builder('foo as al')->joinOn($subBuilder->alias('ali'),'a','b')
                ->order(['id','foo.aid','al.bid','bar.cid','ali.did','subAl.eid', 'subAli.fid', 'subFoo.gid'], true)
                ->query()
        );

        self::$connection->setPrefix($prefix);
    }

    public function testSelectWithPrefixTable()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        static::assertEquals(
            'SELECT * FROM `foo` ORDER BY `foo`.`id` DESC',
            $this->builder('foo')->setPrefixTable('foo')->order('id')->query()
        );
        static::assertEquals(
            'SELECT `foo`.*, `foo`.`id` FROM `foo` ORDER BY `foo`.`id` DESC',
            $this->builder('foo')->setPrefixTable('foo')->select('*', 'id')->order('id')->query()
        );
        static::assertEquals(
            'SELECT `foo`.*, `foo`.`id` FROM `foo` WHERE `foo`.`tid` = ? '.
            'GROUP BY `foo`.`sid` HAVING `foo`.`pid` = ? ORDER BY `foo`.`id` DESC',
            $this->builder('foo')->setPrefixTable('foo')->select('*', 'id')
                ->where('tid', 2)
                ->group('sid')->having('pid', 3)
                ->order('id')->query()
        );
        self::$connection->setPrefix($prefix);
    }


    /**
     * 供 insert update delete 使用的 builder
     * @param null $table
     * @param null $prefix
     * @return Builder
     */
    protected function builderStub( $table = null, $prefix = null)
    {
        $config = self::$connection->config;
        $config['prefix'] = $prefix;

        $connectionStub = $this->getMockBuilder(
            'Tanbolt\Database\Connection'
        )->setMethods(
            ['fetchOne', 'fetchAll', 'execute']
        )->setConstructorArgs(
            ['PHPUNIT_CONNECTION_STUB', $config]
        )->getMock();
        /** @var Connection $connectionStub */
        return new Builder($connectionStub, $table);
    }

    /**
     * @param Builder $builderStub
     * @return PHPUnit_Framework_MockObject_Builder_InvocationMocker
     */
    protected function mockStub(Builder $builderStub)
    {
        /** @var \PHPUnit_Framework_MockObject_MockObject $mock */
        $mock = $builderStub->connection();
        return $mock->expects(static::once());
    }

    /**
     * test insert method
     * @param $insertTestData
     */
    protected function insertStubTest($insertTestData)
    {
        foreach ($insertTestData as $insert) {
            $data = $insert['data'];     // insert 数据
            $time = $insert['time'];     // execute 执行次数
            $execute = $insert['execute']; // execute 执行语句 和 bindings 数据
            $return = $insert['return']; // execute 每次执行返回值
            $replace = $insert['replace'] ?? false;

            $builderStub = $this->builderStub('foo', null);
            /** @var \PHPUnit_Framework_MockObject_MockObject $mock */
            $mock = $builderStub->connection();
            $invocationMocker = $mock->expects(static::exactly($time))->method('execute')
                ->will(call_user_func_array([$this, 'onConsecutiveCalls'], $return));
            call_user_func_array([$invocationMocker, 'withConsecutive'], $execute);
            $num = 0;
            foreach ($return as $r) {
                $num += $r ?: 0;
            }
            static::assertEquals($num, $builderStub->insert($data, $replace));
        }
    }

    /**
     * @param $insertTestData
     */
    protected function insertFromStubTest($insertTestData)
    {
        foreach ($insertTestData as $insert) {
            $select = $insert['select'];     // insert select
            $column = $insert['column'];    // insert column
            $execute = $insert['execute'];  // execute 执行语句
            $bindings = $insert['bindings']; // bindings 数据
            $return = $insert['return'];    // execute 执行返回值

            $builderStub = $this->builderStub('foo', null);
            /** @var \PHPUnit_Framework_MockObject_MockObject $mock */
            $mock = $builderStub->connection();
            $mock->expects(static::once())->method('execute')->with($execute, $bindings)->willReturn($return);
            static::assertEquals($return, $builderStub->insertFrom($select, $column));
        }
    }

    /**
     * test  update method
     * @param Builder $builderStub
     * @param $data
     * @param $query
     * @param $bindings
     * @param $number
     */
    protected function updateStubTest(Builder $builderStub, $data, $query, $bindings, $number)
    {
        /** @var \PHPUnit_Framework_MockObject_MockObject $mock */
        $mock = $builderStub->connection();
        $mock->expects(static::once())
            ->method('execute')->with($query, $bindings)->willReturn($number);
        static::assertEquals($number, $builderStub->update($data));
    }

    /**
     * test upsert method
     * @param $insertTestData
     */
    protected function upsertStubTest($insertTestData)
    {
        foreach ($insertTestData as $insert) {
            $data = $insert['data'];     // upsert  数据
            $search = $insert['search']; // search  字段
            $getTime = $insert['getTime'];   // get 执行次数
            $getReturn = $insert['getResult']; // get 每次执行返回值
            $time = $insert['time'];     // execute 执行次数
            $execute = $insert['execute'];     // execute 执行语句 和 bindings 数据
            $return = $insert['return']; // execute 每次执行返回值

            $builderStub = $this->builderStub('foo', null);
            /** @var \PHPUnit_Framework_MockObject_MockObject $mock */
            $mock = $builderStub->connection();

            $method = 'fetchAll';
            $mock->expects(static::exactly($getTime))->method($method)
                ->will(call_user_func_array([$this, 'onConsecutiveCalls'], $getReturn));

            $invocationMocker = $mock->expects(static::exactly($time))->method('execute')
                ->will(call_user_func_array([$this, 'onConsecutiveCalls'], $return));
            call_user_func_array([$invocationMocker, 'withConsecutive'], $execute);
            $num = 0;
            foreach ($return as $r) {
                $num += $r ?: 0;
            }
            static::assertEquals($num, $builderStub->upsert($data, $search));
        }
    }

    public function testAggregateExecute()
    {
        $builder = $this->builderStub('foo', null)->where('id', 1);
        $this->mockStub($builder)->method('fetchOne')
            ->with('SELECT function(`id`) AS `aggregate` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn((object)['aggregate' => 'foo']);
        static::assertEquals('foo', $builder->aggregate('function', 'id', true));

        $builder = $this->builderStub('foo', null)->where('id', 1);
        $this->mockStub($builder)->method('fetchOne')
            ->with('SELECT count(*) AS `aggregate` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn((object)['aggregate' => 2]);
        static::assertEquals(2, $builder->records());

        $builder = $this->builderStub('foo', null)->where('id', 1);
        $this->mockStub($builder)->method('fetchOne')
            ->with('SELECT sum(`id`) AS `aggregate` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn((object)['aggregate' => 2]);
        static::assertEquals(2, $builder->sum('id'));

        $builder = $this->builderStub('foo', null)->where('id', 1);
        $this->mockStub($builder)->method('fetchOne')
            ->with('SELECT max(`id`, `tid`) AS `aggregate` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn((object)['aggregate' => 2]);
        static::assertEquals(2, $builder->max(['id', 'tid']));

        $builder = $this->builderStub('foo', null)->where('id', 1);
        $this->mockStub($builder)->method('fetchOne')
            ->with('SELECT min(`tid`) AS `aggregate` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn((object)['aggregate' => 2]);
        static::assertEquals(2, $builder->min(['tid']));

        $builder = $this->builderStub('foo', null)->where('id', 1);
        $this->mockStub($builder)->method('fetchOne')
            ->with('SELECT avg(`tid`) AS `aggregate` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn((object)['aggregate' => 2]);
        static::assertEquals(2, $builder->avg(['tid']));
    }

    public function testGetOneMethod()
    {
        $r = ['id' =>0 , 'name' => 'foo', 'title' => 'bar'];

        $builder = $this->builderStub('foo', null)->where('id', 1)->select('id', 'name', 'title');
        $return = (object)$r;
        $this->mockStub($builder)->method('fetchOne')
            ->with('SELECT `id`, `name`, `title` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn( $return);
        static::assertEquals($return, $builder->getOne());
    }

    public function testGetManyMethod()
    {
        $r = [['id' =>0 , 'name' => 'foo', 'title' => 'bar']];

        $builder = $this->builderStub('foo', null)->where('id', 1)->select('id', 'name', 'title');
        $return = [(object)$r];
        $this->mockStub($builder)->method('fetchAll')
            ->with('SELECT `id`, `name`, `title` FROM `foo` WHERE `id` = ?', [1])
            ->willReturn( $return);
        static::assertEquals( $return, $builder->getMany());
    }

    public function testDeleteMethod()
    {
        $builder = $this->builderStub('foo', null);
        $return = 2;
        $this->mockStub($builder)->method('execute')
            ->with('DELETE FROM `foo`', [])
            ->willReturn($return);
        static::assertEquals($return, $builder->delete());

        $builder = $this->builderStub('foo', null)->where('id', 1);
        $return = 2;
        $this->mockStub($builder)->method('execute')
            ->with('DELETE FROM `foo` WHERE `id` = ?', [1])
            ->willReturn( $return);
        static::assertEquals( $return, $builder->delete());
    }




    // 使用 sqlite 真实测试 last id 获取, 只测试一次
    public function testGetLastIdActual()
    {
        $key = 'PHPUNIT_TEST_2F1577AE750A3F660897585EBB58D52B';
        if (array_key_exists($key, $GLOBALS) && $GLOBALS[$key]) {
            static::assertTrue(true);
            return ;
        }

        $drivers = PDO::getAvailableDrivers();
        if (!in_array('sqlite', $drivers)) {
            static::fail('Unit test need sqlite pdo driver support');
        }
        $dbName = __DIR__ .'/../Fixtures/DB_2F1577AE750A3F660897585EBB58D52B';
        @unlink($dbName);

        $connect = new Connection('PHPUNIT_GetLastIdActual', [
            'driver' => 'sqlite',
            'dbname' => $dbName,
        ]);
        $connect->statement('
            CREATE TABLE `foo` (
                `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
                `foo` mediumint
            )
        ');

        // insert one row
        $builder = $connect->table('foo')->setIncrementColumn('id');
        $row = $builder->insert([
            'id' => 1, 'foo' => 1
        ]);
        static::assertEquals(1, $row);
        static::assertEquals(1, $builder->lastId());

        // insert one row
        $row = $builder->insert([
            'foo' => 1
        ]);
        static::assertEquals(1, $row);
        static::assertEquals(2, $builder->lastId());

        // insert multiple row
        $row = $builder->insert([
            ['foo' => 1], ['foo' => 1], ['foo' => 1]
        ]);
        static::assertEquals(3, $row);
        static::assertEquals([3,4,5], $builder->lastId());

        // insert multiple row
        $row = $builder->insert([
            ['id' => 7, 'foo' => 1], ['id' => 9, 'foo' => 1], ['id' => 15, 'foo' => 1]
        ]);
        static::assertEquals(3, $row);
        static::assertEquals([7,9,15], $builder->lastId());

        // insert multiple row
        $row = $builder->insert([
            ['foo' => 1], ['id' => 22, 'foo' => 1], ['foo' => 1], ['id' => 21, 'foo' => 1]
        ]);
        static::assertEquals(4, $row);
        static::assertEquals([16, 22, 17, 21], $builder->lastId());


        $connect->statement('
            CREATE TABLE `bar` (
                `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
                `foo` mediumint
            )
        ');
        $row = $connect->table('bar')->insert([
            ['foo' => 1],
            ['foo' => 2],
            ['foo' => 3]
        ]);
        static::assertEquals(3, $row);

        // insert from
        $row = $builder->insertFrom($connect->table('bar')->select('foo'));
        static::assertEquals(3, $row);
        static::assertEquals([23, 24, 25], $builder->lastId());

        // check
        $list = $builder->select('id')->order('id', true)->getMany(PDO::FETCH_ASSOC);
        static::assertEquals([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
            ['id' => 5],
            ['id' => 7],
            ['id' => 9],
            ['id' => 15],
            ['id' => 16],
            ['id' => 17],
            ['id' => 21],
            ['id' => 22],
            ['id' => 23],
            ['id' => 24],
            ['id' => 25],
        ], $list);

        $connect->disconnect();
        @unlink($dbName);
        $GLOBALS[$key] = true;
    }
}
