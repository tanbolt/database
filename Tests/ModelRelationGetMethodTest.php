<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelRelationGetMethodTest extends TestCase
{
    protected static $dbPath = 'ModelFindWithRelationTest';

    /**
     * @var ModelRelationGetMethodTest_Foo
     */
    protected static $Parent;

    protected static function insertTable()
    {
        $foo = ModelRelationGetMethodTest_Foo::createModel([
            'x' => 1,
        ]);
        $foo->bar()->addCollection([
            ['x' => 11, 'y' => 12, 'cls' => 'ModelRelationGetMethodTest_FetchClass'],
            ['x' => 21, 'y' => 22]
        ], [
            'x' => 'xx',
            'z' => 'zz'
        ]);
        return $foo;
    }

    public static function setUpBeforeClass():void
    {
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => __DIR__.'/Fixtures/'.self::$dbPath,
        ]], true);
        $connection = (new ModelRelationGetMethodTest_Foo)->connection();

        // foo
        $connection->execute("CREATE TABLE `foo` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` mediumint
        )");

        // bar
        $connection->execute("CREATE TABLE `bar` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `x` mediumint,
            `y` mediumint,
            `cls` VARCHAR(50) DEFAULT ''
        )");
        
        // pivot
        $connection->execute("CREATE TABLE `pivot` (
            `id`  INTEGER PRIMARY KEY AUTOINCREMENT,
            `foo_id` INTEGER,
            `bar_id` INTEGER,
            `x` VARCHAR(20),
            `z` VARCHAR(20)
        )");

        static::$Parent = static::insertTable();
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass():void
    {
        Database::getNode()->disconnect();
        Database::clearNode();
        @unlink(__DIR__.'/Fixtures/'.self::$dbPath);
        parent::tearDownAfterClass();
    }

    public function testGetAssocMethod()
    {
        $a1 = [
            'id' => 1,
            'x' => 11,
            'y' => 12,
            'cls' => 'ModelRelationGetMethodTest_FetchClass',
            'pivot' => [
                'id' => 1,
                'bar_id' => 1,
                'foo_id' => 1
            ]
        ];
        $a2 = $a1;
        $a2['pivot']['x'] = 'xx';

        $a3 = $a2;
        $a3['pivot']['z'] = 'zz';

        // get one
        static::assertEquals($a1, static::$Parent->bar()->getOne(PDO::FETCH_ASSOC));
        static::assertEquals($a2, static::$Parent->bar()->withPivot('x')->getOne(PDO::FETCH_ASSOC));
        static::assertEquals($a3, static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_ASSOC));

        $b1 = [
            'id' => 2,
            'x' => 21,
            'y' => 22,
            'cls' => '',
            'pivot' => [
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1
            ]
        ];
        $b2 = $b1;
        $b2['pivot']['x'] = 'xx';

        $b3 = $b2;
        $b3['pivot']['z'] = 'zz';

        // getMany
        static::assertEquals([$a1, $b1], static::$Parent->bar()->getMany(PDO::FETCH_ASSOC));
        static::assertEquals([$a2, $b2], static::$Parent->bar()->withPivot('x')->getMany(PDO::FETCH_ASSOC));
        static::assertEquals([$a3, $b3], static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_ASSOC));

        // getCursor
        foreach (static::$Parent->bar()->getCursor(1,PDO::FETCH_ASSOC) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b1, $v);
            } else {
                static::assertEquals($a1, $v);
            }
        }
        foreach (static::$Parent->bar()->withPivot('x')->getCursor(1,PDO::FETCH_ASSOC) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b2, $v);
            } else {
                static::assertEquals($a2, $v);
            }
        }
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_ASSOC) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b3, $v);
            } else {
                static::assertEquals($a3, $v);
            }
        }
    }

    public function testGetNumMethod()
    {
        $a1 = [
            1,
            11,
            12,
            'ModelRelationGetMethodTest_FetchClass',
            [
                'id' => 1,
                'bar_id' => 1,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        $b1 = [
            2,
            21,
            22,
            '',
            [
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        // get one
        static::assertEquals($a1, static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_NUM));
        // getMany
        static::assertEquals([$a1, $b1], static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_NUM));
        // getCursor
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_NUM) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b1, $v);
            } else {
                static::assertEquals($a1, $v);
            }
        }
    }

    public function testGetBothMethod()
    {
        $a1 = [
            'id' => 1,
            0 => 1,
            'x' => 11,
            1 => 11,
            'y' => 12,
            2 => 12,
            'cls' => 'ModelRelationGetMethodTest_FetchClass',
            3 => 'ModelRelationGetMethodTest_FetchClass',
            'pivot' => [
                'id' => 1,
                'bar_id' => 1,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ],
            4 => [
                'id' => 1,
                'bar_id' => 1,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        $b1 = [
            'id' => 2,
            0 => 2,
            'x' => 21,
            1 => 21,
            'y' => 22,
            2 => 22,
            'cls' => '',
            3 => '',
            'pivot' => [
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ],
            4 => [
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        // get one
        static::assertEquals($a1, static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_BOTH));
        // getMany
        static::assertEquals([$a1, $b1], static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_BOTH));
        // getCursor
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_BOTH) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b1, $v);
            } else {
                static::assertEquals($a1, $v);
            }
        }
    }

    public function testGetObjAndClassMethod()
    {
        $a1 = (object) [
            'id' => 1,
            'x' => 11,
            'y' => 12,
            'cls' => 'ModelRelationGetMethodTest_FetchClass',
            'pivot' => [
                'id' => 1,
                'bar_id' => 1,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        $b1 = (object) [
            'id' => 2,
            'x' => 21,
            'y' => 22,
            'cls' => '',
            'pivot' => [
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        // get one
        static::assertEquals($a1, static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_OBJ));
        static::assertEquals($a1, static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_CLASS));
        // getMany
        static::assertEquals([$a1, $b1], static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_OBJ));
        static::assertEquals([$a1, $b1], static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_CLASS));
        // getCursor
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_OBJ) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b1, $v);
            } else {
                static::assertEquals($a1, $v);
            }
        }
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_CLASS) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b1, $v);
            } else {
                static::assertEquals($a1, $v);
            }
        }
    }

    public function testGetColumnMethod()
    {
        // get one
        static::assertEquals(1, static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_COLUMN));
        static::assertEquals(11, static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_COLUMN,1));
        // getMany
        static::assertEquals([1, 2], static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_COLUMN));
        static::assertEquals([11, 21], static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_COLUMN,1));
        // getCursor
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_COLUMN) as $k => $v) {
            if ($k > 0) {
                static::assertEquals(2, $v);
            } else {
                static::assertEquals(1, $v);
            }
        }
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_COLUMN,1) as $k => $v) {
            if ($k > 0) {
                static::assertEquals(21, $v);
            } else {
                static::assertEquals(11, $v);
            }
        }
    }

    public function testGetNamedMethod()
    {
        $a1 = [
            'id' => [1,1],
            'x' => [11, 'xx'],
            'y' => 12,
            'cls' => 'ModelRelationGetMethodTest_FetchClass',
            '*' => '*',
            'foo_id' => 1,
            'bar_id' => 1,
            'z' => 'zz',
            '**' => '**'
        ];
        $b1 = [
            'id' => [2,2],
            'x' => [21, 'xx'],
            'y' => 22,
            'cls' => '',
            '*' => '*',
            'foo_id' => 1,
            'bar_id' => 2,
            'z' => 'zz',
            '**' => '**'
        ];
        $removeQuote = function ($data, $many = false) {
            if (!$many) {
                $data = [$data];
            }
            $rs = [];
            foreach ($data as $k => $v) {
                $row = [];
                foreach ($v as $kk => $vv) {
                    if ($kk === "'*'") {
                        $row['*'] = $vv;
                    } elseif ($kk === "'**'") {
                        $row['**'] = $vv;
                    } else {
                        $row[$kk] = $vv;
                    }
                }
                $rs[$k] = $row;
            }
            if ($many) {
                return $rs;
            }
            return $rs[0];
        };
        // get one
        static::assertEquals($a1, $removeQuote(static::$Parent->bar()->withPivot()->getOne(PDO::FETCH_NAMED)));
        // getMany
        static::assertEquals([$a1, $b1], $removeQuote(static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_NAMED), true));
        // getCursor
        foreach (static::$Parent->bar()->withPivot()->getCursor(1,PDO::FETCH_NAMED) as $k => $v) {
            if ($k > 0) {
                static::assertEquals($b1, $removeQuote($v));
            } else {
                static::assertEquals($a1, $removeQuote($v));
            }
        }
    }

    public function testGetKeyPairMethod()
    {
        // get one
        static::assertEquals([1 => 11], static::$Parent->bar()->select('id', 'x')->getOne(PDO::FETCH_KEY_PAIR));
        static::assertEquals([11 => 12], static::$Parent->bar()->select('x', 'y')->getOne(PDO::FETCH_KEY_PAIR));
        // get many
        static::assertEquals([
            1 => 11,
            2 => 21
        ], static::$Parent->bar()->select('id', 'x')->getMany(PDO::FETCH_KEY_PAIR));
        static::assertEquals([
            11 => 12,
            21 => 22
        ], static::$Parent->bar()->select('x', 'y')->getMany(PDO::FETCH_KEY_PAIR));
        // getCursor
        foreach (static::$Parent->bar()->select('id', 'x')->getCursor(1,PDO::FETCH_KEY_PAIR) as $k => $v) {
            if ($k > 1) {
                static::assertEquals(2, $k);
                static::assertEquals(21, $v);
            } else {
                static::assertEquals(1, $k);
                static::assertEquals(11, $v);
            }
        }
        foreach (static::$Parent->bar()->select('x', 'y')->getCursor(1,PDO::FETCH_KEY_PAIR) as $k => $v) {
            if ($k > 11) {
                static::assertEquals(21, $k);
                static::assertEquals(22, $v);
            } else {
                static::assertEquals(11, $k);
                static::assertEquals(12, $v);
            }
        }
    }

    public function testGetFuncMethod()
    {
        $a1 = [
            1,
            11,
            12,
            'ModelRelationGetMethodTest_FetchClass',
            [
                'id' => 1,
                'bar_id' => 1,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        $b1 = [
            2,
            21,
            22,
            '',
            [
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];
        // get many
        $rs = static::$Parent->bar()->withPivot()->getMany(PDO::FETCH_FUNC, function() {
            return func_get_args();
        });
        static::assertEquals([$a1, $b1], $rs);
    }


    protected function checkFetchClassObj(
        ModelRelationGetMethodTest_FetchClass $obj,
        $val,
        $row = 1,
        $seralize = false,
        $late = false
    ) {
        if ($seralize) {
            static::assertFalse($obj->construct);
            static::assertFalse($obj->hasId);
            static::assertNull($obj->getId()); // 第一个字段被用于执行 unserialize()
            static::assertNotNull($obj->unserializeDat); // unserializeDat 应该是第一列数据
        } else {
            if ($late) {
                static::assertTrue($obj->construct);
                static::assertFalse($obj->hasId);
            } else {
                static::assertTrue($obj->construct);
                static::assertTrue($obj->hasId);
            }
            static::assertEquals($obj->getId(), $val['id']);
            static::assertNull($obj->unserializeDat);
        }

        static::assertEquals($obj->getX(), $val['x']);
        static::assertEquals($obj->y, $val['y']);

        if ($row > 1) {
            static::assertEquals([
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ], $obj->pivot);
        } else {
            static::assertEquals([
                'id' => 1,
                'bar_id' => 1,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ], $obj->pivot);
        }
    }

    public function testGetClassMethod()
    {
        $a1 = [
            'id' => 1,
            'x' => null,
            'y' => 12
        ];
        $b1 = [
            'id' => 2,
            'x' => null,
            'y' => 22
        ];

        // getOne row1
        $bar = static::$Parent->bar()->withPivot()->getOne(
            PDO::FETCH_CLASS,
            'ModelRelationGetMethodTest_FetchClass'
        );
        $this->checkFetchClassObj($bar, $a1);
        static::assertEquals('ModelRelationGetMethodTest_FetchClass', $bar->cls);

        $bar = static::$Parent->bar()->withPivot()->getOne(
            PDO::FETCH_CLASS,
            'ModelRelationGetMethodTest_FetchClass',
            ['arg']
        );
        $this->checkFetchClassObj($bar, [
            'id' => 1,
            'x' => 'arg',
            'y' => 12
        ]);
        static::assertEquals('ModelRelationGetMethodTest_FetchClass', $bar->cls);


        // getMany
        $bars = static::$Parent->bar()->withPivot()->getMany(
            PDO::FETCH_CLASS,
            'ModelRelationGetMethodTest_FetchClass'
        );
        // row1
        $this->checkFetchClassObj($bars[0], $a1);
        static::assertEquals('ModelRelationGetMethodTest_FetchClass', $bars[0]->cls);
        // row2
        $this->checkFetchClassObj($bars[1], $b1, 2);
        static::assertEmpty($bars[1]->cls);

        // getCursor
        $bars = static::$Parent->bar()->withPivot()->getCursor(
            1,
            PDO::FETCH_CLASS,
            'ModelRelationGetMethodTest_FetchClass'
        );
        foreach ($bars as $k=>$bar) {
            if ($k > 0) {
                // row2
                $this->checkFetchClassObj($bar, $b1, 2);
                static::assertEmpty($bar->cls);
            } else {
                // row1
                $this->checkFetchClassObj($bar, $a1);
                static::assertEquals('ModelRelationGetMethodTest_FetchClass', $bar->cls);
            }
        }
    }

    public function testGetClassPropsLateMethod()
    {
        //getOne row1
        $bar = static::$Parent->bar()->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_PROPS_LATE,
            'ModelRelationGetMethodTest_FetchClass'
        );
        $this->checkFetchClassObj($bar, [
            'id' => 1,
            'x' => 11,
            'y' => 12
        ], 1, false,true);
        static::assertEquals('ModelRelationGetMethodTest_FetchClass', $bar->cls);

        //getOne row1
        $bar = static::$Parent->bar()->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_PROPS_LATE,
            'ModelRelationGetMethodTest_FetchClass',
            ['arg']
        );
        $this->checkFetchClassObj($bar, [
            'id' => 1,
            'x' => 11,
            'y' => 12
        ], 1, false,true);
        static::assertEquals('ModelRelationGetMethodTest_FetchClass', $bar->cls);
    }

    public function testGetClassSeralizeMethod()
    {
        // getOne row1
        /** @var ModelRelationGetMethodTest_FetchClass $bar */
        $bar = static::$Parent->bar()->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_SERIALIZE,
            'ModelRelationGetMethodTest_FetchClass'
        );

        $this->checkFetchClassObj($bar, [
            'x' => 11,
            'y' => 12
        ], 1, true);
        static::assertEquals('ModelRelationGetMethodTest_FetchClass', $bar->cls);
    }


    public function testGetClassTypeMethod()
    {
        // getOne row1, has class (FETCH_CLASSTYPE)
        $bar = static::$Parent->bar()->select('cls', 'id', 'x', 'y')->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_CLASSTYPE
        );
        $this->checkFetchClassObj($bar, [
            'id' => 1,
            'x' => null,
            'y' => 12
        ], 1);
        static::assertFalse(isset($bar->cls));

        // getOne row1, has class (FETCH_CLASSTYPE|FETCH_PROPS_LATE)
        $bar = static::$Parent->bar()->select('cls', 'id', 'x', 'y')->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_CLASSTYPE|PDO::FETCH_PROPS_LATE
        );
        $this->checkFetchClassObj($bar, [
            'id' => 1,
            'x' => 11,
            'y' => 12
        ], 1, false, true);
        static::assertFalse(isset($bar->cls));


        // getOne row1, has class (FETCH_CLASSTYPE|FETCH_SERIALIZE)
        $bar = static::$Parent->bar()->select('cls', 'id', 'x', 'y')->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_CLASSTYPE|PDO::FETCH_SERIALIZE
        );
        $this->checkFetchClassObj($bar, [
            'id' => 1,
            'x' => 11,
            'y' => 12
        ], 1, true);
        static::assertFalse(isset($bar->cls));

        $obj = (object) [
            'id' => 2,
            'x' => 21,
            'y' => 22,
            'pivot' => [
                'id' => 2,
                'bar_id' => 2,
                'foo_id' => 1,
                'x' => 'xx',
                'z' => 'zz'
            ]
        ];

        // getOne row1, no class (FETCH_CLASSTYPE)
        $bar = static::$Parent->bar()->wherePrimary(2)->select('cls', 'id', 'x', 'y')->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_CLASSTYPE
        );
        static::assertEquals($obj, $bar);

        // getOne row1, no class (FETCH_CLASSTYPE|FETCH_PROPS_LATE)
        $bar = static::$Parent->bar()->wherePrimary(2)->select('cls', 'id', 'x', 'y')->withPivot()->getOne(
            PDO::FETCH_CLASS|PDO::FETCH_CLASSTYPE|PDO::FETCH_PROPS_LATE
        );
        static::assertEquals($obj, $bar);

        // getOne row1, no class (FETCH_CLASSTYPE|FETCH_SERIALIZE)
        try {
            static::$Parent->bar()->wherePrimary(2)->select('cls', 'id', 'x', 'y')->withPivot()->getOne(
                PDO::FETCH_CLASS|PDO::FETCH_CLASSTYPE|PDO::FETCH_SERIALIZE
            );
            static::fail('It should throw exception if class not instanceof \Serializable');
        } catch (\PDOException $e) {
            static::assertTrue(true);
        }
    }

}

class ModelRelationGetMethodTest_Bar extends Model
{
    protected $tableName = 'bar';
}

class ModelRelationGetMethodTest_Foo extends Model
{
    protected $tableName = 'foo';

    /**
     * @return Model\Relation\HasMany
     */
    public function bar()
    {
        return $this->hasMany('ModelRelationGetMethodTest_Bar', 'id', 'id')
            ->throughTable('pivot', 'bar_id', 'foo_id', 'id');
    }
}

class ModelRelationGetMethodTest_FetchClass implements Serializable
{
    private $id;
    protected $x;

    public $unserializeDat;

    public $construct = false;
    public $hasId = false;
    public function __construct($x = null)
    {
        $this->construct = true;
        if ($this->id) {
            $this->hasId = true;
        }
        $this->x = $x;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getX()
    {
        return $this->x;
    }

    public function serialize()
    {
        return '';
    }

    public function unserialize($dat)
    {
        $this->unserializeDat = $dat;
    }
}

