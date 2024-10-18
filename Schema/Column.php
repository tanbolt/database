<?php
namespace Tanbolt\Database\Schema;

/**
 * Class Column: 数据表字段对象
 * @package Tanbolt\Database\Schema
 *
 * @property ?string $name 字段名
 * @property ?string $type 字段类型 ex:varchar
 * @property ?string $realType 实际字段类型: ex:varchar(20)
 * @property string|int|null $length 字段长度
 * @property bool $unsigned 是否为无符合值
 * @property bool $auto 是否为自递增值
 * @property bool $null 是否可以为 Null
 * @property ?string $collation 语言
 * @property mixed $default 默认值
 * @property ?string $comment 注释
 *
 * // 供驱动接口使用的属性
 * @property string $command 操作类型 add|alter|drop
 * @property ?string $rename 修改后的新字段名
 * @property ?string $after 前置字段名
 *
 * // 可用方法, 也可以直接设置属性值, 但用方法设置可以用链式代码
 * @method $this type(string $type) 设置字段类型, 建议使用下面方法设置常用字段类型
 * @method $this length(string $length) 设置字段长度
 * @method $this realType(string $realType) 设置字段真实类型, 既 type+length 组合
 *
 * @method $this int($length = null)
 * @method $this tinyint($length = null)
 * @method $this smallint($length = null)
 * @method $this mediumint($length = null)
 * @method $this bigint($length = null)
 * @method $this bool()
 *
 * @method $this float($length = null)
 * @method $this double($length = null)
 * @method $this decimal($length = null)
 *
 * @method $this char($length = null)
 * @method $this varchar($length = null)
 * @method $this text()
 * @method $this mediumtext()
 * @method $this longtext()
 * @method $this json()
 * @method $this blob()
 * @method $this enum($length = null)
 *
 * @method $this date()
 * @method $this time($length = null)
 * @method $this timestamp($length = null)
 * @method $this datetime($length = null)
 *
 * @method $this unsigned(bool $unsigned = true) 设置是否有符号
 * @method $this auto(bool $auto = true) 设置是否自增
 * @method $this null(bool $null = true) 设置是否可以为NULL
 * @method $this default($default) 设置默认值
 * @method $this collation(?string $collation) 设置语言
 * @method $this comment(?string $comment) 设置注释
 * @method $this rename(?string $rename) 新字段名
 * @method $this after(?string $after) 添加到哪个字段之后
 */
class Column extends Collection
{
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

    /**
     * 默认值 default 字段比较特殊, 可以指定为 null 值,
     * 所以 default===false 时, 才认为未指定 default 值,
     * 其他字段 === null 认为未指定
     * @var array
     */
    protected $accepts = [
        'name'=> null,
        'type' => null, //如：varchar
        'length' => null,
        'realType' => null, //如：varchar(20)，不指定则使用 type+length 组合
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
     * 添加 设置字段类型 的方法
     * @param $method
     * @param array $parameters
     * @return $this
     */
    public function __call($method, $parameters = [])
    {
        if (array_key_exists($method, $this->columnType)) {
            parent::setAttribute('type', $this->columnType[$method]);
            if (isset($parameters[0])) {
                parent::setAttribute('length', $parameters[0]);
            }
            return $this;
        }
        return parent::__call($method, $parameters);
    }
}
