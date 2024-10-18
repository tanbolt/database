<?php
require_once __DIR__ . '/DatabaseBuilderBasic.php';

use Tanbolt\Database\Query\Builder;
use Tanbolt\Database\Query\Expression;

class DatabaseBuilderMysql extends DatabaseBuilderBasic
{
    /**
     * @param bool $having
     */
    public function whereTimeClauseBasicTest($having = false)
    {
        $subBuilder = $this->builder('x')->where('x','x');
        $subQuery = 'SELECT * FROM `x` WHERE `x` = ?';
        $subFunction = function(Builder $builder) {
            $builder->from('y')->where('y','y');
        };
        $subString = 'SELECT * FROM `y` WHERE `y` = ?';

        // start
        $builder = $this->builder()->select(false);
        $select = '';
        $where = 'WHERE';
        if ($having) {
            $builder = $this->builder('foo')->group('foo');
            $select = 'SELECT * FROM `foo` GROUP BY `foo`';
            $where = ' HAVING';
        }
        $function = $having ? 'having' : 'where';
        $method = $function.'Time';
        $orMethod = 'or'.ucfirst($function).'Time';
        $clear = 'clear'.ucfirst($function);
        $bindings = [];

        // where(left, right)
        static::assertEquals(
            ($query = $select.$where.' `foo` = ?'),
            $builder->$method('foo', 0)->query()
        );
        array_push($bindings, 0);
        static::assertEquals($bindings, $builder->getBindings());

        // where(left, right)  test and
        static::assertEquals(
            ($query = $query.' AND `foo1` = ?'),
            $builder->$method('foo1', 1)->query()
        );
        array_push($bindings, 1);
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere(left, right)
        static::assertEquals(
            ($query = $query.' OR `foo2` = ?'),
            $builder->$orMethod('foo2', 2)->query()
        );
        array_push($bindings, 2);
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere where(left, operator, right)
        static::assertEquals(
            ($query = $query .
                ' AND DATE(`t1`) > ?'.
                ' OR TIME(`t2`) = ?'.
                ' AND YEAR(`t3`) BETWEEN ? AND ?'.
                ' OR QUARTER(`t4`) IN (?,?,?)'.
                ' AND MONTH(`t5`) != ?'.
                ' OR WEEK(`t6`) >= ?'.
                ' AND DAY(`t7`) EXISTS ('.$subQuery.')'.
                ' OR HOUR(`t8`) <> ?'.
                ' AND MINUTE(`t9`) <= ?'.
                ' AND SECOND(`t0`) NOT IN ('.$subString.')'.
                ' OR UNIX_TIMESTAMP(`tt`) > ?'.
                ' OR `td` <> ?'
            ),
            $builder
                ->$method('t1', '> DATE', '2014-10-28')
                ->$orMethod('t2', '= time', '18:30:04')
                ->$method('t3', ' between year ', [2008, 2016])
                ->$orMethod('t4', ' in QUARTER ', [2,3,4])
                ->$method('t5', ' != MONTH ', 6)
                ->$orMethod('t6', ' >= WEEK ', 7)
                ->$method('t7', ' exists day ', $subBuilder)
                ->$orMethod('t8', ' <> hour ', 12)
                ->$method('t9', ' <= MINUTE ', 20)
                ->$method('t0', ' NOT in   SECOND ', $subFunction)
                ->$orMethod('tt', ' > TIMESTAMP ', 1326341168)
                ->$orMethod('td', ' <> datetime ', '2014-10-28 18:30:04')
                ->query()
        );
        array_push($bindings,
            '2014-10-28',
            '18:30:04',
            2008, 2016,
            2,3,4,
            6,
            7,
            'x',
            12,
            20,
            'y',
            1326341168,
            '2014-10-28 18:30:04'
        );
        static::assertEquals($bindings, $builder->getBindings());

        $builder->$clear();
        $bindings = [];
        static::assertEquals($select, $builder->query());
        static::assertEquals($bindings, $builder->getBindings());

        // test where unix timestamp

        // where(left, right)
        static::assertEquals(
            ($query = $select.$where.' `foo` = ?'),
            $builder->$method('foo', 0)->query()
        );
        array_push($bindings, 0);
        static::assertEquals($bindings, $builder->getBindings());

        // orWhere where(left, operator, right)
        $method = $function.'Unix';
        $orMethod = 'or'.ucfirst($function).'Unix';
        static::assertEquals(
            ($query = $query .
                " AND FROM_UNIXTIME(`t1`, '%Y-%m-%d') > ?".
                " OR FROM_UNIXTIME(`t2`, '%H:%i:%s') = ?".
                " AND FROM_UNIXTIME(`t3`, '%Y') BETWEEN ? AND ?".
                " OR QUARTER(FROM_UNIXTIME(`t4`)) IN (?,?,?)".
                " AND FROM_UNIXTIME(`t5`, '%c') != ?".
                " OR FROM_UNIXTIME(`t6`, '%w') >= ?".
                " AND FROM_UNIXTIME(`t7`, '%e') EXISTS ({$subQuery})".
                " OR FROM_UNIXTIME(`t8`, '%k') <> ?".
                " AND CONVERT(FROM_UNIXTIME(`t9`, '%i'), SIGNED) <= ?".
                " AND CONVERT(FROM_UNIXTIME(`t0`, '%s'), SIGNED) NOT IN ({$subString})".
                " OR `tt` > ?".
                " OR FROM_UNIXTIME(`td`) <> ?"
            ),
            $builder
                ->$method('t1', '> DATE', '2014-10-28')
                ->$orMethod('t2', '= time', '18:30:04')
                ->$method('t3', ' between year ', [2008, 2016])
                ->$orMethod('t4', ' in QUARTER ', [2,3,4])
                ->$method('t5', ' != MONTH ', 6)
                ->$orMethod('t6', ' >= WEEK ', 7)
                ->$method('t7', ' exists day ', $subBuilder)
                ->$orMethod('t8', ' <> hour ', 12)
                ->$method('t9', ' <= MINUTE ', 20)
                ->$method('t0', ' NOT in   SECOND ', $subFunction)
                ->$orMethod('tt', ' > TIMESTAMP ', 1326341168)
                ->$orMethod('td', ' <> datetime ', '2014-10-28 18:30:04')
                ->query()
        );
        array_push($bindings,
            '2014-10-28',
            '18:30:04',
            2008, 2016,
            2,3,4,
            6,
            7,
            'x',
            12,
            20,
            'y',
            1326341168,
            '2014-10-28 18:30:04'
        );
        static::assertEquals($bindings, $builder->getBindings());
    }

