<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Schema\Index;

class DatabaseSchemaIndexTest extends TestCase
{
    /**
     * @var array
     */
    protected $accepts = [
        'rename' => null,
        'name'=> null,
        'type' => null,
        'unique' => null,
        'primary' => null,
        'implicit' => null,
        'column' => null,
    ];

    public function testConstruct()
    {
        $index = new Index();
        static::assertCount(0, array_diff($index->toArray(), $this->accepts));
        $v = 1;
        foreach ($this->accepts as $key => $val) {
            $val = 'column' === $key ? [$v] : $v;

            static::assertSame($index, $index->$key($v));

            static::assertTrue(isset($index->{$key}));
            static::assertFalse(empty($index->{$key}));
            static::assertEquals($val, $index->{$key});

            static::assertTrue(isset($index[$key]));
            static::assertFalse(empty($index[$key]));
            static::assertEquals($val, $index[$key]);

            $index[$key] = null;
            static::assertNull($index->{$key});
            static::assertNull($index[$key]);

            $index->$key($v);
            $v++;
        }
        static::assertSame($index, $index->clear());
        foreach ($this->accepts as $key => $val) {
            static::assertEquals($val, $index->{$key});
            static::assertEquals($val, $index[$key]);
        }
    }

    public function testSetType()
    {
        $index = new Index();

        static::assertSame($index, $index->type('index'));
        static::assertEquals('index', $index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        $index->type('primary');
        static::assertEquals('primary', $index->type);
        static::assertTrue($index->primary);
        static::assertFalse($index->unique);

        $index->type('unique');
        static::assertEquals('unique', $index->type);
        static::assertFalse($index->primary);
        static::assertTrue($index->unique);

        $index->type(null);
        static::assertNull($index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);
    }

    public function testSetPrimary()
    {
        $index = new Index();
        static::assertNull($index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        static::assertSame($index, $index->primary());
        static::assertTrue($index->primary);
        static::assertFalse($index->unique);
        static::assertEquals('PRIMARY', $index->type);

        static::assertSame($index, $index->primary(false));
        static::assertEmpty($index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        $index = new Index();
        $index->type('index')->primary();
        static::assertEquals('PRIMARY', $index->type);
        static::assertTrue($index->primary);
        static::assertFalse($index->unique);

        $index->primary(false);
        static::assertEmpty($index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        $index = new Index();
        $index->type('index')->primary(false);
        static::assertEquals('index', $index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        $index->primary();
        static::assertEquals('PRIMARY', $index->type);
        static::assertTrue($index->primary);
        static::assertFalse($index->unique);
    }

    public function testSetUnique()
    {
        $index = new Index();
        static::assertNull($index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        static::assertSame($index, $index->unique());
        static::assertTrue($index->unique);
        static::assertFalse($index->primary);
        static::assertEquals('UNIQUE', $index->type);

        static::assertSame($index, $index->unique(false));
        static::assertEmpty($index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        $index = new Index();
        $index->type('index')->unique();
        static::assertEquals('UNIQUE', $index->type);
        static::assertFalse($index->primary);
        static::assertTrue($index->unique);

        $index->unique(false);
        static::assertEmpty($index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        $index = new Index();
        $index->type('index')->unique(false);
        static::assertEquals('index', $index->type);
        static::assertFalse($index->primary);
        static::assertFalse($index->unique);

        $index->unique();
        static::assertEquals('UNIQUE', $index->type);
        static::assertFalse($index->primary);
        static::assertTrue($index->unique);
    }

    public function testReset()
    {
        $index = new Index();
        static::assertSame($index, $index->reset(['name' => 'name', 'rename' => 'rename']));
        static::assertEquals('name', $index->name);
        static::assertEquals('rename', $index['rename']);

        $index->reset(['type' => 'index', 'primary' => true]);
        static::assertEquals('PRIMARY', $index->type);
        static::assertTrue($index->primary);

        $index->reset(['primary' => true, 'type' => 'index']);
        static::assertEquals('index', $index->type);
        static::assertFalse($index->primary);
    }
}
