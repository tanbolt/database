<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Schema\Column;
use Tanbolt\Database\Schema\Schema;

class DatabaseSchemaColumnTest extends TestCase
{
    /**
     * @var array
     */
    protected $accepts = [
        'name'=> null,
        'realType' => null,
        'type' => null,
        'length' => null,
        'unsigned' => null,
        'auto' => null,
        'null' => null,
        'collation' => null,
        'default' => false,
        'comment' => null,
        'after' => null,
        'rename' => null,
    ];

    /**
     * 字段类型
     * @var array
     */
    protected $columnType = [
        'int' => Schema::TYPE_INT,
        'tinyint' => Schema::TYPE_TINYINT,
        'smallint' => Schema::TYPE_SMALLINT,
        'mediumint' => Schema::TYPE_MEDIUMINT,
        'bigint' => Schema::TYPE_BIGINT,
        'bool' => Schema::TYPE_BOOL,

        'float' => Schema::TYPE_FLOAT,
        'double' => Schema::TYPE_DOUBLE,
        'decimal' => Schema::TYPE_DECIMAL,

        'char' => Schema::TYPE_CHAR,
        'varchar' => Schema::TYPE_VARCHAR,
        'text' => Schema::TYPE_TEXT,
        'mediumtext' => Schema::TYPE_MEDIUMTEXT,
        'longtext' => Schema::TYPE_LONGTEXT,
        'json' => Schema::TYPE_JSON,
        'blob' => Schema::TYPE_BLOB,
        'enum' => Schema::TYPE_ENUM,

        'date' => Schema::TYPE_DATE,
        'time' => Schema::TYPE_TIME,
        'datetime' => Schema::TYPE_DATETIME,
        'timestamp' => Schema::TYPE_TIMESTAMP,
    ];

    public function testConstruct()
    {
        $column = new Column();
        static::assertCount(0, array_diff($column->toArray(), $this->accepts));
        $v = 1;
        foreach ($this->accepts as $key => $val) {
            static::assertSame($column, $column->$key($v));

            static::assertTrue(isset($column->{$key}));
            static::assertFalse(empty($column->{$key}));
            static::assertEquals($v, $column->{$key});

            static::assertTrue(isset($column[$key]));
            static::assertFalse(empty($column[$key]));
            static::assertEquals($v, $column[$key]);

            $column[$key] = null;
            static::assertNull($column->{$key});
            static::assertNull($column[$key]);

            $column->$key($v);
            $v++;
        }
        static::assertSame($column, $column->clear());
        foreach ($this->accepts as $key => $val) {
            static::assertEquals($val, $column->{$key});
            static::assertEquals($val, $column[$key]);
        }
    }


    public function testReset()
    {
        $v = 1;
        $reset = [];
        foreach ($this->accepts as $key => $val) {
            $reset[$key] = $v++;
        }
        $column = new Column();
        static::assertSame($column, $column->reset($reset));
        $v = 1;
        foreach ($this->accepts as $key => $val) {
            static::assertEquals($v, $column->{$key});
            static::assertEquals($v, $column[$key]);
            $v++;
        }
    }


    public function testSetType()
    {
        $column = new Column();
        foreach ($this->columnType as $method => $value) {
            $column->{$method}($method);
            static::assertEquals($column->type, $value);
            static::assertEquals($column->length, $method);
        }
    }
}