    public function testWhereTimeMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $this->whereTimeClauseBasicTest();
        self::$connection->setPrefix($prefix);
    }

    public function testHavingTimeMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $this->whereTimeClauseBasicTest(true);
        self::$connection->setPrefix($prefix);
    }


    public function testWhereInMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);

        $builder = $this->builder('foo')->where(['id', 'tid'], 'in', [[1,2], [2,4]]);
        static::assertEquals(
            'SELECT * FROM `foo` WHERE (`id`, `tid`) IN ((?,?),(?,?))',
            $builder->query()
        );
        static::assertEquals(
            [1,2,2,4],
            $builder->getBindings()
        );
        $this->whereTimeClauseBasicTest(true);
        self::$connection->setPrefix($prefix);
    }


    public function testLimitOffsetMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        static::assertEquals(
            'SELECT * FROM `foo` LIMIT 10',
            $this->builder('foo')->limit(10)->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` OFFSET 10',
            $this->builder('foo')->offset(10)->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` LIMIT 5 OFFSET 10',
            $this->builder('foo')->limit(5)->offset(10)->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` LIMIT 10 OFFSET 0',
            $this->builder('foo')->limit(10, 0)->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` LIMIT 10 OFFSET 5',
            $this->builder('foo')->limit(10, 0)->offset(5)->query()
        );
        self::$connection->setPrefix($prefix);
    }

    public function testUnionsMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        $builder1 = $this->builder('foo1')->where('bar1', 1);
        $builder2 = $this->builder('foo2')->limit(5)->where('bar2', 2);
        $builder3 = $this->builder('foo3')->order('id')->where('bar3', 'x');
        $builder4 = $this->builder('foo4')->limit(10,2)->order('aid',true)->where('bar4', 3);

        $builder = $this->builder('foo')->where('bar', '!=', 'bar')
                    ->union($builder1)->union($builder2, true)->union($builder3)->union($builder4, true);
        static::assertEquals($builder->query(),
            'SELECT * FROM `foo` WHERE `bar` != ? '.
            'UNION (SELECT * FROM `foo1` WHERE `bar1` = ?) '.
            'UNION ALL (SELECT * FROM `foo2` WHERE `bar2` = ? LIMIT 5) '.
            'UNION (SELECT * FROM `foo3` WHERE `bar3` = ? ORDER BY `id` DESC) '.
            'UNION ALL (SELECT * FROM `foo4` WHERE `bar4` = ? ORDER BY `aid` ASC LIMIT 10 OFFSET 2)'
        );
        static::assertEquals(['bar', 1, 2, 'x', 3], $builder->getBindings());


        $builder = $this->builder('foo')->where('bar', '!=', 'bar')->order('id')->limit(20,5)
           ->union($builder2, true)->union($builder1)->union($builder4, true)->union($builder3)->order('uid')->limit(100,50);
        static::assertEquals($builder->query(),
            'SELECT * FROM `foo` WHERE `bar` != ? ORDER BY `id` DESC LIMIT 20 OFFSET 5 '.
            'UNION ALL (SELECT * FROM `foo2` WHERE `bar2` = ? LIMIT 5) '.
            'UNION (SELECT * FROM `foo1` WHERE `bar1` = ?) '.
            'UNION ALL (SELECT * FROM `foo4` WHERE `bar4` = ? ORDER BY `aid` ASC LIMIT 10 OFFSET 2) '.
            'UNION (SELECT * FROM `foo3` WHERE `bar3` = ? ORDER BY `id` DESC) '.
            'ORDER BY `uid` DESC LIMIT 100 OFFSET 50'
        );
        static::assertEquals(['bar', 2, 1, 3, 'x'], $builder->getBindings());

        self::$connection->setPrefix($prefix);
    }

    public function testSelectLockMethod()
    {
        $prefix = self::$connection->prefix;
        self::$connection->setPrefix(null);
        static::assertEquals(
            'SELECT * FROM `foo` FOR UPDATE',
            $this->builder('foo')->lockForeUpdate()->query()
        );
        static::assertEquals(
            'SELECT * FROM `foo` LOCK IN SHARE MODE',
            $this->builder('foo')->lockShared()->query()
        );
        self::$connection->setPrefix($prefix);
    }

    // test  Insert
    public function testInsertMethod()
    {
        $insertTestData = [
            // 插入一条数据
            [
                'data' => ['id' => 1, 'tid' => 2],
                'time' => 1,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`) VALUES (?,?)',  [1, 2] ]
                ],
                'return' => [1]
            ],

            // 插入数据 并使用 Expression
            [
                'data' => ['id' => 1, 'tid' => new Expression("LOW('A')")],
                'time' => 1,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`) VALUES (?,LOW(\'A\'))',  [1] ]
                ],
                'return' => [1]
            ],

            // 插入多条 结构相同 的数据
            [
                'data' => [
                    ['id' => 1, 'tid' => 2],
                    ['id' => 1, 'tid' => new Expression("LOW('A')")],
                    ['id' => 3, 'tid' => 4],
                ],
                'time' => 1,
                'execute' => [
                    [ 'REPLACE INTO `foo` (`id`, `tid`) VALUES (?,?), (?,LOW(\'A\')), (?,?)',  [1,2,1,3,4] ]
                ],
                'return' => [3],
                'replace' => true,
            ],

            // 插入多条 数据结构不同 的数据
            [
                'data' => [
                    ['id' => 1, 'tid' => 2],
                    ['id' => 5, 'tid' => new Expression("LOW('A')")],
                    ['id' => 3, 'mid' => 4],
                ],
                'time' => 2,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`) VALUES (?,?), (?,LOW(\'A\'))',  [1,2,5] ],
                    [ 'INSERT INTO `foo` (`id`, `mid`) VALUES (?,?)',  [3,4] ]
                ],
                'return' => [2, 1]
            ],
        ];
        $this->insertStubTest($insertTestData);
    }

    // test insertFrom
    public function testInsertFromMethod()
    {
        $insertTestData = [
            // 复制数据
            [
                'select' => $this->builderStub('bar')->select('id', 'tid'),
                'column' => null,
                'execute' => 'INSERT INTO `foo` (`id`, `tid`) SELECT `id`, `tid` FROM `bar`',
                'bindings' => [],
                'return' => 1
            ],

            // 指定字段
            [
                'select' => $this->builderStub('bar')->select('id', 'tid'),
                'column' => ['id', 'cid'],
                'execute' => 'INSERT INTO `foo` (`id`, `cid`) SELECT `id`, `tid` FROM `bar`',
                'bindings' => [],
                'return' => 1
            ],

            // 通过别名指定字段
            [
                'select' => $this->builderStub('bar')->from('bar','b')->joinOn('biz as z', 'b.id', 'z.id')
                            ->select('id', 'z.tid as cid', 'z.value as name')->where('b.id', 1)->where('z.id', 3),
                'column' => null,
                'execute' => 'INSERT INTO `foo` (`id`, `cid`, `name`) SELECT `id`, `z`.`tid` AS `cid`, `z`.`value` AS `name` '.
                             'FROM `bar` AS `b` INNER JOIN `biz` AS `z` ON `b`.`id` = `z`.`id` WHERE `b`.`id` = ? AND `z`.`id` = ?',
                'bindings' => [1, 3],
                'return' => 5
            ],
        ];
        $this->insertFromStubTest($insertTestData);
    }

    // test update
    public function testUpdateMethod()
    {
        // basic
        $this->updateStubTest(
            $this->builderStub('foo', null)->where('id', 1),
            ['tid' => 1, 'name' => 'x', 'age+' => 2],
            'UPDATE `foo` SET `tid` = ?, `name` = ?, `age` = `age` + ? WHERE `id` = ?',
            [1, 'x', 2, 1],
            1
        );

        // use sub query
        $subQuery = $this->builderStub('bar', null)->select('age')->where('mid', '>', 4);
        $this->updateStubTest(
            $this->builderStub('foo', null)->where('id', 1),
            ['tid' => 1, 'name' => 'x', 'age+' => $subQuery],
            'UPDATE `foo` SET `tid` = ?, `name` = ?, `age` = `age` + (SELECT `age` FROM `bar` WHERE `mid` > ?) WHERE `id` = ?',
            [1, 'x', 4, 1],
            2
        );

        // mysql  use join
        $this->updateStubTest(
            $this->builderStub('foo', 'db_')->from('foo', 'f')->joinOn('bar as b', 'f.a', 'b.b')->where('foo.id', 1)->where('b.uid','>',2),
            ['foo.tid' => 1, 'f.name' => 'x', 'f.age+' => 2, 'bar.tid' => 3, 'b.name' => 'y', 'b.age+' => 4],
            'UPDATE `db_foo` AS `f` INNER JOIN `db_bar` AS `b` ON `f`.`a` = `b`.`b` '.
            'SET `db_foo`.`tid` = ?, `f`.`name` = ?, `f`.`age` = `f`.`age` + ?, '.
            '`db_bar`.`tid` = ?, `b`.`name` = ?, `b`.`age` = `b`.`age` + ? '.
            'WHERE `db_foo`.`id` = ? AND `b`.`uid` > ?',
            [1, 'x', 2, 3, 'y', 4, 1, 2],
            2
        );
    }

    // test upsert
    public function testUpsertMethod()
    {
        $insertTestData = [

            // search 一个字段  入库一条
            [
                'data' => ['id' => 1, 'tid' => 2],
                'search' => 'id',
                'getTime' => 1,
                'getResult' => [[]],
                'time' => 1,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`) VALUES (?,?)',  [1, 2] ]
                ],
                'return' => [1]
            ],

            // search 一个字段  入库多条
            [
                'data' => [
                    ['id' => 1, 'tid' => 2], ['id' => 2, 'tid' => 4]
                ],
                'search' => 'id',
                'getTime' => 1,
                'getResult' => [[]],
                'time' => 1,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`) VALUES (?,?), (?,?)',  [1, 2, 2, 4] ]
                ],
                'return' => [2]
            ],

            // search 一个字段  分别插入 / 更新
            [
                'data' => [
                    ['id' => 1, 'tid' => 2], ['id' => 2, 'tid' => 4], ['id' => 3, 'tid' => 4]
                ],
                'search' => 'id',
                'getTime' => 1,
                'getResult' => [[['id' => 3]]],
                'time' => 2,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`) VALUES (?,?), (?,?)',  [1, 2, 2, 4] ],
                    [ 'UPDATE `foo` SET `tid` = ? WHERE `id` IN (?)',  [4, 3] ],
                ],
                'return' => [1,2]
            ],


            // search 一个字段   分别插入 / 更新
            [
                'data' => [
                    ['id' => 1, 'tid' => 2],
                    ['id' => 2, 'tid' => 4],
                    ['id' => 3, 'tid' => 4],
                    ['id' => 4, 'uid' => 4],
                    ['id' => 5, 'tid' => 4],
                    ['id' => 6, 'tid' => 5],
                ],
                'search' => 'id',
                'getTime' => 1,
                'getResult' => [[
                    ['id' => 3],
                    ['id' => 5],
                    ['id' => 6],
                ]],
                'time' => 4,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`) VALUES (?,?), (?,?)',  [1, 2, 2, 4] ],
                    [ 'INSERT INTO `foo` (`id`, `uid`) VALUES (?,?)',  [4,4] ],
                    [ 'UPDATE `foo` SET `tid` = ? WHERE `id` IN (?,?)',  [4, 3, 5] ],
                    [ 'UPDATE `foo` SET `tid` = ? WHERE `id` IN (?)',  [5, 6] ],
                ],
                'return' => [1,2,1]
            ],

            // search 多个  入库一条
            [
                'data' => ['id' => 1, 'tid' => 2, 'uid' => 3],
                'search' => ['id', 'tid'],
                'getTime' => 1,
                'getResult' => [[]],
                'time' => 1,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`, `uid`) VALUES (?,?,?)',  [1, 2, 3] ]
                ],
                'return' => [1]
            ],

            // search 多个  入库一条
            [
                'data' => ['id' => 1, 'tid' => 2, 'uid' => 3],
                'search' => ['id', 'tid'],
                'getTime' => 1,
                'getResult' => [[
                    ['id' => 1, 'tid' => 2]
                ]],
                'time' => 1,
                'execute' => [
                    [ 'UPDATE `foo` SET `uid` = ? WHERE (`id`, `tid`) IN ((?,?))',  [3, 1, 2] ]
                ],
                'return' => [1]
            ],

            // search 多个  入库多条
            [
                'data' => [
                    ['id' => 1, 'tid' => 2 , 'uid' => 3],
                    ['id' => 2, 'tid' => 2 , 'uid' => 4],
                    ['id' => 3, 'tid' => 1 , 'xid' => 5],

                    ['id' => 4, 'tid' => 1 , 'uid' => 5],
                    ['id' => 5, 'tid' => 1 , 'uid' => 5],
                    ['id' => 6, 'tid' => 1 , 'uid' => 7],
                ],
                'search' => ['id', 'tid'],
                'getTime' => 1,
                'getResult' => [[
                    ['id' => 4, 'tid' => 1],
                    ['id' => 5, 'tid' => 1],
                    ['id' => 6, 'tid' => 1],
                ]],
                'time' => 4,
                'execute' => [
                    [ 'INSERT INTO `foo` (`id`, `tid`, `uid`) VALUES (?,?,?), (?,?,?)',  [1, 2, 3, 2, 2, 4] ],
                    [ 'INSERT INTO `foo` (`id`, `tid`, `xid`) VALUES (?,?,?)',  [3,1,5] ],
                    [ 'UPDATE `foo` SET `uid` = ? WHERE (`id`, `tid`) IN ((?,?),(?,?))',  [5, 4, 1,5,1] ],
                    [ 'UPDATE `foo` SET `uid` = ? WHERE (`id`, `tid`) IN ((?,?))',  [7, 6, 1] ],
                ],
                'return' => [1, 1, 1]
            ],
        ];

        $this->upsertStubTest($insertTestData);
    }

}
