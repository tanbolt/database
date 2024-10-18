<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Schema\Table;

class DatabaseSchemaTableTest extends TestCase
{
    /**
     * @var array
     */
    protected $accepts = [
        'name' => null,
        'engine' => null,
        'collation' => null,
        'charset' => null,
    ];

    public function testConstruct()
    {
        $table = new Table();
        $v = 1;
        foreach ($this->accepts as $key => $val) {
            static::assertSame($table, $table->$key($v));

            static::assertTrue(isset($table->{$key}));
            static::assertFalse(empty($table->{$key}));
            static::assertEquals($v, $table->{$key});

            static::assertTrue(isset($table[$key]));
            static::assertFalse(empty($table[$key]));
            static::assertEquals($v, $table[$key]);

            $table[$key] = null;
            static::assertNull($table->{$key});
            static::assertNull($table[$key]);

            $table->$key($v);
            $v++;
        }
        static::assertSame($table, $table->clear());
        foreach ($this->accepts as $key => $val) {
            static::assertEquals($val, $table->{$key});
            static::assertEquals($val, $table[$key]);
        }
    }

    public function testReset()
    {
        $v = 1;
        $reset = [];
        foreach ($this->accepts as $key => $val) {
            $reset[$key] = $v++;
        }
        $table = new Table();
        static::assertSame($table, $table->reset($reset));
        $v = 1;
        foreach ($this->accepts as $key => $val) {
            static::assertEquals($v, $table->{$key});
            static::assertEquals($v, $table[$key]);
            $v++;
        }
    }

    public function testColumnRepeat()
    {
        $table = new Table();
        try {
            $table->addColumn('foo');
            $table->addColumn('foo');
            static::fail('It should be throw exception when use same column name');
        } catch (Exception $e) {
            static::assertTrue(true);
        }
    }

    public function testIndexRepeat()
    {
        $table = new Table();
        try {
            $table->addIndex('foo');
            $table->alterIndex('foo');
            static::fail('It should be throw exception when use same index name');
        } catch (Exception $e) {
            static::assertTrue(true);
        }
    }

    public function testForeignRepeat()
    {
        $table = new Table();
        try {
            $table->addForeign('foo');
            $table->dropForeign('foo');
            static::fail('It should be throw exception when use same Foreign key name');
        } catch (Exception $e) {
            static::assertTrue(true);
        }
    }

    public function testColumnMethod()
    {
        $this->manageMethodBasic('Column');
    }

    public function testIndexMethod()
    {
        $this->manageMethodExtra('Index');
    }

    public function testForeignMethod()
    {
        $this->manageMethodExtra('Foreign');
    }

    protected function manageMethodExtra($type)
    {
        $this->manageMethodBasic($type);
        $this->manageIndexMethodTest('add', $type);
        $this->manageIndexMethodTest('alter', $type);
    }

    protected function manageIndexMethodTest($method, $type)
    {
        $table = new Table();
        $table->name('test');
        $function = $method.$type;
        $object = $table->$function(['foo', 'bar']);
        static::assertEquals($method, $object->command);
        static::assertNotEmpty($object->name);
        static::assertEquals(['foo', 'bar'], $object->column);
        return $object;
    }

    protected function manageMethodBasic($type)
    {
        $this->manageMethodTest('add', $type, ['rename' => 'bar']);
        $this->manageMethodTest('alter', $type, ['rename' => 'bar']);
        $this->manageMethodTest('alter', $type, 'bar');
        $this->manageMethodTest('drop', $type, ['rename' => 'bar']);
    }

    protected function manageMethodTest($method, $type, $parameters)
    {
        $table = new Table();
        $function = $method.$type;
        $object = $table->$function('foo', $parameters);
        static::assertInstanceOf('Tanbolt\\Database\\Schema\\'.$type, $object);
        static::assertEquals($method, $object->command);
        static::assertEquals('foo', $object->name);
        if ($method === 'drop') {
            static::assertNull($object->rename);
        } else {
            static::assertEquals('bar', $object->rename);
        }
        return $object;
    }
}
