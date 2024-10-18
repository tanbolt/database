<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Schema\Foreign;

class DatabaseSchemaForeignTest extends TestCase
{
    /**
     * @var array
     */
    protected $accepts = [
        'name'=> null,
        'column' => null,
        'table' => null,
        'reference' => null,
        'onUpdate' => null,
        'onDelete' => null,
        'rename' => null,
    ];

    public function testConstruct()
    {
        $foreign = new Foreign();
        static::assertCount(0, array_diff($foreign->toArray(), $this->accepts));
        $v = 1;
        foreach ($this->accepts as $key => $val) {
            $val = ('column' === $key || 'reference' === $key) ? [$v] : $v;

            static::assertSame($foreign, $foreign->$key($v));

            static::assertTrue(isset($foreign->{$key}));
            static::assertFalse(empty($foreign->{$key}));
            static::assertEquals($val, $foreign->{$key});

            static::assertTrue(isset($foreign[$key]));
            static::assertFalse(empty($foreign[$key]));
            static::assertEquals($val, $foreign[$key]);

            $foreign[$key] = null;
            static::assertNull($foreign->{$key});
            static::assertNull($foreign[$key]);

            $foreign->$key($v);
            $v++;
        }
        static::assertSame($foreign, $foreign->clear());
        foreach ($this->accepts as $key => $val) {
            static::assertEquals($val, $foreign->{$key});
            static::assertEquals($val, $foreign[$key]);
        }
    }

    public function testReset()
    {
        $v = 1;
        $reset = [];
        foreach ($this->accepts as $key => $val) {
            $reset[$key] = $v++;
        }
        $foreign = new Foreign();
        static::assertSame($foreign, $foreign->reset($reset));
        $v = 1;
        foreach ($this->accepts as $key => $val) {
            $vv = 'column' === $key || 'reference' === $key ? [$v] : $v;
            static::assertEquals($vv, $foreign->{$key});
            static::assertEquals($vv, $foreign[$key]);
            $v++;
        }
    }
}
