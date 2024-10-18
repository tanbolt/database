<?php
namespace Tanbolt\Database\Query;

/**
 * Class Expression: SQL语句表达式
 * @package Tanbolt\Database\Query
 */
class Expression implements ExpressionInterface
{
    /**
     * @var string
     */
    private $query;

    /**
     * @var string
     */
    private $alias;

    /**
     * @var array
     */
    private $bindings;

    /**
     * 创建一个 raw 组合语句
     * @param $query
     * @param array|string $bindings
     * @param ?string $alias
     * @return Expression
     */
    public static function raw($query, $bindings = [], string $alias = null)
    {
        return new self($query, $bindings, $alias);
    }

    /**
     * Expression constructor.
     * @param null $query
     * @param array|string $bindings
     * @param ?string $alias
     */
    public function __construct($query = null, $bindings = [], string $alias = null)
    {
        $this->query = $query;
        $this->bindings = is_array($bindings) ? $bindings : [$bindings];
        $this->alias = $alias;
    }

    /**
     * 设置/获取 query()
     * - 设置: query($query)
     * - 获取: query()
     * @inheritDoc
     */
    public function query()
    {
        if (func_num_args()) {
            $this->query = func_get_arg(0);
            return $this;
        }
        return $this->query;
    }

    /**
     * set bindings
     * @param array|string $bindings
     * @return $this
     */
    public function bindings($bindings)
    {
        $this->bindings = is_array($bindings) ? $bindings : [$bindings];
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * set alias
     * @param ?string $as
     * @return $this
     */
    public function alias(?string $as)
    {
        $this->alias = $as;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * flatten array
     * @param $array
     * @param ?callable $callback
     * @return array
     */
    protected static function flattenArray($array, callable $callback = null)
    {
        $return = [];
        if (!is_array($array)) {
            $array = [$array];
        }
        array_walk_recursive($array, function ($x) use (&$return, $callback) {
            if ($callback) {
                $x = call_user_func($callback, $x);
            }
            is_array($x) ? $return = array_merge($return, $x) : $return[] = $x;
        });
        return $return;
    }
}
